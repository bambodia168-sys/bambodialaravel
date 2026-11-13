<?php

/**
 * Authentication configuration for the Bambodia MCP connection.
 *
 * Never allow "*" in production: this OAuth server is specifically
 * registered for ChatGPT's verified HTTPS OAuth callback URLs.
 * The Laravel MCP vendor config defaults to an unrestricted wildcard.
 */
return [
    'redirect_domains' => [
        'https://chatgpt.com',
    ],
    'custom_schemes' => [],
    // Fail closed by default: an administrator must copy the EXACT callback
    // URI from ChatGPT's MCP management UI before any real registration.
    // Only the callback-ID mode is supported until issuer identification
    // (RFC 9207 authorization-response iss) is independently accepted.
    // Comma-separated only for explicitly approved, unique callback IDs.
    'exact_redirect_uris' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('BAMBODIA_MCP_ALLOWED_CALLBACK_URIS', ''))
    ), static fn (string $uri): bool => $uri !== '')),
    'authorization_server' => 'https://bambodia.com',
    // Production MCP is disabled until separately approved after restore QA.
    'production_enabled' => (bool) env('BAMBODIA_MCP_ENABLED', false),
    'production_writes_enabled' => (bool) env('BAMBODIA_MCP_WRITES_ENABLED', false),
    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],
    'clients' => [],
];
