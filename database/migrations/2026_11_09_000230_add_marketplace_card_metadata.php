<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_listings', function(Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->index();
            $table->string('preview_url',500)->nullable();
        });
        Schema::table('marketplace_jobs', function(Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_jobs', fn(Blueprint $table) => $table->dropColumn('is_featured'));
        Schema::table('marketplace_listings', fn(Blueprint $table) => $table->dropColumn(['is_featured','preview_url']));
    }
};
