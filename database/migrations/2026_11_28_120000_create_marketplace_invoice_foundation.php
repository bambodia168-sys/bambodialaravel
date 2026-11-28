<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('marketplace_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('issuer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->char('currency',3);
            $table->decimal('subtotal',12,2);
            $table->decimal('total',12,2);
            $table->string('status',16)->default('draft');
            $table->date('due_on')->nullable();
            $table->string('invoice_number',80)->nullable()->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->char('snapshot_digest',64);
            $table->timestamps();
            $table->index(['issuer_id','status','id']);
            $table->index(['recipient_id','status','id']);
            $table->index('issued_at');
        });
        Schema::create('marketplace_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('description',180);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount',12,2);
            $table->decimal('line_total',12,2);
            $table->unique(['marketplace_invoice_id','position'],'invoice_item_position_unique');
        });
        Schema::create('mcp_invoice_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('tool',64);
            $table->foreignUuid('resource_reference')->references('public_id')->on('marketplace_invoices')->restrictOnDelete();
            $table->char('payload_digest',64);
            $table->foreignId('audit_event_id')->constrained('audit_events')->restrictOnDelete();
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['actor_id','request_id'],'invoice_actor_request_unique');
        });
        // MySQL enforces the accounting invariants in addition to the service.
        // SQLite test fixtures exercise the same domain checks and FK/unique constraints.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE marketplace_invoices ADD CONSTRAINT invoice_amount_check CHECK (currency = 'USD' AND total > 0 AND subtotal = total AND revision >= 1)");
            DB::statement("ALTER TABLE marketplace_invoices ADD CONSTRAINT invoice_lifecycle_check CHECK ((status = 'draft' AND invoice_number IS NULL AND issued_at IS NULL AND voided_at IS NULL AND discarded_at IS NULL) OR (status = 'discarded' AND invoice_number IS NULL AND issued_at IS NULL AND voided_at IS NULL AND discarded_at IS NOT NULL) OR (status = 'issued' AND invoice_number IS NOT NULL AND issued_at IS NOT NULL AND voided_at IS NULL AND discarded_at IS NULL) OR (status = 'void' AND invoice_number IS NOT NULL AND issued_at IS NOT NULL AND voided_at IS NOT NULL AND discarded_at IS NULL))");
            DB::statement('ALTER TABLE marketplace_invoice_items ADD CONSTRAINT invoice_item_amount_check CHECK (position = 1 AND quantity = 1 AND unit_amount > 0 AND line_total = unit_amount)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve invoice and audit history; disable invoice features and roll back application code instead.');
    }
};
