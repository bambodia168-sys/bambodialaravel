# Bambodia completion and production readiness roadmap (11 November 2026)

**Owner tracking:** [#25](https://github.com/bambodia168-sys/bambodialaravel/issues/25)  
**Baseline inspected:** `935a7f58bab4d39e1a0a5684a6afe2c13bcb954b` (`main`)  
**Status:** Substantial working Laravel marketplace, **not fully commercially production-ready**. Individual feature PRs and CI passes are not proof of live acceptance.

This roadmap complements, and does not override, `production-release-gates.md`, `independent-real-host-acceptance.md`, `contract-workflows.md`, `operational-acceptance.md` and `product-license-lifecycle.md`. The most restrictive security/release gate applies.

## Verified foundation and evidence levels

- Core roles/workspaces, Builder Studio (Product, Project, Service, Freelance, Job), moderation, support, policies, posts, media, notifications, manual licensing, contracts, Security Alerts and AI Draft have source and automated test coverage.
- On the baseline SHA, six GitHub push workflows succeeded (Quality, Installer Build, MySQL Installer, Browser Emulation, Operational Acceptance, Isolated Authenticated Journeys). The Quality log reports 395 tests / 5,127 assertions. This is **source/CI evidence**.
- Prior aaPanel operator logs show scoped Builder and AI upgrades completed with backups. This is **installation evidence**, not full end-user or multi-device certification.
- MCP `bmb_` bearer-token read-only route was operator-verified, but ChatGPT OAuth 2.1 remains in draft PRs #10 and #15 and was **not** certified live.
- Commercial checkout, verified payments, refunds, automatic paid entitlements, escrow, tax invoices and payouts remain disabled and must not be represented as available.

**Status vocabulary:** `IMPLEMENTED` = code exists, `CI-PASS` = automated pass on named SHA, `HOST-PASS` = actual isolated aaPanel/cPanel evidence, `DEVICE-PASS` = physical user-device evidence, `PRODUCTION-ACCEPTED` = approved operator signoff after full matrix. Do not infer the latter three from CI.

## P0: Public content readiness and first safe development milestone — #26

1. Implement `php artisan marketplace:readiness-audit --json --strict` as a read-only report of public approved listings/jobs carrying explicit preview markers and published Support Knowledge Base coverage. **Do not delete, hide, edit, publish or seed** any content from this command.
2. Verify JSON output and non-zero strict status when review is needed. No private titles, customer emails, API credentials or source files in output. No migrations.
3. Admin individually reviews public test/preview records and approves proper unpublication or replacement via **existing authorized moderation** controls. Retain original audit/history; use backups before any actual content edits.
4. Review support drafts for accurate links, payment availability language, policy status and approved publication. Do not automatically publish seeded templates.

**Acceptance:** repeatable read-only output; no database mutations; synthetically tested clean and flagged cases; independent operator attaches sanitized report; actual public pages no longer expose unapproved preview inventory.

## P0: Authenticated real-browser workflows — #27

Use six isolated test-only accounts (customer, freelancer/creator, admin moderator, support staff, super admin, outsider). Run the `independent-real-host-acceptance.md` matrix on a truly separate DB, storage namespace, APP_KEY, host and session/cache namespace.

| Scope | Actual sign-off evidence |
| --- | --- |
| Builder Studio | All five types; title/category/media/preview; AI drafting human review; consent popup; rich text; draft/update/submit without auto-publish |
| Moderation | publish/reject; notification and audit; no unauthorized cross-account access |
| Account and Admin | nav, grouped menus, settings modal, notification feedback, ten-row pagination/go-to, outside-table actions on desktop and mobile |
| Content/help | published pages/posts/policies, read-only display, Support KB, support tickets and staff replies |
| Customer and Creator | saved items/jobs, inquiries, proposals, contracts, milestone reviews, disputes and notifications |
| Files/licenses | real file upload and integrity/rights evidence, private denial cases, complimentary entitlements, customer license action audit |

Record PASS/FAIL/BLOCKED/UNTESTED with sanitized screenshots, role, device, UTC timestamp and exact SHA. No synthetic success may be passed off as real host acceptance.

## P1: Independent hosting, security and physical devices — #28

- Isolated aaPanel and cPanel **fresh installs** of release ZIP, installer lockout, rollback/restore, isolated upgrade migration and actual Nginx/Apache PHP-FPM restrictions.
- Genuine ClamAV on benign upload and harmless EICAR smoke under PHP-FPM, freshness of definitions, SHA binding, independent rights review and authorization-controlled downloads.
- Real SSL/CDN and `.env`/vendor/private path denial; CSRF/role/IDOR tests; dependency/static analysis and independent penetration review.
- SMTP sandbox password-reset and notification delivery; queue/scheduler proof; observe failures instead of assuming mail from in-app notifications.
- Physical iPhone Safari, Android Chrome, tablet, zoom 200%, keyboard and accessibility; upload interruptions and secure downloads.
- Distinguish the installed production site from the independently accepted customer-installable product.

## P1: ChatGPT-connected MCP / OAuth 2.1 — #29

Preserve draft [PR #10](https://github.com/bambodia168-sys/bambodialaravel/pull/10) and [PR #15](https://github.com/bambodia168-sys/bambodialaravel/pull/15). Reconcile against current main and latest security/AI overlays before any merge. Verify OAuth consent, PKCE S256, resource audience, 30-minute access tokens, rotation/revocation, ownership-based read/write tool calls against disposable data; verify actual ChatGPT discovery and connection from an authorized user account. Keep OAuth nonproduction until independent security and host signoff.

## P2: Commercial workflows behind hard feature gates — #30

Implement **in increments**, after provider onboarding and jurisdiction/compliance review:
1. Financial threat model, roles, order and quote state machines, idempotent purchase records and payment-boundary abstractions.
2. Signed webhook authenticity, replay protection, server-only order settlement and disagreement/reconciliation handling.
3. Successful-payment-only version-specific entitlement and license issuance, refunds/reversals and audit trail.
4. Regional tax invoice, support renewal, creator settlement/payout and escrow eligibility only where approved.
5. Sandbox → isolated provider acceptance → approved limited rollout.

**Hard prohibition until explicitly approved:** live charging, checkout activation, marking unpaid orders paid, auto-issuing paid licenses, escrow balances, payouts and refund execution.

## P2: Launch closure

- Editorial content and licenses accurately describe the enabled features.
- Complete legal/privacy/source-rights review and accessibility/translations with native speakers.
- Confirm no debug/demo disclosures, verify secrets, logs, backup restoration, rollback plan, monitoring and alerts.
- Require dated acceptance signoffs for each release gate with explicit unresolved issues.
- Merge and deploy only a scoped release with immutable CI SHA, exact live-file comparison, backup-first read-only preflight, independent smoke and rollback.

## Operational rules

- Use drafts/PRs for source code; avoid direct production writes. No destructive cleanup in an audit command.
- Live `bambodia.com` must remain available; the independent QA database and private media must never be shared with production.
- Never paste full API keys, license keys, private records, personal data, passwords or token-bearing logs to issues or ChatGPT.
- No change to existing public frontend page layouts as part of readiness infrastructure.
- Update the linked GitHub issue and this roadmap with actual acceptance evidence only, not estimates.
