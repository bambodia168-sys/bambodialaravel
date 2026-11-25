<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_tracking_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->string('status', 24)->default('planned');
            $table->date('due_on')->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_order_id', 'position'], 'order_task_unique_position');
            $table->index(['marketplace_order_id', 'status']);
        });
        Schema::create('order_tracking_extension_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->date('proposed_due_on');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['marketplace_order_id','status'], 'order_tracking_extension_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking_extension_requests');
        Schema::dropIfExists('order_tracking_tasks');
    }
};
