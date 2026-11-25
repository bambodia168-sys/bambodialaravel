<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_studio_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->decimal('monthly_goal_usd', 12, 2)->nullable();
            $table->decimal('weekly_capacity_hours', 5, 1)->nullable();
            $table->timestamps();
        });
        Schema::create('creator_studio_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('logged_on');
            $table->decimal('hours', 5, 2);
            $table->boolean('is_billable');
            $table->string('work_type', 16);
            $table->string('description', 240)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'logged_on', 'work_type'], 'creator_time_owner_date_type');
        });
        Schema::create('creator_studio_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('spent_on');
            $table->decimal('amount_usd', 12, 2);
            $table->string('work_type', 16);
            $table->string('category', 40);
            $table->string('description', 240)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'spent_on', 'work_type'], 'creator_expense_owner_date_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_studio_expenses');
        Schema::dropIfExists('creator_studio_time_entries');
        Schema::dropIfExists('creator_studio_preferences');
    }
};
