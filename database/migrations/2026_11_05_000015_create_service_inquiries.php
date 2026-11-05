<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 180);
            $table->decimal('proposed_budget', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'updated_at']);
            $table->index(['creator_id', 'updated_at']);
        });
        Schema::create('service_inquiry_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_inquiry_id')->constrained('service_inquiries')->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['service_inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiry_messages');
        Schema::dropIfExists('service_inquiries');
    }
};
