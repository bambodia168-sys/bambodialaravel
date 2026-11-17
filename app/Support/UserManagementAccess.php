<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserManagementAccess
{
    // Staff marketplace transfers require an explicit assignment.
    public const CAPABILITIES = [
        'badge_awards'=>'Creator badge grants',
        'support_center'=>'Support ticket management',
        'marketplace_review'=>'Marketplace review queue',
        'marketplace_transfer'=>'Marketplace ownership transfers',
        'commerce_orders'=>'Marketplace order records',
    ];

    public static function baseAllowed(User $user, string $permission): bool
    {
        return match($permission) {
            'badge_awards','support_center','marketplace_transfer','commerce_orders'=>in_array($user->role,['staff','admin','super_admin'],true),
            'marketplace_review'=>in_array($user->role,['admin','super_admin'],true),
            default=>false,
        };
    }

    public static function allows(User $user, string $permission): bool
    {
        if ($permission === 'commerce_orders' && ! config('commerce.enabled')) return false;
        if ($user->account_status !== 'active' || !self::baseAllowed($user,$permission)) return false;
        if ($user->role === 'super_admin') return true;
        $value = DB::table('user_permission_overrides')
            ->where('user_id',$user->id)->where('permission',$permission)->value('allowed');
        return $value === null ? self::defaultAllowed($user, $permission) : (bool)$value;
    }

    public static function defaultAllowed(User $user, string $permission): bool
    {
        return self::baseAllowed($user, $permission)
            && match ($permission) {
                'marketplace_transfer' => $user->role !== 'staff',
                'commerce_orders' => $user->role === 'super_admin',
                default => true,
            };
    }

    public static function allowedFor(User $user): array
    {
        $existing = DB::table('user_permission_overrides')
            ->where('user_id',$user->id)->pluck('allowed','permission');
        $result=[];
        $capabilities = self::CAPABILITIES;
        if (! config('commerce.enabled')) {
            unset($capabilities['commerce_orders']);
        }
        foreach ($capabilities as $key=>$name) {
            $result[$key] = [
                'name'=>$name,
                'role_permits'=>self::baseAllowed($user,$key),
                'allowed'=>self::baseAllowed($user,$key)
                    && ($user->role==='super_admin' || (isset($existing[$key]) ? (bool) $existing[$key] : self::defaultAllowed($user, $key))),
            ];
        }
        return $result;
    }
}
