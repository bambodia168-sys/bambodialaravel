<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_brand_assets', function (Blueprint $table): void {
            $table->string('slot', 32)->primary();
            $table->string('storage_path', 255);
            $table->string('mime_type', 60);
            $table->unsignedInteger('bytes');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('site_seo_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 180);
            $table->string('locale', 8)->default('en');
            $table->string('title', 180);
            $table->string('description', 320);
            $table->string('keywords', 400)->nullable();
            $table->string('canonical_url', 400)->nullable();
            $table->string('og_image_url', 400)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['path', 'locale']);
        });

        Schema::create('site_social_links', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 24);
            $table->string('label', 100);
            $table->string('url', 400);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        // Keep the original public footer links when introducing database-backed CRUD.
        DB::table('site_social_links')->insert([
            [
                'platform' => 'website', 'label' => 'Author website', 'url' => 'https://tonsamith.com',
                'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'platform' => 'github', 'label' => 'Bambodia GitHub repository',
                'url' => 'https://github.com/bambodia168-sys/bambodialaravel',
                'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_social_links');
        Schema::dropIfExists('site_seo_pages');
        Schema::dropIfExists('site_brand_assets');
    }
};
