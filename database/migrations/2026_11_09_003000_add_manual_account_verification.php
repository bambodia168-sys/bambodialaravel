<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_review_status', 16)->default('unreviewed')->index();
            $table->timestamp('account_verified_at')->nullable();
            $table->foreignId('account_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_review_method', 32)->nullable();
            $table->text('account_review_note')->nullable();
        });

        Schema::create('user_account_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 16);
            $table->string('method', 32)->nullable();
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_review_events');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['account_verified_by']);
            $table->dropColumn([
                'account_review_status', 'account_verified_at',
                'account_verified_by', 'account_review_method', 'account_review_note',
            ]);
        });
    }
};