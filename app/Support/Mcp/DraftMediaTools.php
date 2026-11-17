<?php

namespace App\Support\Mcp;

use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\BuilderGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DraftMediaTools
{
    public function __construct(private readonly Request $request) {}

    /**
     * Links existing, reviewed media from the token owner's own Media Library
     * to their own unpublished draft. Does not accept file paths, remote URLs,
     * base64 blobs, or change the media approval state.
     */
    public function change(array $args, User $actor): array
    {
        $values = Validator::make($args, [
            'entity' => ['required', Rule::in(['product','project','service','freelance','job','post','page'])],
            'id' => ['required','integer','min:1'],
            'action' => ['required', Rule::in(['attach','detach'])],
            'purpose' => ['required', Rule::in(['cover','featured','gallery'])],
            'media_id' => ['required','integer','min:1'],
        ])->validate();

        $entity = $values['entity'];
        $purpose = $values['purpose'];
        $id = (int) $values['id'];
        $mediaId = (int) $values['media_id'];

        if ($purpose === 'cover' && ! in_array($entity, ['product','project','service','freelance'], true)) {
            throw ValidationException::withMessages(['purpose' => 'Only marketplace listings support cover media.']);
        }
        if ($purpose === 'gallery' && in_array($entity, ['post','page'], true)) {
            throw ValidationException::withMessages(['purpose' => 'Posts and pages do not support a builder gallery.']);
        }

        return DB::transaction(function () use ($entity, $purpose, $values, $id, $mediaId, $actor): array {
            $record = match ($entity) {
                'product','project','service','freelance' => MarketplaceListing::query()
                    ->where('type', $entity)->where('user_id', $actor->id)
                    ->lockForUpdate()->findOrFail($id),
                'job' => MarketplaceJob::query()->where('client_id', $actor->id)
                    ->lockForUpdate()->findOrFail($id),
                'post','page' => ContentEntry::query()->where('type', $entity)
                    ->where('author_id', $actor->id)->lockForUpdate()->findOrFail($id),
            };
            if ($record->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Media changes through MCP are restricted to unpublished drafts.']);
            }

            $asset = null;
            if ($values['action'] === 'attach') {
                $asset = MediaAsset::query()->whereKey($mediaId)->where('user_id', $actor->id)
                    ->where('review_status', 'approved')->firstOrFail();
                $allowed = match ($purpose) {
                    'cover' => ['image/jpeg','image/png','image/webp'],
                    'gallery' => ['image/jpeg','image/png','image/webp','image/gif'],
                    'featured' => MediaAsset::ALLOWED_MIME,
                };
                if (! in_array($asset->mime_type, $allowed, true)) {
                    throw ValidationException::withMessages(['media_id' => 'Media format is not allowed for this placement.']);
                }
            }

            if ($purpose === 'gallery') {
                $ids = array_values(array_map('intval', $record->gallery_media_ids ?? []));
                if ($values['action'] === 'attach') {
                    if (! in_array($mediaId, $ids, true)) {
                        $ids[] = $mediaId;
                    }
                    if (count($ids) > BuilderGallery::MAX_ITEMS) {
                        throw ValidationException::withMessages(['gallery' => 'The gallery can contain at most eight images.']);
                    }
                } else {
                    if (! in_array($mediaId, $ids, true)) {
                        throw ValidationException::withMessages(['media_id' => 'This media item is not part of the gallery.']);
                    }
                    $ids = array_values(array_filter($ids, fn ($item) => $item !== $mediaId));
                }
                $record->gallery_media_ids = $ids;
            } elseif ($purpose === 'cover') {
                if ($values['action'] === 'detach' && (int) $record->cover_media_id !== $mediaId) {
                    throw ValidationException::withMessages(['media_id' => 'This item is not the current cover.']);
                }
                $record->cover_media_id = $asset?->id;
            } else {
                if ($values['action'] === 'detach' && (int) $record->featured_media_id !== $mediaId) {
                    throw ValidationException::withMessages(['media_id' => 'This item is not the current featured media.']);
                }
                $record->featured_media_id = $asset?->id;

                if ($record instanceof ContentEntry) {
                    $record->featured_type = $asset
                        ? (str_starts_with($asset->mime_type, 'video/') ? 'video' : 'image') : 'none';
                    $record->featured_url = null;
                    $record->featured_color = null;
                } else {
                    $record->featured_kind = $asset ? match ($asset->mime_type) {
                        'image/gif' => 'animated',
                        'video/mp4','video/webm' => 'video',
                        default => 'image',
                    } : 'none';
                    $record->featured_youtube_id = null;
                    $record->featured_color = null;
                }
            }
            $record->save();
            AuditEvent::record('mcp.media.'.($values['action'] === 'attach' ? 'attached' : 'detached'), $actor, $this->request);
            return [
                'entity'=>$entity, 'id'=>$id, 'status'=>'draft',
                'purpose'=>$purpose, 'action'=>$values['action'],
                'media_id'=>$mediaId,
                'cover_media_id'=>$record instanceof MarketplaceListing ? $record->cover_media_id : null,
                'featured_media_id'=>$record->featured_media_id,
                'gallery_media_ids'=>$record->gallery_media_ids ?? [],
            ];
        });
    }
}
