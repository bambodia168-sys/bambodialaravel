<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class CreatorBadgeDisplay
{
    public const MODES = ['badge'=>'Badge only','badge_name'=>'Badge + Name'];
    public const SIZES = ['small'=>'Small','standard'=>'Standard','large'=>'Large'];

    public static function settings(): object
    {
        return DB::table('creator_badge_settings')->where('id',1)->first() ?? (object)[
            'display_mode'=>'badge',
            'badge_size'=>'standard',
            'tooltips_enabled'=>true,
        ];
    }
}