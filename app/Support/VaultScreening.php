<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ProductFileVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VaultScreening
{
    public function __construct(private readonly VaultMalwareScanner $scanner)
    {
    }

    public function scan(ProductFileVersion $version, ?Request $request = null): string
    {
        $allowed = $request === null ? ['approved'] : ['pending', 'approved'];
        if (! in_array($version->status, $allowed, true)) {
            abort_if($request !== null, 409);

            return 'skipped';
        }

        $path = ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size);
        if ($path === null) {
            abort_if($request !== null, 409, 'Private file is missing or fails integrity validation.');

            return 'integrity';
        }

        // Only the verified private original is passed to ClamAV.
        $result = $this->scanner->scanFile($path);
        $status = $result['status'] ?? null;
        abort_unless(in_array($status, ['clean', 'infected', 'error'], true), 500);

        return DB::transaction(function () use ($request, $version, $result, $status, $allowed): string {
            $locked = ProductFileVersion::query()->lockForUpdate()->find($version->id);
            if ($locked === null || ! in_array($locked->status, $allowed, true)
                || $locked->storage_path !== $version->storage_path
                || $locked->sha256 !== $version->sha256
                || (int) $locked->byte_size !== (int) $version->byte_size) {
                abort_if($request !== null, 409, 'The release changed during screening.');

                return 'skipped';
            }

            if (ProductVault::verifiedPath($locked->storage_path, $locked->sha256, (int) $locked->byte_size) === null) {
                abort_if($request !== null, 409, 'File integrity changed during screening.');

                return 'integrity';
            }

            $actor = $request?->user();
            if ($request === null && $status !== 'infected') {
                // A manual scan or another refresh may have completed while ClamAV ran.
                if ($locked->getRawOriginal('malware_scanned_at') !== $version->getRawOriginal('malware_scanned_at')
                    || $locked->malware_scan_status !== $version->malware_scan_status) {
                    return 'skipped';
                }

                // An unavailable scanner cannot renew evidence. Keep the existing expiry date.
                if ($status === 'error') {
                    AuditEvent::record('vault.malware.error');

                    return $status;
                }
            }

            $attributes = [
                'malware_scan_status' => $status,
                'malware_scanned_sha256' => $status === 'clean' ? $locked->sha256 : null,
                'malware_scan_engine' => ($status === 'error' ? null : mb_substr((string) ($result['engine'] ?? ''), 0, 180)) ?: null,
                'malware_scanned_at' => now(),
                'malware_scanned_by' => $actor?->id,
                'malware_scan_note' => mb_substr((string) ($result['note'] ?? 'Scanner returned no details.'), 0, 2000),
            ];
            if ($status === 'infected') {
                $attributes['status'] = $locked->status === 'approved' ? 'disabled' : 'rejected';
                $attributes['reviewed_at'] = now();
                $attributes['reviewed_by'] = $actor?->id;
                $attributes['review_note'] = 'Distribution blocked following antivirus threat detection.';
            }

            $locked->update($attributes);
            if ($status === 'infected') {
                AccountNotification::deliver(
                    (int) $locked->product()->firstOrFail()->user_id,
                    'vault.version.reviewed',
                    'Product file release blocked',
                    'Your uploaded release was blocked after malware screening.',
                    '/studio/products/'.$locked->marketplace_listing_id.'/releases'
                );
            }
            AuditEvent::record('vault.malware.'.$status, $actor, $request);

            return $status;
        });
    }
}
