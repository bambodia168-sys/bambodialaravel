# Bambodia OAuth — independent browser-QA deployment and acceptance plan

**Status: planning and source preparation only; not a live deployment.** Draft PR #15. No production migration, vhost switch, customer-data copy, or public OAuth activation authorized.

## Operator-confirmed starting point (11 Nov 2026)

- Existing CLI migration rehearsal: `/var/lib/bambodia-oauth-qa-acceptance`, clean checkout `34e86fbbdfcbe56e5e28e0286a47ba2309e48455`. QA-only keys and six migration records were created, and five OAuth tables and scopes column passed acceptance.
- Protected pre-migration snapshot `/root/bambodia-oauth-qa-snapshots/run.l0IsKwRj/before.sql`, SHA-256 `3498997c561f5941d8f095e10b50203396ae79b5dd7ae80e6c9673de26d533a0`. Separate empty-schema restoration passed at `bambodia_oauthqa_restore_acceptance`.
- The CLI rehearsal app uses `https://oauth-qa.invalid` and its schema has **no `users` table**. This is not a browser-capable application.
- `oauth-qa.bambodia.com` currently points to `/www/wwwroot/oauth-qa.bambodia.com` (not the CLI app), with 6 entries: `.htaccess` (1 byte), `.user.ini` (one `open_basedir` directive), `.well-known/`, `404.html`, `502.html`, `index.html` (SHA-256 `0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b`). No `index.php`. **Preserve these files; neither blank nor approved for overwrite.**
- HTTPS certificate hostname and seven-day validity checks pass, Nginx syntax passes, included PHP reference appears to use PHP 8.5. One `allow` directive and no `deny` directive were found; this does **not** prove access restriction. External TLS chain, routing and network restrictions remain unverified.

## Separate browser QA assets, never reuse accepted CLI rehearsal

| Asset | Intended isolated value | Gate |
|---|---|---|
| Source working tree | `/var/lib/bambodia-oauth-browser-qa` | New path, existing path must be absent; clean SHA pinned to reviewed PR commit |
| MySQL schema | `bambodia_oauthqa_browser` | New empty schema and dedicated restricted `bambodiaqa_browser` account, no other schema grants |
| MySQL options | `/root/bambodia-oauth-browser-qa.cnf` | Root-owned mode 0600; never print or copy production credentials |
| Laravel config | Dedicated `.env` with unique APP_KEY, QA DB only, `APP_ENV=staging`, `APP_DEBUG=false`, `MAIL_MAILER=log`, file session/cache, stopped database queue and no external storage/AI/payments |
| OAuth keys | Brand-new QA-only private/public keys | Never share with CLI rehearsal, production or staging |
| Web host | `oauth-qa.bambodia.com`, only after review | Controlled switch of verified vhost and PHP routing, tested rollback to existing site with backed-up config/content |
| Nginx root | Browser QA **public/** only | Never point web server to project root or `storage`; ensure denied dotfiles and `.env` |
| Access | Operator-approved restricted access | Verify effective server/location `allow` **plus** `deny all`, or equivalent gateway/firewall policy; keep OAuth metadata/callback compatibility in view |

The browser QA path and schema must not share the CLI app's Passport keys, database, backup, APP_KEY, cookie or mutable storage. No copying real users, sessions, API tokens, customer emails, media or OAuth credentials. Do not run `composer update` against unreviewed sources; use a reviewed lockfile and vetted install with Composer scripts/plugins disabled during preparation.

## Synthetic authentication schema and data

Run full application migrations **only** against the separately created and independently validated browser QA schema, after reviewing dependencies and backup/rollback. The base user migration creates `users`, `password_reset_tokens`, and `sessions`; Bambodia's identity migration adds `public_id`, `role` and `locale`. Later security/account/audit migrations add fields and tables required for login/consent. Do **not** run those migrations in the already accepted six-table CLI schema.

Create temporary synthetic identities only, with new strong passwords entered locally and never emitted to logs or GitHub. Include: active super-admin, restricted staff, inactive/demoted former admin, a non-admin, and another admin owning a disposable draft. Seed only local test records and audit data. Disable registration, public publishing, payments, email delivery, queues, external imports and outgoing callbacks until tested and explicitly authorized.

## Acceptance sequence

1. Review source checkout and PHP 8.5 extensions/dependency lock, Nginx includes/effective root/FPM, `.user.ini` effective scope, DNS/TLS chain, firewall/CDN and real access restrictions. Preserve a checksummed root-private backup and rollback plan for existing vhost plus webroot files. **No website switch at this gate.**
2. Provision the **separate** restricted browser QA schema and Laravel configuration. Run dependencies and application migrations with no production or established staging credentials; validate full auth schema and log-in prerequisites. Independently restore synthetic QA backup after migration.
3. Create synthetic identities; independently verify access policy, login/logout, CSRF, session fixation protection, optional TOTP, password confirmation, role downgrade, and audit recording.
4. Reconfigure the dedicated QA vhost only after explicit operator acceptance and verified rollback. Expose only public Laravel files, with tested 403/404 denial for `.env`, `.git`, `storage`, backup and private keys. Verify HTTPS trust and host/cookie isolation.
5. Test OIDC/OAuth discovery, HTTPS redirect allowlist, DCR registration boundaries, PKCE S256 valid and invalid challenge, `state`, consent approve/deny, missing/mismatched `resource`, issued JWT audience and issuer, refresh/rotation, expiry and revocation.
6. Exercise all four read and six write MCP tools against disposable drafts; deny cross-owner edits, disallowed scope and non-admin access. Never call writes on public production.
7. Connect ChatGPT through its actual OAuth flow with a verified callback URL from ChatGPT and one authorized read call. Do not invent callback IDs or declare it working based only on CLI or curl.
8. Reconcile draft PR #15 against the **current** main, run all five workflows on final SHA, obtain independent security review, and document accepted deployment and rollback before considering production.

**Hard stop conditions:** Any QA path resolves into production/staging; credentials are shared; unreviewed root contents would be overwritten; `open_basedir` excludes the intended app; effective Nginx access controls are unknown; real external integrations are enabled; or exact vetted source SHA / dependency versions differ from acceptance.

All production and existing CLI QA / restore assets remain untouched until the next approval gate.
