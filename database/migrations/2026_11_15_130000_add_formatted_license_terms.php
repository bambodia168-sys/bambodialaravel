<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('license_definitions', function (Blueprint $table): void {
            $table->longText('permissions_html')->nullable();
            $table->longText('restrictions_html')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('license_definitions', function (Blueprint $table): void {
            $table->dropColumn(['permissions_html', 'restrictions_html']);
        });
    }
};
