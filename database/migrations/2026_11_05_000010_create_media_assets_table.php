<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 9)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_name', 180);
            $table->string('storage_path', 300)->unique();
            $table->string('mime_type', 30);
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt_text', 180);
            $table->string('rights_basis', 20);
            $table->string('rights_holder', 180);
            $table->text('rights_note')->nullable();
            $table->string('review_status', 20)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
