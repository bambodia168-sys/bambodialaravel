<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Validation\ValidationException;

final class SupportMessageContent
{
    private const ALLOWED = [
        'p', 'div', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i',
        'u', 's', 'del', 'mark', 'ul', 'ol', 'li', 'blockquote',
        'pre', 'code', 'a', 'hr', 'table', 'thead', 'tbody',
        'tr', 'th', 'td', 'span', 'sup', 'sub', 'img', 'audio',
        'video', 'iframe',
    ];

    private const DISCARDED = [
        'script', 'style', 'svg', 'math', 'object', 'embed', 'form',
        'input', 'button', 'textarea', 'link', 'meta', 'template',
        'base', 'source', 'picture',
    ];

    /**
     * Ticket messages remain text in the existing support_messages.body field.
     * Sanitize on write and independently again on display.
     */
    public static function prepare(string $value, int $minimum): string
    {
        if (strlen($value) > 40000) {
            throw ValidationException::withMessages(['message' => 'The message is too long.']);
        }
        $safe = self::sanitize($value);
        $text = BuilderContent::plainText($safe);
        if (mb_strlen($text) < $minimum) {
            throw ValidationException::withMessages([
                'message' => 'Enter at least '.$minimum.' characters of readable message text.',
            ]);
        }
        return $safe;
    }

    public static function display(string $value): string
    {
        return self::sanitize($value);
    }

    public static function sanitize(string $input): string
    {
        if (trim($input) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadHTML('<?xml encoding="UTF-8"?><div id="ticket-root">'.$input.'</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $root = (new DOMXPath($dom))->query('//*[@id="ticket-root"]')->item(0);
            if (! $root) {
                return '';
            }
            self::clean($root);
            $safe = '';
            foreach ($root->childNodes as $node) {
                $safe .= $dom->saveHTML($node);
            }
            return trim($safe);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function clean(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if (! $node instanceof DOMElement) {
                if ($node->nodeType !== XML_TEXT_NODE) {
                    $parent->removeChild($node);
                }
                $node = $next;
                continue;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DISCARDED, true)) {
                $parent->removeChild($node);
                $node = $next;
                continue;
            }
            if (! in_array($tag, self::ALLOWED, true)) {
                self::clean($node);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                $node = $next;
                continue;
            }
            $attrs = [];
            if ($tag === 'a') {
                $href = self::safeUrl($node->getAttribute('href'), false);
                if ($href !== null) {
                    $attrs = ['href' => $href, 'rel' => 'noopener noreferrer nofollow', 'target' => '_blank'];
                }
            } elseif ($tag === 'img') {
                $src = self::safeUrl($node->getAttribute('src'));
                if ($src === null) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                $attrs = [
                    'src' => $src, 'alt' => mb_substr($node->getAttribute('alt'), 0, 160),
                    'loading' => 'lazy', 'referrerpolicy' => 'no-referrer',
                ];
            } elseif ($tag === 'iframe') {
                $src = self::youtubeEmbed($node->getAttribute('src'));
                if ($src === null) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                $attrs = [
                    'src' => $src, 'title' => 'YouTube video',
                    'loading' => 'lazy', 'referrerpolicy' => 'strict-origin-when-cross-origin',
                    'sandbox' => 'allow-scripts allow-same-origin allow-presentation',
                    'allow' => 'encrypted-media; picture-in-picture; fullscreen',
                    'allowfullscreen' => 'allowfullscreen',
                ];
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
            } elseif (in_array($tag, ['audio', 'video'], true)) {
                $src = self::safeUrl($node->getAttribute('src'));
                if ($src === null) {
                    $parent->removeChild($node);
                    $node = $next;
                    continue;
                }
                $attrs = ['src' => $src, 'controls' => 'controls', 'preload' => 'none'];
                if ($tag === 'video') {
                    $attrs['playsinline'] = 'playsinline';
                }
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
            } elseif ($tag === 'code') {
                $class = $node->getAttribute('class');
                if (preg_match('/^language-(?:php|html|css|javascript|typescript|python|java|sql|bash|json|xml|go|rust|c|cpp|csharp|ruby|swift|kotlin|plaintext)$/D', $class)) {
                    $attrs['class'] = $class;
                }
            }
            while ($node->attributes->length) {
                $node->removeAttributeNode($node->attributes->item(0));
            }
            foreach ($attrs as $key => $value) {
                $node->setAttribute($key, $value);
            }
            if (! in_array($tag, ['iframe', 'audio', 'video'], true)) {
                self::clean($node);
            }
            $node = $next;
        }
    }

    private static function safeUrl(string $value, bool $httpsOnly = true): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f<>"]/', $value) || str_contains($value, '\\')) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), $httpsOnly ? ['https'] : ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private static function youtubeEmbed(string $value): ?string
    {
        $url = self::safeUrl($value);
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (! in_array(strtolower($parts['host'] ?? ''), [
            'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        ], true) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('~^/embed/([A-Za-z0-9_-]{11})$~D', $parts['path'] ?? '', $match)) {
            return null;
        }
        return 'https://www.youtube-nocookie.com/embed/'.$match[1];
    }
}
