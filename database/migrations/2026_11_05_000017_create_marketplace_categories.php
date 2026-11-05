<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id',9)->unique();
            $table->string('type',24)->index();
            $table->string('name',120);
            $table->string('slug',150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['type','slug'],'category_unique_type_slug');
        });
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()
                ->constrained('marketplace_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings',fn(Blueprint $table)=>$table->dropConstrainedForeignId('category_id'));
        Schema::dropIfExists('marketplace_categories');
    }
};
