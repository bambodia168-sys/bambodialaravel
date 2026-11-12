<?php

namespace App\Support\Mcp;

/**
 * A scope-only adapter for Passport-issued bearer access tokens.
 * We intentionally do not persist or expose the OAuth token's secret.
 */
final class McpAccessGrant
{
    public function __construct(private readonly bool $mayWrite) {}

    public function allows(string $scope): bool
    {
        return $scope === 'mcp.read' || ($scope === 'mcp.write' && $this->mayWrite);
    }
}
