# Bambodia MCP OAuth — Step 26 HTTPS QA deployment plan

**Status: PROPOSED, NOT DEPLOYED.** The current host and accepted QA application are separate. Draft PR #15 must remain unmerged. This plan is for explicit review before *any* Nginx/webroot change, database creation, OAuth client registration or browser consent. Do not copy an existing accepted QA `.env`, Passport key, private media, token, or database into the public host.

## Operator evidence (2026-11-12)

- `oauth-qa.bambodia.com` resolves and has valid externally and locally verified HTTPS; aaPanel Nginx syntax PASS and its exact server_name, port 443 and SSL directives are present.
- The original vhost root is `/www/wwwroot/oauth-qa.bambodia.com`. The webroot has no `index.php`, `public/index.php`, `artisan` or `.env`.
- Four vhost/include files were inspected without finding a `fastcgi_pass`, PHP location, Laravel `try_files` or rewrite directive.
- PHP 8.5.11 is installed; a PHP-FPM Unix socket exists.
- Both public and local validated TLS GET requests return HTTP 404 at OAuth discovery. GET `/mcp` also returned 404; the actual MCP endpoint expects authenticated POST, so that alone is not a functional MCP rejection test.
- Step 25 accepted 17 PHPUnit tests and 173 assertions against a separate SQLite in-memory workspace. This does not constitute real-browser, TLS/OAuth endpoint or ChatGPT integration acceptance.

## Nonproduction boundaries required before deployment

1. **Source**: independently approved Git revision, with fully verified `composer.lock` identical to accepted QA source; preserve all accepted QA checkouts at their pinned SHA. No production or staging source changes.
2. **Filesystem**: use **only** the separately reserved `/www/wwwroot/oauth-qa.bambodia.com` application tree, after a complete read-only occupancy/symlink/ownership audit and an explicit operator approval. Set Nginx document root to that tree's `/public` *only after* reviewing the proposed complete vhost and validating `nginx -t`; never expose `.env`, `vendor`, `storage`, `artisan`, `.git` or private files. The current webroot is **not** ready for `root .../public` until a securely installed app exists.
3. **Database**: independently provision a new *empty* schema `bambodia_oauthqa_https` with distinct restricted user `bambodiaqa_https`; deny `*.*` and production/staging privileges. Do not re-use `bambodia_oauthqa_browser` or `bambodia_oauthqa_acceptance` and do not migrate any shared schema. A separate root-private credentials file is needed; do not disclose passwords or tokens in terminal logs.
4. **Application settings**: a fresh host-specific `APP_KEY`, `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL=https://oauth-qa.bambodia.com`, `DB_CONNECTION=mysql` with the dedicated schema and restricted credentials, `SESSION_DOMAIN=null`, a unique `SESSION_COOKIE`, `MAIL_MAILER=log`, no external side effects, no payments, no R2, no workers, and no production callbacks. Issue independent Passport signing keys held outside public webroot. No public sign-up or actual customer data.
5. **Nginx/PHP**: configure PHP-FPM only for the dedicated QA vhost, using the verified PHP 8.5 socket, suitable `try_files` rewrite to `/index.php`, static asset cache and restrictive location rules. Backup and review original vhost, syntax-check with the proposed config, verify other vhosts unchanged, and require approval before reload. Beware aaPanel-managed include files and `open_basedir` constraints.
6. **Network and auth controls**: restrict access to authorized test identities (IP allowlist, VPN, or suitable access gate compatible with real ChatGPT callbacks), review DCR and redirect URI allowlist, PKCE S256, state, CSRF, consent, token scopes, refresh/revocation, issuer/audience and MCP authorization errors. Avoid relaxing protections just to make a smoke test pass.
7. **Rollback**: pre-record original Nginx config hash and snapshot of only the new QA schema. Recovery must not drop/modify existing accepted QA data or production. Prove that the prior vhost/route state can be restored before enabling public OAuth.
8. **Release gate**: only after independent TLS browser and real ChatGPT authorized connection acceptance plus security review may PR #15 move from draft; production deployment requires separate authorization.

## Step 26C — boundary inventory (read-only)

Review and download the pinned `support/bambodia-oauth-step26c-deployment-boundary-check.sh` from draft PR #15, verify its Git blob and `bash -n`, and run it. It verifies the accepted source and lock, the currently reserved host's webroot/vhost identity, PHP runtime and socket, DNS and TLS, and prints non-sensitive file-presence checks. It does **not** connect to MySQL, discover database availability, modify the vhost, clone the application, install Composer, create keys or execute migrations. A passing inventory does not authorize deployment.

## Next implementation milestone after authorization

Prepare a *separate*, guarded `--check` plus `--stage` installer and an explicit `--activate` phase with two operator confirmations, scoped backup/rollback, one new empty QA DB only, and HTTP-only QA health checks before public OAuth consent. Do not repurpose the accepted browser-QA checkout; test on synthetic records and verify private/public key independence. No automatic migration or vhost activation from a read-only audit.
