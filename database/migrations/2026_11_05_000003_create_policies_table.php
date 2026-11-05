<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->string('title', 180);
            $table->string('slug', 200);
            $table->string('category', 40)->default('general');
            $table->string('jurisdiction', 120)->nullable();
            $table->longText('body');
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
