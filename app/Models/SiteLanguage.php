<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteLanguage extends Model
{
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;

    // The route catalog is code-reviewed. Only enabled languages appear to visitors.
    public const CATALOG = [
        'en'=>'English','zh'=>'中文','km'=>'ខ្មែរ','ko'=>'한국어','ja'=>'日本語',
        'th'=>'ไทย','vi'=>'Tiếng Việt','de'=>'Deutsch',
        'fr'=>'Français','hi'=>'हिन्दी','id'=>'Bahasa Indonesia',
        'ms'=>'Bahasa Melayu','es'=>'Español',
    ];

    protected $fillable = ['code','name','is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled'=>'boolean'];
    }

    public static function activeCodes(): array
    {
        $codes = static::query()->where('is_enabled',true)->pluck('code')->all();
        return array_values(array_unique(['en', ...$codes]));
    }
}
