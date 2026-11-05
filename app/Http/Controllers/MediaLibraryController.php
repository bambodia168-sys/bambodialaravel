<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\MarketplaceListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function store(Request $request): RedirectResponse
    {
        $studio = $request->routeIs('studio.media.*');
        $data = $this->validateMetadata($request, true);
        $file = $request->file('image');
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info['mime'], MediaAsset::ALLOWED_MIME, true)
            || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > 24000000) {
            throw ValidationException::withMessages(['image' => 'Invalid image or image dimensions exceed the permitted limit.']);
        }

        $extension = match ($info['mime']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        };
        $path = 'marketplace/previews/'.$request->user()->id.'/'.bin2hex(random_bytes(24)).'.'.$extension;
        if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
            throw ValidationException::withMessages(['image' => 'The image could not be saved securely.']);
        }

        try {
            MediaAsset::query()->create([
                ...$data,
                'user_id' => $request->user()->id,
                'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                'storage_path' => $path,
                'mime_type' => $info['mime'],
                'bytes' => $file->getSize(),
                'width' => $info[0],
                'height' => $info[1],
                'review_status' => 'pending',
            ]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        AuditEvent::record('media.uploaded', $request->user(), $request);

        return redirect()->route($studio ? 'studio.media.index' : 'dashboard.media.index')
            ->with('status', 'Private image uploaded for rights review.');
    }

    public function update(Request $request, MediaAsset $asset): RedirectResponse
    {
        $this->authorizeAsset($request, $asset);
        abort_unless($asset->review_status !== 'approved', 409, 'Reviewed image metadata is locked. Upload a new asset for changes.');

        $values = $this->validateMetadata($request);
        $asset->update([...$values, 'review_status' => 'pending', 'review_note' => null]);

        return back()->with('status', 'Image details updated for review.');
    }

    public function destroy(Request $request, MediaAsset $asset): RedirectResponse
    {
        $this->authorizeAsset($request, $asset);

        $path = DB::transaction(function () use ($asset): string {
            $asset = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
            abort_if($asset->coverListings()->exists(), 409, 'This image is attached to a listing and cannot be deleted.');
            $path = $asset->storage_path;
            $asset->delete();
            return $path;
        });

        Storage::disk('local')->delete($path);
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
            abort_unless(Storage::disk('local')->exists($asset->storage_path), 409, 'The stored image is missing.');
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

    public function preview(Request $request, string $publicId): BinaryFileResponse
    {
        abort_unless(preg_match('/^\d{9}$/D', $publicId) === 1, 404);
        $asset = MediaAsset::query()->where('public_id', $publicId)->firstOrFail();
        $actor = $request->user();
        $owner = $actor && (int) $actor->id === (int) $asset->user_id;
        $admin = $actor && in_array($actor->role, ['super_admin', 'admin'], true);
        $public = $asset->review_status === 'approved'
            && MarketplaceListing::query()
                ->where('cover_media_id', $asset->id)
                ->where('status', 'published')
                ->where('type', 'product')
                ->exists();

        abort_unless($owner || $admin || $public, 404);
        abort_unless(in_array($asset->mime_type, MediaAsset::ALLOWED_MIME, true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($asset->storage_path), 404);

        return response()->file($disk->path($asset->storage_path), [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'inline; filename="bambodia-preview-'.$asset->public_id.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
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
            $rules['image'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:4096'];
        }

        return $request->validate($rules);
    }
}
