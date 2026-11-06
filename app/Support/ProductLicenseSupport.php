<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\ProductLicense;
use App\Models\ProductLicenseEvent;
use App\Models\ProductLicenseSupportRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductLicenseSupport
{
    public const TERMS = [
        'six_months'=>'6-month support',
        'twelve_months'=>'12-month support',
        'five_years'=>'5-year support',
        'lifetime'=>'Lifetime support',
    ];

    public static function assertTerm(string $term): void
    {
        if (! array_key_exists($term,self::TERMS)) {
            throw ValidationException::withMessages(['term'=>'Select a supported renewal period.']);
        }
    }

    public static function interval(string $term, CarbonImmutable $from): ?CarbonImmutable
    {
        self::assertTerm($term);
        return match($term) {
            'six_months'=>$from->addMonthsNoOverflow(6),
            'twelve_months'=>$from->addMonthsNoOverflow(12),
            'five_years'=>$from->addYearsNoOverflow(5),
            'lifetime'=>null,
        };
    }

    public static function issuePeriod(string $term): array
    {
        self::assertTerm($term);
        $from=CarbonImmutable::instance(now());
        return [
            'support_term'=>$term,
            'support_started_at'=>$from,
            'support_expires_at'=>self::interval($term,$from),
        ];
    }

    public static function request(ProductLicense $license, User $customer, string $term): ProductLicenseSupportRequest
    {
        self::assertTerm($term);
        return DB::transaction(function () use ($license,$customer,$term): ProductLicenseSupportRequest {
            $locked=ProductLicense::query()->lockForUpdate()->findOrFail($license->id);
            abort_unless((int)$locked->user_id===(int)$customer->id,404);
            abort_if($locked->status==='revoked',409,'A revoked license cannot renew support.');
            abort_if($locked->support_term==='lifetime',409,'Lifetime support does not need renewal.');
            abort_if(ProductLicenseSupportRequest::query()->where('product_license_id',$locked->id)
                ->where('status','pending')->exists(),409,'A support renewal request is already pending.');

            $renewal=ProductLicenseSupportRequest::query()->create([
                'product_license_id'=>$locked->id,'user_id'=>$customer->id,
                'term'=>$term,'status'=>'pending',
            ]);
            ProductLicenseEvent::query()->create([
                'product_license_id'=>$locked->id,'actor_id'=>$customer->id,
                'action'=>'support.requested','reason'=>self::TERMS[$term],
                'created_at'=>now(),
            ]);
            return $renewal;
        });
    }

    public static function review(ProductLicenseSupportRequest $request, User $administrator,
        string $decision, string $reason): ProductLicenseSupportRequest
    {
        abort_unless($administrator->role==='super_admin',403,
            'Only the platform owner may approve complimentary support terms.');
        abort_unless(in_array($decision,['approve','reject'],true),422);

        return DB::transaction(function () use ($request,$administrator,$decision,$reason): ProductLicenseSupportRequest {
            $license=ProductLicense::query()->lockForUpdate()
                ->findOrFail($request->product_license_id);
            $renewal=ProductLicenseSupportRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless((int)$renewal->product_license_id===(int)$license->id,409);
            abort_unless($renewal->status==='pending',409,'This request is already resolved.');
            abort_unless((int)$renewal->user_id===(int)$license->user_id,409);
            abort_if($license->status==='revoked',409,'A revoked license cannot receive support.');
            abort_if($license->support_term==='lifetime' && $decision==='approve',409,
                'Lifetime support is already active.');

            $newStatus=$decision==='approve'?'approved':'rejected';
            $start=null;
            $end=null;
            if ($decision==='approve') {
                $now=CarbonImmutable::instance(now());
                $existing=$license->support_expires_at;
                $start=$existing && $existing->isFuture()
                    ? CarbonImmutable::instance($existing) : $now;
                $end=self::interval($renewal->term,$start);
                $license->update([
                    'support_term'=>$renewal->term,
                    'support_started_at'=>$start,
                    'support_expires_at'=>$end,
                ]);
            }

            $renewal->update([
                'status'=>$newStatus,'reviewed_by'=>$administrator->id,
                'reviewed_at'=>now(),'review_note'=>$reason,
                'granted_from'=>$start,'granted_until'=>$end,
            ]);
            ProductLicenseEvent::query()->create([
                'product_license_id'=>$license->id,'actor_id'=>$administrator->id,
                'action'=>'support.'.$newStatus,
                'reason'=>mb_substr(self::TERMS[$renewal->term].' · '.$reason,0,500),
                'created_at'=>now(),
            ]);
            AccountNotification::deliver(
                (int)$license->user_id,'license.support.reviewed',
                'Product support renewal '.$newStatus,
                $decision==='approve'
                    ? 'Your requested product support period has been granted.'
                    : 'Your support renewal request was not approved. Review your licenses.',
                '/account/licenses'
            );
            return $renewal;
        });
    }
}
