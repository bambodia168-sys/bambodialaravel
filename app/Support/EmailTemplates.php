<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\EmailTemplate;
use Illuminate\Support\Str;

final class EmailTemplates
{
    public const PROMOTION = 'campaign.listing';
    public const ANNOUNCEMENT = 'campaign.announcement';
    public const VARIABLES = ['name', 'site_name', 'title', 'message', 'order_number', 'receipt_number', 'amount', 'payment_method'];
    public const EDITABLE = ['subject', 'heading', 'body', 'button'];

    public static function catalog(): array
    {
        static $catalog;
        if ($catalog !== null) {
            return $catalog;
        }

        $accountBody = "Hello {{name}},\n\nYour Bambodia account is ready. Sign in to manage your profile and browse the marketplace.";
        $activity = "{{title}}";
        $body = "Hello {{name}},\n\n{{message}}";
        $entries = [
            'account.welcome' => ['Welcome email', 'Account', true, 'Welcome to {{site_name}}', 'Welcome to {{site_name}}', $accountBody, 'Open your account'],
            'account.created_by_admin' => ['Account created by admin', 'Account', true, 'Your {{site_name}} account is ready', 'Your account is ready',
                "Hello {{name}},\n\nA Bambodia administrator created your account. Use the sign-in page or request a password reset to set a password you control.", 'Sign in'],
            'account.social_sign_in' => ['Social sign-in alert', 'Account', true, 'New sign-in to {{site_name}}', 'New social sign-in',
                "Hello {{name}},\n\n{{message}}\n\nIf you did not sign in, review your account security.", 'Review account security'],
            'commerce.receipt' => ['Purchase receipt', 'Commerce', true, 'Your Bambodia receipt for {{title}}', 'Payment confirmed',
                "Hello {{name}},\n\nWe received {{amount}} for {{title}} via {{payment_method}}.\n\nOrder {{order_number}}\nReceipt {{receipt_number}}", 'View your receipt'],
            'commerce.seller_purchase' => ['New sale for listing owner', 'Commerce', true, 'New Bambodia sale for {{title}}', 'You have a new sale',
                "Hello {{name}},\n\nA buyer paid {{amount}} for {{title}}. Open Sales Orders for delivery details.\n\nOrder {{order_number}}", 'View sales order'],
            'listing.published' => ['Listing published', 'Marketplace', true, $activity, 'Your listing is live', $body, 'Open listing'],
            'job.published' => ['Job published', 'Marketplace', true, $activity, 'Your job is live', $body, 'Open job'],
            self::PROMOTION => ['Listing promotion', 'Campaign', false, $activity, $activity, $body, 'View listing'],
            self::ANNOUNCEMENT => ['Marketplace announcement', 'Campaign', false, $activity, $activity, $body, 'Explore Bambodia'],
        ];

        foreach (AccountNotification::EVENTS as $event) {
            // Policy notices already respect the administrator's selected delivery channels.
            if (in_array($event, ['policy.updated', 'account.email.verification_sent'], true)) {
                continue;
            }
            $category = match (strtok($event, '.')) {
                'commerce', 'license', 'vault' => 'Commerce',
                'account', 'security', 'privacy' => 'Account',
                'support' => 'Support',
                default => 'Marketplace',
            };
            $enabled = in_array($event, [
                'listing.reviewed', 'job.reviewed', 'marketplace.submission.received', 'commerce.payment.rejected',
                'commerce.refund.confirmed', 'support.replied', 'inquiry.received', 'job.proposal.received',
            ], true);
            $entries[$event] = [Str::headline(str_replace('.', ' ', $event)), $category, $enabled, $activity, $activity, $body, 'View details'];
        }

        return $catalog = array_map(fn (array $entry) => array_combine(
            ['label', 'category', 'enabled', ...self::EDITABLE], $entry
        ), $entries);
    }

    public static function defaults(string $event): ?array
    {
        return self::catalog()[$event] ?? null;
    }

    public static function resolve(string $event, ?EmailTemplate $override): ?array
    {
        $defaults = self::defaults($event);
        if ($defaults === null) {
            return null;
        }

        return array_replace($defaults, array_filter(
            $override?->only(['enabled', ...self::EDITABLE]) ?? [], fn ($value) => $value !== null
        ));
    }

    public static function render(array $template, array $context): array
    {
        $replacements = [];
        foreach (self::VARIABLES as $key) {
            $replacements['{{'.$key.'}}'] = (string) ($context[$key] ?? '');
        }

        $rendered = [];
        foreach (self::EDITABLE as $field) {
            $rendered[$field] = trim(strtr($template[$field], $replacements));
        }
        $rendered['subject'] = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $rendered['subject']), 0, 220);

        return $rendered;
    }
}
