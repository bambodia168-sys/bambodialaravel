<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_inquiry_messages', function (Blueprint $table): void {
            $table->string('content_format', 10)->default('plain');
        });

        Schema::create('tracking_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('service_inquiry_message_id')->constrained('service_inquiry_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_path', 260);
            $table->string('original_name', 180);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20);
            $table->timestamps();
            $table->index(['marketplace_order_id', 'service_inquiry_message_id'], 'tracking_attachment_order_message');
        });

        Schema::create('tracking_one_time_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('label', 100);
            $table->text('ciphertext');
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['marketplace_order_id', 'recipient_id', 'expires_at'], 'tracking_one_time_recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_one_time_notes');
        Schema::dropIfExists('tracking_message_attachments');
        Schema::table('service_inquiry_messages', function (Blueprint $table): void {
            $table->dropColumn('content_format');
        });
    }
};
