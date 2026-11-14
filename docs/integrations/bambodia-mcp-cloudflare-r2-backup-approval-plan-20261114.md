# Bambodia MCP — Cloudflare R2 encrypted off-server backup approval and implementation plan

**Prepared:** 2026-11-14
**Decision:** Cloudflare R2 selected for *planning only*. No bucket, token, Restic password, repository, snapshot, backup, restore, schedule, or credentials have been created or assumed by this plan.
**Scope:** Existing live application behind bambodia.com, on aaPanel, whose actual app directory is /www/wwwroot/bambodia-staging-qa (name is historical; do not treat it as an isolated staging instance).
**Release policy:** PR #46 stays DRAFT and UNMERGED. Production MCP is not enabled. The entire recovery release gate is BLOCKED until independent restoration/rollback/OAuth acceptance.

## Verified starting evidence (from aaPanel operator outputs)

- Root-only approved single-file private permission correction physically succeeded and independent permission scan passed: 12 private regular files, 6 private subdirectories, 0 world-readable files, 0 world-writable files/directories, 0 symlinks, 6 group-readable files (not automatically defects). ACL, HTTP exposure and application authorization remain separately unverified.
- Live DB metadata-only inventory: 80 InnoDB tables; 0 MyISAM; 0 views; approximate allocated table+index metadata 4,538,368 bytes (NOT the size of a valid SQL dump). MySQL binary logging DISABLED: no binlog PITR can be assumed.
- DB and media are on the same local filesystem as existing aaPanel backups; this is not an independently durable off-server backup.
- mysqldump/mysql/tar were found; restic was NOT found at last host audit. /root/bambodia-mcp-recovery/approval.json was NOT present. No off-server recipient, escrow or remote access was approved.
- Any assertion above may become stale. Revalidate the source identity, effective active DB and media disks, installed tooling and permissions before future execution.

## Architecture decision (planning, not deployment)

Production MySQL transaction-consistent logical export + authorized local/private/public/remote media and config recovery inventory
  -> reviewed write-quiescent recovery checkpoint and paired manifest
  -> **Restic client-side encryption** before leaving host
  -> private, dedicated **Cloudflare R2** bucket via its S3-compatible HTTPS API
  -> independent isolated retrieval, decryption, full restore and reconciliation.

**Restic repository S3 URI shape, NOT an actual destination:**
  s3:https://<APPROVED_CLOUDFLARE_ACCOUNT_ID>.r2.cloudflarestorage.com/<APPROVED_PRIVATE_BUCKET>/<APPROVED_REPOSITORY_PREFIX>
For jurisdiction-restricted buckets choose the official jurisdiction-specific hostname instead of assuming the ordinary global endpoint. R2 S3 region is generally "auto"; restic S3 client compatibility, path-style access and region behavior require a disposable authorized connectivity test after approvals.

Never copy the example placeholders verbatim into a live credential or command. No bucket name, account ID, prefix, access key ID, secret access key or Restic password is provided here.

## Owner/security approval matrix: ALL entries pending

| Approval field | Acceptance criteria | Current status |
| --- | --- | --- |
| R2 account owner and billing | Owner verifies authorized Cloudflare account, R2 availability and billing | NOT PROVIDED |
| New vs existing private bucket | Dedicated owner-selected bucket, no mixed public media, no public custom domain or r2.dev | NOT PROVIDED |
| Bucket name and jurisdiction | Privately recorded name, location/jurisdiction, regulatory and restore-access review | NOT PROVIDED |
| S3 endpoint | Independently matched to selected account and jurisdiction | NOT PROVIDED |
| Access credentials | Owner-created dedicated R2 S3 key pair, restricted to this bucket, least necessary object operations and accountable rotation | NOT CREATED |
| R2 bucket public exposure | Private access and public endpoints disabled, independently verified after creation | NOT VERIFIED |
| Restic installation | Official distribution, checksum/signature verification, pinned version and compatibility test approved | NOT INSTALLED |
| Restic password | High-entropy owner-generated secret, root-only local reference, independent/off-host escrow with tested recovery | NOT GENERATED |
| Encryption key custody | Separate custodian, recovery contact and rotation/escrow procedure independent of server and R2 account | NOT APPROVED |
| Protection against deletion | Separate break-glass/retention approval; active Restic prune and bucket-lock/lifecycle compatibility reviewed | NOT APPROVED |
| Recovery objective | Owner-approved RPO minutes, RTO minutes, retention days and maximum downtime | NOT APPROVED |
| Content scope | Current effective MySQL credentials/privileges; DB, uploads, local/public disks, remote object disks, permissions, source artifacts and application-encryption material privately reviewed | NOT VERIFIED |
| Consistency checkpoint | Approved backup window, in-flight writes/uploads/scheduler/queues quiescence, rollback and resume plan | NOT APPROVED |
| Offsite network | Host outbound HTTPS/TLS, R2 access and nonlocal storage independently validated | NOT TESTED |
| Isolated restore target | Separate nonpublic host / database with independent credentials, egress restrictions, disabled jobs/email/payments/OAuth/MCP | NOT APPROVED |
| Operator/independent reviewers | Recorded separate owner and security sign-offs; explicit authorization before each state-changing phase | NOT APPROVED |

An apparent approved boolean in a local JSON file is insufficient proof of independent approval. The existing root-only preflight support/bambia... (see exact tool below) must not fabricate those declarations.

## Phase 0 — approval-only preparation (this document; NO API mutation)

1. Record the *choice* of R2 for architecture only, not acceptance of any specific bucket.
2. Keep endpoint/bucket/account identifiers, credentials, password and detailed private inventory out of GitHub, ChatGPT, web URLs, unprotected shell history and CI logs.
3. Independently review source DB, uploads and remote filesystem disks. Do not use the 4.33 MiB table allocation estimate to size backup costs.
4. Decide RPO, RTO, retention, change window, location/jurisdiction and legal/operational deletion controls. No actual values are assumed here.
5. Decide a separate secret escrow path for Restic and any Laravel APP_KEY or other recovery secrets; an R2 access key is not the Restic encryption password.
6. Confirm appropriate storage capacity, operation costs, retrieval costs and storage class after source sizing and policy approval. Do not infer price from estimated DB allocation.

**Phase 0 output:** a signed owner/security decision record only, containing approved nonsecret parameters and references to secrets held outside GitHub/ChatGPT. No bucket or credentials created.

## Phase 1 — R2 configuration (requires explicit separate authorization; NOT EXECUTED)

1. Owner, through approved Cloudflare account, creates or selects a *dedicated private* R2 bucket, with account/jurisdiction recorded privately. No public URL, custom domain or r2.dev.
2. Create a dedicated scoped R2 S3 credential using the smallest practical object permissions on that bucket. Restrict its lifecycle, access controls and rotation; keep write/maintenance roles separated when policy permits. Verify actual R2 permission behavior rather than assuming AWS IAM equivalents.
3. Review deletion/overwrite threat model. R2 bucket locks can protect objects from deletion and overwrite, but MUST NOT be applied blindly to an active Restic repository: the Restic repository requires maintenance, forget/prune and potential rewriting. Consider a separately managed locked *recovery copy* with independently tested compatibility and retention, rather than an untested lock on the live repository.
4. Disable automatic R2 object-expiration lifecycle rules on Restic's *active repository prefix*: age-based object deletion can corrupt deduplicated repositories. Restic retention management is a separate reviewed operation; never trigger an unapproved prune.
5. Store S3 access keys only in owner-controlled root/private credential store. No service credentials in Laravel .env unless the new process has a demonstrable reason to access them; prefer a root-owned backup-only execution context.

## Phase 2 — restic preparation and safe, isolated network test (separate authorization)

1. Install verified/signed or checksummed Restic release on operator-controlled host after version compatibility review. Source server's prior last-audit finding was RESTIC NOT_FOUND.
2. Use the authorized Cloudflare R2 S3 HTTPS endpoint; do not run R2 access checks with fictional account IDs. Keep AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY secret; configure S3 client region per R2 docs and actual tested Restic version.
3. Store the independently generated Restic password via a root-only file or secure password-command, with separate off-host escrow; do not log it, hash it into chat, place it on the backup server only, or depend solely on the live aaPanel host.
4. Run a limited *disposable repository or test-prefix* connectivity/write/read/delete drill on a distinct approved temporary scope. Verify private access and cleanup; do not initialize the production repository or upload production customer data in this phase.
5. Verify access-token rotation and revocation, transfer failure behavior, checksum behavior, API budget and object operations that Restic actually needs.

## Phase 3 — first full encrypted offsite recovery checkpoint (separate approval; NOT EXECUTED)

**Before transaction:** obtain approval for effective active MySQL database identity, dump privileges, storage scope, media inventory, maintenance/quiescence window, rollback path and exact approved repository. Re-run local source-identity checks. All 80 discovered tables were InnoDB at audit time but verify again.

1. Quiesce application writes, background queue workers, scheduler, uploads and other integration writers as required. Laravel maintenance mode alone may not stop queue/webhook writes. Record the controlled point in time and safe resumption steps.
2. Create a transaction-consistent MySQL logical backup using a reviewed MySQL 8 client; use Restic's \`backup --stdin-from-command\` so the child command's failure is detected. Do not run a raw \`mysqldump | restic backup --stdin\` pipeline that could silently accept incomplete data. Never put passwords into process argv.
3. Back up privately approved local/private/public media and any remote disks at the same controlled checkpoint; capture symlink/mount/permissions metadata with approved handling. Do not accidentally include temporary backup artifacts or publicly readable logs. If media are remote, define versioned or otherwise consistent snapshot coverage.
4. Pair DB and media Restic snapshot IDs using the same opaque run identifier in a protected source/restore manifest. Preserve application decryption material (e.g. Laravel APP_KEY where necessary) in **separate** encrypted owner-controlled escrow; don't activate production OAuth signing keys in a restore clone.
5. Upload encrypted data **only** to the independently confirmed off-host R2 repository. Avoid unencrypted SQL dumps or full customer export files anywhere on the live filesystem; if staging is unavoidable, use a separately approved encrypted temporary volume, explicit cleanup and privacy controls.
6. Treat any nonzero Restic result, unreadable source file, incomplete media inventory, changed DB identity, unexpected new writes or failed remote check as NOT ACCEPTED. Do not automatically delete the last known good checkpoint.
7. Resume writes and jobs with documented checks and protected audit evidence; no implicit OAuth/MCP enablement.

**No MySQL binary logging is enabled** per prior operator audit; this is periodic checkpoint recovery, NOT true binlog point-in-time recovery. Snapshot cadence must meet the approved RPO without claiming unimplemented PITR.

## Phase 4 — independent restoration and evidence (mandatory, separate approval)

1. From an independently administered restore environment network-isolated from production, with only explicitly allowlisted R2 outbound access, independently retrieve and decrypt the checkpoint using escrowed secrets. Verify firewall, credentials, domain/DNS, app identity and DB isolation before import.
2. Turn off outbound email/SMS, queue workers, scheduler, webhooks, payments, notifications and all MCP/OAuth entrypoints. Do not point the clone at live R2 application disks or production SaaS credentials.
3. Restore schema/data and all approved media to dedicated nonpublic destinations. Reconcile table counts, migrations, ownership/role relationships, licenses, orders, protected records and media hashes, using only opaque/redacted audit proofs.
4. Test sample authorized application workflows, media access, restore decryption and rollback time against the approved RTO. Verify full Restic repository integrity at suitable intervals and independent restore usability; snapshot existence is not sufficient.
5. Have an independent reviewer approve actual restoration results. Record exceptions and a go/no-go decision; no customer data/raw exports in GitHub or chat.

## Phase 5 — ongoing operations after restoration acceptance (NOT ACTIVATED)

- Establish explicit schedules meeting *approved* RPO; detect overlap and stalled jobs; report encrypted snapshot IDs, sizes and masked status only.
- Manage retention through reviewed Restic \`forget\` and \`prune\` with a separate maintenance authority and off-hours planning. Do not apply automatic object lifecycle expiration to live Restic packs.
- Consider independent immutable copy and separate break-glass authority to reduce deletion/ransomware risk. Validate R2 bucket-lock conflict with prune before adopting it.
- Monitor failures, last known successfully *restored* checkpoint, key-expiry/rotation, storage consumption and restore drill results. Periodic independent recovery tests are required.
- Keep production MCP and writes OFF until separate recovery, rollback, application entitlement and OAuth approvals.

## Reuse existing fail-closed operator checks

- \`support/bambodia-mcp-offsite-backup-approval-preflight-20261113.py --inspect\` — existing metadata-only approval preflight. It reads the optional root-only approval JSON at \`/root/bambia-mcp-recovery/approval.json\` [NOTE: use the actual script constant; see below] but does not prove remote connectivity, perform a backup or authorize operations.
- **Actual manifest path from the code:** \`/root/bambodia-mcp-recovery/approval.json\`. Do not create a manifest with fabricated truth values.
- \`support/bambodia-mcp-isolated-restore-preflight-20261113.py\` — local restore-environment flags only, not independent proof of network isolation or restored data.
- \`support/bambodia-mcp-restored-manifest-reconcile-20261113.py\` — compares opaque, root-private source and restored manifests, not a substitute for independent data provenance.

The approved JSON manifest schema (booleans required true only **after** actual sign-off; numeric objective fields required positive), not an authorization to populate them:

\`\`\`json
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
\`\`\`

**Only after approvals** can the actual repository string be set to the privately validated s3:https Cloudflare R2 URI and the password file point to an existing root-only file. The preflight prints only approval declarations; independent reviewer sign-off, R2 privacy/remote access and key escrow still need separate proof.

## Relevant official documentation (checked 2026-11-14)

- Cloudflare R2 S3 endpoint, scoped R2 API credentials: https://developers.cloudflare.com/r2/api/tokens/
- S3 API / region behavior: https://developers.cloudflare.com/r2/api/s3/api/
- Private/public bucket exposure: https://developers.cloudflare.com/r2/buckets/public-buckets/
- R2 location and jurisdiction decisions: https://developers.cloudflare.com/r2/reference/data-location/
- Bucket locks: https://developers.cloudflare.com/r2/buckets/bucket-locks/
- Lifecycle deletion: https://developers.cloudflare.com/r2/buckets/object-lifecycles/
- Restic S3-compatible backend and repository passwords: https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html
- Restic stream from command and backup exit codes: https://restic.readthedocs.io/en/stable/040_backup.html
- Restic retention and prune: https://restic.readthedocs.io/en/stable/060_forget.html

## Current GO / NO-GO

**R2 architecture proposal: PREPARED. Provider-specific account/bucket/token/key approvals: MISSING. Backup and Restore: NOT PERFORMED. Production state: UNCHANGED by this plan. PR #46: DRAFT/UNMERGED. MCP production release: BLOCKED.**

No account/bucket/credential/token/password values have been invented or created.

## Phase 0.1 — real Cloudflare account discovery and provider-specific preflight (2026-11-14)

An authorized read-only Cloudflare API inventory found **two Cloudflare accounts**, including one named **Bambodia**. That account lists **two R2 buckets**, neither named in a way that indicates a dedicated Restic/recovery repository. The real names and account IDs remain excluded from this document. Neither existing bucket was selected or modified. This inventory does not prove privacy, suitability, credentials, recovery access, or independent backup ownership. The owner must approve a specific private account/bucket/jurisdiction before any mutation.

Provider-specific offline root-only tool: `support/bambodia-mcp-r2-approval-readonly-20261114.py --inspect`. It cannot create buckets, tokens or keys; connect to S3/MySQL; install Restic; initialize a repository; or upload, back up or restore anything. It checks only existence and secure metadata of a future root-only approval manifest and private credential files; its status output excludes all account, bucket, prefix and credential values.

Additional private manifest fields required before provider-specific approval can even be declared (all currently UNAPPROVED): `r2_account_id`, `r2_bucket_name`, `r2_jurisdiction` (matching selected account endpoint), `r2_credentials_file` (root-private file), and the booleans `r2_private_bucket_verified`, `r2_scoped_s3_credential_reviewed`, `r2_endpoint_independently_verified`, `r2_repo_prefix_no_expiry_verified`, `r2_restic_retention_maintenance_approved`, `r2_independent_offhost_secret_escrow_verified`. The earlier generic owner/security signoffs, approved RPO/RTO/retention, DB/media scope, quiescence, rollback plan and isolated restore target are also required.

Even when all declarations are present, the result is `COMPLETE_NOT_INDEPENDENTLY_PROVEN`, **not backup authorization**. The scripts do not create or assume a destination, R2 keys or a Restic secret. The current state remains **NO BUCKET CREATED / NO BACKUP / NO RESTORE / PR DRAFT / RELEASE BLOCKED**.

## Gate 2.4 — owner-identified existing R2 bucket "bambodia" (read-only verified 2026-11-14)

The owner named the **existing** bucket `bambodia` for proposed backup storage. Connected Cloudflare API was used **READ-ONLY** to verify its state in the Cloudflare account named Bambodia:

- Bucket `bambodia` **EXISTS**, created 2026-05-24; bucket location **APAC**, jurisdiction **default/non-jurisdictional** (not a selected eu/us/fedramp jurisdiction).
- Managed public `r2.dev` domain is disabled.
- **One custom domain is enabled**, its SSL and ownership statuses are active. Cloudflare documentation explains that a connected custom domain can deliver bucket objects publicly; whether any additional Access policy applies or which objects exist was **NOT VERIFIED**. Never assume the bucket is private merely because `r2.dev` is disabled.
- Lifecycle inspection returned **one enabled multipart-upload abort/cleanup rule**, not an identified broad object-expiration rule. No bucket lock rules were returned.
- No R2 object names or contents, access keys, account IDs or custom domain names were read or printed; no bucket modification, domain change, key creation, Restic installation, repository init, backup or restore occurred.

**Decision: `bambodia` is NOT APPROVED as the encrypted backup destination while the enabled custom domain remains and independent privacy/tenant/Access review is missing.** Do not set `r2_private_bucket_verified=true` for this bucket. Do not attempt `restic init` or upload a backup into its current namespace. Do not disable its existing custom domain without a separate, explicit owner impact review and change authorization; it may serve existing production assets. The safe default is an owner-named **new dedicated private R2 backup bucket** with managed `r2.dev` disabled, zero enabled custom domains, separate scoped credentials and validated retention/key custody. The owner has not yet chosen the new bucket name or authorized bucket creation. An existing bucket may only be reconsidered after independent proof that all paths and object keys are appropriately private, and explicit owner/security signoff.

Official documentation: https://developers.cloudflare.com/r2/buckets/public-buckets/ and https://developers.cloudflare.com/r2/reference/data-location/

**Gate remains BLOCKED**: no approved private R2 destination, secrets, key escrow, manifest, approved RPO/RTO/retention, backup or isolated restore. PR #46 remains DRAFT/UNMERGED; production remains unchanged.
