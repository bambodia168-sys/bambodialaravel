<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_entitlement_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_entitlement_id')->constrained('product_entitlements')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('reason', 500);
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_entitlement_id', 'created_at'], 'entitlement_action_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_entitlement_actions');
    }
};
