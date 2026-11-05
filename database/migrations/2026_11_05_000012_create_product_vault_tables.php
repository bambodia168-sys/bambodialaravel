<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_file_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('version', 32);
            $table->string('release_notes', 3000);
            $table->string('original_filename', 180);
            $table->string('storage_path', 350)->unique();
            $table->string('file_type', 8);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('rights_attested_at');
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_listing_id', 'version']);
            $table->index(['marketplace_listing_id', 'status']);
        });

        Schema::create('product_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_file_version_id')->constrained('product_file_versions')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('grant_reason', 500);
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_file_version_id']);
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('product_download_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_entitlement_id')->constrained('product_entitlements')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_file_version_id')->constrained('product_file_versions')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_download_events');
        Schema::dropIfExists('product_entitlements');
        Schema::dropIfExists('product_file_versions');
    }
};
