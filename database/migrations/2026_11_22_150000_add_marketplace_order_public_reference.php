<?php

use App\Support\OrderReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketplace_orders', 'public_reference')) {
            Schema::table('marketplace_orders', function (Blueprint $table): void {
                $table->char('public_reference', 10)->nullable();
            });
        }

        if (! Schema::hasIndex('marketplace_orders', OrderReference::INDEX)) {
            Schema::table('marketplace_orders', function (Blueprint $table): void {
                $table->unique('public_reference', OrderReference::INDEX);
            });
        }

        DB::table('marketplace_orders')->select('id')->whereNull('public_reference')->chunkById(500, function ($orders): void {
            foreach ($orders as $order) {
                OrderReference::assign(fn (string $reference) => DB::table('marketplace_orders')
                    ->where('id', $order->id)->whereNull('public_reference')->update(['public_reference' => $reference]));
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropUnique(OrderReference::INDEX);
            $table->dropColumn('public_reference');
        });
    }
};
