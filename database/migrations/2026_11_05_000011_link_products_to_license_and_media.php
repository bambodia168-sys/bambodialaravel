<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->foreignId('license_definition_id')->nullable()
                ->constrained('license_definitions')->restrictOnDelete();
            $table->foreignId('cover_media_id')->nullable()
                ->constrained('media_assets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('license_definition_id');
            $table->dropConstrainedForeignId('cover_media_id');
        });
    }
};
