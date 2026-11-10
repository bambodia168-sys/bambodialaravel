<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteSocialLink extends Model
{
    public const PLATFORMS = [
        'website' => 'Website',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'telegram' => 'Telegram',
        'github' => 'GitHub',
        'pinterest' => 'Pinterest',
    ];

    protected $fillable = ['platform', 'label', 'url', 'sort_order', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function iconName(): string
    {
        // Supported real SVGs only: never silently render placeholder circles.
        return $this->platform === 'github' ? 'github' : 'globe';
    }
}
