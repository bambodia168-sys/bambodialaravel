<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class UserTwoFactor extends Model
{
    protected $primaryKey='user_id';
    public $incrementing=false;

    protected $fillable=['user_id','secret','recovery_hashes','last_counter','enabled_at','authenticator_name','authenticator_key'];

    protected $hidden = ['secret', 'recovery_hashes'];

    public function devices(): HasMany
    {
        return $this->hasMany(UserTwoFactorDevice::class, 'user_id');
    }

    public function authenticatorKey(): string
    {
        return $this->authenticator_key ?? 'legacy';
    }

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
