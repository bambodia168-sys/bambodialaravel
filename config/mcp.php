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
