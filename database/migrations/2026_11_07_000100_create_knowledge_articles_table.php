<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('public_code', 8)->unique();
            $table->string('slug', 200);
            $table->string('title', 180);
            $table->string('excerpt', 320);
            $table->string('category', 40)->index();
            $table->longText('body');
            $table->string('body_format', 12)->default('html');
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status','category','published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
    }
};
