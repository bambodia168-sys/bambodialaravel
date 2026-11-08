<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->string('featured_type', 12)->default('none');
            $table->string('featured_url', 255)->nullable();
            $table->string('featured_color', 7)->nullable();
        });
        // Preserve featured images on posts created before this upgrade.
        DB::table('content_entries')->where('type', 'post')->whereNotNull('featured_media_id')
            ->update(['featured_type' => 'image']);
    }

    public function down(): void
    {
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->dropColumn(['featured_type', 'featured_url', 'featured_color']);
        });
    }
};
