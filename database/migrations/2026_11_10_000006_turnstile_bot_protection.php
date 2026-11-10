<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('turnstile_bot_protections', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('site_key', 128);
            $table->text('secret_key'); // Always encrypted by the Eloquent model.
            $table->boolean('is_enabled')->default(false);
            $table->boolean('protect_login')->default(true);
            $table->boolean('protect_registration')->default(true);
            $table->boolean('protect_password_reset')->default(true);
            $table->timestamp('tested_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnstile_bot_protections');
    }
};
