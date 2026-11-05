<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('privacy_requests',function(Blueprint $table): void {
            $table->id();
            $table->string('public_id',9)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('category',30);
            $table->text('details');
            $table->string('status',24)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_note')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id','created_at'],'privacy_request_user_date_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('privacy_requests'); }
};
