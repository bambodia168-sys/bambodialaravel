<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    public const SCOPES = ['account.read', 'listings.read', 'mcp.read', 'mcp.write'];

    protected $fillable = ['user_id', 'name', 'prefix', 'token_hash', 'scopes', 'expires_at', 'revoked_at', 'last_used_at'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** @return array{0: self, 1: string} The secret is returned only at creation. */
    public static function issue(User $owner, string $name, array $scopes, int $days): array
    {
        $secret = 'bmb_'.bin2hex(random_bytes(32));
        $token = self::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'prefix' => substr($secret, 0, 20),
            'token_hash' => hash('sha256', $secret),
            'scopes' => $scopes,
            'expires_at' => now()->addDays($days),
        ]);

        return [$token, $secret];
    }

    public static function authenticate(string $secret): ?self
    {
        if (preg_match('/^bmb_[a-f0-9]{64}$/D', $secret) !== 1) {
            return null;
        }

        $token = self::query()->with('owner')->where('prefix', substr($secret, 0, 20))->first();
        if ($token === null
            || ! hash_equals($token->token_hash, hash('sha256', $secret))
            || ! $token->isActive()
            || $token->owner?->role !== 'super_admin') {
            return null;
        }

        return $token;
    }
}
