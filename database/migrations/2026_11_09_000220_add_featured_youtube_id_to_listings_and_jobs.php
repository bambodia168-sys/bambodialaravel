<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach(['marketplace_listings','marketplace_jobs'] as $tableName) {
            Schema::table($tableName,function(Blueprint $table): void {
                $table->string('featured_youtube_id',11)->nullable()->after('featured_color');
            });
        }
    }

    public function down(): void
    {
        foreach(['marketplace_jobs','marketplace_listings'] as $tableName) {
            Schema::table($tableName,function(Blueprint $table): void {
                $table->dropColumn('featured_youtube_id');
            });
        }
    }
};
