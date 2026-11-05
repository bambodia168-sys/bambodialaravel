<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saved_listings',function(Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id','marketplace_listing_id'],'saved_listing_account_unique');
            $table->index(['user_id','created_at'],'saved_listing_account_recent');
        });
    }

    public function down(): void { Schema::dropIfExists('saved_listings'); }
};
