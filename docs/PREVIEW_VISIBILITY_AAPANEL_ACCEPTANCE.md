# Bambodia Preview Visibility — independent aaPanel QA acceptance

**Status: NOT YET ACCEPTED.** This is a proposed release gate for Issue #35 after merged PR #36 (`133f82a3e8681f3efbc8de9424a83dc3306e15d5`). It does **not** authorize deployment to `bambodia.com`, migration of the current aaPanel installation, withdrawal of any of the 25 observed public test-marked records, or activation of production writes.

## 1. Strict isolation before any application commands

A different operator must create a **new installation**, not copy or reconfigure `/www/wwwroot/bambodia-staging-qa`. Give it a dedicated QA vhost, dedicated document root pointing only to the new instance's `public/`, and a new empty MySQL database. The QA database name **must** match `bambodia_previewqa_...`, and its restricted MySQL username must match `pvqa_...`. Use a distinct `.env`, newly generated `APP_KEY`, `storage/`, local sessions/cache, local private media, and a mail `log` or `array` driver. Keep payment, payout, escrow, real SMTP and external storage connections disabled. Use no production accounts, private media, credentials, or real customer records.

For example, an operator-controlled path **different from the protected installed app** may be `/var/lib/bambodia-preview-qa-20261111`. Provision it from the exact reviewed GitHub commit or a checksum-verified build. Keep the release SHA, build checksum, and vhost configuration in **private** operator evidence. Verify the new instance is **not** exposed under the production document root, and that the deployed code and compiled assets match the signed-off release. Never run the browser UAT seed at `tests/browser/uat-seed.php` on this host; that seed is guarded for a different, loopback-only CI database.

Set on the new QA instance only:

```dotenv
APP_ENV=testing
APP_DEBUG=false
APP_URL=https://YOUR-DEDICATED-QA-HOST
DB_CONNECTION=mysql
DB_DATABASE=bambodia_previewqa_example1
DB_USERNAME=pvqa_example1
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MEDIA_LIBRARY_DISK=local
MAIL_MAILER=log
PREVIEW_VISIBILITY_WRITES_ENABLED=false
```

Generate separate keys/passwords privately; do not paste an `.env` or any secret into GitHub comments, ChatGPT, or issue screenshots. Do not use a production APP_KEY or production MySQL user. The example values are **templates**, not credentials or proof that a host exists.

## 2. Read-only QA safety gate

In a **manual migration rehearsal** with a dedicated `.env` already created and its own Composer dependencies installed, run this read-only gate **before creating any schema**. Substitute the actual dedicated origin; the expectation must match `APP_URL` exactly:

```bash
export BAMBODIA_PREVIEW_QA_ISOLATED=1
export BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN=https://YOUR-DEDICATED-QA-HOST
php support/preview-visibility-qa-preflight.php --before-install
```

It must print `"status":"PASS"` in JSON. A `BLOCKED` result is a stop, **not** permission to relax the check. The gate rejects the existing aaPanel application paths, non-testing environments, `bambodia.com`, reused/non-QA MySQL naming, remote mail/queue/default storage, a missing dedicated environment, a nonempty database, and an enabled withdrawal switch. This cannot independently prove DNS ownership, vhost isolation, truly separate credentials/APP_KEY, or absence of other outbound integrations; an administrator must verify those out of band.

The native five-step installer is a **separate path**: it creates `.env`, performs migrations and provisions exactly one Super Admin, so **do not precreate `.env` or run `--before-install` for native installer acceptance**. First independently verify an empty dedicated QA MySQL database, separate vhost and storage, complete the QA-only installer, then set `APP_ENV=testing` and the safe drivers listed above in the generated QA `.env`, and clear config cache on that QA instance. The installer-generated Super Admin must be the only user. For either native installer or manual-migration rehearsal, run this check **before any additional fixture accounts are created**:

```bash
php support/preview-visibility-qa-preflight.php --after-install
php artisan migrate:status
php artisan route:list --name=dashboard.preview-visibility
```

The post-install gate requires the visibility-history schema and all relevant tables; it permits zero users (manual migrations) or **exactly one installer-created Super Admin**, and requires zero listings, jobs and visibility-history records. It still requires writes disabled. Store sanitized logs and the exact release commit, UTC timestamp, PHP-FPM and MySQL versions; never store an APP_KEY, credential, cookie or private file.

## 3. Backup and **actual restoration** exercise — QA data only

Take a consistent dump of the **isolated QA database** with a restricted, non-production MySQL client configuration (0600, outside the web root), using `mysqldump --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --triggers`. Back up the isolated `.env` *privately* and QA `storage/` with protected file ownership. Confirm archive hashes, `gzip -t`, disk free space and a full archive listing. A successful backup command alone is **not** a restore proof.

Provision a **different, new, empty** restore schema named `bambodia_previewrestore_...` and a separate restricted restore account. Import the dumped SQL there, not into the QA source database. Compare source/restored table inventory, exact row counts for every table, schema/migration state, and SHA-256 of any QA private media copied into a separate restoration directory. Boot a **separate** restored Laravel instance with its own host, **restored QA APP_KEY** from the securely backed-up QA `.env` (needed to decrypt any encrypted QA data), isolated session namespace and separate `.env` (never the original QA origin or directory) and validate representative read-only routes. Record who performed the restore and the result. If any compare or startup check fails, **BLOCK**. Never restore into the existing aaPanel installation, and do not interpret a local database import as successful disaster recovery for production.

## 4. Independent role and lifecycle acceptance

After the empty-state gate, use an approved QA-only process to create distinct **synthetic** accounts: Super Admin, Admin, Staff, Creator/Freelancer, Customer and Outsider. Seed separate fake preview-marked listing and job records; create protected test fixtures for a saved listing, a job proposal, a media attachment and a stale review. No real customer or historic preview ID may be used.

| Test ID | Test on isolated QA host | Required result |
| --- | --- | --- |
| PV-01 | Visit the review page as guest, Admin, Staff, Creator, Customer and Outsider | Login redirect for guest; 403 for all non-Super Admin accounts |
| PV-02 | Super Admin opens `/dashboard/preview-content` with switch **off** | 10-per-page review inventory; controls visibly locked; attempted PATCH is 403 with zero state/history writes |
| PV-03 | Independently enable writes **only in disposable QA**, one approved synthetic listing | Exact typed nine-digit ID, current fingerprint, explicit reason and references required; published → withdrawn, one audit event |
| PV-04 | Revisit homepage, search and detail URL | Withdrawn item absent from public discovery, not disclosed by public detail endpoints; private owner/admin history retained |
| PV-05 | Restore the same synthetic listing once | Withdrawn → **pending**, second immutable history entry; still absent from public results until new moderation |
| PV-06 | Attempt repeat withdrawal/restore and mismatched typed ID | 409/422 as appropriate; no duplicate audit or owner notification |
| PV-07 | Add synthetic saved customer activity / inquiry / support comment | Withdrawal blocked even if a form was reviewed before that activity; existing relationship remains intact |
| PV-08 | Add a synthetic job proposal / contract relationship | Withdrawal blocked; no lost application, contract, message or entitlement history |
| PV-09 | Add synthetic media to another preview listing | Rights-review reference required, actual media remains intact and private; reference alone is **not** proof of rights |
| PV-10 | Edit title, owner, status, description or attached media after a review snapshot | Stale fingerprint rejected; re-review required |
| PV-11 | Attempt unauthorized PATCH with valid-looking form and CSRF/missing-CSRF cases | Roles remain denied; invalid CSRF rejected; zero state/history changes |
| PV-12 | Test in actual iPhone Safari, Android Chrome, tablet and desktop browser | Dialog focus and closing, keyboard navigation, viewport, 10-row pagination, audit table, top-right feedback, accessible form labels |

An independent reviewer must inspect database states, notifications, server logs and actual HTTP status, not merely click success banners. Test actions involving a disposable database must be logged privately, and `PREVIEW_VISIBILITY_WRITES_ENABLED=false` must be restored immediately afterward. The preflight modes intentionally require **empty QA fixtures**, so neither mode is applicable after lifecycle tests have created records. Independently verify the disabled value using `php artisan config:show preview_visibility` and ensure no cached config inadvertently keeps writes enabled.

## 5. Release decision register

| Gate | Current evidence | Required for acceptance |
| --- | --- | --- |
| Merged source and five CI workflows | Recorded against PR #36 head | Preserve immutable SHA and workflow URLs |
| Completely separate aaPanel QA and empty MySQL | **Not verified** | Two-person isolation review + before/after preflight output |
| Backup and independently booted restore | **Not verified** | Exact QA-only dump, media copy, row/table comparisons, restored QA boot |
| Default-off / role isolation on real host | **Not verified** | PV-01–PV-02, PV-11 logs |
| One-item withdrawal, restoration and blocks | **Not verified** | PV-03–PV-10 on disposable records only |
| Physical devices and accessibility | **Not verified** | PV-12 with real device evidence |
| Live record-specific owner approval and media rights | **Not approved** | Private human consent and rights review, separately per public ID |
| Production migration and release installer | **Not prepared/approved** | Separate pinned, backup-first release review; explicit owner approval |

**STOP / ROLLBACK:** On any unexpected real customer ID, shared DB/storage, permission bypass, missing history event, public exposure, credential leak or failed restoration, disable writes, freeze QA activity, preserve sanitized evidence and report the failure to Issue #35. Do not attempt automatic live cleanup. Preserve the two customer-linked listings and five media-linked listings identified in Issue #35. Counts may change: re-run `marketplace:preview-review --json --strict` as a read-only production audit before any later owner-level decision.

**Current decision:** No aaPanel production installation or content withdrawal is authorized by this runbook. It supports **independent evidence collection**, not automatic approval.

## 7. Executable host acceptance tools and safe order

The repository now provides operator-executed, **QA-only** helpers. None has been run on the actual aaPanel host by the GitHub pipeline.

**A. First run a fully read-only aaPanel inventory.** On the actual aaPanel root terminal, after verifying the source file comes from the independently reviewed PR commit, use:

```bash
bash support/preview-visibility-aapanel-discovery.sh --check
```

This reports PHP and service availability, protected application presence, disk free space and whether the proposed QA directory exists, without reading secrets or changing files. The discovery helper by itself never creates a QA instance or verifies a vhost.

**B. Before fixture creation, run the preflight** as described in section 2. After creating test-only fixture accounts and records, the read-only ongoing guard is:

```bash
export BAMBODIA_PREVIEW_QA_ISOLATED=1
export BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN=https://YOUR-DEDICATED-QA-HOST
/www/server/php/85/bin/php support/preview-visibility-qa-preflight.php --audit
```

This mode validates the same protected isolation settings and permits only test-marked listing/job titles. It requires writes to remain disabled; it does **not** prove all source media/identities are synthetic or authenticate a rights holder.

**C. Prove QA backup and restoration** only after creating a truly separate, EMPTY `bambodia_previewrestore_...` schema and restricted restore MySQL credentials. Use two distinct, private `0600` MySQL option files outside all web directories. These must be provisioned by the host operator without publishing credentials. Set the source app path, exact QA source database name and restore database name explicitly:

```bash
export BAMBODIA_PREVIEW_QA_ISOLATED=1
export BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN=https://YOUR-DEDICATED-QA-HOST
export BAMBODIA_PREVIEW_QA_PHP=/www/server/php/85/bin/php
bash support/preview-visibility-qa-restore-proof.sh --check \
  /var/lib/bambodia-preview-qa-20261112 \
  bambodia_previewqa_example1 bambodia_previewrestore_example1 \
  /root/previewqa-source-client.cnf /root/previewqa-restore-client.cnf
```

Only if the **empty restore database** and protected path checks pass, perform the scoped restore:

```bash
export BAMBODIA_PREVIEW_QA_RESTORE_CONFIRM=bambodia_previewrestore_example1
bash support/preview-visibility-qa-restore-proof.sh --apply \
  /var/lib/bambodia-preview-qa-20261112 \
  bambodia_previewqa_example1 bambodia_previewrestore_example1 \
  /root/previewqa-source-client.cnf /root/previewqa-restore-client.cnf
```

This script refuses all database names outside the dedicated QA and restore prefixes, refuses existing restore tables, rejects an enabled withdrawal switch, verifies application DB identity, and never writes to its source database. It produces compressed backup evidence in a private root directory, compares restored table inventory, **exact row counts and extended MySQL checksums**, extracts synthetic local media separately and verifies SHA-256 hashes. A failed restore is **not automatically dropped or retried**. Its PASS result is explicitly limited to **QA DB+media restoration**; a separately booted recovery app on its own HTTPS origin, restored QA encryption key and file/media access must still be tested before real-host sign-off.

**D. Run actual HTTPS Chromium read-only acceptance.** From a trusted QA workstation with Playwright installed, create a JSON file outside any web root with `0600` permissions containing exactly six *disposable* role entries, each with `email` and `password`: `super_admin`, `admin`, `staff`, `freelancer`, `customer` and `outsider`. Never publish or paste this file. With at least one synthetic marked listing and one marked job in the QA DB, run:

```bash
export BAMBODIA_PREVIEW_QA_ISOLATED=1
export BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN=https://YOUR-DEDICATED-QA-HOST
export QA_BASE_URL=https://YOUR-DEDICATED-QA-HOST
export QA_REAL_BROWSER_ACK=readonly-synthetic-only
export QA_BROWSER_ROLES_FILE=/root/private-previewqa-roles.json
node tests/browser/preview-visibility-real-host.mjs
```

The script requires **HTTPS and an exact independent QA origin**, refuses Bambodia's production origin, logs in as the six test roles using separate browser contexts, verifies default-disabled actions and server 403s for non-Super Admins, and tests real browser dialogs at multiple emulated viewport sizes. The script performs **no withdrawal or restore POST/PATCH**. Manual PV-03–PV-11, actual external account authorization evidence, cross-browser Safari, physical devices and full accessibility acceptance still require an independent reviewer.

**E. Record physical device evidence separately.** On actual iPhone Safari, Android Chrome and tablet Safari/Chrome, use the private QA URL and synthetic test-only accounts; document actual model/browser version, UTC time, tested SHA, responsive/dialog/navigation behavior, public absence after controlled withdrawal, restoration to pending only, keyboard/focus where available, screenshots without credentials, and reviewer sign-off. Do not mark real physical devices PASS from Chromium emulation. Keep the 25 actual production-marked records completely out of this matrix.

The dedicated GitHub workflow `Bambodia Preview QA Restore Proof` runs the same DB/media restore script on a disposable CI MySQL service. Its success validates the helper only; it cannot certify the real aaPanel filesystem, PHP-FPM, Nginx, database isolation, or physical devices.
