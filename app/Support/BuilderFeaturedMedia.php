<?php

namespace App\Support;

use App\Models\MediaAsset;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BuilderFeaturedMedia
{
    public const KINDS = ['none', 'image', 'video', 'animated', 'color'];

    public static function rules(): array
    {
        return [
            'featured_kind' => ['nullable', Rule::in(self::KINDS)],
            'featured_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')],
            'featured_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    public static function check(array &$data, int $ownerId): void
    {
        if (! array_key_exists('featured_kind', $data)) {
            return; // Legacy creation/editing endpoints preserve existing media settings.
        }

        $kind = $data['featured_kind'] ?: 'none';
        $data['featured_kind'] = $kind;

        if ($kind === 'color') {
            if (empty($data['featured_color'])) {
                throw ValidationException::withMessages([
                    'featured_color' => 'Choose a six-digit featured color.',
                ]);
            }
            $data['featured_media_id'] = null;
            return;
        }

        $data['featured_color'] = null;
        if ($kind === 'none') {
            $data['featured_media_id'] = null;
            return;
        }

        $mimes = match ($kind) {
            'image' => ['image/jpeg', 'image/png', 'image/webp'],
            'animated' => ['image/gif'],
            'video' => ['video/mp4', 'video/webm'],
        };

        $asset = ! empty($data['featured_media_id'])
            ? MediaAsset::query()->whereKey($data['featured_media_id'])
                ->where('user_id', $ownerId)->where('review_status', 'approved')->first()
            : null;

        if (! $asset || ! in_array($asset->mime_type, $mimes, true)) {
            throw ValidationException::withMessages([
                'featured_media_id' => 'Select an approved matching media file from your own library.',
            ]);
        }
    }
}
