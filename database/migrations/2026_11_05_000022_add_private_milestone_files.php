<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('milestone_submissions', function(Blueprint $table): void {
            $table->string('original_filename',180)->nullable();
            $table->string('storage_path',350)->nullable()->unique();
            $table->string('file_type',8)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('sha256',64)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('milestone_submissions',function(Blueprint $table): void {
            $table->dropUnique(['storage_path']);
            $table->dropColumn(['original_filename','storage_path','file_type','byte_size','sha256']);
        });
    }
};
