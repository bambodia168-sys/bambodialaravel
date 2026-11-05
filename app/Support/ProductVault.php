<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class ProductVault
{
    public const MAX_UPLOAD_KB = 25600; // 25 MiB for shared-host compatibility
    public const ALLOWED_TYPES = ['zip', 'pptx', 'pdf'];

    /**
     * Validate file structure without extracting untrusted uploaded content.
     * Original files are never served by the web root.
     */
    public static function inspect(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages(['artifact' => 'Only ZIP, PPTX and PDF originals are accepted.']);
        }

        $path = $file->getRealPath();
        if (! is_string($path) || ! is_file($path) || $file->getSize() === 0) {
            throw ValidationException::withMessages(['artifact' => 'The uploaded file is missing or empty.']);
        }

        if ($extension === 'pdf') {
            if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
                throw ValidationException::withMessages(['artifact' => 'This is not a valid PDF file.']);
            }
            return ['type' => 'pdf', 'mime' => 'application/pdf', 'sha256' => hash_file('sha256', $path)];
        }

        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['artifact' => 'ZIP processing is not installed on this server.']);
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['artifact' => 'Invalid or damaged archive.']);
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 1000) {
                throw ValidationException::withMessages(['artifact' => 'Archives must contain 1–1000 entries.']);
            }

            $uncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat)) {
                    throw ValidationException::withMessages(['artifact' => 'Unreadable ZIP entry.']);
                }
                $name = $stat['name'];
                $normalized = str_replace('\\', '/', $name);
                if ($name === '' || str_contains($name, "\0") || str_starts_with($normalized, '/')
                    || preg_match('/^[a-zA-Z]:/', $normalized)
                    || preg_match('#(^|/)\.\.(/|$)#', $normalized)
                    || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw ValidationException::withMessages(['artifact' => 'Archive contains an unsafe path or filename.']);
                }

                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    if ((($attr >> 16) & 0170000) === 0120000) {
                        throw ValidationException::withMessages(['artifact' => 'Archives cannot include symbolic links.']);
                    }
                }

                $size = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                $uncompressed += $size;
                if ($uncompressed > 524288000 || ($size > 1048576 && $size > max(1, $compressed) * 300)) {
                    throw ValidationException::withMessages(['artifact' => 'Archive expands beyond safe storage limits.']);
                }
            }

            if ($extension === 'pptx'
                && ($zip->locateName('[Content_Types].xml') === false
                    || $zip->locateName('ppt/presentation.xml') === false
                    || $zip->locateName('ppt/vbaProject.bin', ZipArchive::FL_NOCASE) !== false)) {
                throw ValidationException::withMessages(['artifact' => 'The PPTX presentation structure is invalid or includes macros.']);
            }
        } finally {
            $zip->close();
        }

        return [
            'type' => $extension,
            'mime' => $extension === 'pptx'
                ? 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                : 'application/zip',
            'sha256' => hash_file('sha256', $path),
        ];
    }

    public static function persist(UploadedFile $file, int $ownerId, int $productId, string $type): string
    {
        $path = 'marketplace/originals/'.$ownerId.'/'.$productId.'/'.bin2hex(random_bytes(32)).'.'.$type;
        $saved = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        if ($saved === false) {
            throw ValidationException::withMessages(['artifact' => 'File could not be saved in private storage.']);
        }

        return $path;
    }

    public static function verifiedPath(string $storedPath, string $sha256, int $size): ?string
    {
        if (! preg_match('#^marketplace/originals/[0-9]+/[0-9]+/[a-f0-9]{64}\.(zip|pptx|pdf)$#D', $storedPath)) {
            return null;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($storedPath)) {
            return null;
        }
        $absolute = $disk->path($storedPath);
        if (! is_file($absolute) || is_link($absolute)
            || filesize($absolute) !== $size
            || ! hash_equals($sha256, hash_file('sha256', $absolute))) {
            return null;
        }
        return $absolute;
    }
}
