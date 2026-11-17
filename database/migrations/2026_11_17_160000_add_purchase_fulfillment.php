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

        Schema::table('service_inquiries', function (Blueprint $table): void {
            $table->foreignId('marketplace_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('product_entitlements')->whereNotNull('marketplace_order_id')->exists()
            || DB::table('marketplace_orders')->whereNotNull('fulfillment_version_id')->exists()
            || DB::table('service_inquiries')->whereNotNull('marketplace_order_id')->exists()) {
            throw new RuntimeException('Preserve purchase records when rolling back application code.');
        }

        foreach (['service_inquiries', 'product_entitlements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['marketplace_order_id']);
                $table->dropUnique(['marketplace_order_id']);
                $table->dropColumn('marketplace_order_id');
            });
        }
        Schema::table('product_entitlements', function (Blueprint $table): void {
            $table->unique(['user_id', 'product_file_version_id']);
            $table->dropUnique('entitlements_manual_unique');
            $table->dropColumn('manual_slot');
        });
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_version_id');
            $table->dropConstrainedForeignId('fulfillment_definition_id');
            $table->dropColumn('fulfillment_terms_hash');
        });
    }
};
