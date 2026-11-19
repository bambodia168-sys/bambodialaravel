<?php

namespace App\Console\Commands;

use App\Models\AccountNotification;
use App\Models\ProductFileVersion;
use App\Models\User;
use App\Support\VaultReleaseGate;
use App\Support\VaultScreening;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class RescanExpiringVaultReleases extends Command
{
    protected $signature = 'vault:rescan-expiring';

    protected $description = 'Refresh expiring ClamAV scans for approved product releases';

    public function handle(VaultScreening $screening): int
    {
        $refreshAt = now()->addHours(VaultReleaseGate::refreshWindowHours());
        $scanCutoff = $refreshAt->copy()->subDays(VaultReleaseGate::scanValidityDays());
        $versions = ProductFileVersion::query()->where('status', 'approved')
            ->whereHas('product', fn ($query) => $query->where('status', 'published'))
            ->where('rights_review_status', 'verified')
            ->where(fn ($query) => $query->whereNull('malware_scanned_at')
                ->orWhere('malware_scanned_at', '<=', $scanCutoff)
                ->orWhere('malware_scan_status', '!=', 'clean')
                ->orWhereNull('malware_scanned_sha256')
                ->orWhereColumn('malware_scanned_sha256', '!=', 'sha256')
                ->orWhereNull('malware_scan_engine')
                ->orWhere('malware_scan_engine', 'not like', 'ClamAV %'))
            ->lazyById();

        $attempted = 0;
        $errors = 0;
        foreach ($versions as $version) {
            $expiry = VaultReleaseGate::scanExpiresAt($version);
            if (! VaultReleaseGate::rightsReviewed($version) || $expiry?->greaterThan($refreshAt)) {
                continue;
            }

            $retryKey = 'vault:rescan-attempt:'.$version->id;
            if (! Cache::add($retryKey, true, now()->addHours(6))) {
                continue;
            }

            $status = $screening->scan($version);
            if (in_array($status, ['error', 'integrity', 'infected'], true)) {
                $this->notifyAdministrators($version, $status);
            }

            if (in_array($status, ['error', 'integrity'], true)) {
                $errors++;
            } else {
                Cache::forget($retryKey);
            }

            if (++$attempted >= 5) {
                break;
            }
        }

        $this->info("Vault screening attempted for {$attempted} release(s). {$errors} require attention.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function notifyAdministrators(ProductFileVersion $version, string $status): void
    {
        $path = route('dashboard.vault.index', ['status' => 'all', 'release' => $version->id], false);
        $infected = $status === 'infected';
        $event = $infected ? 'vault.rescan.infected' : 'vault.rescan.attention';
        $title = $infected ? 'File Vault release blocked' : 'File Vault scan needs attention';
        $message = match ($status) {
            'infected' => 'ClamAV detected a threat and disabled downloads for this release.',
            'integrity' => 'The stored release is missing or failed its integrity check. Review the original and storage configuration.',
            default => 'ClamAV could not refresh the scan. Check the engine and signatures. Downloads require a current clean scan.',
        };

        $notified = AccountNotification::query()->where('event', $event)->where('target_path', $path)
            ->where('created_at', '>=', now()->subDay())->pluck('user_id');
        $recipients = User::query()->whereIn('role', ['admin', 'super_admin'])->where('account_status', 'active')
            ->whereNotIn('id', $notified)->pluck('id');
        foreach ($recipients as $recipient) {
            AccountNotification::deliver((int) $recipient, $event, $title, $message, $path);
        }
    }
}
