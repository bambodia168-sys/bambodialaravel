<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteTypographyRule extends Model
{
    protected $fillable = [
        'language_code','element','font_family','font_size','font_weight','line_height','alignment',
    ];

    public const ELEMENTS = [
        'body'=>'Body','heading'=>'Headings','paragraph'=>'Paragraphs',
        'button'=>'Buttons','label'=>'Labels',
    ];

    public const FONTS = [
        'system'=>'Inter / System',
        'arial'=>'Arial','georgia'=>'Georgia','times'=>'Times New Roman',
        'tahoma'=>'Tahoma','verdana'=>'Verdana',
        'khmer'=>'Khmer-compatible system fonts',
        'cjk'=>'Chinese, Japanese and Korean system fonts',
    ];

    public const FONT_STACKS = [
        'system'=>'Inter,system-ui,-apple-system,sans-serif',
        'arial'=>'Arial,Helvetica,sans-serif',
        'georgia'=>'Georgia,serif',
        'times'=>'Times New Roman,Times,serif',
        'tahoma'=>'Tahoma,sans-serif',
        'verdana'=>'Verdana,sans-serif',
        'khmer'=>'Noto Sans Khmer,Khmer OS,system-ui,sans-serif',
        'cjk'=>'Noto Sans CJK SC,Noto Sans CJK JP,Meiryo,system-ui,sans-serif',
    ];

    public const SELECTORS = [
        'body'=>'body',
        'heading'=>'h1,h2,h3,h4,h5,h6',
        'paragraph'=>'p',
        'button'=>'button,.btn',
        'label'=>'label',
    ];

    protected function casts(): array
    {
        return ['font_size'=>'integer','font_weight'=>'integer','line_height'=>'float'];
    }

    public static function forLocale(string $locale): \Illuminate\Support\Collection
    {
        // Missing locale elements inherit English, then existing CSS defaults.
        $english=static::query()->where('language_code','en')->get()->keyBy('element');
        $localized=$locale==='en' ? collect() : static::query()
            ->where('language_code',$locale)->get()->keyBy('element');
        return $english->merge($localized);
    }
}
