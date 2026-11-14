# Bambodia MCP — Cloudflare R2 encrypted backup & isolated restoration execution gates

**Prepared:** 2026-11-14  
**Live code status:** untouched. PR #46 remains DRAFT; no merge or deployment.  
**Storage destination:** approved, created `bambodia-backup` in the Cloudflare account named *Bambodia*; default/non-jurisdictional, APAC, Standard storage.
**This file is a restricted-action implementation runbook, not a command to back up now.**

## Verified provider conditions

Live Cloudflare API GET checks, after the bucket was created, found: the bucket exists; `r2.dev` public delivery disabled; no custom domains; no object-expiration lifecycle rules (only multipart-upload abort cleanup); no bucket-lock rules. These checks establish appropriate public R2 settings as of inspection, not safe API-token custody or the full data-recovery chain. Existing `bambodia` (media/public) bucket is separate and must remain untouched.

**The previous physical aaPanel preflight returned:**
`RESTIC_TOOL=NOT_FOUND`, no root-private approval manifest, no authorized R2 S3 credentials or encryption password escrow, no repository initialized, no backup/restore executed. Do not claim those gates were passed because a private R2 bucket exists.

## Before starting any state-changing stage

- Record explicit owner + independent security reviewer approval and signoff *for the specific stage*.
- Agree recovery objectives (RPO in minutes, RTO in minutes, retention in days). Do not silently choose a policy in a script; the existing source evidence has MySQL binlogging disabled, so no binlog PITR is available.
- Verify the **effective** live database using a privately authorized admin audit. The prior metadata scan found 80 InnoDB tables, 0 MyISAM, but the physical database identity, credentials, privileges, version, MySQL client and future DDL cannot be inferred from table counts alone.
- Verify complete local private/public storage and any remote application disks (including the **separate** existing R2 media bucket) and whether write jobs/queues can be safely held at a common checkpoint.
- Identify the team responsible for off-host escrow of (a) the Restic repository password, (b) any required Laravel APP_KEY/application decryption secrets, and (c) a way to regain the R2 account/credential access **when the aaPanel server is lost**.
- Identify and approve a separate nonpublic restore host, isolated MySQL database and blocked email/SMS/payments/webhooks/scheduler/queues/MCP/OAuth. Cloudflare R2 access for a restore worker should be narrowly permitted; no production account/DB targets.
- Validate lifecycle/immutability interaction: no automatic object expiration on an active Restic repo. Don't turn on bucket lock for an active Restic repository unless a tested design explains how retention/prune/maintenance will work.

## Phase 1 — owner-managed S3 credential creation (not executed)

Use Cloudflare dashboard **R2 > API Tokens > Create** for the Bambodia account. Select only bucket `bambodia-backup` and `Object Read & Write` permissions as required by Restic's S3 repository; apply reasonable expiry/rotation restrictions after recovery-review approval. The existing `bambodia` public/media bucket should NOT be accessible by this token.

The **Access Key ID and Secret Access Key are revealed at creation**. Deliver directly to an authorized host operator using a secure, non-chat/non-GitHub method and install only into a root-owned 0600 private credential file under a 0700 parent directory. Never paste a token, Access Key ID, Secret Access Key, restic password or secrets into prompts, GitHub, CI logs, issue comments or public shell command-line flags. Account-scope API tokens and S3 credentials are different security layers; check the exact Cloudflare permission model and S3 API access.

For the eventual Restic backup, the operator can export `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` only inside the root backup process, with no shell tracing and no child output logging. Cloudflare R2 S3 region is `auto` for the default endpoint. Verify these assumptions in a disposable authorized test before touching the production repository.

Provider docs: https://developers.cloudflare.com/r2/get-started/s3/ and https://developers.cloudflare.com/r2/api/tokens/

## Phase 2 — Restic password + independent escrow (not executed)

Generate a long, cryptographically random Restic repository password **under root-controlled host administration**, store it at a root-only path separate from the R2 credential file, and transfer a recovery copy to an **independent off-host custodian** before initializing the repository. Prove the custodian can retrieve and use it from a separate environment without dependence on the live aaPanel host. Do not upload an unencrypted password file to the same R2 bucket or put it into GitHub. Consider separate approval and split/dual-control recovery access, then document a tested key-rotation plan.

Also escrow any application decryption material required to read restored encrypted Laravel records. It is not the same as the Restic repository password. Do not import live OAuth signing keys or enable production webhooks into a restore clone.

## Phase 3 — version-pinned Restic installation & disposable R2 connection test (not executed)

1. Review latest compatible Restic release and integrity verification, installation location and dependency changes. Avoid running arbitrary downloaded install scripts as root. Install only after host-operator approval.
2. Use an owner-approved temporary object namespace or disposable test prefix/bucket, narrowly scoped credentials and a separately disposable Restic key.
3. Test S3 authentication, encrypted object write, read, checksum, delete and recovery from a different authorized environment. Confirm no public domain can serve test objects and no unrelated bucket is accessible.
4. Do NOT initialize `bambodia-backup`'s production Restic repository, configure cron, `forget` or `prune` until the private approval manifest and isolated-restore design have been reviewed.

Restic reference: https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html

## Phase 4 — consistent, encrypted MySQL + media checkpoint (not executed)

The eventual **explicitly owner-authorized** execution procedure must:

1. Revalidate live app/vhost and effective DB/media identities; verify free space, credentials, MySQL transaction consistency, table engines and DDL behavior. Schedule a reviewed application write-quiescence/rollback window; Laravel maintenance mode alone does not guarantee queue/webhook/upload writers stop.
2. Create a consistent MySQL logical export and send it directly to Restic **without a plaintext intermediate SQL file**. Preferred Restic form is `restic backup --stdin-filename database.sql --stdin-from-command -- mysqldump [reviewed options]`; it checks the child exit status and cancels failed dumps. Use an approved MySQL client config file, never password flags in process argv. Use `--single-transaction` only when all participating tables/DDL behavior make that safe.
3. Back up the approved local/private/public filesystem media (and separately versioned remote media sources if used) at the same controlled checkpoint, without crossing unreviewed mounts or following unreviewed symlinks.
4. Record matched opaque database and media snapshot IDs plus source coverage and recovery time in root-private evidence. Never upload raw SQL, screenshots of credentials, live private paths, customers' names or public recovery URLs.
5. Verify Restic output and snapshot inventory, check repository integrity at a separately approved level, then resume authorized production writers with observed checks. A successful upload alone is NOT acceptance.
6. Reject partially readable media, inconsistent SQL, missing remote object versions, unexpected DDL, token scope errors or incomplete snapshots. Fail closed and preserve the last known good snapshot; never automatically prune on failure.

Restic references: https://restic.readthedocs.io/en/stable/040_backup.html and https://restic.readthedocs.io/en/stable/045_working_with_repos.html

## Phase 5 — isolated restore & reconciliation (not executed)

- Recover from the independent off-host escrow into a nonpublic, isolated host/DB, not to the live site. Use only controlled egress to the approved backup bucket.
- Restore the paired DB/media checkpoint; match schema, migrations, table/record counts, ownership/role/permission links, media hashes, storage mode and app decryption capability. Disable external integrations, scheduler, queues, mail, payments, OAuth and MCP.
- Rehearse restarting from restored code/assets, test rollback after simulated failure, log redacted audit evidence, and measure recovery against approved RTO.
- Have an independent security reviewer verify ownership and acceptance. Only then consider regular schedules and retention; keep production MCP release separate.

## Operator-only, read-only execution readiness inspection

Tool: `support/bambodia-mcp-r2-recovery-execution-readiness-readonly-20261114.py --inspect`. It checks only root-private manifest metadata, reviewed source path declarations, credential file metadata and explicit source/restore approval flags. It does **not** connect to Cloudflare/MySQL, open private media contents or credentials, execute Restic, create artifacts, initialize a repo, send backups or restore. Even complete manifest declarations are reported as **NOT INDEPENDENTLY PROVEN** and do not authorize execution.

This tool intentionally adds the following private manifest fields, which must remain absent/false until independently proven:

- `recovery_runbook_version`: 1
- `mysql_backup_strategy`: `single_transaction` only after confirming MySQL table/DDL suitability
- `mysql_defaults_file`: root-only existing MySQL client options file
- `local_media_roots`: exact verified local private/public storage root paths; remote media tracked separately
- `mysql_export_consistency_reviewed`
- `nontransactional_tables_excluded_or_locked`
- `schema_change_and_ddl_quiescence_reviewed`
- `queue_scheduler_uploads_quiesced_plan_reviewed`
- `remote_media_sources_accounted_for`
- `encrypted_sql_streaming_reviewed`
- `root_private_mysql_client_custody_reviewed`
- `isolated_restore_network_and_database_independently_verified`
- `application_decryption_secrets_escrow_independently_verified`
- `restoration_acceptance_criteria_approved`

The prior R2 preflight additionally checks exact R2 account ID, `bambodia-backup` bucket, global/default jurisdiction, endpoint format, private bucket exposure, S3 credentials and Restic password existence, owner and reviewer decisions, numeric RPO/RTO/retention, escrow and lifecycle safety. Both preflights must pass their respective declaration gates and an **independent manual approval** before a production backup can begin.

## Owner approval reference template — intentionally unapproved

The version-controlled [R2 recovery approval reference JSON](bambodia-mcp-r2-recovery-approval-template-20261114.json) pre-fills only the already owner-selected `bambodia-backup` / default jurisdiction identifiers; **all 25 approval booleans are false, recovery objectives are null, R2 account ID and repository are empty, and every private credential/password path is empty**. This is a policy reference only, not an operational manifest. A protected reviewer-created manifest must be generated directly under root-only custody *after* actual approvals. Do not commit completed approvals, bucket S3 credential material, the live DB name, or any secrets to the repository.

## Synthetic end-to-end Restic acceptance (isolated CI ONLY)

The draft branch contains `tests/security/bambodia-mcp-r2-restic-synthetic-restore-qa.sh` and a dedicated GitHub workflow `bambodia-mcp-r2-restic-synthetic.yml`. The workflow installs Ubuntu's packaged Restic on an ephemeral runner, generates an ephemeral local-only password, creates a **local-only synthetic Restic repository**, saves disposable SQL-like and media fixture snapshots, checks repository integrity, restores both into a separate runner-local directory, verifies exact fixture content, and asserts that a failing stream child cannot create a valid backup snapshot. It cannot access production MySQL, Bambodia aaPanel, R2, existing credentials or a live Restic key. This validates Restic mechanics only, **not** production backup, R2 S3 network compatibility or independent real-host recovery.

This test must pass independently of the five existing CI workflows before referring to encrypted-backup/restore tooling as synthetically accepted. Even if all synthetic checks pass, **no production snapshot or restore is implied**.

## Explicit unresolved decisions (NO values invented)

| Item | Status |
| --- | --- |
| Selected R2 bucket `bambodia-backup` / private public-domain posture | VERIFIED |
| Scoped S3 API credentials created and safely distributed | NOT DONE |
| Restic password generated and independent custodian verified | NOT DONE |
| Laravel application decryption secrets escrowed | NOT DONE |
| RPO (minutes), RTO (minutes), retention (days) | NOT APPROVED |
| Recovery source DB, public/private/remote-media scope | NOT INDEPENDENTLY ACCEPTED |
| Root-only private manifest/secure credentials | NOT PRESENT at last aaPanel preflight |
| Restic installed and integrity-checked | NOT INSTALLED at last aaPanel preflight |
| Repository initialized or encrypted backup captured | NOT DONE |
| Separate isolated restore/reconciliation | NOT DONE |
| Overall production OAuth/MCP release | BLOCKED |

**No automated assumption of approval or guessed credentials is allowed.**
