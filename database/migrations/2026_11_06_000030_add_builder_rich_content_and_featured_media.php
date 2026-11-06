<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['marketplace_listings', 'marketplace_jobs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->longText('description_html')->nullable();
                $table->string('featured_kind', 16)->default('none');
                $table->foreignId('featured_media_id')->nullable()
                    ->constrained('media_assets')->restrictOnDelete();
                $table->string('featured_color', 7)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['marketplace_jobs', 'marketplace_listings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('featured_media_id');
                $table->dropColumn(['featured_color', 'featured_kind', 'description_html']);
            });
        }
    }
};
