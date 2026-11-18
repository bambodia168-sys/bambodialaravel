<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_two_factors', function (Blueprint $table): void {
            $table->uuid('authenticator_key')->nullable();
            $table->string('authenticator_name', 80)->default('Authenticator');
        });

        Schema::create('user_two_factor_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('user_two_factors', 'user_id')->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('secret');
            $table->unsignedBigInteger('last_counter')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_two_factor_devices');
        Schema::table('user_two_factors', function (Blueprint $table): void {
            $table->dropColumn(['authenticator_key', 'authenticator_name']);
        });
    }
};
