<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

class User extends Authenticatable implements HasPasskeys
{
    use HasFactory, Notifiable, InteractsWithPasskeys;

    public const ROLES = ['super_admin', 'admin', 'staff', 'customer', 'freelancer', 'subscriber'];

    protected $fillable = ['name', 'username', 'email', 'password', 'role', 'locale', 'account_status'];

    // Eloquent instances created by tests, registration and admins must have
    // the same secure default as the database column without a reload.
    protected $attributes = ['account_status' => 'active'];

    protected $hidden = ['password', 'remember_token', 'account_review_note', 'account_review_method', 'account_verified_by'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'marketing_subscribed_at' => 'datetime', 'account_verified_at' => 'datetime', 'password' => 'hashed'];
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

    public function publicExpertise(): HasMany
    {
        return $this->hasMany(CreatorProfileItem::class)
            ->where('kind', 'expertise')
            ->where('is_visible', true)
            ->whereNull('profile_section_id')
            ->whereHas('owner.creatorProfile', fn (Builder $profile) => $profile->where('is_public', true))
            ->whereNotExists(fn ($section) => $section->selectRaw('1')
                ->from('creator_profile_sections')
                ->whereColumn('creator_profile_sections.user_id', 'creator_profile_items.user_id')
                ->where('section_key', 'expertise')
                ->where('is_visible', false))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function profileAvatarUrl(bool $public = false): ?string
    {
        if ($public && ! $this->creatorProfile?->is_public) {
            return null;
        }

        $path = $this->userProfile?->avatar_path;
        if (! filled($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    public function publishedListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class,'user_id')->publiclyAvailable();
    }

    public function scopeDiscoverableCreator(Builder $query, bool $includeAdministratorProfiles = false): Builder
    {
        $portfolioRoles = $includeAdministratorProfiles ? ['freelancer', 'super_admin'] : ['freelancer'];

        return $query->where(function (Builder $creators) use ($portfolioRoles): void {
            $creators->where(fn (Builder $portfolios) => $portfolios->whereIn('role', $portfolioRoles)
                ->whereHas('creatorProfile', fn (Builder $profile) => $profile->where('is_public', true)))
                ->orWhereHas('publishedListings');
        });
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

    /** Primary private support-ticket workspace for this account's role. */
    public function supportTicketRoutePrefix(): string
    {
        return match ($this->role) {
            'super_admin', 'admin', 'staff' => 'dashboard.support-tickets',
            'freelancer' => 'studio.support-tickets',
            default => 'account.support',
        };
    }

    public function defaultWorkspaceUrl(): string
    {
        return match (true) {
            $this->isAdministrator() => route('dashboard'),
            $this->isCreator() => route('creator'),
            default => route('account').'?='.$this->public_id,
        };
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
