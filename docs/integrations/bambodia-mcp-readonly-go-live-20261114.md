# Bambodia MCP — read-only go-live path (MCP-only)

**Requested:** 2026-11-14. **Scope:** enable ChatGPT ↔ Bambodia MCP; no Cloudflare R2, Restic, database backup policy, private-file remediation or unrelated marketplace changes.

**Canonical MCP endpoint:** `https://bambodia.com/mcp`. **OAuth issuer:** `https://bambodia.com`. **Initial access:** read-only, `BAMBODIA_MCP_WRITES_ENABLED=false`.

## Immediate release reality

The existing owner-accepted private candidate passed PHP 8.5 OAuth/HTTP loopback testing, but it was NOT installed on the live app. Last verified live app document root is `/www/wwwroot/bambodia-staging-qa/public` despite its name. Last physical host probes found `vendor/laravel/mcp` and `vendor/laravel/passport` absent, OAuth schema absent, public discovery endpoints 404, and the real site's source containing live-only changes that make a wholesale GitHub source replacement unsafe. A successful synthetic CI run cannot enable a production endpoint.

**Do not directly merge PR #46's 92 files or replace live code**: it includes unrelated recovery tooling and preserves pre-existing live-only assets and marketplace content only in the private overlay. The previously accepted live-preserving candidate is at `/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp`; it must be freshly reconciled against CURRENT live source before any PHP/Passport/OAuth release install.

## Host-only guarded switch implemented

`support/bambodia-mcp-readonly-go-live-20261114.py` supports:
- `--inspect`: no filesystem writes; verifies the actual live app, root-owned operator context, expected Nginx document root, PHP 8.5, installed MCP and Passport vendor, Passport signing keys, live MCP write-gate source, uncached configuration, exact **approved** ChatGPT callback `https://chatgpt.com/connector/oauth/{id}`, both public OAuth protected-resource metadata routes, authorization-server issuer, and status of required OAuth migrations using read-only `artisan migrate:status` under the `www` account. Any failure is listed as `MCP_ONLY_*_BLOCKED`.
- `--enable-readonly`: performs the very same mandatory checks first and refuses ALL changes if any are missing. Only after a local typed operator confirmation does it safely preserve the existing root-owned `.env` in a root-private `0700` rollback directory with a `0600` original, atomically update only `BAMBODIA_MCP_ENABLED=true` and force `BAMBODIA_MCP_WRITES_ENABLED=false` (without modifying other environment variables), and probe the public anonymous JSON-RPC endpoint for a genuine HTTP 401 Bearer challenge. On failed challenge, atomically restores the original `.env` (or emits a CRITICAL manual rollback warning). It never installs Composer dependencies, creates Passport keys, applies database migrations, enables write tools, touches Nginx, uploads backups, or merges PRs.

This switch is **not a release installer**: packages, schema, route reachability and approved exact callback must already exist in production. The host must confirm that the live source was independently validated and rollback-able. The initial inspection is expected to show missing vendor/schema/metadata blockers until the reconciled MCP-only release is deployed.

## Minimum MCP-only go-live sequence

1. Recheck live-vs-private source drift. Preserve all newer frontend/marketplace features and current Vite assets; resolve any newly changed PHP/Blade conflicts. Use the previously accepted private PHP 8.5 OAuth candidate and no unrelated release modules.
2. Prepare a reversible MCP/Passport deployment artifact that installs the reviewed application code, resolved Composer lock/vendor packages, OAuth routes and migrations and new production Passport signing keys **without** replacing production `.env`, APP_KEY, existing users, media, or public content. Ensure Nginx routes `/.well-known/` to Laravel and forwards the bearer Authorization header to PHP-FPM. Verify authentication and OAuth in a representative isolated clone, and preserve a minimal operational rollback point; separate full R2 recovery work remains on hold.
3. Copy the exact ChatGPT callback URI from ChatGPT's MCP connector configuration into `BAMBODIA_MCP_ALLOWED_CALLBACK_URIS` through the host's protected configuration process. Never use a wildcard or guess a callback ID. Do not paste a secret into GitHub/chat.
4. Run the pinned `--inspect` operator tool. Do not invoke `--enable-readonly` until every prerequisite reads PASS. Then, with the operator's existing activation authorization and local typed confirmation, run `--enable-readonly`. It forces writes off and keeps an original root-only environment rollback copy.
5. Independently complete ChatGPT OAuth DCR → PKCE S256 → existing admin sign-in/consent → `tools/list`/read-only `tools/call` → refresh and revoke. Any failed authenticity, ownership, write-denial, expiry, replay or callback test is a release blocker; turn the flag off and re-evaluate before retrying.

**Explicit status:** MCP-only activation script STAGED. Real production dependencies, OAuth metadata, migrations, callback and end-user ChatGPT connection NOT yet verified as deployed. **Never claim MCP enabled from GitHub CI or a root-only preflight alone.**
