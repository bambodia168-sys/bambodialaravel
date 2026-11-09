<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User-requested default: compact artwork with accessible hover descriptions.
        // Respect any already-selected Badge Only setting.
        if (Schema::hasTable('creator_badge_settings')) {
            DB::table('creator_badge_settings')->where('id', 1)->update([
                'display_mode' => 'badge',
                'tooltips_enabled' => true,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Do not silently reset an administrator's later appearance selection.
    }
};
