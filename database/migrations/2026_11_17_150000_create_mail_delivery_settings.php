<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_delivery_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('draft')->nullable();
            $table->uuid('draft_revision')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->text('active')->nullable();
            $table->uuid('active_revision')->nullable();
        });

        DB::table('mail_delivery_settings')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_delivery_settings');
    }
};
