<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_two_factors', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->text('recovery_hashes');
            $table->unsignedBigInteger('last_counter')->nullable();
            $table->timestamp('enabled_at');
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('user_two_factors'); }
};
