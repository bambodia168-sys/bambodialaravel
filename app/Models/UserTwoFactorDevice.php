<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UserTwoFactorDevice extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'secret', 'last_counter'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'last_counter' => 'integer'];
    }
}
