<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteBrandAsset;
use App\Support\SiteIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class SiteBrandingController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        $sharingSlots = SiteIdentity::sharingSlots();
        $data = $request->validate([
            'slot' => ['required', Rule::in([...array_keys(SiteBrandAsset::SLOTS), ...array_keys($sharingSlots)])],
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072',
                'dimensions:max_width=4096,max_height=4096'],
        ]);

        $slot = $data['slot'];
        if (isset($sharingSlots[$slot])) {
            $request->validate(['image' => ['dimensions:width=1200,height=630']]);
        }
        $file = $request->file('image');
        $newPath = $file->store('site-branding', 'public');
        abort_unless($newPath, 500, 'Unable to store branding file.');

        try {
            $previousPath = DB::transaction(function () use ($request, $slot, $file, $newPath): ?string {
                $current = SiteBrandAsset::query()->lockForUpdate()->find($slot);
                $previous = $current?->storage_path;
                SiteBrandAsset::query()->updateOrCreate(['slot' => $slot], [
                    'storage_path' => $newPath,
                    'mime_type' => $file->getMimeType(),
                    'bytes' => $file->getSize(),
                    'updated_by' => $request->user()->id,
                ]);
                AuditEvent::record('site.brand.asset.updated', $request->user(), $request);
                return $previous;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($newPath);
            throw $error;
        }

        if ($previousPath && $previousPath !== $newPath) {
            Storage::disk('public')->delete($previousPath);
        }
        return back()->with('status', ($sharingSlots[$slot] ?? SiteBrandAsset::SLOTS[$slot]['label']).' updated.');
    }

    public function destroy(Request $request, string $slot): RedirectResponse
    {
        abort_unless(isset(SiteBrandAsset::SLOTS[$slot]) || isset(SiteIdentity::sharingSlots()[$slot]), 404);
        $old = DB::transaction(function () use ($request, $slot): ?string {
            $current = SiteBrandAsset::query()->lockForUpdate()->find($slot);
            if (! $current) {
                return null;
            }
            $path = $current->storage_path;
            $current->delete();
            AuditEvent::record('site.brand.asset.deleted', $request->user(), $request);
            return $path;
        });
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        return back()->with('status', 'Branding asset reset to its fallback.');
    }
}
