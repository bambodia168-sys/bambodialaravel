# Bambodia MCP OAuth — protected aaPanel isolated rehearsal

**Status: operator execution required. GitHub CI is not hosting acceptance.**
Draft PR #15 only. Do not merge, generate Passport keys on, or migrate Bambodia production. Never point this helper at `/www/wwwroot/bambodia.com` or the established `/www/wwwroot/bambodia-staging-qa` runtime.

## Safety boundary

- The application checkout must be its own Git tree at `/var/lib/bambodia-oauth-qa-<unique>` at the exact reviewed PR SHA, outside any Nginx document root.
- Provision an isolated local MySQL database `bambodia_oauthqa_<unique>` using a restricted account `bambodiaqa_<unique>`. No production/staging DB names, users or passwords. Store its root-owned, mode 0600 MySQL options file under `/root/`.
- Use synthetic data where possible. A real-data clone requires authorization, sanitization, host access restrictions and outgoing-network isolation. Do not run QA mail deliveries, queue workers, payments, webhooks, imports, cron or external storage sync.
- Fresh QA `.env`: `APP_ENV=staging`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=bambodia_oauthqa_<unique>`, `DB_USERNAME=bambodiaqa_<unique>`, `MAIL_MAILER=log`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `SESSION_COOKIE=bambodia_oauthqa_<unique>`, `SESSION_DOMAIN=null` (host-only), a unique QA `APP_KEY` and a separate HTTPS `APP_URL`. Do not set `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY`, `DB_SOCKET` or `DB_URL` in this runtime. `https://oauth-qa.invalid` is a reserved non-routable example suitable for CLI schema tests only, not browser/ChatGPT acceptance.
- Never copy live `.env`, sessions, OAuth/Passport keys, API tokens, clients, cached configuration, secrets or unapproved media into QA. The helper refuses cached config and existing Passport keys. Keep PHP runtime access restricted to the aaPanel `www` account.
- Protected DB snapshots are stored under `/root/bambodia-oauth-qa-snapshots/`, not a public webroot. The helper does not connect to public production.
- The `--check` preflight now compares the literal `.env` DB username with `CURRENT_USER()` and examines **every** `SHOW GRANTS` line. Only the selected QA schema and `bambodia_oauthqa_restore_*` schemas may have privileges. A user with any grant on a production/staging schema is rejected, even when the account has no global privileges. The validator has offline denial-case self-tests in CI.

## Prepare and run on the isolated aaPanel host

Use the standard restricted GitHub checkout + Composer procedure to provision the exact approved PR source into a new isolated QA directory. Supply `vendor/`, a new `.env`, and a pre-OAuth sanitized QA DB. Create a QA-only root-readable MySQL options file:

~~~ini
[client]
user=bambodiaqa_example
password=<generate locally; never paste into chat or GitHub>
~~~

Ensure file mode 0600. Its MySQL privileges must be schema-only, with no administrative `*.*` privileges beyond normal `USAGE`. Keep outbound connections blocked and workers stopped.

Set these variables to your **actual distinct QA assets**, not a production path:

~~~bash
export BAMBODIA_QA_APP=/var/lib/bambodia-oauth-qa-acceptance
export BAMBODIA_QA_DB=bambodia_oauthqa_acceptance
export BAMBODIA_QA_MYSQL_CNF=/root/bambodia-oauth-qa.cnf
# Pin to the independently retrieved, latest approved PR HEAD (not the local checkout).
export BAMBODIA_QA_EXPECTED_SHA="$(gh api repos/bambodia168-sys/bambodialaravel/pulls/15 --jq .head.sha)"
test "$(git -C "$BAMBODIA_QA_APP" rev-parse HEAD)" = "$BAMBODIA_QA_EXPECTED_SHA"
export BAMBODIA_QA_PHP=/www/server/php/85/bin/php

# Zero-write environment, Git SHA and restricted database preflight:
bash "$BAMBODIA_QA_APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --check

# Only after reviewing the preflight and confirming isolation:
bash "$BAMBODIA_QA_APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --rehearse
~~~

The `--rehearse` mode:
1. Refuses an unapproved filesystem location, unexpected Git SHA, production URL, wrong database, cached configuration, shared runtime, non-isolated mail/queue/session settings, mismatched DB identity or any MySQL grant outside the dedicated QA/restore schemas.
2. Makes a root-private, SHA-256 checksummed backup of **only the dedicated QA DB**, recording existing marketplace/user row counts and table total.
3. Generates fresh, owner-restricted Passport RSA keys **inside QA only**.
4. Runs only the six explicit Passport/MCP migration paths, not unscoped `php artisan migrate`.
5. Checks the five OAuth tables, `oauth_clients.scopes`, authorization/token routes and unchanged core record counts.
6. Prints the protected pre-migration SQL snapshot location. No real website, production code, production key, customer email or ChatGPT connection is modified.

**On any failure:** stop. Retain the protected SQL backup, inspect sanitized logs and recreate an isolated QA clone rather than retrying uncertain partial state.

## Independent backup restoration acceptance

Provision **another empty QA-only schema** named `bambodia_oauthqa_restore_<unique>` and give the restricted QA MySQL account access to it. Confirm the restore schema contains zero tables. Use the exact SQL snapshot path printed by `--rehearse`:

~~~bash
export BAMBODIA_QA_BACKUP=/root/bambodia-oauth-qa-snapshots/run.REPLACE_WITH_ACTUAL/before.sql
export BAMBODIA_QA_RESTORE_DB=bambodia_oauthqa_restore_acceptance
bash "$BAMBODIA_QA_APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --restore-check
~~~

The helper checks the backup hash, refuses nonempty restore databases and imports into **only the separate restore QA schema**. It compares both baseline core row counts and total table count. It never overwrites the original rehearsal DB. Full recovery of that DB requires a separately authorized recreation from the verified snapshot; never issue unscoped DROP or migration rollback on shared staging or production.

## Remaining acceptance gates

| Gate | Evidence required | Status |
|---|---|---|
| Isolated migration and keys | Rehearsal output at pinned SHA, correct tables, private RSA keys, unchanged core records | Operator pending |
| Backup/restore | Successful separate empty-schema `--restore-check` and retained hash manifest | Operator pending |
| Real-browser consent | Dedicated HTTPS QA hostname, admin login, PKCE, CSRF, state, approval/denial, role change during consent | Pending |
| Refresh and revocation | Expiry, revocation, rotation; wrong/missing resource, audience and issuer rejection | Pending |
| MCP authorization | Four read tools and six writes on disposable isolated drafts; cross-owner, role and scope denial | Pending |
| Security review | Independent DCR rate limits, callback allowlist, consent, CSRF, scope control and package-version review | Pending |
| ChatGPT integration | Real authorized OAuth login, token exchange, tool discovery and one read call inside ChatGPT | Pending |
| Production | Separate, explicitly accepted deployment/rollback and security sign-off | **Blocked** |

A passing CI run or CLI rehearsal does not prove ChatGPT connection readiness. Leave [PR #15](https://github.com/bambodia168-sys/bambodialaravel/pull/15) in draft and preserve the live `bmb_` bearer-token service.
