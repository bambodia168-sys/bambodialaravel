<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table): void {
            $table->string('software_version', 48)->nullable();
            $table->date('software_published_on')->nullable();
            $table->date('software_updated_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', fn (Blueprint $table) =>
            $table->dropColumn(['software_version', 'software_published_on', 'software_updated_on'])
        );
    }
};
