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
        if (! in_array($creator->role,['freelancer','super_admin'],true)) {
            return collect();
        }

        $catalog ??= CreatorBadge::query()->where('enabled',true)->orderBy('sort_order')->get();
        $catalog = $catalog->where('enabled',true);
        if (self::isDesignatedOwner($creator)) {
            return $catalog->values();
        }

        $manualIds = CreatorBadgeGrant::query()->where('user_id',$creator->id)
            ->where('source','manual')->pluck('creator_badge_id')->all();
        $autoSlugs = self::automaticSlugs($creator);
        return $catalog->filter(fn (CreatorBadge $badge) =>
            ($badge->grant_mode === 'manual' && in_array($badge->id,$manualIds))
            || ($badge->grant_mode === 'auto' && in_array($badge->slug,$autoSlugs,true))
        )->values();
    }

    public static function automaticSlugs(User $creator): array
    {
        $completed = DB::table('freelance_contracts')
            ->where('freelancer_id',$creator->id)->where('status','completed');
        $completedCount = (clone $completed)->count();
        $slugs = [];
        if ($completedCount >= 1 && $completedCount <= 5
            && $creator->created_at?->greaterThanOrEqualTo(now()->subYear())) {
            $slugs[] = 'rising-talent';
        }
        if (DB::table('freelance_contracts')->where('freelancer_id',$creator->id)
            ->where('status','completed')->select('client_id')->groupBy('client_id')
            ->havingRaw('COUNT(*) >= 2')->exists()) {
            $slugs[] = 'repeat-clients';
        }
        if ($completedCount >= 3) {
            $milestones = DB::table('contract_milestones as m')
                ->join('freelance_contracts as c','c.id','=','m.freelance_contract_id')
                ->where('c.freelancer_id',$creator->id)
                ->where('c.status','completed')->whereNotNull('m.due_on');
            $total = (clone $milestones)->count();
            $late = (clone $milestones)->where(function($q) {
                $q->whereNull('m.accepted_at')
                  ->orWhereRaw('DATE(m.accepted_at) > m.due_on');
            })->count();
            if ($total >= 3 && $late === 0) {
                $slugs[] = 'on-time-delivery';
            }
        }
        return $slugs;
    }
}
