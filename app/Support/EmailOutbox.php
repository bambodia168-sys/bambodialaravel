<?php

namespace App\Support;

use App\Mail\PlatformEmail;
use App\Models\AccountNotification;
use App\Models\EmailCampaign;
use App\Models\EmailDelivery;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

final class EmailOutbox
{
    public static function template(string $event): ?array
    {
        if (! EmailTemplates::defaults($event)) {
            return null;
        }

        return EmailTemplates::resolve($event, EmailTemplate::query()->find($event));
    }

    public static function queue(string $event, int $userId, string $key, array $context, string $path): ?EmailDelivery
    {
        if (! (self::template($event)['enabled'] ?? false)) {
            return null;
        }

        return self::store($event, $userId, $key, $context, $path);
    }

    private static function store(string $event, int $userId, string $key, array $context, string $path, ?int $campaignId = null): EmailDelivery
    {
        $context = array_intersect_key($context, array_flip(EmailTemplates::VARIABLES));
        $context['target_path'] = AccountNotification::isLocalTarget($path) ? $path : null;
        return EmailDelivery::query()->firstOrCreate([
            'deduplication_key' => hash('sha256', $event.':'.$userId.':'.$key),
        ], [
            'event' => $event, 'user_id' => $userId, 'email_campaign_id' => $campaignId, 'context' => $context,
        ]);
    }

    public static function deliveryReady(): bool
    {
        $driver = app('mail.manager')->getDefaultDriver();

        if ($driver === DashboardMailManager::DRIVER) {
            return true;
        }

        $transport = config('mail.mailers.'.$driver.'.transport');

        return in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)
            || ($transport === 'array' && app()->environment('testing'));
    }

    public static function subscribers(): Builder
    {
        return User::query()->where('account_status', 'active')
            ->whereNotNull('marketing_subscribed_at')->whereNotNull('email_verified_at');
    }

    public function run(): int
    {
        if (! self::deliveryReady()) {
            return 0;
        }

        $this->expandCampaign();

        $sent = 0;
        foreach (EmailDelivery::query()->where('status', 'queued')->orderBy('id')->limit(20)->get() as $delivery) {
            if (! EmailDelivery::query()->whereKey($delivery->id)->where('status', 'queued')->update(['status' => 'sending'])) {
                continue;
            }

            $template = self::template($delivery->event);
            $recipient = $delivery->recipient;
            $campaign = $delivery->email_campaign_id ? EmailCampaign::query()->find($delivery->email_campaign_id) : null;
            if (! ($template['enabled'] ?? false) || ! $recipient || $recipient->account_status !== 'active'
                || ($delivery->email_campaign_id && (! $campaign || ! $recipient->hasVerifiedEmail()
                    || $recipient->email_verified_at->gt($campaign->queued_at)
                    || ! $recipient->marketing_subscribed_at || $recipient->marketing_subscribed_at->gt($campaign->queued_at)))) {
                $delivery->update(['status' => 'skipped']);

                continue;
            }

            try {
                $message = self::message($template, $recipient, $delivery->context, $campaign !== null);
                if (! Mail::to($recipient->email)->send($message)) {
                    throw new \RuntimeException('Mail transport did not accept the message.');
                }
            } catch (\Throwable $exception) {
                Log::warning('Platform email delivery failed.', ['delivery_id' => $delivery->id, 'type' => $exception::class]);
                $delivery->update(['status' => 'failed']);

                continue;
            }

            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    public static function message(array $template, User $recipient, array $context, bool $marketing = false): PlatformEmail
    {
        $copy = EmailTemplates::render($template, [
            'name' => $recipient->name, 'site_name' => config('app.name'),
        ] + $context);
        $path = $context['target_path'] ?? null;
        $base = rtrim(config('app.url'), '/');

        return new PlatformEmail(
            $copy,
            is_string($path) && AccountNotification::isLocalTarget($path) ? $base.$path : null,
            $marketing ? $base.URL::signedRoute('marketing.unsubscribe', ['user' => $recipient->public_id], absolute: false) : null,
        );
    }

    private function expandCampaign(): void
    {
        DB::transaction(function (): void {
            $campaign = EmailCampaign::query()->whereNotNull('queued_at')->whereNull('expanded_at')
                ->orderBy('id')->lockForUpdate()->first();
            if (! $campaign) {
                return;
            }
            if (! (self::template($campaign->event)['enabled'] ?? false)) {
                $campaign->update(['expanded_at' => now()]);

                return;
            }
            $recipients = self::subscribers()->where('marketing_subscribed_at', '<=', $campaign->queued_at)
                ->where('email_verified_at', '<=', $campaign->queued_at)
                ->where('id', '>', $campaign->last_user_id)->where('id', '<=', $campaign->audience_max_id)
                ->orderBy('id')->limit(100)->pluck('id');
            foreach ($recipients as $userId) {
                self::store($campaign->event, $userId, 'campaign-'.$campaign->id, [
                    'title' => $campaign->title, 'message' => $campaign->message,
                ], $campaign->target_path, $campaign->id);
            }
            if ($recipients->count() < 100) {
                $campaign->update(['expanded_at' => now()]);
            } else {
                $campaign->update(['last_user_id' => $recipients->last()]);
            }
        }, 3);
    }
}
