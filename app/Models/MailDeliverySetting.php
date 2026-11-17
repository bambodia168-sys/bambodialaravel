<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MailDeliverySetting extends Model
{
    public const SINGLETON = 1;

    public $timestamps = false;

    protected $hidden = ['draft', 'active'];

    protected function casts(): array
    {
        return [
            'draft' => 'encrypted:array',
            'active' => 'encrypted:array',
            'tested_at' => 'datetime',
        ];
    }
}
