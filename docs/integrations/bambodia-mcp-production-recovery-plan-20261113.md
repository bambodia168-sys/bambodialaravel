# Bambodia MCP — production recovery readiness (2026-11-13)

**Status: preflight only / NOT RELEASE-APPROVED.** PR #46 stays DRAFT and unmerged. No deployment, migrations, live database/media backup, restore, OAuth activation or ChatGPT linking is authorized by this document.

## Existing evidence

- The two-Blade private candidate passed real PHP 8.5 localhost OAuth/PKCE HTTP and homepage/blog/jobs smoke tests using disposable SQLite data and temporary keys. It has not passed real production Nginx/PHP-FPM.
- The prior aaPanel metadata-only audit found one possible database archive about 34 days old, no archives in the expected site-backup directory, and 12 files in private app storage versus 3 in public app storage. It did NOT establish a complete media inventory, effective production DB connection, fresh backup or recoverability.
- Five CI workflows passed on the prior head. New commits require their own checks.

## Gate 1 — DB/media topology (read-only tool prepared)

Tool: support/bambodia-mcp-production-topology-readonly-20261113.sh --inspect

Download it by immutable commit after independently checking the Git blob hash, run only under the site operator's control and share only its masked summary markers. The script requires a pinned current live app path, Nginx vhost, Composer manifest and lock. It opens the private live .env with symlink protection and a strict size limit; it reports only configured driver categories, presence flags, private/public media file counts/bytes, storage-link correctness, filesystem separation and capacity, and feature-switch hints. It does not print DB names, hostnames, usernames, passwords, bucket names, customer filenames, SQL or URL values. It does NOT connect to MySQL, read actual media bytes, copy files, write backups, reload services or touch application tables.

The Laravel repository defines storage/app/private as its local disk, storage/app/public as its public disk, and optional S3/R2 disks. FILESYSTEM_DISK and MEDIA_LIBRARY_DISK can select other stores. DB_URL, cached Laravel configuration, host-injected environment and PHP-FPM settings may override .env hints, so this audit deliberately says EFFECTIVE_DB_CONNECTION=NOT_VERIFIED_NO_DB_ACCESS. It does not establish the actual live DB or remote object coverage.

Expected results: MCP_TOPOLOGY_LIVE_IDENTITY=PASS, MCP_TOPOLOGY_AUDIT=READ_ONLY_COMPLETED and MCP_PRODUCTION_RELEASE=BLOCKED. Stop and investigate any changed vhost/Composer hash, unsafe env, unexpected storage symlink, scan error/truncation, different filesystem, remote storage or non-explicitly-off feature switch.

## Gate 2 — approved encrypted backup (NOT EXECUTED)

1. Under restricted access, confirm the effective database engine/host/database/user, table engines, migration state, binlog/PITR settings and the actual current app connection; separately confirm local and cloud media roots and object versions. Do not paste identifiers or credentials into chat.
2. Agree owner-approved RPO, RTO, recovery scope, retention, encryption/key custody, maintenance window and storage target with a separate reviewer. Choose a consistent database snapshot method valid for all table engines and synchronize media to the same recovery point; a transaction-only dump may be insufficient for nontransactional tables.
3. Make a fresh encrypted DB+media recovery set outside the public document root and a protected off-server copy. Keep encryption keys apart from archives; privately verify sizes, cryptographic digests, decryptability and completeness using opaque artifact references. Never put backup files, keys, raw data or identifying manifests in GitHub/ChatGPT.
4. Backup creation is intentionally NOT included in the topology inspector; the separately approved procedure must be reviewed against actual source and mount findings before execution.

## Gate 3 — isolated restore (NOT EXECUTED)

Restore only into a dedicated inaccessible host/database, with production DNS, email, scheduler, queue workers, webhooks, payments and external integrations OFF. Do not import or activate production OAuth private signing keys or let an isolated app contact production services. Test with appropriately secured temporary credentials and keys where application decryption requirements allow.

Reconcile schema/migration versions, table and record counts, account permissions and ownership, products/projects/services/jobs/blogs, orders, licenses and support records, and media object checksums, file permissions and disk coverage. Store only redacted counts/digests and reviewer evidence. Never sample or publish customer records.

## Gate 4 — rollback rehearsal (NOT EXECUTED)

Pin the previous safe code artifact, Composer lock/vendor version, built Vite assets and corresponding DB/media recovery point. Rehearse code/assets revert, schema/data and uploads recovery, Passport/OAuth token revocation, disabled MCP feature gates and interrupted-migration recovery, without connecting the rehearsal to live production. Check duration against approved RTO and capture failures privately. No production rollback or changes authorized in this phase.

## Gate 5 — real-host OAuth acceptance (NOT EXECUTED)

Only after recovery and source-reconciliation approvals, independently test production-equivalent Nginx/PHP-FPM routing, HTTPS protected-resource root and nested discovery, issuer/resource exactness, Authorization header forwarding, approved callback allowlist, PKCE S256, administrator consent and denial, refresh/revoke, bearer scope/ownership and hidden/blocked write tools. Conduct desktop/mobile browser acceptance and independent security review. Local PHP built-in HTTP tests are not real Nginx/PHP-FPM acceptance.

## Release controls

After Gates 1–5 and explicit approvals, deploy initially with both MCP feature switches OFF, perform a further authorized read-only enablement, then user-initiated real ChatGPT linking. Writes remain OFF until a separate independent acceptance and owner approval.

**Current verdict: recovery gate BLOCKED; production remains unchanged; PR #46 remains DRAFT / UNMERGED.**


## Gate 1.2 — completed physical private-media permissions audit (2026-11-13)

The authorized operator ran the SHA-verified, metadata-only private-mode inspector against the pinned live application, after the five commit-specific CI workflows passed. There were **12 regular files in 6 private directories**, no symlinks, no nonregular objects and no truncated scan. The private root has other-read and other-traverse mode bits and a fully other-traversable ancestor chain. Exactly **one private file** is world-readable with an other-traversable path. Six files have group-read bits. **Zero** files or directories were world-writable. These counts do not prove internet exposure or establish the file's sensitivity; ACLs, application serving routes, per-user ownership and Nginx rules were not evaluated. No private filenames/content were printed or read; no production permissions were changed.

**Required privacy remediation review (not performed):**

1. An authorized host operator must identify the single affected pathname privately on the server (do not publish it), verify it is within the intended private storage root, and inspect its owner, group, mode, ancestor traversal permissions, optional ACLs, and any service-user access assumptions. If a named file is a customer/media artifact, handle its path and contents as sensitive.
2. Confirm whether Laravel's entitlement-controlled controllers, Nginx/static aliases or any unexpected storage mount might make it accessible over HTTP. Do not derive web URLs from private filenames or test them against public traffic without explicit authorization.
3. Prefer the **narrowest file-specific** change preserving the effective PHP-FPM user's necessary access; plan the change and rollback against existing ownership and ACL configuration. Do **not** run recursive chmod/chown, change the private root globally, or assume six group-readable files are necessarily unsafe.
4. Independently review the intended fix, carry it out during approved change control, re-run the original metadata-only inspector, and verify actual authorized upload/download and entitlement checks plus absence of unauthorized local read access. Preserve only masked metadata and reviewer signoff.

## Backup-design decision after database identification

The live metadata inspector matched the configured MySQL schema and found **80 InnoDB tables, 0 MyISAM, 0 views**, and approximately **4,538,368 bytes (4.33 MiB)** of allocated table/index metadata. The schema's \`migrations\` and \`users\` tables are present; the Passport access-token table is not present. **Binary logging is disabled**, so do not describe a binary-log-based point-in-time recovery capability. A transaction-consistent InnoDB backup is a plausible approach, but the information-schema allocation estimate does not equal a complete dump size or establish free space/recovery consistency.

**Before any fresh backup operation**, require an approved recipient/off-server destination, authentication and encryption/key custody mechanism, protected retention policy, RPO/RTO, actual source DB scope, media/asset/secret coverage and a restore/reconciliation test design. Capture backup artifacts only outside the public document root. The eventual backup procedure must use the live database's actual account/privileges and MySQL version and must not introduce plaintext exports or customer data into chat, GitHub, CI logs or shared paths. As the current app/media and local aaPanel backup directories share one filesystem, only an independently verified off-server copy protects against host-level loss. A fresh dump by itself does not close the recovery gate.

**Decision:** physical topology and file-mode inventory completed; **one local permission finding open**; fresh encrypted off-server backup, isolated restore, rollback and real-host OAuth acceptance still **NOT PERFORMED**. Release blocked; PR #46 remains draft and unmerged.


## Gate 1.3 — root-private one-file remediation proposal (PREPARED, NOT EXECUTED)

The operator tool support/bambodia-mcp-private-file-review-local-20261113.py accepts --inspect or --prepare-local-review. It checks pinned live app/vhost/Composer identities, traverses private storage without following symlinks, and prints only aggregate file-mode statistics. On explicit --prepare-local-review, it creates a 0700 root-only directory and a 0600 JSON plan identifying the single affected relative filename, original owner/group/mode, device/inode/link count, and a proposed mode that removes ONLY the other-read bit. The private report pathname is printed, but never the original file's name or content.

The plan is not permission-change authorization. An independent owner/security reviewer must inspect the root-private report locally, check file/ancestor modes and ACLs, hard links, PHP-FPM service identity and needed access, app entitlement rules and Nginx/static serving. Do not paste the report, full paths or ACL details into chat/GitHub. If a one-file correction is approved, record the exact original mode and ACL in an owner-only location, recheck the inode immediately before change, apply only the approved single-file mode/ACL correction, and verify authorized access and the absence of unauthorized local reads. The rollback restores the exact original mode/ACL ONLY for the same verified inode and after an approved backout decision. Never run recursive chmod/chown or infer public HTTP exposure solely from a world-readable mode bit. Production permissions remain unchanged now.

## Gate 2.1 — off-server encrypted-backup approval preflight (PREPARED, NO BACKUP)

Tool: support/bambodia-mcp-offsite-backup-approval-preflight-20261113.py --inspect. It verifies pinned app identity, checks installed tool availability, and optionally reads only a root-private approval JSON manifest at /root/bambodia-mcp-recovery/approval.json. It never contacts MySQL or a remote repository, scans customer data, executes a backup or prints repository identifiers/secrets.

Proposed encryption/backup candidate: restic with a separately approved remote repository. The following manifest is a **template**; false/null/empty values deliberately block approval. Do not insert fictional approvals.

    {
      "owner_approved": false,
      "security_reviewer_approved": false,
      "off_server_destination_independently_verified": false,
      "separate_encryption_key_custody_approved": false,
      "db_scope_and_privileges_privately_verified": false,
      "media_disks_and_content_consistency_reviewed": false,
      "approved_change_window_and_write_quiescence": false,
      "isolated_restore_target_confirmed": false,
      "rollback_artifact_and_plan_reviewed": false,
      "rpo_minutes": null,
      "rto_minutes": null,
      "retention_days": null,
      "repository": "",
      "encryption_engine": "restic",
      "restic_password_file": ""
    }

Actual repository and password file must be decided and approved privately, with separate key custody. Store the approval JSON and secret file outside the document root, root-owned with restrictive directory/file modes; never paste their contents. An approval boolean is only a declaration and does not independently establish true offsite ownership, network access, reviewer identity or key escrow. This preflight always reports BACKUP_CREATED=NO, RESTORE=NOT_PERFORMED and RELEASE=BLOCKED.

**Proposed production procedure after approval (NOT EXECUTED):**

1. Confirm exact effective database identity/version/GTID and backup privileges privately. All observed 80 tables use InnoDB; binary logging is disabled, so do not promise binary-log point-in-time recovery. The roughly 4.33 MiB allocated table metadata is not an accurate dump size.
2. During a reviewed consistency window, prevent incompatible DDL and coordinate upload/media modifications and jobs with the DB snapshot. A MySQL single-transaction dump is logically consistent for InnoDB but does not make separately mutable local/remote media consistent. Do not indiscriminately pause production without a rollback plan.
3. Use a reviewed MySQL 8 dump-client invocation and restic's --stdin-from-command option for streamed encrypted snapshots, rather than an unverified shell pipe that can conceal dump failure. Credentials must be passed via protected private client configuration, never argv or CI logs. Capture local/public media and any remote disks using a consistent recovery point and record paired snapshot identifiers in private evidence. Separately secure application encryption material and recovery secrets in owner-approved encrypted escrow.
4. Verify the encrypted copy is truly off-server and independently restore the complete DB/media set before declaring the backup usable. Snapshot existence or upload success alone is NOT recovery acceptance.

Reference guidance:
- MySQL single-transaction backup constraints: https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html
- Restic streamed backup and child-command failure detection: https://restic.readthedocs.io/en/stable/040_backup.html

## Gates 3–5 — staged acceptance and rollback (NOT EXECUTED)

Gate 3: Independently restore the approved encrypted DB/media pair into a separately isolated, nonpublic host/schema with outbound mail, webhooks, payments, scheduler, job workers, public DNS and real OAuth integration disabled. Prove target separation from production first. Reconcile database tables/schema/migration batches, role/ownership links, licenses/orders, user/customer records by opaque counts, and media file hashes/permissions. Never output personal data. A new Laravel APP_KEY alone may make encrypted original records unreadable; any escrowed key handling needs an approved decryption procedure. No production OAuth private keys are to be activated in the clone.

Gate 4: Rehearse the pinned prechange app code, Composer/vendor, Vite assets, schema and corresponding media snapshot together, test interrupted migration and failed release rollback, invalidate test OAuth tokens, and measure duration against approved RTO. Do not rely solely on switching MCP off to undo database changes.

Gate 5: Only after real backup/restore/rollback evidence and independent approval, test Nginx/PHP-FPM HTTPS discovery, Authorization propagation, OAuth PKCE, callback restrictions, admin consent/denial, refresh/revoke, access/ownership and read-only tools. Independent desktop/mobile acceptance and ChatGPT account linking remain separate release gates.

**Current status: private-file review PROPOSAL prepared; no live permission changes, no approved off-server destination/key custody, no backup, no restore, no rollback, no MCP activation. PR #46 DRAFT/UNMERGED; production release BLOCKED.**


## Gate 3.1 — isolated restore target environment preflight (TOOL PREPARED, NOT EXECUTED)

Tool: support/bambodia-mcp-isolated-restore-preflight-20261113.py --inspect --restore-root /var/lib/bambodia-mcp-restore-qa/INSTANCE

The path above is an *example private workspace convention*, not an existing installed or approved host. The script intentionally refuses the production document root, paths outside /var/lib/bambodia-mcp-restore-qa, ancestor symlinks, missing Artisan source and cached Laravel configuration. It reads only selected nonsecret environment flags from a private restore clone. The script requires explicit APP_ENV=testing, APP_DEBUG=false, loopback APP_URL, MAIL_MAILER=array, QUEUE_CONNECTION=null, CACHE_STORE=array, SESSION_DRIVER=array, logging/null broadcast, both MCP switches false and a supported test DB driver. It never connects to a DB, performs a restoration, rekeys customer data, runs migrations, changes permissions or emits sensitive paths.

IMPORTANT: Even if all environment flags are PASS, the script explicitly emits ACTUAL_DATABASE_IDENTITY=NOT_VERIFIED and HOST_NETWORK_ISOLATION=NOT_VERIFIED. A dedicated reviewer must independently prove the restore host's firewall/egress limitations, source/target schema separation, no scheduled jobs or live credentials, and restored data integrity. A restored customer's encrypted Laravel data may require separately escrowed application key material; never reuse live OAuth signing secrets in tests. No physical isolated restoration or rollback rehearsal has happened.

Synthetic unit tests under tests/security/test_bambodia_mcp_recovery_preflight.py exercise the private-file detection, symlink rejection, incomplete backup approvals, and unsafe restore mail/queue/MCP/public URL flags. The Quality workflow lints these inspectors and executes tests. CI success does NOT substitute for physical off-server backup and restore acceptance.
