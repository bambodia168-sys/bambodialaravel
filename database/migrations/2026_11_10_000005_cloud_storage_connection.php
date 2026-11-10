<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cloud_storage_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('provider', 24)->default('cloudflare_r2');
            $table->string('endpoint', 255);
            $table->string('bucket', 90);
            $table->text('access_key_id'); // Encrypted by Eloquent's encrypted cast.
            $table->text('secret_access_key'); // Encrypted by Eloquent's encrypted cast.
            $table->string('upload_mode', 12)->default('inherit');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('cloud_storage_connections'); }
};
