<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authenticatable_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('credential_id', 64)->unique();
            $table->json('data');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('social_login_providers', function (Blueprint $table): void {
            $table->string('provider', 16)->primary();
            $table->string('client_id');
            $table->text('client_secret');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('social_login_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->char('subject_hash', 64);
            $table->unique(['provider', 'subject_hash']);
            $table->unique(['user_id', 'provider']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_login_identities');
        Schema::dropIfExists('social_login_providers');
        Schema::dropIfExists('passkeys');
    }
};
