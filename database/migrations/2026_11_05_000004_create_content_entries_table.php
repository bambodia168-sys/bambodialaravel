<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10)->index();
            $table->string('title', 180);
            $table->string('slug', 200);
            $table->longText('body');
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entries');
    }
};
