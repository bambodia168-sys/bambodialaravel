<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('freelance_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->foreignId('job_proposal_id')->unique()->constrained('job_proposals')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('freelancer_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->longText('scope');
            $table->longText('terms');
            $table->char('currency', 3)->default('USD');
            $table->decimal('agreed_budget', 12, 2);
            $table->char('terms_sha256', 64)->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index(['freelancer_id', 'status']);
        });
        Schema::create('contract_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('freelance_contract_id')->constrained('freelance_contracts')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title', 180);
            $table->text('description');
            $table->decimal('amount', 12, 2);
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('planned');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['freelance_contract_id', 'sequence'], 'contract_milestone_sequence_unique');
        });
        Schema::create('contract_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('freelance_contract_id')->constrained('freelance_contracts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->char('terms_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['freelance_contract_id', 'created_at'], 'contract_consent_history_idx');
        });
        Schema::create('milestone_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_milestone_id')->constrained('contract_milestones')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->longText('delivery_notes');
            $table->string('evidence_url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contract_milestone_id', 'created_at'], 'milestone_submission_history_idx');
        });
        Schema::create('milestone_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('milestone_submission_id')->unique()->constrained('milestone_submissions')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 24);
            $table->text('feedback');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('contract_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('freelance_contract_id')->constrained('freelance_contracts')->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['freelance_contract_id', 'created_at'], 'contract_messages_history_idx');
        });
        Schema::create('contract_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('freelance_contract_id')->unique()->constrained('freelance_contracts')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('category', 30);
            $table->text('details');
            $table->string('status', 24)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->string('disposition', 20)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('contract_disputes');
        Schema::dropIfExists('contract_messages');
        Schema::dropIfExists('milestone_reviews');
        Schema::dropIfExists('milestone_submissions');
        Schema::dropIfExists('contract_consents');
        Schema::dropIfExists('contract_milestones');
        Schema::dropIfExists('freelance_contracts');
    }
};
