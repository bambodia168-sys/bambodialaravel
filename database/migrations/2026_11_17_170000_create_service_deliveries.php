<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_inquiries', function (Blueprint $table): void {
            $table->string('delivery_status', 24)->nullable();
        });

        Schema::create('service_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_inquiry_id')->constrained()->restrictOnDelete();
            $table->text('notes');
            $table->string('original_filename', 180)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('file_type', 8)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('decision', 20)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('service_deliveries')->exists()
            || DB::table('service_inquiries')->whereNotNull('delivery_status')->exists()) {
            throw new RuntimeException('Preserve service delivery records when rolling back application code.');
        }

        Schema::dropIfExists('service_deliveries');
        Schema::table('service_inquiries', fn (Blueprint $table) => $table->dropColumn('delivery_status'));
    }
};
