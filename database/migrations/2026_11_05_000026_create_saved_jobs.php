<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saved_jobs',function(Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('marketplace_job_id')->constrained('marketplace_jobs')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id','marketplace_job_id'],'saved_job_account_unique');
            $table->index(['user_id','created_at'],'saved_job_account_recent');
        });
    }
    public function down(): void { Schema::dropIfExists('saved_jobs'); }
};
