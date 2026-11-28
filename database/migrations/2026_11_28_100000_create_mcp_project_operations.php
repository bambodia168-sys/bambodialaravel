<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mcp_project_operations',function(Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('tool',64);
            $table->char('payload_digest',64);
            $table->string('resource_reference',10);
            $table->foreignId('audit_event_id')->constrained('audit_events')->restrictOnDelete();
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['actor_id','request_id'],'mcp_project_actor_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_project_operations');
    }
};
