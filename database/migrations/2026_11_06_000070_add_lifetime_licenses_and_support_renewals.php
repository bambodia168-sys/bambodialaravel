<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_licenses', function (Blueprint $table): void {
            $table->string('support_term', 20)->nullable()->after('expires_at');
            $table->timestamp('support_started_at')->nullable()->after('support_term');
            $table->timestamp('support_expires_at')->nullable()->after('support_started_at');
        });
        Schema::create('product_license_support_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_license_id')->constrained('product_licenses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('term',20);
            $table->string('status',20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note',500)->nullable();
            $table->timestamp('granted_from')->nullable();
            $table->timestamp('granted_until')->nullable();
            $table->timestamps();
            $table->index(['product_license_id','status']);
            $table->index(['user_id','created_at']);
            $table->index(['status','created_at']);
        });
        // These are *installation licenses*, not time-limited support or
        // file-download grants. Clear the old expiry copied at issuance;
        // retain existing entitlement expiry and revocation independently.
        DB::table('product_licenses')->whereNotNull('expires_at')->update(['expires_at'=>null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_license_support_requests');
        Schema::table('product_licenses', function (Blueprint $table): void {
            $table->dropColumn(['support_term','support_started_at','support_expires_at']);
        });
        // Historical license expiry values cannot be reconstructed after the
        // conversion to lifetime. Restore the pre-migration DB backup if needed.
    }
};
