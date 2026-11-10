<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserTwoFactor extends Model
{
    protected $primaryKey='user_id';
    public $incrementing=false;

    protected $fillable=['user_id','secret','recovery_hashes','last_counter','enabled_at'];

    protected function casts(): array
    {
        return [
            'secret'=>'encrypted',
            'recovery_hashes'=>'encrypted:array',
            'last_counter'=>'integer',
            'enabled_at'=>'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
