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

## QA HTTPS activation PASSED; one administrator email typo pending correction (2026-11-12)

The operator ran exact pinned QA-only activation helper from draft PR HEAD `ae55057b836c69927ba40424fc325d672d65907a`, Git blob `2ab392795f2b7af66f1a8dd428b5d179ff63d128`, and supplied the interactive `ACTIVATE-BAMBODIA-MCP-QA` approval. Nginx syntax and reload succeeded; server output explicitly includes `MCP_QA_HTTPS_ACTIVATION=PASS`, `MCP_QA_LARAVEL_PUBLIC_ROOT=/var/lib/bambodia-oauth-https-qa/public`, rollback backup at `/root/Bambodia-MCP-QA-Nginx.2n97FlfQ/original.conf`, `ORIGINAL_AAPANEL_WEBROOT=RETAINED` and `PRODUCTION_CONFIGS=UNCHANGED`. **`MCP_QA_PUBLIC_FUNCTIONAL_TEST=NOT_YET_PERFORMED`** still means browser login, MCP read/write and real ChatGPT OAuth consent are not accepted yet.

User authorized correction of a typo in the **isolated QA-only administrator email**, from `tonsamith168@gmail.cm` to `tonsamith168@gmail.com`. The new script `support/bambodia-mcp-qa-correct-admin-email.sh --correct-email` is a single narrowly scoped operator action (not yet executed): requires existing private QA Laravel root, exact `APP_ENV=staging`, exact `APP_URL=https://oauth-qa.bambodia.com`, dedicated `bambodia_oauthqa_https` MySQL connection/account, precisely one active `super_admin` account and exact old or already-correct email. With a transaction it changes **only** that existing user's email, preserving account ID, password, verified status, role, and OAuth data. If already corrected, it exits without changes. It refuses ambiguous users, other database names, different prior emails or non-QA environments. It does not send email, reset passwords, migrate DB, modify Nginx, rotate keys or edit production.

After operator reports `QA_ADMIN_EMAIL_CORRECTION=PASS`, physically open `https://oauth-qa.bambodia.com/login` with corrected email and existing QA password, visit `/developer/mcp-connection`, and run the real HTTPS MCP read/draft actions. A successful Nginx activation alone does **not** verify OAuth/ChatGPT functionality. PR #15 remains draft/unmerged pending the actual user journey.

## Physical browser screenshot: installer unexpectedly displayed after terminal provisioning (2026-11-12)

The user opened `https://oauth-qa.bambodia.com/installer` and saw the standalone installation Requirements page. This is **not** a prompt to repeat setup. The previously accepted isolated installation already has Composer/vendor, QA database migrations, QA-only administrator `tonsamith168@gmail.com`, frontend assets, Passport keys and working QA-only HTTPS Nginx activation. The installer says **Application directory writable — Required** because the QA application source is intentionally read-only for PHP-FPM; **do not chmod/chown it writable or rerun the installer**.

Verified repository root cause: `public/index.php` checks `is_file($root.'/storage/app/installed.lock')` *before Laravel starts*. If missing, any path except `/installer` gets a 302 redirect to the installer, and `/installer` loads the standalone `installer/entry.php`. The separate terminal-driven QA setup did not create the **installed.lock** marker; installer itself would ordinarily write it only after its five-step fresh-database flow. Public installer page exposure can disclose runtime/extension status even while `.env` prevents an actual installation with existing credentials; close it promptly.

New one-time, QA-only operator action `support/bambodia-mcp-qa-seal-installed.sh --seal` explicitly verifies the existing PHP 8.5 Laravel bootstrap, expected `APP_ENV=staging`, fixed `APP_URL=https://oauth-qa.bambodia.com`, dedicated MySQL schema/user/host, exactly one active corrected super_admin `tonsamith168@gmail.com`, required OAuth/QA tables, existing private Passport keys, frontend assets, and only the expected QA Nginx virtual host/public root. **Only after these checks** it atomically creates `storage/app/installed.lock` (root:www, mode0640), matching the standalone installer’s completion sentinel. No installer execution, migrations, DB writes, password resets, OAuth key generation, Nginx changes, production actions, or permission changes. It refuses a pre-existing/symlink sentinel. This closes the unexpected redirect and allows real Laravel routing to `/login` and authenticated `/developer/mcp-connection`. It has **not** yet executed on the operator's server; GitHub CI Bash syntax check added, run status unverified.

After `QA_INSTALLATION_STATE=SEALED`, physically refresh the QA login URL. If Laravel throws 500/404/etc, investigate that actual web response rather than restarting the installer or removing the lock. Then use the real MCP read/draft controls and ChatGPT OAuth connection; PR #15 stays draft and unmerged until real functional acceptance.

## Live MCP workspace UX review and page-scoped layout improvement (2026-11-12)

The operator physically logged in and opened `https://oauth-qa.bambodia.com/developer/mcp-connection`, as shown by two screenshots. The page rendered the Developer Workspace with **Isolated QA**, **OAuth database: Available**, **active OAuth tokens: 0**, endpoint copy, read-only test, unpublished QA draft test, and OAuth verification guide. The screenshot proves the page and controls render, **not** that live MCP reads/drafts or OAuth grants have been executed. The original page layout has obvious usability issues: password fields lack a visible input box, labels/checkboxes flow into adjacent text, full-width panels waste vertical space, and form submit controls are not consistently aligned. This is a **page-level visual problem**, not evidence of an OAuth transport failure.

Implemented a **page-scoped premium responsive redesign** on draft PR #15:
- `resources/views/developer/mcp-connection.blade.php`: organized heading/navigation, three consistent status cards, compact copyable MCP endpoint panel, equal-height **two-column read/write functional forms** on desktop (stacked on tablet/mobile), accessible explicitly labeled visible password inputs, properly grouped draft authorization checkbox, action buttons right-aligned with their own form, five-step physical ChatGPT authorization workflow and existing success/failure feedback. All existing `POST developer.mcp.check`, `POST developer.mcp.create-draft`, CSRF, password and checkbox names, route guards, token handling and drafts logic are retained unchanged.
- `public/css/mcp-connection-center.css`: dedicated stylesheet scoped to `.mcp-center` and the existing shared `--surface`, `--line`, `--muted`, `--accent` and `--secondary` tokens, supporting light/dark and mobile layouts. It explicitly restores visible accessible password fields and normal-sized checkboxes without changing the other account dashboards or public frontend. No npm/Vite rebuild is required to serve this static stylesheet.
- `resources/views/layouts/workspace.blade.php`: non-rendering optional `@stack('workspace-head')` hook in head, used only by the MCP page, so the new stylesheet is not included in unrelated workspaces.
- `support/bambodia-mcp-qa-ui-upgrade.sh --apply`: separately operator-executed, backed-up and pinned **QA-only** upgrade. Verifies sealed QA app, active exact QA Nginx root, original QA view/layout Git blobs and no old CSS file, fetches three new files at immutable approved feature commit `fe87ee0987fa77e15ada21504630e123b6f1e198` with exact Git blobs, backs up original QA Blade files in a root-private recovery folder, requires `APPLY-BAMBODIA-MCP-UI` confirmation, deploys root:www 0640 code/styles, verifies www read permissions, and clears only QA compiled Blade views. On deployment failure, restores original view/layout and removes only the newly installed CSS. No PHP dependency installations, migrations, MySQL writes, Nginx reload, OAuth token activity, existing production/public frontend changes or shared CSS alteration.
- CI quality workflow adds a `bash -n` gate for the new UI deploy helper; workflow result not verified yet.

**Status:** redesign source committed on the draft PR; **not yet installed** on live QA until the operator executes the pinned helper. Continue with real physical read and draft actions after the redesigned page loads and input fields are visible. Keep PR #15 draft/unmerged pending user-visible functional acceptance.

## Physical end-to-end MCP read/write accepted from browser (2026-11-12)

The administrator provided seven direct screenshots of the live isolated QA Developer Workspace, MCP Connection Center, OAuth Connections, and Post Manager. Confirmed actual on-page success alerts:

- **Read:** `Live HTTPS MCP check passed: tool discovery, administrator authorization, overview, and post listing. This does not yet verify OAuth sign-in or writes.`
- **Write:** `The live MCP tool created the unpublished draft QA MCP Draft 20261112-144457-b56ff54f.`
- **Database/UI verification:** the linked Post Manager edit page `/dashboard/post-manager/2/edit` displays the **same QA post title** and generated HTML body. Its status selector says **Draft**, and the publication sidebar says **Not published**.
- **OAuth Connections** lists **zero** associated MCP OAuth access tokens. The successful read/write path used the intentionally short-lived scoped QA developer bearer, **not** an OAuth authorization code/PKCE flow from ChatGPT. The zero count is therefore not a failure of the MCP bearer transport; it is evidence there has not yet been a successfully recorded ChatGPT OAuth connection.

This represents **real functional acceptance** of QA MCP HTTPS bearer transport, authorized tool discovery, read tool, unpublished post creation and UI database consistency. Automated counts are no longer substitutes for these user actions. **OAuth dynamic client registration, administrator consent, PKCE callback, token refresh, revocation from ChatGPT and ChatGPT app tool discovery remain unaccepted.**

The screenshots also showed the `/developer/oauth-connections` screen rendered a bare table with concatenated headings and displayed a `Go To page 1` control for zero entries. To fix the actual browser UI rather than adding more infrastructure tests, draft PR #15 now contains a page-only change:
- `resources/views/developer/oauth-connections.blade.php` with a readable no-connections state, a **Connect ChatGPT** link back to MCP Connection Center, themed status cards, and 10-per-page tabular connection history when OAuth tokens exist.
- `public/css/mcp-oauth-connections.css`, using shared light/dark workspace tokens and responsive table layout.
- Secure OAuth revoke action remains password-confirmed `DELETE` through the **existing** `developer.oauth.revoke` route, moved to the existing centered dialog pattern so action controls are not cramped inside a table. Pagination and Go To appear only when more than one page of results exists.
- `support/bambodia-mcp-qa-oauth-connections-ui-upgrade.sh --apply`, a **QA-only**, backed-up, pinned-blob and explicitly confirmed two-file visual update. No OAuth controller, token/grant, MySQL, Nginx or production mutations. This action is **not yet operator-executed**; the current live QA OAuth Connections page remains as seen in the screenshot until the operator approves the update.

For the next real connection acceptance, OpenAI's current Help Center describes Plugins → Add custom MCP server, a public HTTPS MCP endpoint, OAuth authentication, consent/authorization, and tool discovery. Full custom MCP/write is currently workspace/plan-gated, typically to Business/Enterprise/Edu eligible users; the operator must use an eligible workspace. **Do not claim their account supports it without seeing the option.** Do not mark PR #15 merged or production-ready until an authorized real ChatGPT OAuth connection and refresh/revocation have passed.
