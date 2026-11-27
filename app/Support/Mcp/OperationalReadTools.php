<?php

namespace App\Support\Mcp;

use App\Models\AccountNotification;
use App\Models\CreatorApplication;
use App\Models\EmailDelivery;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\EmailOutbox;
use App\Support\MarketplaceManagement;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Aggregate-only, independently authorized operational reads. */
final class OperationalReadTools
{
    public const TOOLS = [
        'bambodia_accounts_summary' => 'Aggregate account registrations, roles and verification counts; no identities.',
        'bambodia_orders_summary' => 'Order counts and verified completed order amounts by currency; no buyer or seller details.',
        'bambodia_review_queue' => 'Current marketplace, job, media and creator application review backlog; no record contents.',
        'bambodia_support_summary' => 'Support ticket counts by status and current unresolved backlog; no messages or credentials.',
        'bambodia_notifications_summary' => 'Counts of the authenticated administrator’s notifications; no messages or other recipients.',
        'bambodia_email_delivery_summary' => 'Email outbox status counts and submission event totals; no email addresses, context or credentials.',
    ];

    public static function definitions(): array
    {
        $tools = [];
        foreach (self::TOOLS as $name => $description) {
            $properties = $name === 'bambodia_review_queue' ? new \stdClass() : [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 7],
            ];
            $tools[] = [
                'name' => $name,
                'description' => $description,
                'inputSchema' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ];
        }

        return $tools;
    }

    public function call(string $name, array $args, User $owner, bool $mayRead): array
    {
        if (! $mayRead || $owner->role !== 'super_admin' || $owner->account_status !== 'active') {
            throw ValidationException::withMessages(['access' => 'Operational MCP reads require an active super administrator and read authorization.']);
        }
        if (! isset(self::TOOLS[$name])) {
            throw ValidationException::withMessages(['name' => 'Unknown operational read tool.']);
        }
        $allowed = $name === 'bambodia_review_queue' ? [] : ['days'];
        if (array_diff(array_keys($args), $allowed)) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported arguments.']);
        }
        $days = (int) (Validator::make($args, ['days' => ['sometimes', 'integer', 'min:1', 'max:90']])->validate()['days'] ?? 7);
        $end = now()->toImmutable();
        $start = $end->subDays($days);
        $window = fn (Builder $query): Builder => $query->whereBetween('created_at', [$start, $end]);
        $data = match ($name) {
            'bambodia_accounts_summary' => $this->accounts($window),
            'bambodia_orders_summary' => $this->orders($window, $owner),
            'bambodia_review_queue' => $this->reviews($owner),
            'bambodia_support_summary' => $this->support($window, $owner),
            'bambodia_notifications_summary' => $this->notifications($window, $owner),
            'bambodia_email_delivery_summary' => $this->email($window),
        };

        return [
            'generated_at' => $end->toIso8601String(),
            ...($name === 'bambodia_review_queue' ? ['basis' => 'current backlog'] : [
                'period_days' => $days, 'from' => $start->toIso8601String(), 'to' => $end->toIso8601String(),
                'basis' => 'records created in window; current status unless explicitly stated',
            ]),
            ...$data,
        ];
    }

    private function counts(Builder $query, string $column, array $allowed): array
    {
        // The column and status allowlists are hardcoded by the callers.
        $values = $query->whereIn($column, $allowed)->selectRaw($column.', COUNT(*) AS total')
            ->groupBy($column)->pluck('total', $column);
        $result = [];
        foreach ($allowed as $key) {
            $result[$key] = (int) ($values[$key] ?? 0);
        }

        return $result;
    }

    private function requireCapability(User $owner, string $capability): void
    {
        if (! UserManagementAccess::allows($owner, $capability)) {
            throw ValidationException::withMessages(['access' => 'The required operational capability is unavailable.']);
        }
    }

    private function accounts(callable $window): array
    {
        $query = $window(User::query());

        return [
            'registered' => (clone $query)->count(),
            'roles' => $this->counts(clone $query, 'role', User::ROLES),
            'email_verified' => (clone $query)->whereNotNull('email_verified_at')->count(),
            'active' => (clone $query)->where('account_status', 'active')->count(),
        ];
    }

    private function orders(callable $window, User $owner): array
    {
        $this->requireCapability($owner, 'commerce_orders');
        $query = $window(MarketplaceOrder::query());
        $verified = (clone $query)->where('status', 'completed')->whereHas('paidAttempt');
        $amounts = (clone $verified)->selectRaw('currency, COUNT(*) as total_orders, SUM(total) as amount')
            ->groupBy('currency')->orderBy('currency')->get()->map(fn ($row) => [
                'currency' => $row->currency, 'orders' => (int) $row->total_orders,
                'gross_order_amount' => (string) $row->amount,
            ])->all();

        return [
            'orders' => $query->count(),
            'status' => $this->counts(clone $query, 'status', array_keys(MarketplaceOrder::STATUSES)),
            'verified_completed_by_currency' => $amounts,
            'amount_note' => 'Gross order amounts of completed orders with a verified paid attempt; not platform revenue or accounting profit. Grouped by order currency.',
        ];
    }

    private function reviews(User $owner): array
    {
        $this->requireCapability($owner, 'marketplace_review');
        $listings = MarketplaceListing::query()->whereIn('status', MarketplaceManagement::REVIEW_STATUSES);

        return [
            'listings_by_type' => $this->counts(clone $listings, 'type', ['product','project','service','freelance']),
            'listings_by_status' => $this->counts(clone $listings, 'status', MarketplaceManagement::REVIEW_STATUSES),
            'jobs_by_status' => $this->counts(MarketplaceJob::query(), 'status', MarketplaceManagement::REVIEW_STATUSES),
            'media_pending' => MediaAsset::query()->where('review_status', 'pending')->count(),
            'creator_applications_pending' => CreatorApplication::query()->where('status', 'pending')->count(),
        ];
    }

    private function support(callable $window, User $owner): array
    {
        $this->requireCapability($owner, 'support_center');

        return [
            'created_by_status' => $this->counts($window(SupportTicket::query()), 'status', array_keys(SupportTicket::STATUSES)),
            'current_unresolved' => SupportTicket::query()->whereIn('status', ['open','pending','in_progress'])->count(),
        ];
    }

    private function notifications(callable $window, User $owner): array
    {
        $own = AccountNotification::query()->forRecipient($owner);
        $recent = $window(clone $own);

        return [
            'received' => (clone $recent)->count(),
            'unread_in_period' => (clone $recent)->whereNull('read_at')->count(),
            'current_unread' => (clone $own)->whereNull('read_at')->count(),
            'submission_events' => $this->counts(clone $recent, 'event', ['marketplace.submission.received','marketplace.review.requested','account.email.verification_sent','account.email.verified']),
        ];
    }

    private function email(callable $window): array
    {
        $query = $window(EmailDelivery::query());

        return [
            'transport_configured' => EmailOutbox::deliveryReady(),
            'created_by_status' => $this->counts(clone $query, 'status', ['queued','sending','sent','failed','skipped']),
            'current_queued' => EmailDelivery::query()->where('status', 'queued')->count(),
            'current_failed' => EmailDelivery::query()->where('status', 'failed')->count(),
            'submission_events' => $this->counts(clone $query, 'event', ['marketplace.submission.received','marketplace.review.requested','account.email.verification_sent','account.email.verified']),
        ];
    }
}
