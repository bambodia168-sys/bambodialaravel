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
        'identity.name' => ['group'=>'identity','label'=>'Site name',
            'description'=>'Official website name in browser titles and brand alternative text.',
            'type'=>'text','default'=>'Bambodia','max'=>80],
        'identity.tagline' => ['group'=>'identity','label'=>'Site tagline',
            'description'=>'Short public summary for the web-app manifest.',
            'type'=>'text','default'=>'Licensed Digital Marketplace','max'=>160],
        'identity.description' => ['group'=>'identity','label'=>'Site description',
            'description'=>'Public identity summary used as metadata fallback.',
            'type'=>'textarea','default'=>'A marketplace for licensed digital products, creators, projects and services.','max'=>1200],
        'identity.contact_phone' => ['group'=>'identity','label'=>'Contact telephone',
            'description'=>'Optional private site identity information, not automatically published.',
            'type'=>'optional_text','default'=>'','max'=>50],
        'identity.company_address' => ['group'=>'identity','label'=>'Business information',
            'description'=>'Optional registered business information. Not automatically published.',
            'type'=>'textarea','default'=>'','max'=>600],
        'seo.default_title' => ['group'=>'seo','label'=>'Default search title',
            'description'=>'Title used when the page has no specific SEO override.',
            'type'=>'text','default'=>'Bambodia · Licensed Digital Marketplace','max'=>180],
        'seo.default_description' => ['group'=>'seo','label'=>'Default meta description',
            'description'=>'Fallback description when a page provides none.',
            'type'=>'textarea','default'=>'Discover licensed digital products, independent creators, and freelance services on Bambodia.','max'=>320],
        'seo.default_keywords' => ['group'=>'seo','label'=>'Default meta keywords',
            'description'=>'Optional keywords; major search engines may ignore this field.',
            'type'=>'optional_text','default'=>'','max'=>400],
        'seo.default_robots' => ['group'=>'seo','label'=>'Default search indexing',
            'description'=>'Indexing directive for public pages without individual override.',
            'type'=>'select','default'=>'index,follow','options'=>['index,follow','noindex,nofollow']],
        'seo.default_og_image' => ['group'=>'seo','label'=>'Default social preview image',
            'description'=>'Optional public HTTPS image URL for Open Graph sharing.',
            'type'=>'url','default'=>'','max'=>400],
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
