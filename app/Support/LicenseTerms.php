<?php

namespace App\Support;

final class LicenseTerms
{
    public static function sanitize(string $html): string
    {
        return PolicyContent::sanitize($html);
    }

    public static function plain(string $html): string
    {
        return PolicyContent::plain($html);
    }

    public static function render(string $plain, ?string $html): string
    {
        if ($html !== null) {
            return self::sanitize($html);
        }

        // Existing plain terms retain literal punctuation and line breaks.
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        return implode('', array_map(
            fn (string $paragraph): string => '<p>'.nl2br(e($paragraph)).'</p>',
            preg_split('/\n[ \t]*\n+/', trim($plain))
        ));
    }
}
