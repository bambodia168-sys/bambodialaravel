<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Restrict rich YouTube previews to verified, canonical video URLs.
 *
 * The support sanitizer remains the authority for message HTML. The chat
 * adds previews only from strict 11-character YouTube IDs and never uses
 * arbitrary external iframe origins or untrusted HTML attributes.
 */
final class TrackingYouTubeContent
{
    public static function prepare(string $message): string
    {
        if (strlen($message) > 40000) {
            throw ValidationException::withMessages(['message' => 'The message is too long.']);
        }

        $safe = SupportMessageContent::sanitize($message);
        $text = trim(BuilderContent::plainText($safe));

        // An inserted image, audio, video, or sanitized YouTube iframe is
        // meaningful content even if it contains no readable text nodes.
        $hasMedia = (bool) preg_match('~<(?:img|audio|video|iframe)\b~i', $safe);

        if (mb_strlen($text) < 2 && ! $hasMedia) {
            throw ValidationException::withMessages([
                'message' => 'Write a message, paste a YouTube link, or insert supported media.',
            ]);
        }

        return $safe;
    }

    /**
     * Render old plain messages and new rich messages alike. A normal pasted
     * watch/short/live/share URL receives a safe embedded preview, and existing
     * YouTube iframes gain a dependable "Watch on YouTube" fallback.
     */
    public static function display(string $message, bool $formatted): string
    {
        $safe = $formatted
            ? SupportMessageContent::display($message)
            : nl2br(e($message));

        $urls = html_entity_decode($safe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match_all("~https://[^\\s<>\"'\\x00-\\x1f]+~i", $urls, $matches)) {
            return $safe;
        }

        $ids = [];
        foreach ($matches[0] as $url) {
            $id = self::videoId(rtrim($url, '.,;:!?)]}'));
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= 2) {
                break; // Avoid turning a long conversation update into a video wall.
            }
        }

        foreach ($ids as $id) {
            $watch = 'https://www.youtube.com/watch?v='.$id;
            $embedded = str_contains($urls, '/embed/'.$id);
            if (! $embedded) {
                $safe .= '<div class="tracking-youtube-card">'
                    .'<div class="tracking-youtube-frame">'
                    .'<iframe src="https://www.youtube-nocookie.com/embed/'.$id.'" '
                    .'title="YouTube video preview" loading="lazy" '
                    .'referrerpolicy="strict-origin-when-cross-origin" '
                    .'sandbox="allow-scripts allow-same-origin allow-presentation" '
                    .'allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>'
                    .'</div>';
            } else {
                $safe .= '<div class="tracking-youtube-fallback">';
            }
            $safe .= '<a href="'.$watch.'" target="_blank" rel="noopener noreferrer nofollow">'
                .'Watch on YouTube</a></div>';
        }

        return $safe;
    }

    public static function videoId(string $raw): ?string
    {
        $parts = parse_url(trim($raw));
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $id = null;

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            if (preg_match('~^/([A-Za-z0-9_-]{11})/?$~D', $path, $match)) {
                $id = $match[1];
            }
        } elseif (in_array($host, [
            'youtube.com', 'www.youtube.com', 'm.youtube.com',
            'youtube-nocookie.com', 'www.youtube-nocookie.com',
        ], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('~^/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})/?$~D', $path, $match)) {
                $id = $match[1];
            }
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : null;
    }
}
