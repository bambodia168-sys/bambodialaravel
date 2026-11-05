<?php

namespace App\Support;

use App\Models\LicenseDefinition;
use App\Models\MediaAsset;
use App\Models\MarketplaceListing;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ProductReadiness
{
    public static function assertReady(int $ownerId, ?int $licenseId, ?int $coverId): void
    {
        $errors = [];
        $license = $licenseId ? LicenseDefinition::query()->find($licenseId) : null;
        if (! $license || $license->status !== 'active') {
            $errors['license_definition_id'] = 'Select an active, administrator-reviewed license definition.';
        }

        $cover = $coverId ? MediaAsset::query()->find($coverId) : null;
        if (! $cover || (int) $cover->user_id !== $ownerId || ! $cover->isApproved()
            || ! in_array($cover->mime_type, MediaAsset::ALLOWED_MIME, true)
            || ! Storage::disk('local')->exists($cover->storage_path)) {
            $errors['cover_media_id'] = 'Select an approved, available preview image belonging to this creator.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function assertListing(MarketplaceListing $listing): void
    {
        if ($listing->type !== 'product') {
            return;
        }
        self::assertReady(
            (int) $listing->user_id,
            $listing->license_definition_id === null ? null : (int) $listing->license_definition_id,
            $listing->cover_media_id === null ? null : (int) $listing->cover_media_id
        );
    }
}
