<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('extended_price', 12, 2)->nullable();
            $table->foreignId('extended_license_definition_id')->nullable()
                ->constrained('license_definitions')->restrictOnDelete();
            $table->json('gallery_media_ids')->nullable();
        });
        Schema::table('marketplace_jobs', function (Blueprint $table): void {
            $table->json('gallery_media_ids')->nullable();
        });
        Schema::create('builder_terms_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->char('terms_sha256', 64);
            $table->timestamp('accepted_at')->useCurrent();
            $table->index(['user_id', 'accepted_at'], 'builder_acceptance_user_history');
            $table->index(['content_type', 'content_id'], 'builder_acceptance_content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_terms_acceptances');
        Schema::table('marketplace_jobs', fn (Blueprint $table) => $table->dropColumn('gallery_media_ids'));
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('extended_license_definition_id');
            $table->dropColumn(['regular_price', 'extended_price', 'gallery_media_ids']);
        });
    }
};
