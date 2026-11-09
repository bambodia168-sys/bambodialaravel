<?php

namespace App\Support;

use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CreatorBadgeAwards
{
    public const OWNER_EMAIL = 'tonsamith168@gmail.com';

    /** The designated owner receives the enabled badge catalog, independently of activity. */
    public static function isDesignatedOwner(User $creator): bool
    {
        return $creator->role === 'super_admin'
            && strcasecmp((string) $creator->email, self::OWNER_EMAIL) === 0;
    }

    /** Verified sources only: no speculative ratings, earnings or identity checks. */
    public static function earned(User $creator, ?Collection $catalog = null): Collection
    {
        // Every active user can receive explicit manually reviewed achievements.
        // Automatic freelancer metrics are still restricted to creator accounts.
        $catalog ??= CreatorBadge::query()->where('enabled',true)->orderBy('sort_order')->get();
        $catalog = $catalog->where('enabled',true);
        if (self::isDesignatedOwner($creator)) {
            return $catalog->values();
        }

        $manualIds = CreatorBadgeGrant::query()->where('user_id',$creator->id)
            ->where('source','manual')->pluck('creator_badge_id')->all();
        $autoSlugs = in_array($creator->role,['freelancer','super_admin'],true)
            ? self::automaticSlugs($creator,$catalog) : [];
        return $catalog->filter(fn (CreatorBadge $badge) =>
            in_array($badge->id,$manualIds)
            || ($badge->grant_mode === 'auto' && in_array($badge->slug,$autoSlugs,true))
        )->values();
    }

    /**
     * Rules are backed only by completed contracts and accepted milestones.
     * Identity / expert / certification awards always require manual evidence.
     */
    public static function automaticSlugs(User $creator, ?Collection $catalog = null): array
    {
        $catalog ??= CreatorBadge::query()->whereIn('slug',[
            'rising-talent','repeat-clients','on-time-delivery',
        ])->get();
        $rules = $catalog->keyBy('slug');
        $rising = $rules->get('rising-talent')?->auto_rules ?? [];
        $repeat = $rules->get('repeat-clients')?->auto_rules ?? [];
        $onTime = $rules->get('on-time-delivery')?->auto_rules ?? [];

        $completed = DB::table('freelance_contracts')
            ->where('freelancer_id',$creator->id)->where('status','completed');
        $completedCount = (clone $completed)->count();
        $slugs = [];

        if ($completedCount >= (int)($rising['min_completed'] ?? 1)
            && $completedCount <= (int)($rising['max_completed'] ?? 5)
            && $creator->created_at?->greaterThanOrEqualTo(
                now()->subDays((int)($rising['max_account_age_days'] ?? 365))
            )) {
            $slugs[]='rising-talent';
        }

        $repeatMinimum = max(2, (int)($repeat['min_repeat_contracts'] ?? 2));
        if (DB::table('freelance_contracts')->where('freelancer_id',$creator->id)
            ->where('status','completed')->select('client_id')->groupBy('client_id')
            ->havingRaw('COUNT(*) >= ?',[$repeatMinimum])->exists()) {
            $slugs[]='repeat-clients';
        }

        if ($completedCount >= (int)($onTime['min_completed'] ?? 3)) {
            $milestones=DB::table('contract_milestones as m')
                ->join('freelance_contracts as c','c.id','=','m.freelance_contract_id')
                ->where('c.freelancer_id',$creator->id)->where('c.status','completed')
                ->whereNotNull('m.due_on');
            $total=(clone $milestones)->count();
            $late=(clone $milestones)->where(function($q){
                $q->whereNull('m.accepted_at')
                  ->orWhereRaw('DATE(m.accepted_at) > m.due_on');
            })->count();
            // An On-Time badge can never auto-award a late or unaccepted milestone.
            if ($total >= (int)($onTime['min_milestones'] ?? 3) && $late===0) {
                $slugs[]='on-time-delivery';
            }
        }

        return $slugs;
    }
}
