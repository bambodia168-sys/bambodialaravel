<?php

namespace App\Support;

final class PolicyContent
{
    public static function sanitize(string $html): string
    {
        // Reuse the existing strict DOM sanitizer, but exclude rich-media embeds
        // from legally binding content and strip non-document elements.
        $safe = BuilderContent::sanitize($html) ?? '';
        return trim(preg_replace('~<iframe\b[^>]*>.*?</iframe>~is', '', $safe));
    }

    public static function display(string $body, string $format = 'markdown'): string
    {
        return $format === 'html' ? self::sanitize($body) : self::markdown($body);
    }

    public static function markdown(string $text): string
    {
        $html = '';
        $list = null;
        $close = function () use (&$html, &$list): void {
            if ($list !== null) {
                $html .= '</'.$list.'>';
                $list = null;
            }
        };
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                $close();
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/u', $line, $m)) {
                $close();
                $tag = 'h'.strlen($m[1]);
                $html .= '<'.$tag.'>'.e($m[2]).'</'.$tag.'>';
                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/u', $line, $m) || preg_match('/^\d+[.)]\s+(.+)$/u', $line, $m)) {
                $tag = preg_match('/^\d+[.)]\s+/u', $line) ? 'ol' : 'ul';
                if ($list !== $tag) {
                    $close();
                    $list = $tag;
                    $html .= '<'.$tag.'>';
                }
                $html .= '<li>'.e($m[1]).'</li>';
                continue;
            }
            $close();
            $html .= '<p>'.e($line).'</p>';
        }
        $close();
        return $html;
    }

    public static function plain(string $html): string
    {
        return BuilderContent::plainText($html);
    }
}
