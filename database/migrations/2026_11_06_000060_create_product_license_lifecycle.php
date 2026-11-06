<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_licenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // An issued key cannot exist without an actual approved file entitlement.
            $table->foreignId('product_entitlement_id')->unique()->constrained('product_entitlements')->restrictOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $table->foreignId('product_file_version_id')->constrained('product_file_versions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('license_definition_id')->constrained('license_definitions')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tier', 12);
            $table->string('source', 28); // 'admin_complimentary'; payment integration intentionally absent.
            $table->char('key_hash', 64)->unique();
            $table->string('key_prefix', 14);
            $table->text('key_ciphertext');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedTinyInteger('max_domains')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id','status']);
            $table->index(['marketplace_listing_id','status']);
        });
        Schema::create('product_license_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_license_id')->constrained('product_licenses')->restrictOnDelete();
            $table->string('domain', 253);
            $table->string('status', 20)->default('active');
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
            $table->unique(['product_license_id','domain']);
            $table->index(['product_license_id','status']);
        });
        Schema::create('product_license_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_license_id')->constrained('product_licenses')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->string('previous_domain', 253)->nullable();
            $table->string('domain', 253)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_license_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_license_events');
        Schema::dropIfExists('product_license_activations');
        Schema::dropIfExists('product_licenses');
    }
};
