<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->foreignId('fulfillment_version_id')->nullable()->constrained('product_file_versions')->restrictOnDelete();
            $table->foreignId('fulfillment_definition_id')->nullable()->constrained('license_definitions')->restrictOnDelete();
            $table->char('fulfillment_terms_hash', 64)->nullable();
        });

        Schema::table('product_entitlements', function (Blueprint $table): void {
            $table->foreignId('marketplace_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('manual_slot')->nullable()->default(1);
            $table->unique(['user_id', 'product_file_version_id', 'manual_slot'], 'entitlements_manual_unique');
        });
        Schema::table('product_entitlements', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'product_file_version_id']);
        });

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
        if (DB::table('commerce_order_operations')->exists()
            || DB::table('marketplace_orders')->whereNotNull('fulfillment_version_id')->exists()) {
            throw new RuntimeException('Keep the commerce operations and purchase history when rolling back application code.');
        }

        Schema::dropIfExists('commerce_operation_events');
        Schema::dropIfExists('commerce_order_operations');
        Schema::table('product_entitlements', function (Blueprint $table): void {
            $table->unique(['user_id', 'product_file_version_id']);
            $table->dropUnique('entitlements_manual_unique');
            $table->dropForeign(['marketplace_order_id']);
            $table->dropUnique(['marketplace_order_id']);
            $table->dropColumn(['marketplace_order_id', 'manual_slot']);
        });
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_version_id');
            $table->dropConstrainedForeignId('fulfillment_definition_id');
            $table->dropColumn('fulfillment_terms_hash');
        });
    }
};
