<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteTranslationEntry extends Model
{
    protected $fillable = ['language_code','translation_key','translation_value','is_translated'];

    protected function casts(): array
    {
        return ['is_translated'=>'boolean'];
    }
}
