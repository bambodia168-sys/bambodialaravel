<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([false, true] as $live) {
            DB::table('commerce_gateway_settings')->insertOrIgnore([
                'provider' => 'baray',
                'live_mode' => $live,
                'credentials' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('commerce_payment_methods')->insertOrIgnore([
            'name' => 'Baray',
            'provider' => 'baray',
            'available' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Foreign keys prevent removing a Baray method that has recorded payments.
        // Leave payment and credential records untouched on rollback.
    }
};
