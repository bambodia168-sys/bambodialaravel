<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EmailTemplate extends Model
{
    protected $primaryKey = 'event';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['event', 'enabled', 'subject', 'heading', 'body', 'button'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
