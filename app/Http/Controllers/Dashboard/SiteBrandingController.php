<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteBrandAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class SiteBrandingController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slot' => ['required', Rule::in(array_keys(SiteBrandAsset::SLOTS))],
        ]);
        $slot = $data['slot'];
        $spec = SiteBrandAsset::SLOTS[$slot];

        $imageRules = [
            'required', 'file', 'image', 'mimes:'.$spec['mimes'], 'max:3072',
            'dimensions:max_width=4096,max_height=4096',
        ];

        if ($spec['exact']) {
            $imageRules[] = 'dimensions:width='.$spec['width'].',height='.$spec['height'];
        }

        $request->validate(['image' => $imageRules]);
        $file = $request->file('image');

        if (in_array($slot, ['favicon_light', 'favicon_dark'], true)
            && ! $this->hasTransparentFaviconCorners($file->getRealPath())) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'image' => 'Use a transparent PNG with clear corners, not a white square background.',
            ]);
        }
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
        return back()->with('status', SiteBrandAsset::SLOTS[$slot]['label'].' updated.');
    }

    /**
     * Avoid the white square/halo shown around rounded favicons in dark tabs.
     * Hosts without GD still accept PNG: the browser's upload preview checks
     * transparency, and file type/dimensions remain server-side enforced.
     */
    private function hasTransparentFaviconCorners(string $path): bool
    {
        if (! function_exists('imagecreatefrompng')) {
            return true;
        }

        $image = @imagecreatefrompng($path);
        if (! $image) {
            return false;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if (($color['alpha'] ?? 0) < 100) {
                    return false;
                }
            }

            return true;
        } finally {
            imagedestroy($image);
        }
    }

    public function destroy(Request $request, string $slot): RedirectResponse
    {
        abort_unless(array_key_exists($slot, SiteBrandAsset::SLOTS), 404);
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
        return back()->with('status', 'Branding asset reset to the existing default.');
    }
}
