<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('freelancer_resumes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('headline', 180);
            $table->text('summary');
            $table->text('experience');
            $table->text('skills');
            $table->string('portfolio_url', 400)->nullable();
            $table->boolean('is_visible')->default(false);
            $table->timestamps();
        });
        Schema::create('marketplace_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->string('slug', 200);
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('description');
            $table->string('category', 60);
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });
        Schema::create('job_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_job_id')->constrained('marketplace_jobs')->restrictOnDelete();
            $table->foreignId('freelancer_id')->constrained('users')->restrictOnDelete();
            $table->text('cover_message');
            $table->decimal('proposed_budget', 12, 2)->nullable();
            $table->string('status', 20)->default('submitted');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_job_id', 'freelancer_id'], 'job_proposal_unique_applicant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_proposals');
        Schema::dropIfExists('marketplace_jobs');
        Schema::dropIfExists('freelancer_resumes');
    }
};
