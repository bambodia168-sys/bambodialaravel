<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\EmailTemplate;
use Illuminate\Support\Str;

final class EmailTemplates
{
    public const PROMOTION = 'campaign.listing';
    public const ANNOUNCEMENT = 'campaign.announcement';
    public const VERIFY = 'account.email.verify';
    public const VARIABLES = ['name', 'site_name', 'title', 'message', 'order_number', 'receipt_number', 'amount', 'payment_method', 'badge', 'years'];
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
                "Hello {{name}},\n\nWe received {{amount}} for {{title}} via {{payment_method}}.\n\nOrder {{order_number}}", 'View your receipt'],
            'commerce.seller_purchase' => ['New sale for listing owner', 'Commerce', true, 'New Bambodia sale for {{title}}', 'You have a new sale',
                "Hello {{name}},\n\nA buyer paid {{amount}} for {{title}}. Open Sales Orders for delivery details.\n\nOrder {{order_number}}", 'View sales order'],
            'listing.published' => ['Listing published', 'Marketplace', true, $activity, 'Your listing is live', $body, 'Open listing'],
            'job.published' => ['Job published', 'Marketplace', true, $activity, 'Your job is live', $body, 'Open job'],
            self::VERIFY => ['Verify email address', 'Account', true, 'Verify your email on {{site_name}}', 'Verify your email address',
                "Hello {{name}},\n\nVerify your email address to continue. This link expires in one hour.", 'Verify email'],
            'account.sign_in' => ['Login alert', 'Account', true, 'New sign-in to {{site_name}}', 'New sign-in detected',
                "Hello {{name}},\n\nYour account was signed in successfully. If you did not sign in, review your account security.", 'Review account security'],
            'account.two_factor_changed' => ['Two-factor authentication changed', 'Account', true, $activity, $activity, $body, 'Review account security'],
            'account.profile_verified' => ['Profile verification decision', 'Account', true, $activity, $activity, $body, 'Open your account'],
            'account.details_changed' => ['Account update notification', 'Account', true, 'Your {{site_name}} account was updated', 'Account information updated',
                "Hello {{name}},\n\nYour account information changed. If you did not expect this change, review your account.", 'Review your account'],
            'account.profile_links_changed' => ['Social and profile links changed', 'Account', true, 'Your profile links were updated', 'Profile links updated',
                "Hello {{name}},\n\nA link in your profile was added, edited, or removed.", 'Review profile links'],
            'creator.badge_awarded' => ['Badge awarded', 'Marketplace', true, 'You earned a badge on {{site_name}}', 'Congratulations, {{name}}',
                "You received {{badge}}. Open your account to review your achievements.", 'Open your account'],
            'listing.archived' => ['Listing archived', 'Marketplace', true, 'Listing archived on {{site_name}}', 'Your listing was archived',
                "Hello {{name}},\n\nYour listing “{{title}}” was archived.", 'View archived listings'],
            'commerce.abandoned_cart' => ['Abandoned cart reminder', 'Reminders', false, 'Your Bambodia cart is saved', 'Return to your cart',
                "Hello {{name}},\n\nItems are still in your saved cart. Open your cart to check current prices and availability.", 'Open your cart'],
            'commerce.unpaid_order' => ['Unpaid order reminder', 'Reminders', false, 'Your Bambodia order is awaiting payment', 'Your order awaits payment',
                "Hello {{name}},\n\nOrder {{order_number}} for {{title}} is awaiting payment. Open your order to review its status.", 'View order'],
            'account.incomplete_profile' => ['Incomplete profile reminder', 'Reminders', false, 'Finish your Bambodia profile', 'Complete your profile',
                "Hello {{name}},\n\nAdd a profile photo so other members can recognize your account.", 'Edit profile'],
            'account.birthday' => ['Birthday greeting', 'Reminders', false, 'Happy birthday, {{name}}!', 'Happy birthday!',
                "Hello {{name}},\n\nWishing you a wonderful birthday from everyone at Bambodia.", 'Open Bambodia'],
            'account.anniversary' => ['Account anniversary', 'Reminders', false, 'Celebrating {{years}} on {{site_name}}', 'Happy Bambodia anniversary!',
                "Hello {{name}},\n\nThank you for being part of Bambodia for {{years}}.", 'Open your account'],
            'account.kyc_update' => ['KYC status update', 'Workflow required', false, 'Identity review update', 'Identity review update',
                "Hello {{name}},\n\nOpen your Bambodia account to review your identity verification status.", 'Open your account'],
            'account.password_expiry' => ['Password expiry reminder', 'Workflow required', false, 'Password expiry reminder', 'Review your account security',
                "Hello {{name}},\n\nOpen Bambodia to review your password settings.", 'Account security'],
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
                'inquiry.replied', 'detail.comment.received', 'contract.message.received', 'support.status_changed',
            ], true);
            $label = match ($event) {
                'marketplace.submission.received' => 'Listing or job pending review',
                'account.email.verified' => 'Email address verified',
                'detail.comment.received' => 'New comment or reply',
                'contract.message.received' => 'Contract chat message',
                'inquiry.replied' => 'Listing chat reply',
                'support.replied' => 'Support reply',
                'support.status_changed' => 'Support ticket status changed',
                default => Str::headline(str_replace('.', ' ', $event)),
            };
            $entries[$event] = [$label, $category, $enabled, $activity, $activity, $body, 'View details'];
        }

        return $catalog = array_map(fn (array $entry) => array_combine(
            ['label', 'category', 'enabled', ...self::EDITABLE], $entry
        ), $entries);
    }

    public static function isReminder(string $event): bool
    {
        return (self::defaults($event)['category'] ?? null) === 'Reminders';
    }

    public static function variables(string $event): array
    {
        $extra = match ($event) {
            'commerce.receipt' => ['title', 'order_number', 'receipt_number', 'amount', 'payment_method'],
            'commerce.seller_purchase', 'commerce.unpaid_order' => ['title', 'order_number', 'amount', 'payment_method'],
            'creator.badge_awarded' => ['badge'],
            'listing.archived' => ['title'],
            'account.anniversary' => ['years'],
            default => str_contains(implode(' ', array_intersect_key(self::defaults($event) ?? [], array_flip(self::EDITABLE))), '{{message}}')
                ? ['title', 'message'] : [],
        };

        return ['name', 'site_name', ...$extra];
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
            $override?->only(['enabled', ...self::EDITABLE, 'body_html']) ?? [], fn ($value) => $value !== null
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
        $html = BuilderContent::sanitize($template['body_html'] ?? null, email: true);
        $rendered['body_html'] = $html ? strtr($html, array_map(e(...), $replacements)) : null;
        $rendered['subject'] = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $rendered['subject']), 0, 220);

        return $rendered;
    }
}
