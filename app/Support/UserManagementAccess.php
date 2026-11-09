<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserManagementAccess
{
    // These are opt-out restrictions, not privilege-escalating grants.
    public const CAPABILITIES = [
        'badge_awards'=>'Creator badge grants',
        'support_center'=>'Support ticket management',
        'marketplace_review'=>'Marketplace review queue',
    ];

    public static function baseAllowed(User $user, string $permission): bool
    {
        return match($permission) {
            'badge_awards','support_center'=>in_array($user->role,['staff','admin','super_admin'],true),
            'marketplace_review'=>in_array($user->role,['admin','super_admin'],true),
            default=>false,
        };
    }

    public static function allows(User $user, string $permission): bool
    {
        if ($user->account_status !== 'active' || !self::baseAllowed($user,$permission)) return false;
        if ($user->role === 'super_admin') return true;
        $value = DB::table('user_permission_overrides')
            ->where('user_id',$user->id)->where('permission',$permission)->value('allowed');
        return $value === null ? true : (bool)$value;
    }

    public static function allowedFor(User $user): array
    {
        $existing = DB::table('user_permission_overrides')
            ->where('user_id',$user->id)->pluck('allowed','permission');
        $result=[];
        foreach (self::CAPABILITIES as $key=>$name) {
            $result[$key] = [
                'name'=>$name,
                'role_permits'=>self::baseAllowed($user,$key),
                'allowed'=>self::baseAllowed($user,$key)
                    && ($user->role==='super_admin' || !isset($existing[$key]) || (bool)$existing[$key]),
            ];
        }
        return $result;
    }
}