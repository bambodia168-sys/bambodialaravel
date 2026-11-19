<?php

namespace App\Support;

use App\Models\ProductFileVersion;
use Illuminate\Support\Carbon;

final class VaultReleaseGate
{
    public static function screened(ProductFileVersion $version): bool
    {
        return self::scanExpiresAt($version)?->greaterThanOrEqualTo(now()) ?? false;
    }

    public static function scanValidityDays(): int
    {
        return max(1, min(365, (int) config('vault.max_scan_age_days', 30)));
    }

    public static function refreshWindowHours(): int
    {
        return min(72, self::scanValidityDays() * 12);
    }

    public static function scanExpiresAt(ProductFileVersion $version): ?Carbon
    {
        $hasCleanEvidence = $version->malware_scan_status==='clean'
            && $version->malware_scanned_sha256!==null
            && preg_match('/^[a-f0-9]{64}$/D',$version->malware_scanned_sha256)===1
            && $version->sha256!==null
            && hash_equals($version->sha256,$version->malware_scanned_sha256)
            && is_string($version->malware_scan_engine)
            && str_starts_with($version->malware_scan_engine,'ClamAV ')
            && $version->malware_scanned_at!==null;

        return $hasCleanEvidence
            ? $version->malware_scanned_at->copy()->addDays(self::scanValidityDays())
            : null;
    }

    public static function rightsReviewed(ProductFileVersion $version): bool
    {
        return $version->rights_review_status==='verified'
            && $version->rights_reviewed_by!==null
            && $version->rights_reviewed_at!==null
            && $version->rights_attested_at!==null
            && filled($version->rights_evidence)
            && filled($version->rights_review_note);
    }

    public static function deliverable(ProductFileVersion $version): bool
    {
        return $version->status==='approved'
            && self::screened($version)
            && self::rightsReviewed($version);
    }
}
