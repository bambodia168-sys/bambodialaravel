<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('license_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('slug', 150);
            $table->string('version', 30);
            $table->longText('permissions');
            $table->longText('restrictions');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_definitions');
    }
};
