<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CreatorProfileSection extends Model
{
    public const DEFAULTS = [
        'expertise' => ['Expertise & Services', 'Show the services and professional skills you offer.'],
        'language' => ['Languages', 'List languages and proficiency levels.'],
        'link' => ['Contact & Links', 'Add HTTPS links to your portfolio and social pages.'],
    ];

    protected $fillable = ['user_id','section_key','title','description','sort_order','is_visible'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'sort_order' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function ensureDefaults(User $owner): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            $offset = array_search($key, array_keys(self::DEFAULTS), true);
            self::query()->firstOrCreate(
                ['user_id' => $owner->id, 'section_key' => $key],
                ['title' => $value[0], 'description' => $value[1], 'sort_order' => ($offset + 1) * 10, 'is_visible' => true]
            );
        }
    }
}
