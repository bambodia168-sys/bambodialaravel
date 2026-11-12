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
