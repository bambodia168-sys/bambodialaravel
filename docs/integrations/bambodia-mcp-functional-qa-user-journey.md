# Bambodia MCP — real administrator workflow first

**Scope:** draft PR #15, isolated `oauth-qa.bambodia.com`, no changes to production or the accepted original QA checkouts.

## Implemented user-facing feature

The existing Developer Dashboard now has a **Connect ChatGPT** action leading to `/developer/mcp-connection`, accessible only to an authenticated `super_admin`. It reuses the existing workspace layout and does not redesign public pages. The page offers:

- A copyable, configured MCP endpoint and the OAuth discovery link, plus an accurate notice that discovery being configured does **not** prove it is externally reachable or authenticated.
- The signed-in administrator's active MCP-scoped Passport token count and a direct link to the existing OAuth connection/revocation UI.
- **Run live read-only check**: after password confirmation, the QA-only controller issues a temporary `mcp.read` developer token, shortens its expiry to three minutes, performs three REAL HTTPS JSON-RPC calls to `https://oauth-qa.bambodia.com/mcp` — `tools/list`, `bambodia_overview`, and `bambodia_list` of posts — and verifies the returned data. It revokes the temporary bearer in a `finally` block; no secret is printed or stored in the browser. There are no HTTP redirects, and the target is hardcoded to the HTTPS QA host. It reports success/failure in the UI; no response bodies, headers, or tokens are included in error notices.
- **Create QA draft through MCP**: after password plus explicit checkbox confirmation, creates ONE unpublished blog-post draft via the actual `bambodia_create_draft` HTTPS MCP write tool with `mcp.read` + `mcp.write`, verifies its owner/status/title in the same ContentEntry model as Post Manager, and offers a direct **Open in Post Manager** link. The temporary credential is promptly revoked. It never publishes, emails, deploys, uploads media, changes production or automatically deletes content. After a timeout, check Post Manager before retrying to avoid duplicates.
- A short operator walk-through to authorize ChatGPT via OAuth, list posts, compare the new draft with Post Manager, and revoke the connection. **The dashboard smoke check uses a developer bearer and is not a substitute for independently accepting OAuth PKCE, consent, refresh, revocation or a real ChatGPT connection.**

Both live actions are restricted to `APP_ENV=staging`, exact `APP_URL=https://oauth-qa.bambodia.com`, a logged-in `super_admin`, CSRF, current-password confirmation and per-minute rate limits.

## Single private QA launch action

`support/bambodia-mcp-qa-functional-launch.sh --launch` is a single **mutating** QA-only action, not another test collection. It targets the already approved source snapshot at `/var/lib/bambodia-oauth-https-qa`, and only the dedicated **empty** `bambodia_oauthqa_https` schema and `bambodiaqa_https@127.0.0.1` credentials. The operator must run a script pinned to a specific draft PR commit and verified Git blob.

The launch script:
1. Refuses an already initialized or partially installed target; verifies the approved original Composer lock, the two original QA source file Git blobs, unchanged original QA Nginx vhost, and an empty dedicated QA database. It never drops/overwrites the existing schema, vendor directory, configuration, or older QA environments.
2. Fetches exactly four UI/code files from the immutable functional feature commit `b799d74c81bda133c351d792540d58eb46923a55` and validates each Git blob before using it.
3. Performs a PHP 8.5 Composer locked dry-run followed by one production-only dependency installation; scripts/plugins disabled. The old `/usr/local/bin/composer` PHP 8.3 shell wrapper is bypassed.
4. Overlays the new UI code within **only** the isolated QA root, leaving copies of the two replaced files inside a new root-only recovery directory; builds the QA-only frontend assets via npm.
5. Generates **new** QA-only `.env`/APP_KEY from the dedicated root-protected MySQL credentials file; runs migrations against only that empty QA database and generates independent Passport signing keys. No production keys, sessions or media are copied.
6. Prompts for a brand-new QA-only admin email/password (not printed in output) and creates a real active super-admin account in the isolated schema, so an operator can physically log in.
7. Gives the aaPanel `www` group read access to the QA code and write access only to private runtime storage/cache, while keeping Passport private keys non-writable by the `www` account.

**Safety:** The action intentionally does NOT change the live QA Nginx vhost, existing six webroot entries, ACME challenges, PHP-FPM pool configuration, other QA applications or production. It does not claim to make the OAuth hostname publicly reachable. If anything fails during package installation, asset build or migration, preserve the partial private state. **Do not rerun the launch script**, delete files or migrate again without diagnosis.

## Actual acceptance path (not automated test count)

1. Run the pinned one-time private QA launch and confirm the final `PRIVATE_QA_RUNTIME=READY_FOR_HTTPS_ACTIVATION`. Do not mistake the presence of GitHub code for execution on aaPanel.
2. Review/apply a **separate**, rollback-safe Nginx change only to the QA vhost; preserve old webroot and ACME challenges. Test `https://oauth-qa.bambodia.com/login` from a real browser.
3. Sign in as the newly created QA-only super-admin. Open `/developer/mcp-connection`, click **Run live read-only check**, then **Create QA draft through MCP**, and open the resulting draft in Post Manager. That demonstrates a real, authenticated HTTPS read and write against the same browser-visible database.
4. Connect ChatGPT to the exact QA MCP HTTPS URL with OAuth, consent to scopes, list records, create/update a disposable draft, then revoke the connection and confirm access is denied.
5. Only after those **actual user journeys** pass should PR #15 be considered for independent review/merge. Production change requires separate authorization.

**Status as authored:** the feature and deployment action are committed to the draft branch. Neither the private launch nor QA Nginx activation has run yet; real browser and ChatGPT OAuth connection remain unverified.

## First operator launch blocked at missing npm; corrected launch ready (2026-11-12)

Operator ran the pinned `support/bambodia-mcp-qa-functional-launch.sh --launch` at draft PR head `2b8abd4628b4df6b3a791a1c6fa56c75a3d26bf3` (Git blob `3a007c1c900f388fafbc214d98693660d6659a45`), verified `gh` PR draft/head, exact blob and `bash -n`. It printed only `QA_LAUNCH_BLOCKED=MISSING_npm`. That original script performed `command -v npm` in the **first** prerequisite loop; no private Composer or npm installation, source overlay, QA environment/key creation, database migration, administrator creation, Nginx or production write was reached. Because execution stopped before any mutation, it is safe to launch the **updated, independently pinned replacement script**, not the old pinned file.

The corrected launcher searches common aaPanel Node version-manager bin directories for an actual compatible Node/npm pair. If none is available, it downloads the **official Node.js 24.22.3 LTS** Linux x64/arm64 build-only tarball into a new root-private recovery workspace, checks the exact SHA256 values published by the Node.js project for each architecture, and extracts it privately. It does **not** install Node system-wide, call `apt`, rewrite global PATH, change aaPanel Node versions, or reload any services. npm's cache is placed inside the same root-only recovery workspace, not under a global npm home. The previous checked QA source/DB and public Nginx restrictions remain unchanged, and the end goal is still the actual browser-connected MCP user workflows.

The updated script is **not yet run by the operator**. It checks pre-existing source and empty QA database before any Composer or Laravel mutation. On a failure after any mutation, preserve the partial state and do not blindly run it again. PR #15 remains draft/unmerged.

## Administrator finalization PASS and QA-only HTTPS activation (2026-11-12)

The operator ran the pinned `support/bambodia-mcp-qa-finish-admin.sh --finish` from verified draft PR commit `6735337cbc2fe427ba25cd900e06df8a9122400f` / file blob `366a5563c0781f444d821a8f1a43c4a9fd0a0cdc`. Output passed:
- `QA_PRIVATE_PACKAGES_ASSETS_MIGRATIONS_KEYS=RETAINED`
- `QA_DEDICATED_DATABASE=READY_FOR_ONE_ADMIN`
- `QA_ADMIN_CREATED=YES`
- `QA_ADMIN_FINISH=PASS`
- `QA_PRIVATE_RUNTIME=READY_FOR_HTTPS_ACTIVATION`
- `QA_CONNECTOR_UI=/developer/mcp-connection`
- `MIGRATIONS=NOT_REPEATED`, `NGINX=UNCHANGED`, `PRODUCTION=UNCHANGED`

**Administrator login email caveat:** the operator entered `tonsamith168@gmail.cm` (not `.com`). This is the literal email currently stored for the QA-only administrator unless corrected through the existing account-management workflow. Do not guess, silently change a login identity or treat the QA password as exposed; it was entered hidden.

A new **separately confirmed, QA-only** HTTPS activation helper `support/bambodia-mcp-qa-https-activate.sh --activate` is prepared in the draft PR. The operator must independently download the exact file at a pinned head SHA, verify Git blob/Bash syntax and then type `ACTIVATE-BAMBODIA-MCP-QA`. The helper:
- Requires the exact known unchanged original `oauth-qa.bambodia.com` vhost hash, the existing old aaPanel webroot/ACME directory, the private QA Laravel index/env/Passport keys/compiled frontend, the PHP 8.5 FPM Unix socket, and `www` read-only access to app secrets with **no write permission** to `.env` or private signing key.
- Saves a root-private copy of the **original vhost**, preserves existing certificate directives and Nginx includes; only replaces the single original QA `root` with Laravel's private `public` directory. Adds Laravel front-controller routing, index.php-only PHP 8.5 FPM execution, arbitrary PHP/hidden-file denial, explicit OAuth discovery metadata routes, and an ACME challenge location that still serves from the **original** aaPanel webroot.
- Refuses ambiguous configurations (unexpected server blocks, existing root location/PHP handler, changed vhost hash), performs the current Nginx syntax check, and checks a **candidate** config with `nginx -t` after replacement. On failed validation/reload, the exit handler restores the original vhost backup and attempts a reload. It does not edit any other vhost, restart PHP-FPM, change MySQL, touch original QA files, or alter production application source.
- Reports the original rollback file location and new URLs. **A successful Nginx reload is not a browser acceptance result**; the operator must physically open `https://oauth-qa.bambodia.com/login`, sign in with the literal QA email and password, visit `/developer/mcp-connection`, perform the real read and disposable draft actions, and review OAuth ChatGPT consent/PKCE/refresh/revocation separately.

**As documented:** private QA installation has passed, but the new Nginx activation script has not run on aaPanel. PR #15 remains draft/unmerged. Do not claim the public endpoint or physical MCP actions are working yet.
