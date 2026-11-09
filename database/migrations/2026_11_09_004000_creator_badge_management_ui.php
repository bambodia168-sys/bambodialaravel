<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_badge_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('display_mode', 20)->default('badge_name');
            $table->string('badge_size', 12)->default('standard');
            $table->boolean('tooltips_enabled')->default(true);
            $table->timestamps();
        });
        DB::table('creator_badge_settings')->insert([
            'id'=>1, 'display_mode'=>'badge_name','badge_size'=>'standard',
            'tooltips_enabled'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        Schema::create('creator_badge_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creator_badge_id')->nullable()->constrained('creator_badges')->nullOnDelete();
            $table->string('action', 32);
            $table->string('scope', 24)->default('single');
            $table->unsignedInteger('affected_count')->default(0);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id','created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('creator_badge_actions');
        Schema::dropIfExists('creator_badge_settings');
    }
};