# Bambodia Manager MCP — Native Main-Domain Integration

Status: DRAFT, source staged from current production main. No production deployment or automatic data migration.

Canonical HTTPS MCP endpoint: https://bambodia.com/mcp
OAuth issuer: https://bambodia.com
Authorization: existing main-site super-admin sign-in with consent.

## Why a separate main-domain release

As of November 12, 2026, the production main branch does not include Laravel MCP, Passport, or the MCP routes. The prior isolated QA OAuth PR #15 is 73 commits behind latest production main and includes many QA setup scripts. Do not merge that branch wholesale, overwrite the live application or proxy the main-domain MCP path to the QA hostname. Such a proxy would create wrong OAuth issuer/resource audiences and mix QA identities with production users.

This separate production-targeted branch ports the core MCP tools, authorization and connection management onto the newer main source without transferring QA users, QA records, QA credentials, RSA keys, or database tables.

## Main-domain URLs

- MCP JSON-RPC: https://bambodia.com/mcp
- Root protected-resource discovery: https://bambodia.com/.well-known/oauth-protected-resource
- Path-specific protected-resource discovery: https://bambodia.com/.well-known/oauth-protected-resource/mcp
- Authorization-server metadata: https://bambodia.com/.well-known/oauth-authorization-server
- Dynamic Client Registration: https://bambodia.com/oauth/register
- Authorization: https://bambodia.com/oauth/authorize
- Token exchange and refresh: https://bambodia.com/oauth/token
- Administrator connection page: https://bambodia.com/developer/mcp-connection
- Token review and revocation: https://bambodia.com/developer/oauth-connections

Both protected-resource metadata endpoints deliberately identify the SAME resource identifier https://bambodia.com/mcp. This prevents resource and JWT audience mismatches.

## Earlier QA rejection — what was and was not proven

The user established that QA nested metadata returned HTTP 200, root metadata returned HTTP 404, authorization-server metadata returned HTTP 200, invalid Dynamic Client Registration returned HTTP 400, and anonymous MCP returned HTTP 401. The 404 is a real discovery obstacle. However, the diagnostic expectation that malformed registration must specifically return invalid_client_metadata was too strict: invalid_redirect_uri is a normal validation error for invalid or missing redirect URIs. Do not weaken redirect URI security or discard DCR based on that 400 alone.

Production Laravel source now includes explicit root and nested protected-resource routes. Nginx and CDN must still allow the hidden well-known paths; adding a Laravel route alone does not ensure public reachability.

## Release feature gates (default off)

- BAMBODIA_MCP_ENABLED=false: disallows main-domain MCP execution, OAuth token flow and DCR registration until authorized.
- BAMBODIA_MCP_WRITES_ENABLED=false: hides mutation tools and rejects direct mutation calls even when a token contains a write scope.

The main site must initially remain disabled. For first activation enable read access only, leaving all writes off. No administrator password, APP_KEY, QA OAuth key, or QA database is reused.

## Mandatory acceptance sequence

1. Inspect the REAL aaPanel production site with the read-only support/bambodia-mcp-main-domain-readiness.sh --inspect helper. Verify the live Nginx vhost, document root, installed lock, PHP-FPM runtime, package presence, current routing, and HTTPS results without exposing production secrets.
2. Reconcile deterministic Composer dependencies and any lockfile against actual new main. QA Composer lock is not transferable. Run the full GitHub quality workflow, PHP syntax, Blade cache checks and synthetic OAuth tests.
3. Verify a fresh real production backup and perform an independent isolated database/media restore rehearsal. Check historical customers, content, ownership, permissions and existing production business functions.
4. Review production Nginx before changing it. Preserve storefront locations, HTTPS certificate, ACME challenges and PHP FastCGI configuration. If necessary add exact-match exceptions for the three well-known URLs and ensure the existing FastCGI handler forwards HTTP_AUTHORIZATION. Do not reuse the QA Nginx template.
5. Deploy only an approved, reversible release with both flags FALSE. Run separately authorized forward-only OAuth schema migrations after backup, and generate dedicated new production Passport signing keys with protected permissions. Preserve existing production APP_KEY and user accounts.
6. In a controlled release window, enable BAMBODIA_MCP_ENABLED=true while keeping writes false. Verify exact public OAuth metadata, 401 Bearer resource challenge, valid ChatGPT DCR and PKCE S256 authorization, issuer/audience, main-domain administrator consent, read-only tools, refresh and revoke.
7. Only after an independent rights/security review, enable write tools in a separate operation and accept one authorized unpublished disposable draft, checking Post Manager and revoking the test connection afterward.
8. On failure disable both flags first, revoke affected grants if needed and restore the previous application release/vhost. Never delete production OAuth tables or roll back historical customer migrations blindly.

## Current proof boundary

The separate QA site passed REAL HTTPS MCP developer-token read and unpublished draft write; the post was visible in Post Manager. ChatGPT creation failed before OAuth consent. A migrated main-domain source branch and default-disabled gates do not establish a functioning or authorized production plugin. Keep the new PR draft and production unchanged until the actual OAuth client linking and controlled production release requirements pass.


## Physical main-domain aaPanel discovery — 2026-11-12

The operator ran the pinned, read-only inspector and confirmed:
- Production Nginx vhost: /www/server/panel/vhost/nginx/bambodia.com.conf, SHA256 5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116; syntax VALID.
- Live bambodia.com document root: /www/wwwroot/bambodia-staging-qa/public (despite that directory's staging-looking name). Do NOT assume the name implies an isolated site: the production domain actually serves it. Protect its own .env, customer database, storage, media and releases.
- Application is installed; MCP controller code is already present, but vendor/laravel/mcp and vendor/laravel/passport packages are BOTH absent. Existing files are a partial integration and are not a working OAuth release.
- Public GET / returned 200, GET /mcp returned 405, GET /.well-known/oauth-protected-resource returned 404, GET /.well-known/oauth-protected-resource/mcp returned 404, GET /.well-known/oauth-authorization-server returned 404.
- Nginx vhost had no explicit root protected-resource handling. HTTP_AUTHORIZATION forwarding was not established by the direct vhost inspection (may be in included FastCGI config).
- Production app code and database, QA app and database, Nginx and PHP-FPM were unchanged by the inspector.

**Next specific task:** run one small read-only inventory support/bambodia-mcp-main-domain-install-target.sh --inventory, pinned from the current draft PR #46. Its output identifies whether that live app's APP_URL and environment match the main domain (without exposing secrets), whether a usable composer.lock exists and lists MCP/Passport packages, current deployed Git head, tracked drift count and PHP 8.4/8.5 availability. Review this once before constructing a host-specific installer. This is not repeated synthetic acceptance testing.

**Reproducible dependencies:** No composer.lock is tracked in draft PR #46. The CI workflow successfully resolves dependencies, but an operator must not run arbitrary composer update on the live site. Produce and verify a release lock/build artifact compatible with real PHP runtime, then stage it outside production before an approved rollback-capable cutover. Never blindly graft the old isolated QA composer.lock into newer main.
