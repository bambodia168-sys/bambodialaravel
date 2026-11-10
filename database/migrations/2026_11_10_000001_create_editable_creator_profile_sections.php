<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('avatar_path', 255)->nullable();
            $table->string('public_location', 120)->nullable();
            $table->string('availability', 24)->nullable();
        });

        Schema::create('creator_profile_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label', 100);
            $table->string('detail', 160)->nullable();
            $table->string('url', 400)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'kind', 'is_visible', 'sort_order'], 'creator_profile_items_listing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_profile_items');
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'public_location', 'availability']);
        });
    }
};
