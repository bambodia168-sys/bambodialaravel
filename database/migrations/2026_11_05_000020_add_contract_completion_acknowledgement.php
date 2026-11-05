<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('freelance_contracts', function(Blueprint $table): void {
            $table->timestamp('freelancer_completed_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('freelance_contracts',fn(Blueprint $table)=>$table->dropColumn('freelancer_completed_at'));
    }
};
