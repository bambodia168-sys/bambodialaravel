<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercePaymentMethod extends Model
{
    protected $fillable = ['name', 'available'];

    protected function casts(): array
    {
        return ['available' => 'boolean'];
    }
}
