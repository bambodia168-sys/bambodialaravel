<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->string('event', 100)->primary();
            $table->boolean('enabled');
            $table->string('subject', 180)->nullable();
            $table->string('heading', 180)->nullable();
            $table->text('body')->nullable();
            $table->string('button', 90)->nullable();
            $table->timestamps();
        });

        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 100);
            $table->string('title', 180);
            $table->text('message');
            $table->string('target_path', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_user_id')->default(0);
            $table->unsignedBigInteger('audience_max_id')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('expanded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 100);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->char('deduplication_key', 64)->unique();
            $table->string('status', 20)->default('queued');
            $table->json('context');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('marketing_subscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('marketing_subscribed_at'));
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_templates');
    }
};
