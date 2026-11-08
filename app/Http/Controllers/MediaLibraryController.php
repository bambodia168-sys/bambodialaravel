<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaLibraryController extends Controller
{
    public function index(Request $request): View
    {
        $studio = $request->routeIs('studio.media.*');
        $assets = MediaAsset::query()
            ->with('owner:id,name,public_id')
            ->withCount('coverListings')
            ->when($studio, fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()->paginate(10)->withQueryString();

        return view('media.index', compact('assets', 'studio'));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $fromBuilder = $request->routeIs('builder.media.store');
        $fromPost = $request->routeIs('dashboard.posts.media.store');
        $studio = $request->routeIs('studio.media.*') || $fromBuilder;
        if ($fromPost) {
            abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
            $request->validate([
                'rights_confirmed' => ['accepted'],
                'rights_basis' => ['required', Rule::in(['owned'])],
            ]);
        }
        if ($fromBuilder) {
            abort_unless(in_array($request->user()->role, [
                'freelancer', 'super_admin', 'customer', 'subscriber',
            ], true), 403);
            $returnTo = (string) $request->input('return_to', '/builder');
            abort_unless(preg_match('~^/builder(?:\?type=(?:product|project|service|freelance|job)|\?=[0-9]{9})?$~D', $returnTo) === 1, 422);
        }
        $data = $this->validateMetadata($request, true);
        $file = $request->file('image');
        $mime = $file->getMimeType();
        $video = in_array($mime, ['video/mp4', 'video/webm'], true);
        $info = $video ? null : @getimagesize($file->getRealPath());

        if ($video) {
            $handle = fopen($file->getRealPath(), 'rb');
            $header = $handle ? fread($handle, 16) : '';
            if ($handle) {
                fclose($handle);
            }
            $signatureValid = $mime === 'video/mp4'
                ? (is_string($header) && substr($header, 4, 4) === 'ftyp')
                : (is_string($header) && str_starts_with($header, "\x1a\x45\xdf\xa3"));
            if (! $signatureValid) {
                throw ValidationException::withMessages(['image' => 'The video container is invalid.']);
            }
        } elseif ($info === false || ! in_array($info['mime'], [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        ], true) || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > 24000000) {
            throw ValidationException::withMessages(['image' => 'Invalid image or image dimensions exceed the permitted limit.']);
        }
        $mime = $video ? $mime : $info['mime'];
        $limit = $video ? 20 * 1024 * 1024 : ($mime === 'image/gif' ? 8 * 1024 * 1024 : 4 * 1024 * 1024);
        if ($file->getSize() > $limit) {
            throw ValidationException::withMessages(['image' => 'Media exceeds the size limit for its format.']);
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
        };
        $path = 'marketplace/previews/'.$request->user()->id.'/'.bin2hex(random_bytes(24)).'.'.$extension;
        $storageDisk = config('filesystems.media_library_disk', 'local');
        abort_unless(in_array($storageDisk, ['local', 'r2'], true), 500, 'Invalid Media Library disk.');
        if (! Storage::disk($storageDisk)->putFileAs(dirname($path), $file, basename($path))) {
            throw ValidationException::withMessages(['image' => 'The image could not be saved securely.']);
        }

        try {
            $asset = MediaAsset::query()->create([
                ...$data,
                'user_id' => $request->user()->id,
                'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                'storage_path' => $path,
                'storage_disk' => $storageDisk,
                'mime_type' => $mime,
                'bytes' => $file->getSize(),
                'width' => $video ? 0 : $info[0],
                'height' => $video ? 0 : $info[1],
                'review_status' => 'pending',
            ]);
        } catch (\Throwable $error) {
            Storage::disk($storageDisk)->delete($path);
            throw $error;
        }

        AuditEvent::record('media.uploaded', $request->user(), $request);

        if (($fromBuilder || $fromPost) && $request->expectsJson()) {
            return response()->json([
                'id' => $asset->id,
                'public_id' => $asset->public_id,
                'alt_text' => $asset->alt_text,
                'mime_type' => $asset->mime_type,
                'review_status' => $asset->review_status,
                'preview_url' => $asset->previewUrl(),
                'message' => 'Media uploaded to your private library. Ready to use.',
            ], 201);
        }

        if ($fromPost) {
            return redirect()->route('dashboard.posts.create')
                ->with('status', 'Media uploaded to your Media Library.');
        }
        return $fromBuilder
            ? redirect($returnTo)->with('status', 'Media uploaded privately. You can select it immediately.')
            : redirect()->route($studio ? 'studio.media.index' : 'dashboard.media.index')
                ->with('status', 'Media uploaded to your private library.');
    }

    public function update(Request $request, MediaAsset $asset): RedirectResponse
    {
        $this->authorizeAsset($request, $asset);
        $values = $this->validateMetadata($request);
        $asset->update($values);

        return back()->with('status', 'Media details updated.');
    }

    public function destroy(Request $request, MediaAsset $asset): RedirectResponse
    {
        $this->authorizeAsset($request, $asset);

        $fileLocation = DB::transaction(function () use ($asset): array {
            $asset = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
            abort_if($asset->coverListings()->exists() || $asset->featuredListings()->exists()
                || $asset->featuredJobs()->exists()
                || MarketplaceListing::query()->whereJsonContains('gallery_media_ids', $asset->id)->exists()
                || MarketplaceJob::query()->whereJsonContains('gallery_media_ids', $asset->id)->exists()
                || ContentEntry::query()->where('type', 'post')->where('featured_media_id', $asset->id)->exists(),
                409, 'This media is attached to a listing or job and cannot be deleted.');
            $path = $asset->storage_path;
            $asset->delete();
            return [$asset->storage_disk ?: 'local', $path];
        });

        Storage::disk($fileLocation[0])->delete($fileLocation[1]);
        AuditEvent::record('media.deleted', $request->user(), $request);

        return back()->with('status', 'Unlinked media item deleted.');
    }

    public function review(Request $request, MediaAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected'],
        ]);

        DB::transaction(function () use ($asset, $data, $request): void {
            $asset = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
            abort_unless($asset->review_status === 'pending', 409, 'This image is not awaiting review.');
            abort_unless(Storage::disk($asset->storage_disk ?: 'local')->exists($asset->storage_path), 409, 'The stored image is missing.');
            $asset->update([
                'review_status' => $data['decision'],
                'review_note' => $data['review_note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            AuditEvent::record('media.review.'.$data['decision'], $request->user(), $request);
        });

        return back()->with('status', 'Media review recorded.');
    }

    public function preview(Request $request, string $publicId): BinaryFileResponse|StreamedResponse
    {
        abort_unless(preg_match('/^\d{9}$/D', $publicId) === 1, 404);
        $asset = MediaAsset::query()->where('public_id', $publicId)->firstOrFail();
        $actor = $request->user();
        $owner = $actor && (int) $actor->id === (int) $asset->user_id;
        $admin = $actor && in_array($actor->role, ['super_admin', 'admin'], true);
        // Private assets are accessible to their owners and administrators.
        // Public access is granted only when linked to published content.
        $public = (
            MarketplaceListing::query()
                ->where('cover_media_id', $asset->id)
                ->where('status', 'published')
                ->where('type', 'product')->exists()
            || MarketplaceListing::query()
                ->where('featured_media_id', $asset->id)
                ->where('status', 'published')->exists()
            || MarketplaceJob::query()
                ->where('featured_media_id', $asset->id)
                ->where('status', 'published')->exists()
            || MarketplaceListing::query()
                ->whereJsonContains('gallery_media_ids', $asset->id)
                ->where('status', 'published')->exists()
            || MarketplaceJob::query()
                ->whereJsonContains('gallery_media_ids', $asset->id)
                ->where('status', 'published')->exists()
            || ContentEntry::query()->where('type', 'post')
                ->where('featured_media_id', $asset->id)
                ->where('status', 'published')->exists()
        );

        abort_unless($owner || $admin || $public, 404);
        abort_unless(in_array($asset->mime_type, MediaAsset::ALLOWED_MIME, true), 404);
        $storageDisk = $asset->storage_disk ?: 'local';
        abort_unless(in_array($storageDisk, ['local', 'r2'], true), 404);
        $disk = Storage::disk($storageDisk);
        abort_unless($disk->exists($asset->storage_path), 404);
        $headers = [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'inline; filename="bambodia-preview-'.$asset->public_id.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($storageDisk === 'local') {
            return response()->file($disk->path($asset->storage_path), $headers);
        }
        // Stream R2 objects through this access-controlled endpoint; never expose
        // permanent public R2 URLs or unrestricted object keys.
        return response()->stream(function () use ($disk, $asset): void {
            $stream = $disk->readStream($asset->storage_path);
            if (! is_resource($stream)) {
                return;
            }
            try {
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }

    private function authorizeAsset(Request $request, MediaAsset $asset): void
    {
        if ($request->routeIs('studio.media.*')) {
            abort_unless((int) $asset->user_id === (int) $request->user()->id, 404);
            return;
        }
        abort_unless(in_array($request->user()->role, ['super_admin', 'admin'], true), 403);
    }

    private function validateMetadata(Request $request, bool $withFile = false): array
    {
        $rules = [
            'alt_text' => ['required', 'string', 'min:4', 'max:180'],
            'rights_basis' => ['required', Rule::in(['owned', 'licensed'])],
            'rights_holder' => ['required', 'string', 'min:2', 'max:180'],
            'rights_note' => ['nullable', 'string', 'max:3000', 'required_if:rights_basis,licensed'],
        ];
        if ($withFile) {
            $rules['image'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,webm',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm', 'max:20480'];
        }

        return $request->validate($rules);
    }
}
