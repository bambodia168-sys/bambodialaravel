<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_payment_methods', function (Blueprint $table): void {
            $table->string('provider', 24)->default('other');
            $table->text('instructions')->nullable();
            $table->string('qr_path')->nullable();
        });

        Schema::create('commerce_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('number')->unique();
            $table->foreignId('marketplace_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('commerce_payment_method_id')->constrained()->restrictOnDelete();
            $table->string('provider', 24);
            $table->string('method_name', 80);
            $table->boolean('live_mode')->default(false);
            $table->string('status', 24);
            $table->string('remote_id')->nullable();
            $table->string('settlement_reference')->nullable();
            $table->string('submitted_reference', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('review_note', 300)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'live_mode', 'remote_id'], 'commerce_payments_remote_unique');
            $table->unique(['live_mode', 'settlement_reference'], 'commerce_payments_settlement_unique');
            $table->index(['marketplace_order_id', 'id'], 'commerce_payments_order_history');
            $table->index(['status', 'id']);
        });

        foreach (['Stripe'=>'stripe', 'PayPal'=>'paypal', 'Bank Transfer'=>'bank_transfer', 'KHQR'=>'khqr',
            'ABA'=>'aba', 'Bakong'=>'bakong', 'Wing'=>'wing'] as $name => $provider) {
            DB::table('commerce_payment_methods')->insertOrIgnore([
                'name' => $name, 'provider' => $provider, 'available' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('commerce_payment_methods')->where('name', $name)->where('provider', 'other')
                ->update(['provider' => $provider, 'available' => false]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payments');
        Schema::table('commerce_payment_methods', fn (Blueprint $table) => $table->dropColumn(['provider', 'instructions', 'qr_path']));
    }
};
