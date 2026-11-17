<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('marketplace_order_reviews')->exists()) {
            throw new RuntimeException('Preserve marketplace reviews when rolling back application code.');
        }

        Schema::dropIfExists('marketplace_order_reviews');
    }
};
