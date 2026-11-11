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

## Resource indicators and audience verification (development implementation)

- `App\\Support\\Mcp\\McpResource` derives the resource URI from trusted `config('app.url')`, not the incoming Host header. Canonical resource: `https://bambodia.com/mcp`.
- `RequireMcpOAuthResource` applies only to Passport's `GET /oauth/authorize` and `POST /oauth/token`. Both require the exact MCP resource (scheme/host matching is case-insensitive); missing, extra-path or cross-resource values fail with `invalid_target`. Only authorization-code and refresh-token grants are accepted.
- `BambodiaPassportAccessToken` extends Passport's token entity and adds `iss` plus the MCP URI as the **second** signed JWT `aud` only when `mcp:use` is granted. The first audience remains Passport's client ID for League's token validator.
- `AuthenticateMcpToken` accepts OAuth only after Passport has verified the signature, expiry and revocation. It then binds the current signed bearer JWT to the resolved token's `jti`, client audience, exact second MCP audience and configured issuer; absent or mismatched values yield HTTP 401. Legacy revocable `bmb_` developer tokens are unaffected.
- Existing browser login and OAuth consent forms retain CSRF protection. Dynamic client registration alone has a scoped CSRF exemption and ChatGPT HTTPS callback allowlist. Only active super-admins may authorize or use MCP.
- `BambodiaTools::ownedDraftQuery` refuses cross-owner edits/deletes of listings, jobs, posts and pages. Site-wide policies have no per-user owner field and remain restricted to super-admins and existing audit/publication controls.

**Security acceptance:** Validate signed JWT issuance, PKCE S256 mismatch denial, resource-parameter absence/mismatch, Passport tokens with valid signatures but no MCP audience, refresh/revocation, account demotion, both developer-token and OAuth permissions, and cross-admin draft rejection on a disposable database. A CI pass does not replace external security review or ChatGPT browser acceptance.

## Mandatory release gates (NOT YET COMPLETED)

1. **CI + isolated MySQL migrations** — All five workflows green at the exact final source revision; MySQL install, backup, restore, rollback, no duplicate Passport migrations. Never generate Passport RSA keys in GitHub or commit them. CI makes ephemeral disposable keys.
2. **OAuth security** — The development branch now enforces `resource` at authorization and token exchange and issues signed JWTs containing both the Passport client ID and the exact Bambodia MCP URI as audiences. Middleware checks the issuer, JWT ID and audience after Passport signature/expiry/revocation verification. CI includes valid signed cross-resource rejection and missing/incorrect resource cases. **Independent security review and real hosting acceptance remain required** before claiming OAuth 2.1 production compliance.
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
