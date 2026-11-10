<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_profile_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('section_key', 24);
            $table->string('title', 100);
            $table->string('description', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::table('creator_profile_items', function (Blueprint $table): void {
            $table->foreignId('profile_section_id')->nullable()
                ->constrained('creator_profile_sections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creator_profile_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('profile_section_id'));
        Schema::dropIfExists('creator_profile_sections');
    }
};
