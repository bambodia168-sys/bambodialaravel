<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('commerce_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('refund_status', 12)->nullable()->index();
            $table->string('refund_reason', 500)->nullable();
            $table->string('refund_note', 500)->nullable();
            $table->timestamp('refund_requested_at')->nullable();
            $table->timestamp('refund_resolved_at')->nullable();
            $table->string('refund_reference', 120)->nullable()->unique();
            $table->string('statement_reference', 120)->nullable()->unique();
            $table->decimal('seller_payable', 12, 2)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('payout_reference', 120)->nullable()->unique();
            $table->timestamp('paid_out_at')->nullable();
            $table->string('recovery_reference', 120)->nullable()->unique();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commerce_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_order_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 28);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('commerce_order_operations')->exists()) {
            throw new RuntimeException('Keep refund, reconciliation and settlement history when rolling back application code.');
        }

        Schema::dropIfExists('commerce_operation_events');
        Schema::dropIfExists('commerce_order_operations');
    }
};
