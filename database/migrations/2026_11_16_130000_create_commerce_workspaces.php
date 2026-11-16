<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commerce_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('available')->default(false);
            $table->timestamps();
        });

        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('number')->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('listing_title', 180);
            $table->string('buyer_name');
            $table->string('seller_name');
            $table->string('license_tier', 16)->nullable();
            $table->string('license_name')->nullable();
            $table->decimal('total', 12, 2);
            $table->char('currency', 3);
            $table->string('payment_method', 80)->nullable();
            $table->string('status', 20);
            $table->uuid('receipt_number')->nullable()->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['buyer_id', 'request_key']);
            $table->index(['buyer_id', 'id']);
            $table->index(['seller_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('commerce_payment_methods');
    }
};
