<?php

namespace App\Support;

use App\Models\MediaAsset;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BuilderFeaturedMedia
{
    public const KINDS = ['none', 'image', 'video', 'animated', 'color', 'youtube'];

    public static function rules(): array
    {
        return [
            'featured_kind' => ['nullable', Rule::in(self::KINDS)],
            'featured_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')],
            'featured_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'featured_youtube_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public static function check(array &$data, int $ownerId): void
    {
        if (! array_key_exists('featured_kind', $data)) {
            unset($data['featured_youtube_url']);
            return; // Legacy creation/editing endpoints preserve existing media settings.
        }

        $kind = $data['featured_kind'] ?: 'none';
        $data['featured_kind'] = $kind;

        $youtubeUrl=trim((string)($data['featured_youtube_url'] ?? ''));
        unset($data['featured_youtube_url']);

        if ($kind === 'youtube') {
            $id=self::youtubeId($youtubeUrl);
            if ($id === null) {
                throw ValidationException::withMessages([
                    'featured_youtube_url'=>'Enter a valid HTTPS YouTube, YouTube Shorts, or youtu.be video URL.',
                ]);
            }
            $data['featured_youtube_id']=$id;
            $data['featured_media_id']=null;
            $data['featured_color']=null;
            return;
        }

        $data['featured_youtube_id']=null;
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
                ->where('user_id', $ownerId)->first()
            : null;

        if (! $asset || ! in_array($asset->mime_type, $mimes, true)) {
            throw ValidationException::withMessages([
                'featured_media_id' => 'Select a matching media file from your own library.',
            ]);
        }
    }

    /** Parse user links to an ID; never store arbitrary iframe origins. */
    public static function youtubeId(string $raw): ?string
    {
        $url=trim($raw);
        if ($url === '' || strlen($url)>2048 || preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return null;
        }
        $parts=parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])) {
            return null;
        }
        $host=strtolower((string)($parts['host'] ?? ''));
        $path=(string)($parts['path'] ?? '');
        $id='';
        if (in_array($host,['youtu.be','www.youtu.be'],true)) {
            if (preg_match('~^/([A-Za-z0-9_-]{11})$~D',$path,$match)) $id=$match[1];
        } elseif (in_array($host,[
            'youtube.com','www.youtube.com','m.youtube.com',
            'youtube-nocookie.com','www.youtube-nocookie.com',
        ],true)) {
            if ($path==='/watch') {
                parse_str((string)($parts['query'] ?? ''),$params);
                if (is_string($params['v'] ?? null)) $id=$params['v'];
            } elseif (preg_match('~^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})$~D',$path,$match)) {
                $id=$match[1];
            }
        }
        return preg_match('/^[A-Za-z0-9_-]{11}$/D',$id) ? $id : null;
    }
}
