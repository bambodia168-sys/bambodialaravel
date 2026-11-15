<?php

namespace App\Support;

use App\Models\MarketplaceCategory;
use Illuminate\Support\Str;

final class BambodiaTaxonomy
{
    private const LEGACY_JOB = [
        'development' => 'Development',
        'design' => 'Design',
        'writing' => 'Writing',
        'marketing' => 'Marketing',
        'business' => 'Business',
        'other' => 'Other',
    ];

    /** Real active database categories, with legacy fallback until seeded. */
    public static function jobChoices(): array
    {
        $choices = MarketplaceCategory::query()
            ->where('type', 'job')->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')->orderBy('name')
            ->pluck('name', 'slug')->all();

        return $choices ?: self::LEGACY_JOB;
    }

    /** Include old codes only for searching or editing existing jobs. */
    public static function jobFilterChoices(): array
    {
        return self::jobChoices() + self::LEGACY_JOB;
    }

    public static function jobEditorChoices(?string $current): array
    {
        $choices = self::jobChoices();
        if ($current !== null && $current !== '' && ! isset($choices[$current])) {
            $choices[$current] = self::jobFilterChoices()[$current] ?? Str::headline($current);
        }
        return $choices;
    }

    public static function jobLabel(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return 'Uncategorized';
        }
        return self::jobFilterChoices()[$slug] ?? Str::headline($slug);
    }
}
