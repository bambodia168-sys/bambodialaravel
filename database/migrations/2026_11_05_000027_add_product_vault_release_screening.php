<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_file_versions', function(Blueprint $table): void {
            $table->string('rights_evidence',3000)->nullable();
            $table->string('rights_source_url',500)->nullable();
            $table->string('rights_review_status',24)->default('pending')->index();
            $table->foreignId('rights_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rights_reviewed_at')->nullable();
            $table->text('rights_review_note')->nullable();
            $table->string('malware_scan_status',24)->default('pending')->index();
            $table->char('malware_scanned_sha256',64)->nullable();
            $table->string('malware_scan_engine',180)->nullable();
            $table->timestamp('malware_scanned_at')->nullable();
            $table->foreignId('malware_scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('malware_scan_note')->nullable();
            $table->index(['status','malware_scan_status','rights_review_status'],'vault_release_screening_state');
        });
    }
    public function down(): void
    {
        Schema::table('product_file_versions',function(Blueprint $table): void {
            $table->dropIndex('vault_release_screening_state');
            $table->dropColumn([
                'rights_evidence','rights_source_url','rights_review_status','rights_reviewed_by',
                'rights_reviewed_at','rights_review_note','malware_scan_status','malware_scanned_sha256',
                'malware_scan_engine','malware_scanned_at','malware_scanned_by','malware_scan_note',
            ]);
        });
    }
};
