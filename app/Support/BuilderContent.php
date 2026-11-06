<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BuilderContent
{
    private const ALLOWED = [
        'p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'mark',
        'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span',
        'sup', 'sub', 'font', 'iframe',
    ];

    private const DISCARDED = [
        'script', 'style', 'object', 'embed', 'form',
        'input', 'textarea', 'button', 'svg', 'math', 'img',
        'audio', 'video', 'source', 'template',
    ];

    public static function prepare(Request $request): void
    {
        $submitted = $request->input('description_html');
        if (! is_string($submitted) || trim($submitted) === '') {
            return;
        }

        if (strlen($submitted) > 100000) {
            throw ValidationException::withMessages([
                'description_html' => 'The formatted description is too long.',
            ]);
        }

        $html = self::sanitize($submitted);
        $text = self::plainText($html);
        $request->merge(['description_html' => $html, 'description' => $text]);
    }

    public static function sanitize(?string $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(
                '<?xml encoding="UTF-8"?><div id="builder-content-root">'.$html.'</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );

            $container = (new \DOMXPath($document))
                ->query('//*[@id="builder-content-root"]')?->item(0);
            if (! $container) {
                return null;
            }

            self::clean($container);
            $safe = '';
            foreach ($container->childNodes as $child) {
                $safe .= $document->saveHTML($child);
            }

            return trim($safe) ?: null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function plainText(?string $html): string
    {
        if (! $html) {
            return '';
        }
        $spaced = preg_replace(
            '~</(?:p|div|h2|h3|h4|blockquote|li|pre|tr)>|<br\s*/?>~i',
            "\n",
            $html
        );
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private static function clean(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if ($tag === 'iframe') {
                    $src = self::verifiedYoutubeEmbed((string) $node->getAttribute('src'));
                    if ($src === null) {
                        $parent->removeChild($node);
                    } else {
                        while ($node->attributes->length) {
                            $node->removeAttributeNode($node->attributes->item(0));
                        }
                        while ($node->firstChild) {
                            $node->removeChild($node->firstChild);
                        }
                        $node->setAttribute('src', $src);
                        $node->setAttribute('title', 'YouTube video preview');
                        $node->setAttribute('loading', 'lazy');
                        $node->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                        $node->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
                        $node->setAttribute('allow', 'encrypted-media; picture-in-picture; fullscreen');
                        $node->setAttribute('allowfullscreen', 'allowfullscreen');
                    }
                } elseif (in_array($tag, self::DISCARDED, true)) {
                    $parent->removeChild($node);
                } elseif (! in_array($tag, self::ALLOWED, true)) {
                    // Unwrap unknown structural elements, but keep their readable text.
                    self::clean($node);
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                } else {
                    $href = $tag === 'a' ? $node->getAttribute('href') : '';
                    $style = self::safeStyle($node->getAttribute('style'));
                    $color = $tag === 'font' ? $node->getAttribute('color') : '';
                    while ($node->attributes->length) {
                        $node->removeAttributeNode($node->attributes->item(0));
                    }
                    if ($tag === 'a' && preg_match('~^https?://[^\s<>"\x00-\x1f]+$~i', $href)) {
                        $node->setAttribute('href', $href);
                        $node->setAttribute('rel', 'nofollow noopener noreferrer');
                        $node->setAttribute('target', '_blank');
                    }
                    if ($tag === 'font' && preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color)) {
                        $node->setAttribute('color', $color);
                    }
                    if ($style !== '') {
                        $node->setAttribute('style', $style);
                    }
                    self::clean($node);
                }
            } elseif (! in_array($node->nodeType, [XML_TEXT_NODE], true)) {
                $parent->removeChild($node);
            }
            $node = $next;
        }
    }

    /**
     * Only the canonical HTTPS YouTube embed path is executable content.
     * Raw third-party iframes, attributes, query strings and scripts are removed.
     */
    private static function verifiedYoutubeEmbed(string $source): ?string
    {
        $parts = parse_url(trim($source));
        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), [
                'youtube.com', 'www.youtube.com',
                'youtube-nocookie.com', 'www.youtube-nocookie.com',
            ], true)
            || isset($parts['port'], $parts['user'])
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! preg_match('~^/embed/([A-Za-z0-9_-]{11})$~D', $parts['path'] ?? '', $matches)) {
            return null;
        }
        $query = $parts['query'] ?? '';
        if ($query !== '' && ! preg_match('/^start=([0-9]{1,5})$/D', $query, $time)) {
            return null;
        }
        return 'https://www.youtube-nocookie.com/embed/'.$matches[1]
            .($query !== '' ? '?start='.(int)$time[1] : '');
    }

    private static function safeStyle(string $input): string
    {
        $allowed = [];
        foreach (explode(';', $input) as $part) {
            if (! str_contains($part, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $part, 2));
            $key = strtolower($key);
            if ($key === 'text-align' && in_array(strtolower($value), ['left', 'center', 'right', 'justify'], true)) {
                $allowed[] = 'text-align:'.strtolower($value);
            } elseif (in_array($key, ['color', 'background-color'], true)) {
                $color = self::normalizeColor($value);
                if ($color !== null) {
                    $allowed[] = $key.':'.$color;
                }
            }
        }

        return implode(';', $allowed);
    }

    private static function normalizeColor(string $value): ?string
    {
        if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $value)) {
            return strtolower($value);
        }
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $m)) {
            if ((int)$m[1] <= 255 && (int)$m[2] <= 255 && (int)$m[3] <= 255) {
                return sprintf('#%02x%02x%02x', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
        }

        return null;
    }
}
