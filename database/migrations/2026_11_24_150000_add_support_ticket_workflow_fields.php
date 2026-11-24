<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->index(['assigned_to', 'status', 'updated_at'], 'support_ticket_assignment_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex('support_ticket_assignment_index');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropSoftDeletes();
        });
    }
};
