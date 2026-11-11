# Bambodia Manager — OAuth 2.1 implementation and release acceptance

Status: **DEVELOPMENT ONLY** on `feature/bambodia-oauth21-mcp-20261111` / draft PR #15.
The live production site remains on bearer-token MCP foundation `021cc1da048f6d4c4787b717e09b46378d754238`.
Never run OAuth migrations, generate Passport keys, or overwrite production PHP files until the independent QA gates below are complete.

## Architecture

- The current production `POST https://bambodia.com/mcp` controller is preserved, with the same four read and six write tools.
- Laravel Passport 13 implements OAuth authorization-code+PKCE, short-lived bearer tokens and refresh-token grants.
- Laravel MCP implements OAuth protected-resource and authorization-server discovery and client registration.
- A custom `mcp.auth` middleware accepts (a) an active, `mcp.read` scoped hashed `bmb_` developer token or (b) an active Passport bearer token with the explicit `mcp:use` scope. Only `mcp.write` may expose mutations. Never authenticate MCP from a Laravel web session.
- All requests require a live `super_admin` user with `account_status=active`. Cookie sessions only serve login/consent.
- Token lifetimes: Passport access tokens 30 minutes, refresh tokens 14 days. A self-service Developer Workspace requires password confirmation to revoke both access and refresh tokens.
- Dynamic client registration is restricted to HTTPS redirect URIs starting `https://chatgpt.com/` by configuration. The login/consent endpoint uses CSRF; `POST /oauth/register` explicitly opts out because it is a machine-to-machine endpoint.
- ChatGPT may request callback-specific `https://chatgpt.com/connector/oauth/{callback_id}` or stable `https://chatgpt.com/connector_platform_oauth_redirect`, depending on the issuer's metadata capabilities. The live ChatGPT UI is authoritative; never invent callback IDs.

## OAuth discovery (development release)

- Protected MCP resource: `https://bambodia.com/mcp`.
- Challenge metadata URL: `https://bambodia.com/.well-known/oauth-protected-resource/mcp`, whose `resource` must be the exact MCP URL.
- Authorization metadata URL: `https://bambodia.com/.well-known/oauth-authorization-server` with `code_challenge_methods_supported: ["S256"]`.
- Authorization endpoint: `/oauth/authorize`.
- Public OAuth client registration: `POST /oauth/register`.
- Token endpoint: `/oauth/token` (public client, PKCE S256, `token_endpoint_auth_method=none`).
- Existing `POST /mcp` still returns 401 with `WWW-Authenticate` when the user has no bearer token. Failed scope checks never reveal tokens, hashes or customer PII.

## Mandatory release gates (NOT YET COMPLETED)

1. **CI + isolated MySQL migrations** — All five workflows green at the exact final source revision; MySQL install, backup, restore, rollback, no duplicate Passport migrations. Never generate Passport RSA keys in GitHub or commit them. CI makes ephemeral disposable keys.
2. **OAuth security** — Independently verify the OAuth `resource` parameter is honored at both authorization and token exchange and that tokens are audience-restricted to `https://bambodia.com/mcp`; reject cross-resource tokens. Passport's default JWT `aud` identifies a client rather than this resource, so **do not accept audience compliance based only on Passport signature+scope checks**. Document and implement resource binding before claiming fully compliant OAuth 2.1 / enabling ChatGPT.
3. **PKCE + consent** — Test valid and invalid S256 verifier, exact registered callback URI, unpredictable `state`, consent approval/denial, unapproved clients, and role change during consent.
4. **Revocation** — Test a real authorization-code exchange, access token, refresh, rotation/revocation, password-confirmed revocation, expiry, downgrade and removed administrator.
5. **MCP read** — Test real authenticated `tools/call` for `bambodia_overview`, `bambodia_list`, `bambodia_get`, `bambodia_analytics` against scoped isolated records.
6. **MCP write** — Test all six write tools exclusively against disposable drafts; deny cross-owner edits, publishing, unreviewed media and unconfirmed active SEO/social changes.
7. **Real aaPanel staging** — Backup files/MySQL, preserve the installed `/www/wwwroot/bambodia-staging-qa` runtime and security-alert modules, generate Passport private keys using `php artisan passport:keys` with tight OS permissions, migrate only on an authorized isolated clone, audit route registration and DCR rate limits.
8. **ChatGPT workspace** — Test an actual OAuth consent/PKCE/login/refresh session, tool discovery and at least one authorized read call inside the user's eligible ChatGPT workspace. Availability/permission is plan dependent; successful raw curl requests alone do not prove plugin readiness.
9. **Production** — Release only after explicit acceptance and protected deployment helper with rollback. PR #15 remains draft until then.

## Rollback safety

The current live MCP server is working. No production Composer install, Passport migration, key generation, oauth grants, plugin registration, PR merges or aaPanel deployments have been performed as part of this development branch. Production must not be changed merely to make an integration test green.

Previously verified live command: `POST /mcp` with a revocable read-only `bmb_` token returned HTTP 200 and four tools; without credentials returned 401. Continue using existing token-based MCP until OAuth acceptance passes.
