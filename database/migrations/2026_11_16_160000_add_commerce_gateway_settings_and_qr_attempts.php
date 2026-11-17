<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_gateway_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 24);
            $table->boolean('live_mode');
            $table->text('credentials')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'live_mode']);
        });
        foreach (['stripe', 'paypal', 'aba', 'bakong'] as $provider) {
            foreach ([false, true] as $live) {
                DB::table('commerce_gateway_settings')->insert(['provider' => $provider, 'live_mode' => $live]);
            }
        }

        Schema::table('commerce_payments', function (Blueprint $table): void {
            $table->text('qr_payload')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_payments', fn (Blueprint $table) => $table->dropColumn(['qr_payload', 'qr_expires_at']));
        Schema::dropIfExists('commerce_gateway_settings');
    }
};
