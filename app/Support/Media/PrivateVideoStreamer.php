<?php

namespace App\Support\Media;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateVideoStreamer
{
    private const CHUNK_SIZE = 1024 * 1024;

    /**
     * A single HTTP Range, capped to a short segment to support private,
     * seekable playback without buffering the whole R2 file through PHP.
     * Returns null for an unsatisfiable/unsupported range.
     *
     * @return array{0:int,1:int}|null
     */
    public static function parseRange(?string $header, int $size): ?array
    {
        if ($size < 1) {
            return null;
        }
        if ($header === null || $header === '') {
            return [0, $size - 1];
        }
        if (! preg_match('/\Abytes=(\d*)-(\d*)\z/D', trim($header), $matches)
            || ($matches[1] === '' && $matches[2] === '')) {
            return null; // Multi-range requests are intentionally unsupported.
        }
        if ($matches[1] === '') {
            $suffix = (int) $matches[2];
            if ($suffix < 1) {
                return null;
            }
            $count = min($suffix, self::CHUNK_SIZE, $size);
            return [$size - $count, $size - 1];
        }
        $start = (int) $matches[1];
        if ($start >= $size) {
            return null;
        }
        $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];
        if ($end < $start) {
            return null;
        }
        return [$start, min($size - 1, $end, $start + self::CHUNK_SIZE - 1)];
    }

    /**
     * R2-backed MP4/WebM: the browser's Range request is forwarded to R2
     * after existing owner/admin/published-content authorization.
     * $disk is the Laravel S3 disk adapter. No public object URL is created.
     */
    public static function serve(Request $request, MediaAsset $asset, $disk, array $headers): Response
    {
        $size = (int) $disk->size($asset->storage_path);
        abort_unless($size > 0, 404);

        $hasRange = $request->headers->has('Range') && ! $request->headers->has('If-Range');
        $range = self::parseRange($hasRange ? $request->header('Range') : null, $size);
        if ($range === null) {
            return response('', 416, $headers + [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */'.$size,
                'Content-Length' => '0',
            ]);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;
        $status = $hasRange ? 206 : 200;
        $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Length'] = (string) $length;
        if ($hasRange) {
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }

        if ($request->isMethod('HEAD')) {
            return response('', $status, $headers);
        }

        // Request the exact segment using the S3 API. Reading through Flysystem
        // without a Range would make every seek transfer the entire video.
        try {
            $client = $disk->getClient();
            $result = $client->getObject([
                'Bucket' => (string) config('filesystems.disks.r2.bucket'),
                'Key' => $asset->storage_path,
                'Range' => "bytes=$start-$end",
            ]);
            $body = $result['Body'];
        } catch (\Throwable) {
            abort(502, 'Private video temporarily unavailable.');
        }

        return response()->stream(static function () use ($body): void {
            try {
                while (! $body->eof()) {
                    $chunk = $body->read(64 * 1024);
                    if ($chunk === '') {
                        break;
                    }
                    echo $chunk;
                }
            } finally {
                $body->close();
            }
        }, $status, $headers);
    }
}
