<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->string('featured_type', 12)->default('none');
            $table->string('featured_url', 255)->nullable();
            $table->string('featured_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->dropColumn(['featured_type', 'featured_url', 'featured_color']);
        });
    }
};
