<?php

namespace App\Support;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BuilderGallery
{
    public const MAX_ITEMS = 8;

    public static function rules(): array
    {
        return [
            'gallery_media_ids' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'gallery_media_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public static function check(array &$data, Request $request, ?int $ownerId = null, ?array $attachedIds = null): void
    {
        if (! $request->boolean('builder_mode')) {
            // Legacy forms do not overwrite or clear the builder gallery.
            unset($data['gallery_media_ids']);
            return;
        }
        if ($attachedIds !== null && ! array_key_exists('gallery_media_ids', $data) && ! $request->boolean('gallery_present')) {
            return;
        }

        $ids = array_map('intval', $data['gallery_media_ids'] ?? []);
        $allowed = MediaAsset::query()->whereIn('id', $ids)
            ->where(fn ($query) => $query->where('user_id', $ownerId ?? $request->user()->id)->orWhereIn('id', $attachedIds ?? []))
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->count();
        if ($allowed !== count($ids)) {
            throw ValidationException::withMessages([
                'gallery_media_ids' => 'Choose only image or screenshot files from your own Media Library.',
            ]);
        }
        $data['gallery_media_ids'] = $ids;
    }

    public static function attachedImages(?array $ids): \Illuminate\Support\Collection
    {
        if (! $ids) return collect();

        return MediaAsset::query()->whereIn('id', $ids)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->get()->sortBy(fn ($asset) => array_search($asset->id, $ids, false))->values();
    }
}
