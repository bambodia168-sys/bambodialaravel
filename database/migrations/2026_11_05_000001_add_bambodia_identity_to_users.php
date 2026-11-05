<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('public_id', 8)->unique()->after('id');
            $table->string('role', 24)->default('customer')->index()->after('password');
            $table->string('locale', 10)->default('en')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['public_id', 'role', 'locale']);
        });
    }
};
