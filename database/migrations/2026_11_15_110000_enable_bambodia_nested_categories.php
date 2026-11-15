<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older deployments may have defined the four listing types as an ENUM.
        // Expand to a string so the new Job type can share the same category table.
        Schema::table('marketplace_categories', function (Blueprint $table): void {
            $table->string('type', 32)->change();
        });
        // Preserve all six legacy Job codes while allowing the new category slugs.
        // The original Job schema may be an ENUM or a shorter VARCHAR.
        Schema::table('marketplace_jobs', function (Blueprint $table): void {
            $table->string('category', 64)->change();
        });
        Schema::table('marketplace_categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('marketplace_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Do not narrow type back to four values: that could destroy real Job rows.
        Schema::table('marketplace_categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
