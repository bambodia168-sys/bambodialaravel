<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('preview_visibility_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_kind', 12);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_public_id', 9);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 12); // withdrawn or restored
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->char('review_fingerprint', 64);
            $table->string('owner_confirmation_reference', 250);
            $table->string('backup_reference', 350);
            $table->string('rights_review_reference', 250)->nullable();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_kind', 'subject_id', 'id'], 'preview_visibility_subject_history');
            $table->index(['actor_id', 'created_at'], 'preview_visibility_actor_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_visibility_actions');
    }
};
