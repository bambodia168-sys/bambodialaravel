<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('policy_publications', function(Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('title',180);
            $table->string('category',40);
            $table->string('jurisdiction',120)->nullable();
            $table->longText('body_snapshot');
            $table->char('sha256',64);
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['policy_id','revision'], 'policy_revision_unique');
        });
        Schema::create('policy_acknowledgements', function(Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_publication_id')->constrained('policy_publications')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('sha256',64);
            $table->string('record_type',40)->default('read_acknowledgement');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['policy_publication_id','user_id'],'policy_receipt_once_per_revision');
            $table->index(['user_id','created_at'],'policy_receipt_account_history');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('policy_publications');
    }
};
