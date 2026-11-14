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

## Confirmed live installation and private Composer lock preparation — 2026-11-12

The operator's read-only inventory confirmed the main website actually serves /www/wwwroot/bambodia-staging-qa/public. Despite its staging-looking directory name, it is the live bambodia.com application. The app has a regular production environment, APP_DEBUG disabled, a valid installation marker and existing vendor/autoload.php. Laravel MCP and Passport packages and signing keys remain absent. Both MCP feature switches are disabled or unset.

The installed composer.json Git blob is 457992551f4f234ad442d9db5ad73c8b54689e7b, exactly matching GitHub main. Its existing composer.lock is not tracked on the current PR and has SHA256 ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03. The live root is NOT a Git checkout; a live git pull is inappropriate. PHP 8.4.26 and 8.5.11 are available.

Prepared operator-only script support/bambodia-mcp-main-domain-isolated-lock.sh --prepare-lock. Before any write it verifies the exact vhost hash 5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116, actual production document root, live composer.json Git blob, existing composer.lock SHA256, and direct PHP 8.4 + /usr/bin/composer source entrypoint (not the PHP 8.3 shell wrapper). It requires the operator to type PREPARE-BAMBODIA-MCP-LOCK.

After approval it creates a randomly named root-private directory under /root/Bambodia-MCP-Main-Lock.XXXXXXXX, copies only the live composer.json and composer.lock, fetches a pinned main-domain composer.json and verifies it adds only laravel/mcp:^1.1 and laravel/passport:^13.10. Composer runs only inside that new directory using private HOME/CACHE/TMP and --no-install --no-scripts --no-plugins, generating a lock candidate, not a vendor installation. It lists package delta counts, specific newly locked versions, and candidate lock SHA256. If Composer fails, its log stays private for review. No production source, vendor, .env, MySQL, Nginx or OAuth credentials are modified.

A resolved candidate lock is NOT an approved live release. Next: independently review package/version changes, create and verify an isolated vendor/source bundle, rehearse real production backups and restore, and review the host-specific Nginx .well-known locations and Authorization forwarding. Only afterward plan an operator-approved deployment with MCP and writes default OFF, then a separate read-only activation and real ChatGPT OAuth acceptance.

## Isolated Composer lock acceptance — operator output, November 12, 2026

The operator completed `support/bambodia-mcp-main-domain-isolated-lock.sh --prepare-lock` on aaPanel with explicit `PREPARE-BAMBODIA-MCP-LOCK`. The original live production application at `/www/wwwroot/bambodia-staging-qa` remained unchanged; the output confirms no production Artisan/MySQL, Composer vendor installation, Nginx or APP_KEY/OAuth activation.

Accepted private lock directory: `/root/Bambodia-MCP-Main-Lock.BpgOtvFv`; pinned lock SHA256 `4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8`. Original live lock SHA256 remains `ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03`. The release manifest differs by exactly `laravel/mcp:^1.1` and `laravel/passport:^13.10` from the installed production `composer.json`, using PHP 8.4.26.

Original lock has **110** packages; candidate lock **129** packages. Added **19**, removed **0**, updated **6** package versions. The six updates are `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `laravel/framework`, `laravel/prompts`, `laravel/serializable-closure`, and `nunomaduro/termwind`. Resolved `laravel/mcp v1.2.0` and `laravel/passport v13.11.0`. The six changed packages require normal regression acceptance before release; notably, the Laravel framework itself was updated.

Next: `support/bambodia-mcp-main-domain-isolated-vendor.sh --build` checks the installed live site is still at the same manifest/lock/vhost baseline and the private candidate lock exactly matches the approved SHA and package-delta. With explicit operator approval it copies only candidate Composer files into a new root-private build directory and executes PHP 8.4 Composer `install --no-dev --no-scripts --no-plugins --prefer-dist --optimize-autoloader` there. It verifies the generated autoload file and installed MCP/Passport versions. On failure, private logs are kept under `/root` without printing their contents. This does **not** assemble the full source release, run production app/artisan or migrate/activate any production OAuth data. Full source/vendor staging, backup+restore rehearsal, Nginx OAuth route handling and read-only ChatGPT authorization still remain mandatory before production release.

## Isolated vendor dependency build accepted — 2026-11-13

The operator executed `support/bambodia-mcp-main-domain-isolated-vendor.sh --build` with explicit `BUILD-BAMBODIA-MCP-VENDOR` approval and reported `ISOLATED_VENDOR_BUILD=PASS`.

Private vendor directory: `/root/Bambodia-MCP-Main-Vendor.93QHfsxh`, containing Composer's optimized autoloader and **96 production packages** (`--no-dev`) including `laravel/mcp v1.2.0` and `laravel/passport v13.11.0`. Its Composer lock SHA256 remains `4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8`. The package version changes from the live installed baseline were independently printed: guzzlehttp/guzzle `8.3.1 → 8.3.2`, guzzlehttp/psr7 `3.2.0 → 3.3.0`, laravel/framework `v13.40.0 → v13.42.0`, laravel/prompts `v0.3.26 → v0.3.27`, laravel/serializable-closure `v2.1.0 → v2.2.0`, nunomaduro/termwind `v2.4.0 → v2.4.1`. No installed packages removed. Composer scripts and plugins were disabled. The actual production source, vendor, lockfile, MySQL, Passport keys and Nginx were unchanged.

Next operator-only stage: `support/bambodia-mcp-main-domain-isolated-source-bundle.sh --stage`, pinned to approved source SHA `e44227b0a886a1b65f0355a92ae7b704edf225ed` and exact existing private vendor/Composer SHA. It guards the approved live application/vhost/Composer baselines, checks the isolated vendor package versions, requires operator to type `STAGE-BAMBODIA-MCP`, retrieves the GitHub source archive for the immutable commit, validates archive paths and file types (rejecting symlinks and special files) before extraction into a fresh `/root/Bambodia-MCP-Main-Source.XXXXXXXX` directory, and attaches only the private approved Composer lock and the already built vendor. Checks critical code file blobs and keeps all source private. Never copies live .env, user records, media, MySQL, Passport keys, Nginx or live vendor, and does not execute Laravel or install anything on the website.

**Significant remaining frontend gate:** The GitHub PR lacks tracked `public/build/manifest.json` and `package-lock.json`. A source/vendor candidate is **NOT yet a deployable release**. Create a pinned Vite/frontend release from a reviewed dependency lockfile or explicitly validated already-built production assets, and then run isolated UI compatibility checks before a real release. Backup/restore reconciliation of live marketplace data, host-specific protected-resource routing/Authorization forwarding, rollback-safe deployment and a separate OAuth read-only activation remain outstanding.

## Isolated source+vendor staging accepted; frontend asset reuse candidate — November 13, 2026

The operator executed `support/bambodia-mcp-main-domain-isolated-source-bundle.sh --stage` and typed `STAGE-BAMBODIA-MCP`. GitHub archive extraction passed for **697** source files from immutable commit `e44227b0a886a1b65f0355a92ae7b704edf225ed`. It attached the previously approved private Composer lock SHA256 `4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8` and installed vendor. The new, root-private source+vendor directory is `/root/Bambodia-MCP-Main-Source.tpE9k1zN`. The script confirmed `MCP_MAIN_SOURCE_VENDOR_STAGING=PASS`, and explicitly reported missing `public/build/manifest.json` and `package-lock.json`. The live app source/vendor/database and Nginx were unchanged; no migrations or activation.

The next small milestone takes advantage of the fact that `resources/css/app.css`, `resources/js/app.js`, `vite.config.js` and `package.json` have byte-identical Git blobs in both production main and the proposed MCP branch: `40c59724376cb324b83633eb79f8ddca652d0667`, `4bc4f4096e02249cf2a0bcb10438139b324ae0c3`, `1fd66d538c217d36db94b62968257a7d9a558bbb`, `13ec88546892c1fd790d2161d7226c14e7be0b54` respectively. No Vite input change is needed solely for MCP; the new MCP CSS uses page-scoped static files.

The guarded operator-only helper `support/bambodia-mcp-main-domain-reuse-live-assets.sh --reuse` first verifies both the real live app and isolated release source contain those exact four files. It guards the known vhost SHA, live Composer baseline, private source+vendor lock SHA and the absence of an existing staged frontend build. It then validates the existing live `public/build/manifest.json` is a well-formed Vite manifest with both required input entries, every referenced asset present, and no symlinks/special files or unexpected scripts in the public build tree. With explicit typed approval `REUSE-BAMBODIA-MCP-ASSETS`, it copies the live build ONLY into the root-private staging tree and compares SHA256 hashes of every file before moving it into place. No production asset, dependency, database, Nginx, credentials, or QA file is modified.

If any validation fails, the script stops and reports its specific blocker: **do not force-copy assets**. Plan a separate isolated reproducible npm/Vite build only if source parity or the manifest fails. Reuse is accepted only for the four unchanged Vite inputs and the existing working frontend. It is NOT proof of a reproducible fresh Node build.

**Release boundary:** Even after a successful frontend copy, the source/vendor/assets candidate is still NOT approved for production. The full existing customer database/media backup and independent restore rehearsal, current database migration dry-run, host-specific hidden well-known OAuth routing and FastCGI bearer header review, production-specific Passport keys and actual ChatGPT OAuth consent/refresh/revocation remain outstanding. Both production MCP and writes must stay OFF, PR #46 draft/unmerged; unrelated homepage creator-avatar concerns are not part of this release.

## Live asset-reuse guard correctly blocked on source drift — 2026-11-13

The operator ran the verified private frontend asset reuse command at draft PR head `b66a7cef84c5322043d76e4239be593562adbd18`, script blob `22e06da02aa8ba4f3c1d0f4f89eae8b2e99871bd`. It passed script-integrity checking but stopped immediately at `MCP_ASSET_REUSE_BLOCKED=LIVE_SOURCE_DIFFERS_resources/css/app.css`. This occurred before operator approval and before any asset copy. The private staged candidate still has no `public/build`, and production was not modified.

**Critical correction:** Exact frontend file identity between GitHub `main` and the proposed MCP PR does not establish identity to the real aaPanel production deployment. Current live `resources/css/app.css` differs from GitHub `main`. This may represent newer reviewed live-only styling, such as independent talent/avatar work. Do not overwrite working production source or force-reuse a Vite build with mismatched source. The live app is not a Git checkout, so its source may differ in other areas as well.

Added focused read-only reconciler `support/bambodia-mcp-main-domain-live-drift-review.sh --inspect`. It checks the unchanged aaPanel vhost, production Composer manifest/lock, and private staged candidate; prints SHA256, byte sizes and CSS line-change counts for the four Vite entry/config files; inventories safe source paths under `app`, `config`, `routes`, `resources`, `database/migrations`, `public/css`, and `public/js` and summarizes changed/shared/live-only/staged-only file names without displaying content, credentials, `.env`, media or database records. It also reports whether live `public/build/manifest.json` contains the required entry keys. It does not copy or modify anything.

If drift is limited to approved live frontend source, reconcile those exact files into the **private candidate**, preserve its working live Vite build with hash verification, and perform independent UI regression review. If broader live app drift exists, STOP the full source replacement plan and prepare a delta patch/overlay against actual installed production code rather than deploying a stale 697-file GitHub snapshot. Keep MCP and writes OFF; no production deployment until restore and rollback acceptance.

## Physical source reconciliation — live-first overlay required (2026-11-13)

Operator read-only drift inspection PASSED; the live production website differs from the older GitHub-based full release. Compared within the approved source paths: 408 shared files, 369 identical, **39 changed**, **2 live-only**, and **26 staged-only**. Live CSS `resources/css/app.css` is 284,871 bytes SHA256 `58e0a51bbc411987604b36916fb8c0e5931d07dd222c5d8e825ade56103209df`; staged CSS is 282,933 bytes SHA256 `cd02d0539b25dacbfbc75ab248a55c825bc2f79dc23f076c6e4a7be875f8660d`, 9 CSS change blocks. JS app entry, Vite config and package.json match byte-for-byte. Existing live Vite manifest is JSON valid and contains CSS and JS entry keys.

The live-only files `public/css/header-flag-scroll-top.css` and `public/js/site-identity-assets.js` must be preserved. Changed files include important real production work in `HomeController`, marketplace managers, account layouts, home view, listing cards, site branding, blog and support views. A full staged source replacement would lose newer functionality.

Nine existing live files also overlap the MCP integration: `app/Http/Controllers/Api/BambodiaMcpController.php`, `app/Support/Mcp/BambodiaTools.php`, `app/Http/Middleware/SetLocale.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `config/auth.php`, `routes/web.php`, `resources/views/layouts/workspace.blade.php`, `resources/views/developer/index.blade.php`. The original GitHub main commit has **no** `BambodiaMcpController.php` or `BambodiaTools.php`, although both are present in the live application. Those two have no safe ancestral base for automatic three-way merging and need explicit human code reconciliation.

Implemented `support/bambodia-mcp-main-domain-live-overlay.sh --assemble` as a **private, live-first overlay builder**, not a production deployment. It verifies the known Nginx/Composer/live/private release baselines and the exact 39/2/26 drift. With typed operator approval `PREPARE-MCP-LIVE-OVERLAY`, it copies the existing staged source+vendor into a fresh root-private `/root/Bambodia-MCP-Main-Overlay.XXXXXXXX/source`, overlays all 30 non-MCP changed live files and the two live-only assets, and separately preserves the original nine live MCP-touchpoint versions under `review/live-critical`. It downloads seven GitHub main ancestor files at pinned commit `133f82a3e8681f3efbc8de9424a83dc3306e15d5`, verifies each expected Git blob, and attempts private `git merge-file -p --diff3` against the MCP release. It reports conflicts and stores unresolved merges privately, never forcing an old source file over the live version. It never sends live code or secrets to GitHub.

The same private script verifies the existing working Vite build and copies it byte-for-byte only to the private overlay, after confirming the four resulting Vite inputs are identical to the current live app. This avoids a risky npm rebuild or a stale frontend asset release. It still labels the package `DEPLOYABLE_RELEASE=NO`, because two preexisting live MCP classes and any additional three-way conflicts require manual review, and the staged source includes six unrelated new Preview Visibility files that need release-scope reconciliation.

After operator execution: review the generated `PRIVATE_OVERLAY_MERGE_REVIEW` paths, resolve conflicts privately, validate PHP/Blade syntax and current marketplace user journeys against a restored isolated database, exclude unrelated stage-only modules as appropriate, then independently confirm production backup/restore. Only then prepare a rollback-safe main-domain deploy with BOTH `BAMBODIA_MCP_ENABLED` and `BAMBODIA_MCP_WRITES_ENABLED` off. A separate authorized real ChatGPT OAuth sign-in/PKCE/read-only test still must pass before enabling production MCP. The unrelated creator-avatar UI issue should not be modified as part of this MCP release.

## Physical private live-first overlay accepted — 2026-11-13

The operator executed `support/bambodia-mcp-main-domain-live-overlay.sh --assemble`, entered `PREPARE-MCP-LIVE-OVERLAY`, and reported `MCP_PRIVATE_LIVE_OVERLAY=COMPLETE_WITH_REVIEW_REQUIRED`. Root-private overlay directory: `/root/Bambodia-MCP-Main-Overlay.ApguAhIY`. The script preserved 30 unrelated changed live source files and the two live-only public files, correctly verified and copied 15 existing Vite build files into the private workspace, and did not touch production source/keys/DB/Nginx or activate MCP.

Five MCP touchpoints successfully three-way merged: `app/Http/Middleware/SetLocale.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `config/auth.php`, `resources/views/developer/index.blade.php`. Two automatic conflicts require deliberate resolution: `routes/web.php` and `resources/views/layouts/workspace.blade.php`. Two MCP classes already exist on the live server but have no GitHub main ancestor for safe three-way merging: `app/Http/Controllers/Api/BambodiaMcpController.php` and `app/Support/Mcp/BambodiaTools.php`. No assumption should be made that simply overwriting the live versions is safe.

Next focused private-only action: `support/bambodia-mcp-main-domain-resolve-private-layout-review.sh --review`. The helper verifies the exact live vhost/source Composer and private overlay baselines; it patches only the PRIVATE workspace layout by preserving the exact live file and inserting a single page-scoped `@stack('workspace-head')` hook when missing. This preserves the current dashboard styles and all existing menu markup. It prints the remaining routing conflict block excerpts and a limited diff of the two preexisting MCP classes, from known source-only PHP files, to guide the next targeted code reconciliation. It does not upload live source, access `.env`, write production, execute a migration or activate MCP. The `routes/web.php` and both existing MCP classes remain pending review after this step; the candidate explicitly remains **not deployable**.

After reconciling these three files, separately review the six unrelated staged Preview Visibility modules, validate isolated Laravel boot and routes without touching live database, then perform production backup/restore, forward-only OAuth migration rehearsal and controlled read-only activation with rollback. Both MCP feature flags remain OFF, and GitHub PR #46 stays draft.
