<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceCart extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'items'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
