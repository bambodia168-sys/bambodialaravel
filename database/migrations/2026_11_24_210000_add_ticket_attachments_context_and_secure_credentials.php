<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('issue_type', 40)->default('general')->index();
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('support_message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path', 255)->unique();
            $table->string('original_name', 180);
            $table->string('mime_type', 90);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 12)->default('clean');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['support_ticket_id', 'created_at'], 'ticket_attachment_thread_index');
        });

        Schema::create('support_ticket_credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 120);
            $table->longText('ciphertext')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();
            $table->index(['support_ticket_id', 'expires_at'], 'ticket_credential_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_credentials');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn('issue_type');
        });
    }
};
