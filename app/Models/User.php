<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['super_admin', 'admin', 'staff', 'customer', 'freelancer', 'subscriber'];

    protected $fillable = ['name', 'username', 'email', 'password', 'role', 'locale', 'account_status'];

    // Eloquent instances created by tests, registration and admins must have
    // the same secure default as the database column without a reload.
    protected $attributes = ['account_status' => 'active'];

    protected $hidden = ['password', 'remember_token', 'account_review_note', 'account_review_method', 'account_verified_by'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'account_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->public_id) {
                return;
            }
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = (string) random_int(10000000, 99999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $user->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Could not allocate a unique public user ID.');
        });
    }

    public function userProfile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function creatorProfile(): HasOne
    {
        return $this->hasOne(CreatorProfile::class);
    }

    public function publishedListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class,'user_id')->publiclyAvailable();
    }

    public function savedJobs(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }

    public function savedListings(): HasMany
    {
        return $this->hasMany(SavedListing::class);
    }

    public function resume(): HasOne
    {
        return $this->hasOne(FreelancerResume::class);
    }

    public function isAdministrator(): bool
    {
        return in_array($this->role, ['super_admin', 'admin', 'staff'], true);
    }

    public function isCreator(): bool
    {
        return in_array($this->role, ['freelancer', 'super_admin'], true);
    }
}
