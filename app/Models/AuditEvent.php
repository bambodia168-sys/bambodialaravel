<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditEvent extends Model
{
    public $timestamps = false;

    public const EVENTS = [
        'auth.login.success',
        'auth.login.failed',
        'auth.logout',
        'creator.application.submitted',
        'creator.application.approved',
        'creator.application.rejected',
        'user.role.changed',
        'feature.flag.changed',
        'listing.review.published',
        'listing.review.rejected',
        'policy.published',
        'license.definition.created',
        'license.definition.updated',
        'license.definition.activated',
        'media.uploaded',
        'media.deleted',
        'media.review.approved',
        'media.review.rejected',
        'product.created',
        'product.updated',
        'vault.version.uploaded',
        'vault.version.approved',
        'vault.version.rejected',
        'vault.version.disabled',
        'vault.entitlement.granted',
        'vault.entitlement.revoked',
        'vault.download.served',
        'developer.token.created',
        'developer.token.revoked',
        'developer.api.account.read',
        'developer.api.listings.read',
    ];

    protected $fillable = ['actor_id', 'event', 'ip_fingerprint', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $event, ?User $actor = null, ?Request $request = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unrecognized audit event type.');
        }

        $request ??= request();

        // Short-lived daily pseudonymous fingerprint. Do not store raw IPs or request contents.
        $ip = $request->ip();
        $fingerprint = $ip ? substr(hash_hmac('sha256', $ip.'|'.now()->toDateString(), config('app.key')), 0, 16) : null;

        self::query()->create([
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'ip_fingerprint' => $fingerprint,
            'created_at' => now(),
        ]);
    }
}
