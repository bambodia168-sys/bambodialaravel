<?php

namespace App\Support;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\JobProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class SupportTicketContext
{
    public const ISSUES = [
        'general' => 'General question',
        'license' => 'License & activation',
        'installation' => 'Installation & setup',
        'customization' => 'Customization & integration',
        'bug_report' => 'Bug report',
        'technical' => 'Technical assistance',
        'payment' => 'Payment & billing',
        'purchase' => 'Purchase & delivery',
        'account' => 'Account access',
        'other' => 'Other request',
    ];

    /** Labels come only from the member's own verified purchases, listings, jobs and orders. */
    public static function choices(User $user): array
    {
        $id = (int) $user->id;
        $paidListings = MarketplaceOrder::query()->where('buyer_id', $id)
            ->where('status', 'completed')->whereHas('paidAttempt')
            ->whereNotNull('marketplace_listing_id')
            ->latest()->limit(200)->pluck('marketplace_listing_id');
        $listings = MarketplaceListing::query()
            ->where(fn ($q) => $q->where('user_id', $id)->orWhereIn('id', $paidListings))
            ->where('status', '!=', 'deleted')
            ->latest()->limit(200)->get(['id', 'type', 'title']);

        $appliedJobs = JobProposal::query()->where('freelancer_id', $id)
            ->latest()->limit(200)->pluck('marketplace_job_id');
        $jobs = MarketplaceJob::query()
            ->where(fn ($q) => $q->where('client_id', $id)->orWhereIn('id', $appliedJobs))
            ->latest()->limit(150)->get(['id', 'title']);

        $orders = MarketplaceOrder::query()
            ->where(fn ($q) => $q->where('buyer_id', $id)->orWhere('seller_id', $id))
            ->where('status', 'completed')->whereHas('paidAttempt')
            ->latest()->limit(100)->get(['id','number','public_reference','listing_title']);

        return [
            'Listings (owned or purchased)' => $listings->map(fn ($v) => [
                'value' => 'listing:'.$v->id,
                'label' => ucfirst($v->type).' · '.$v->title,
            ])->all(),
            'Jobs (posted or applied)' => $jobs->map(fn ($v) => [
                'value' => 'job:'.$v->id, 'label' => 'Job · '.$v->title,
            ])->all(),
            'Verified orders' => $orders->map(fn ($v) => [
                'value' => 'order:'.$v->id,
                'label' => 'Order '.$v->reference().' · '.$v->listing_title,
            ])->all(),
        ];
    }

    public static function resolve(User $user, ?string $value): array
    {
        if ($value === null || $value === '') {
            return ['context_type' => null, 'context_id' => null];
        }
        if (! preg_match('/^(listing|job|order):([1-9][0-9]{0,12})$/D', $value, $parts)) {
            throw ValidationException::withMessages([
                'related_item' => 'Choose a valid related listing, job or verified order.',
            ]);
        }
        $id = (int) $user->id;
        $recordId = (int) $parts[2];
        $exists = match ($parts[1]) {
            'listing' => MarketplaceListing::query()->whereKey($recordId)
                ->where('status', '!=', 'deleted')
                ->where(fn ($q) => $q->where('user_id', $id)
                    ->orWhereIn('id', MarketplaceOrder::query()
                        ->where('buyer_id', $id)->where('status', 'completed')
                        ->whereHas('paidAttempt')->select('marketplace_listing_id')))
                ->exists(),
            'job' => MarketplaceJob::query()->whereKey($recordId)
                ->where(fn ($q) => $q->where('client_id', $id)
                    ->orWhereIn('id', JobProposal::query()->where('freelancer_id', $id)
                        ->select('marketplace_job_id')))
                ->exists(),
            'order' => MarketplaceOrder::query()->whereKey($recordId)
                ->where(fn ($q) => $q->where('buyer_id', $id)->orWhere('seller_id', $id))
                ->where('status', 'completed')->whereHas('paidAttempt')->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'related_item' => 'The related item is not available to your account.',
            ]);
        }

        return ['context_type' => $parts[1], 'context_id' => $recordId];
    }

    /** Do not reveal unrelated or unpublished resource details in public UI. */
    public static function label(?string $type, ?int $id): ?string
    {
        if ($id === null) return null;

        return match ($type) {
            'listing' => (function () use ($id) {
                $listing = MarketplaceListing::query()->find($id, ['type', 'title']);
                return $listing ? ucfirst($listing->type).' · '.$listing->title : 'Former listing';
            })(),
            'job' => (function () use ($id) {
                $job = MarketplaceJob::query()->find($id, ['title']);
                return $job ? 'Job · '.$job->title : 'Former job';
            })(),
            'order' => (function () use ($id) {
                $order = MarketplaceOrder::query()->find($id);
                return $order ? 'Order '.$order->reference().' · '.$order->listing_title : 'Former order';
            })(),
            default => null,
        };
    }
}
