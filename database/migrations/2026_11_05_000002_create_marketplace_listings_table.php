<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24)->index();
            $table->string('title', 180);
            $table->string('slug', 200);
            $table->text('description');
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->longText('license_terms')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
            $table->index(['status', 'type', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
    }
};
