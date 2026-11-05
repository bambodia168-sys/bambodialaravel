<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('title', 180);
            $table->string('message', 500);
            $table->string('target_path', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id','read_at','created_at'], 'account_notify_inbox_idx');
            $table->index(['event','created_at'], 'account_notify_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_notifications');
    }
};
