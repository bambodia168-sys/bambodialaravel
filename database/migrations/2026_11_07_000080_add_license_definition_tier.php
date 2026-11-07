<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_definitions', function (Blueprint $table): void {
            // Keep existing definitions unclassified rather than inventing or
            // changing historic customer rights.
            $table->string('tier', 16)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('license_definitions', fn (Blueprint $table) => $table->dropColumn('tier'));
    }
};
