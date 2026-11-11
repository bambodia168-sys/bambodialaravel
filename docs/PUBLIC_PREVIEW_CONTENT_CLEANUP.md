# Bambodia public preview-content review and reversible cleanup runbook

**Parent:** GitHub issues [#25](https://github.com/bambodia168-sys/bambodialaravel/issues/25) and [#26](https://github.com/bambodia168-sys/bambodialaravel/issues/26)  
**Observed on 2026-11-11:** 20 publicly available `[LAYOUT TEST]` listings, 5 published `[LAYOUT TEST]` jobs; 26 published and 7 draft Knowledge Base articles.

## Rules

1. A preview marker is evidence of a *test-labeled title*, **not proof that its ownership, contractual obligations, attachments, licenses or customer actions are disposable**.
2. Preserve customer data, messages, media, licenses and historical records. Never mass-delete or rename preview content to pass an audit.
3. Release the separate dependency review report first; use the real aaPanel PHP 8.5 interpreter as `www`. The command is READ ONLY and prints no customer names, emails, media paths, licensing keys or private conversations.
4. Any publication withdrawal is a **separate, privileged and reversible transaction** requiring explicit operator approval and a verified database backup. This milestone contains no withdrawal command, no status-writing route and no hidden auto-cleanup.

## Step 1 — Read-only dependency assessment

After deploying the reviewed source via an independently accepted, backup-first release, run:

```bash
cd /www/wwwroot/bambodia-staging-qa
runuser -u www -- /www/server/php/85/bin/php artisan marketplace:preview-review --json --strict
```

Exit 2 means manual review is required. The report includes a public ID, title, owner presence/role, attached media reference count, related-record counts, a change-detection fingerprint and review classification for up to 50 public test-labeled items. The counts include *all* matching items, even when the sample is truncated. **No record is deemed automatically safe for unpublication.**

The dependency checks cover:
- **Listings:** owner presence, saved listings, service inquiries, detail comments, contextual support tickets/resources, attached featured/cover/gallery media, product file versions, entitlements, download events and issued licenses.
- **Jobs:** owner presence, saved jobs, proposals, contracts, detail comments, contextual support tickets/resources and featured/gallery media.
- An item with any customer activity is classified `customer_activity_blocked`. Missing ownership or media require separate verification; none of these outcomes grants publishing authority.
- Audit events in the existing general `audit_events` table do **not** contain record IDs, so history attribution remains a **manual review** and must never be described as automatically cleared.

## Step 2 — Administrator classification and signoff

Record an internal restricted review worksheet (do not paste private account details to GitHub) with:
- Exact public ID, kind, submitted title and snapshot fingerprint.
- Owner/creator identity verified in the authorized dashboard, and whether the owner confirms the listing is a test.
- Related saved items, proposals/contracts, inquiries, support threads, media, file rights and customer licenses checked.
- Decision: `keep`, `withdraw_after_backup`, `blocked_customer_activity`, `needs_owner_confirmation`, or `needs_rights_review`.
- Date, reviewer and approver; redact customer data in issue updates.

**Hard block:** do not withdraw a record with a contract, proposal, entitlement, license, customer inquiry, support relationship or saved item until the accountable administrator has separately addressed those obligations. Do not withdraw unknown-owner records without confirming who is responsible. Media references require rights and attachment preservation review.

## Step 3 — Backup-first reversible withdrawal (NOT IMPLEMENTED YET)

The next independent PR should add a super-admin-only, CSRF-protected, per-record action in the existing administrator workspace. It must:
- Re-check all source status, public ID, owner and dependencies inside a locked database transaction; reject stale fingerprints.
- Require explicit record-specific approval, reason and a separately verified full MySQL backup reference.
- Change only the visibility status of the single authorized record; do not delete associated uploads, threads or rights evidence.
- Persist record ID, previous/new status, actor, reason, date and rollback reference to a dedicated immutable action history, with tests proving authorization, concurrency safety, reversibility and privacy.
- Refuse automatic bulk withdrawal, leave active disputes/contracts/licenses blocked, and support explicit administrator-approved restoration with the same checks.
- Leave public UI layouts, MCP, AI provider and payment flags unchanged.

Only deploy this functionality after dependency report review and independent test acceptance. Do not use `UPDATE marketplace_listings` or `DELETE` statements directly from shell for cleanup.

## Step 4 — Replace test content with real offerings

Invite authentic, authorized creators and employers to submit accurate listings through Builder Studio and the existing review queue. Do **not** fabricate offers, replace titles alone or publish Knowledge Base drafts automatically. If removing previews leaves the homepage/Jobs page empty, display the platform's truthful empty state rather than misleading public content.

## Step 5 — Verification and milestone handoff

After individually approved withdrawals, run both `marketplace:preview-review --json --strict` and `marketplace:readiness-audit --json --strict`. Cross-check exact records in homepage, categories, search, listing details and Jobs page. Expect both reports to pass only when preview markers are removed from public content and published help coverage is maintained. Keep backup, rollback plan, approved action history, CI SHA and real-device screenshots. Only then mark the content portion of issue #26 accepted and continue issue #27's six-role UAT.

## Current acceptance

- [x] Real audit installed and observed: 25 test-labeled public items, 26 published help articles.
- [x] Read-only dependency-review code and isolated tests authored.
- [ ] GitHub CI and scoped aaPanel installation for review command.
- [ ] Actual owner/media/activity report from aaPanel.
- [ ] Per-record administrator signoff.
- [ ] Independent withdrawal functionality and rollback acceptance.
- [ ] Real content verification and issue #27 role tests.
