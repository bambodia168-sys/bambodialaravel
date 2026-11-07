<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            // Preserve the existing nine-digit public_id and all historical URLs.
            $table->string('public_code', 8)->nullable()->unique();
            $table->string('version', 24)->default('1.0.0');
            $table->string('body_format', 12)->default('markdown');
            $table->string('pic_email', 180)->default('legal@bambodia.com');
            $table->timestamp('published_at')->nullable();
        });

        // Backfill independently from the old public_id. Never mutate historic identifiers.
        foreach (DB::table('policies')->orderBy('id')->get(['id']) as $policy) {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $code = (string) random_int(10000000, 99999999);
                if (! DB::table('policies')->where('public_code', $code)->exists()) {
                    DB::table('policies')->where('id', $policy->id)->update(['public_code' => $code]);
                    break;
                }
            }
            if (! DB::table('policies')->where('id', $policy->id)->whereNotNull('public_code')->exists()) {
                throw new RuntimeException('Unable to backfill unique eight-digit policy reference.');
            }
        }

        Schema::table('policy_publications', function (Blueprint $table): void {
            $table->string('version', 24)->default('1.0.0');
            $table->string('body_format', 12)->default('markdown');
        });

        Schema::create('policy_update_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('policy_publication_id')->unique()->constrained('policy_publications')->cascadeOnDelete();
            $table->string('headline', 180);
            $table->string('message', 500);
            $table->boolean('notify_account')->default(false);
            $table->boolean('notify_email')->default(false);
            $table->boolean('show_banner')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('policy_notice_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_update_notice_id')->constrained('policy_update_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('account_sent_at')->nullable();
            $table->timestamp('email_queued_at')->nullable();
            $table->timestamps();
            $table->unique(['policy_update_notice_id', 'user_id'], 'policy_notice_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_notice_recipients');
        Schema::dropIfExists('policy_update_notices');
        Schema::table('policy_publications', fn (Blueprint $table) => $table->dropColumn(['version', 'body_format']));
        Schema::table('policies', fn (Blueprint $table) => $table->dropColumn([
            'public_code', 'version', 'body_format', 'pic_email', 'published_at',
        ]));
    }
};
