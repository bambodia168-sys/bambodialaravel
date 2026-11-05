<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    protected $fillable = ['key','value','updated_by'];

    public const DEFINITIONS = [
        'appearance.hero_eyebrow' => [
            'group'=>'appearance','label'=>'Homepage eyebrow',
            'description'=>'Short introductory label displayed above the homepage headline.',
            'type'=>'text','default'=>'A marketplace for independent work','max'=>120,
        ],
        'appearance.hero_title_en' => [
            'group'=>'appearance','label'=>'Homepage headline (English)',
            'description'=>'The main homepage title shown to visitors using English.',
            'type'=>'text','default'=>'Digital creations and expert talent, in one place.','max'=>180,
        ],
        'appearance.hero_intro_en' => [
            'group'=>'appearance','label'=>'Homepage introduction (English)',
            'description'=>'The introductory paragraph on the homepage for English visitors.',
            'type'=>'text','default'=>'Discover licensed digital products, freelancers, projects and services.','max'=>600,
        ],
        'appearance.hero_search_enabled' => [
            'group'=>'appearance','label'=>'Show homepage search',
            'description'=>'Show or hide the homepage search form, without changing access to the actual search endpoint.',
            'type'=>'bool','default'=>'1',
        ],
        'appearance.hero_categories_enabled' => [
            'group'=>'appearance','label'=>'Show homepage categories',
            'description'=>'Show or hide the four marketplace type quick links on the homepage.',
            'type'=>'bool','default'=>'1',
        ],
        'site.default_theme' => [
            'group'=>'system','label'=>'Default display theme',
            'description'=>'Theme used by visitors without an existing light/dark preference.',
            'type'=>'select','default'=>'light','options'=>['light','dark'],
        ],
        'site.default_locale' => [
            'group'=>'system','label'=>'Default guest language',
            'description'=>'Language used for guests who have not selected a preferred language.',
            'type'=>'select','default'=>'en','options'=>['en','zh','km','ko','ja','th','vi','de'],
        ],
        'site.support_email' => [
            'group'=>'system','label'=>'Public support email',
            'description'=>'Optional mailto contact displayed in the public footer. This does not configure SMTP.',
            'type'=>'email','default'=>'','max'=>254,
        ],
    ];

    public static function setting(string $key, ?string $default=null): string
    {
        if(!array_key_exists($key,self::DEFINITIONS)){
            throw new \InvalidArgumentException('Unknown site setting.');
        }

        return (string)(self::query()->whereKey($key)->value('value')
            ?? $default ?? self::DEFINITIONS[$key]['default']);
    }

    public static function enabled(string $key): bool { return self::setting($key)==='1'; }
}
