<?php

namespace App\Support\Mcp;

/**
 * Canonical resource identifier for OAuth RFC 8707 / MCP audience validation.
 *
 * Derived from the configured application origin, never the untrusted Host
 * header. The only resource this authorization server can issue for is /mcp.
 */
final class McpResource
{
    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function uri(): string
    {
        return self::issuer().'/mcp';
    }

    public static function matches(mixed $supplied): bool
    {
        if (! is_string($supplied) || $supplied === '') {
            return false;
        }

        $requested = parse_url($supplied);
        $expected = parse_url(self::uri());
        if (! is_array($requested) || ! is_array($expected)
            || isset($requested['user'], $requested['pass'])
            || isset($requested['query']) || isset($requested['fragment'])) {
            return false;
        }

        // Match the whole resource URI, not just the host. HTTP localhost and
        // arbitrary API paths must never authorize this MCP server.
        return strtolower((string) ($requested['scheme'] ?? '')) === strtolower((string) ($expected['scheme'] ?? ''))
            && strtolower((string) ($requested['host'] ?? '')) === strtolower((string) ($expected['host'] ?? ''))
            && ($requested['path'] ?? '') === ($expected['path'] ?? '')
            && ((int) ($requested['port'] ?? (strtolower((string) ($requested['scheme'] ?? '')) === 'https' ? 443 : 80)))
                === ((int) ($expected['port'] ?? (strtolower((string) ($expected['scheme'] ?? '')) === 'https' ? 443 : 80)));
    }

    /**
     * Call only AFTER Passport has verified the JWT signature, lifetime and
     * database revocation. This inspects the exact already-validated bearer,
     * never treating an unsigned or independently decoded JWT as identity.
     */
    public static function hasAudience(string $bearer, string $tokenId, string $clientId): bool
    {
        $parts = explode('.', $bearer);
        if (count($parts) !== 3 || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]) !== 1) {
            return false;
        }

        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($raw === false) {
            return false;
        }

        $claims = json_decode($raw, true);
        if (! is_array($claims) || ! is_array($claims['aud'] ?? null)
            || count($claims['aud']) !== 2 || ! is_string($claims['jti'] ?? null)
            || ! is_string($claims['iss'] ?? null)) {
            return false;
        }

        return hash_equals($tokenId, $claims['jti'])
            && hash_equals(self::issuer(), $claims['iss'])
            && hash_equals($clientId, (string) ($claims['aud'][0] ?? ''))
            && hash_equals(self::uri(), (string) ($claims['aud'][1] ?? ''));
    }
}
