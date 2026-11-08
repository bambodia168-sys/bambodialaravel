<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('detail_support_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20)->default('documentation');
            $table->string('title', 120);
            $table->string('description', 300)->nullable();
            $table->string('url', 2048);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['subject_type','subject_id','is_enabled','sort_order'], 'detail_resource_public_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detail_support_resources');
    }
};
