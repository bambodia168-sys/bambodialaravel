<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('event', 100)->unique();
            $table->unsignedSmallInteger('threshold');
            $table->unsignedSmallInteger('window_minutes');
            $table->unsignedSmallInteger('cooldown_minutes');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('security_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('security_alert_rule_id')->nullable()
                ->constrained('security_alert_rules')->nullOnDelete();
            $table->string('rule_name', 100);
            $table->string('event', 100);
            $table->unsignedInteger('observed_count');
            $table->timestamp('observed_from');
            $table->timestamp('observed_until');
            $table->foreignId('acknowledged_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['acknowledged_at', 'created_at'], 'security_alert_open_idx');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['event','created_at'], 'audit_event_security_scan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_event_security_scan_idx');
        });
        Schema::dropIfExists('security_alerts');
        Schema::dropIfExists('security_alert_rules');
    }
};
