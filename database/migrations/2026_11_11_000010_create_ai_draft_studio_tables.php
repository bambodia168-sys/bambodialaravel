<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_draft_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('openai');
            $table->string('model', 64)->default('gpt-4.1-mini');
            $table->text('api_key')->nullable();
            $table->unsignedTinyInteger('daily_limit')->default(5);
            $table->unsignedSmallInteger('global_daily_limit')->default(100);
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_draft_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('content_type', 24);
            $table->string('model', 64);
            $table->string('status', 16)->default('reserved');
            $table->unsignedSmallInteger('input_chars')->default(0);
            $table->unsignedSmallInteger('output_chars')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id','created_at'], 'ai_requests_user_day_idx');
            $table->index(['created_at','status'], 'ai_requests_day_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_draft_requests');
        Schema::dropIfExists('ai_draft_providers');
    }
};
