<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('detail_comments', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('detail_comments')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'parent_id', 'created_at'], 'detail_comment_subject_index');
        });

        // Existing support tickets remain valid: context is optional, and only
        // detail-page tickets receive a resource link.
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('context_type', 12)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->index(['user_id', 'context_type', 'context_id'], 'support_ticket_context_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex('support_ticket_context_index');
            $table->dropColumn(['context_type', 'context_id']);
        });
        Schema::dropIfExists('detail_comments');
    }
};
