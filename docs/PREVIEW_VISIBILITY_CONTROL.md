# Super-admin preview visibility controls — guarded development (2026-11-11)

**Tracking:** [Issue #35](https://github.com/bambodia168-sys/bambodialaravel/issues/35), parent [#26](https://github.com/bambodia168-sys/bambodialaravel/issues/26)  
**Baseline:** source branch based on `9f21fc0b6934042480513d570ed357f1a639e501`; no production rollout authorized.

## Release safety

- `config/preview_visibility.php` defaults `PREVIEW_VISIBILITY_WRITES_ENABLED=false`. Merging code or installing it does **not** activate withdrawal. This is a deliberate production safety lock.
- `/dashboard/preview-content` is visible only to an authenticated `super_admin`. Other admin roles, staff, creators, customers and guests cannot access the page or mutate endpoints.
- The admin page lists 10 preview-marked published or withdrawn records at a time; forms and centered modals live **outside the table**, with public IDs and per-record relationship counts. All submitted confirmations are individually validated.
- Every action is one subject identified by kind + exact nine-digit public ID. No bulk actions, deletion or renaming exist.
- The server re-reads the dependency snapshot and checks an SHA-256 fingerprint under a database row lock. The fingerprint covers status, owner and role, title/description, media references and known customer relationship counts.
- Server rejects missing owners, changed fingerprints, non-preview titles, repeated submissions, mismatched public ID and customer-linked records. Media-linked records require a separate rights-review evidence reference.
- Successful withdrawal changes `published → withdrawn` and writes an append-only `preview_visibility_actions` history entry with actor, owner confirmation reference, backup reference, optional rights reference and reason. It never destroys the owner, media, customer conversations or public identifier.
- Restoration changes `withdrawn → pending`, logs its own action and returns the item to the existing moderation queue. It **does not republish** the item.
- Owner is notified through an existing account-notification type. Public listing and job queries already require status `published`, so withdrawn items disappear without rewriting frontend page layouts.
- Existing administration filters recognize `withdrawn` and history remains available in the database.

## Critical limitation: approvals are human attestations

The application **cannot verify from a text field** that an owner signed consent, a media license is valid, or a private full-MySQL backup really exists and restores successfully. `owner_confirmation_reference`, `rights_review_reference` and `backup_reference` are internal attestation references only; the server validates their presence and lengths, **not their factual authenticity**. Therefore keep the write switch OFF until an independent administrator has:

1. Authenticated the owner, checked actual business intent and recorded private owner consent (never post personal identifiers on GitHub).
2. Verified a full private database snapshot, file and media backups, integrity and a recovery exercise; recorded the backup manifest out of band.
3. Checked outstanding customer messages, saved items, support tickets, disputes, contracts, license entitlements and any related obligations. Customer-linked items must be **excluded** from the current withdrawal flow; the current implementation always rejects them.
4. Checked media/rights evidence on each attached record; physical files are retained.
5. Executed independent MySQL, Laravel, aaPanel/cPanel, browser and role acceptance on an isolated host with a separate DB and APP_KEY.
6. Explicitly approved an individual public ID. Only after a scoped rollout checklist may the protected write switch be enabled for operator-only action.

**Known production snapshot 2026-11-11:** 20 public layout-test listings, 5 published layout-test jobs, 2 customer-linked listings (`662814286`, `800849627`), 5 media-linked listings (`487948714`, `673785514`, `381502999`, `792285328`, `140274590`) and 18 others requiring ownership confirmation. These figures can change; always run the dependency audit before any decision. No record was changed by collecting this snapshot.

## Why the feature is disabled by default

A row lock and rechecked dependency snapshot protect the record against most stale-review problems, but some activity relationships use polymorphic IDs without a database foreign key. Separate writers may still create concurrent relationships without locking the same parent. That risk, plus the need for actual owner/backup verification, makes **real host acceptance and controlled feature enabling mandatory**. Do not assume source CI proves financial/legal or production safety.

## Developer acceptance

- `php artisan test --filter=PreviewVisibilityControlTest`
- Existing `PreviewContentReviewTest`, `MarketplaceReadinessAuditTest`, all Laravel tests, MySQL installer, isolated authenticated journeys, operational and browser CI.
- Verify guests/other roles cannot access either GET or PATCH; default-off returns 403 and no history.
- Verify an authorized synthetic item can be withdrawn and restored to pending, requiring re-approval.
- Verify one linked saved item blocks withdrawal, attached media requires rights reference, stale fingerprint rejects, confirmation ID mismatch rejects, and duplicate submit returns conflict.
- Verify no deletes, no changes to media, and notifications/history remain intact.
- Verify no change to public frontend design or enabled financial, MCP or AI functionality.

## Deployment

This PR is **source-only**. A separately reviewed, exact-file, backup-first installer must account for the migration, route/view/config changes and existing aaPanel customizations. Do not run `php artisan migrate --force` against production without release-specific tests and verified restore evidence. Do not set `PREVIEW_VISIBILITY_WRITES_ENABLED=true` on production as part of installation.
