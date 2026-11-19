<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commerce_order_operations')) {
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
        }

        Schema::table('commerce_order_operations', function (Blueprint $table): void {
            $table->string('refund_access', 6)->nullable();
            $table->foreignId('refund_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Keep confirmed refund and order access history when rolling back application code.');
    }
};
