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

## Physical aaPanel result: runtime not installed (2026-11-14)

Operator executed the pinned `--enable-readonly` tool at HEAD `3715d07a00b1530b5dd032d2db5d06842d6377da` after verifying six commit-specific CI runs and source blob integrity. **Activation correctly stopped before any config change.** Host passed root identity, live app, Nginx root, PHP 8.5, FastCGI Authorization forwarding, and no config cache. It failed on installed MCP/Passport vendor packages, MCP gate-aware config/controller, Passport signing keys, exact approved ChatGPT callback, both public protected-resource metadata endpoints, auth-server issuer and OAuth schema. Result: `MCP_ONLY_ACTIVATION=BLOCKED_INCOMPLETE_LIVE_RUNTIME`. This is an installation gap, not an authorization request.

The correct next step is the **live-preserving MCP-only runtime install**. Do not repeat the switch or set `BAMBODIA_MCP_ENABLED=true` manually. The private two-Blade release candidate at `/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp` passed real isolated PHP 8.5 HTTP/OAuth QA previously, but it must be revalidated against current live source.

Committed `support/bambodia-mcp-minimal-runtime-stage-20261114.py --stage`. It verifies the pinned real Nginx vhost, root-private prior candidate and the exact previously tested OAuth blobs and Composer lock, protects the nine current live MCP touchpoint snapshots, and stages only an explicit **36-file MCP/OAuth source/migration/UI allowlist**. Unrelated global homepage/blog CSS and Vite assets are deliberately **not read as activation prerequisites or copied** under a new root-only installation preparation directory. It excludes recovery, public homepage/blog, unrelated marketplace features, preview-visibility and any live `.env`/storage/database content. It records a metadata-only difference inventory and references the existing privately installed MCP/Passport vendor; it **does not install** vendor, change live code, apply OAuth migrations or enable the feature. A changed protected MCP file blocks stage and requires a targeted private rebase rather than overwriting newer production work. Global frontend changes are left untouched.

After the physical stage succeeds, review the exact existing-file change inventory and candidate package diff, build a narrowly scoped **reversible installer** against current host runtime, then install MCP/Passport and forward-only OAuth schema in a controlled deployment window (with minimal relevant rollback). Run `--inspect` then `--enable-readonly` only after those prerequisites pass. Keep write tools off. Actual ChatGPT OAuth linking remains a separate final acceptance.

**Current production MCP: not enabled.** This work excludes further R2 backup planning per owner's explicit scope direction.

## Physical MCP-only staging acceptance — ten code differences (2026-11-14)

The operator successfully executed the SHA-pinned private-only 36-file stage on aaPanel from draft head `1a9cf4fd12bf2db8edde8bbbe91a73cd70275c79` (6/6 commit-specific CI PASS). Sanitized actual output:
- `MCP_ONLY_PROTECTED_LIVE_SOURCE=UNCHANGED`, `MCP_ONLY_UNRELATED_GLOBAL_FRONTEND=NOT_COPIED_OR_MODIFIED`
- `MCP_ONLY_PATCH_FILES_VERIFIED=36`, `MCP_ONLY_EXISTING_FILES_DIFFER=10`
- `MCP_ONLY_PRIVATE_STAGE=CREATED`, at root-private `/root/Bambodia-MCP-Only-Install.kw4pjkmi`
- `MCP_ONLY_INSTALLABLE_RELEASE=NOT_YET_APPROVED`; no production files, vendor, migrations, environment or feature flags touched.

**Immediate installation compatibility step:** `support/bambodia-mcp-compatibility-review-readonly-20261114.py --inspect` inspects only the immutable root-private stage, its expected 36 file SHA-256 values, the nine live MCP-critical snapshots, and the ten currently modified source files, plus the Composer lockfile package inventories. It prints code filenames and aggregate changed line counts (not source contents) and reports whether Composer adds dependencies without altering/removing existing dependency versions. It does not copy vendor, install packages, run PHP, connect to MySQL, modify the live website, enable MCP, touch credential files or R2. An unsafe source change or hash mismatch blocks; a non-additive Composer change requires explicit dependency reconciliation before a live vendor swap.

This is the final **code-compatibility review**, not another disaster-recovery exercise. When code/Composer compatibility is proven, a narrowly scoped installer will be prepared that preserves all ten known old live versions and the existing vendor/Composer state for rollback, applies only the tested MCP files and OAuth schema, checks public discovery/authentication endpoints, then permits read-only MCP activation with writes disabled and independent ChatGPT OAuth acceptance.

**No user authorization to skip the ten-file compatibility gate or overwrite newer production code should be inferred from the request to enable MCP.**
