<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->string('body_format', 12)->default('plain');
            $table->string('excerpt', 320)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('tags', 320)->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->foreignId('featured_media_id')->nullable()
                ->constrained('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('featured_media_id');
            $table->dropColumn([
                'body_format', 'excerpt', 'category', 'tags',
                'seo_title', 'seo_description',
            ]);
        });
    }
};
