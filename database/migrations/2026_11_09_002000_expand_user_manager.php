<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username',60)->nullable()->unique();
            $table->string('account_status',16)->default('active')->index();
        });
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality',80)->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });
        Schema::create('user_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission',50);
            $table->boolean('allowed')->default(true);
            $table->timestamps();
            $table->unique(['user_id','permission']);
        });
        Schema::create('user_management_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action',32);
            $table->text('changed_fields')->nullable();
            $table->string('reason',500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id','created_at']);
        });
        Schema::create('user_ownership_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->string('resource_type',30);
            $table->unsignedBigInteger('resource_id');
            $table->string('resource_public_id',24);
            $table->string('resource_title',180);
            $table->string('reason',500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['from_user_id','created_at']);
            $table->index(['to_user_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ownership_transfers');
        Schema::dropIfExists('user_management_events');
        Schema::dropIfExists('user_permission_overrides');
        Schema::dropIfExists('user_profiles');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_username_unique');
            $table->dropColumn(['username','account_status']);
        });
    }
};