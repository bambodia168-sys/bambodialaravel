# Bambodia Operational Acceptance

## Scope

This gate executes the real Laravel HTTP routes and database transitions on a disposable MySQL 8.0 instance in GitHub Actions. Every workflow is authenticated as its actual role. It checks:

- Moderator publication and rejection, approved media and product readiness, queue access, audit records, recipient notifications, and replay rejection.
- Creator product submission and immutable version uploads; malware screening, artifact integrity, independent rights review, final approval, refusal to bypass review, manual non-payment entitlements, and revoked or expired download access.
- Customer and Creator inquiry creation, replies, closure, recipient notification, audit recording, localized routes, and cross-account denial.
- Staff support replies and changed statuses; recipient updates, audit events, idempotent repeats, private ticket access, and customer follow-up.
- Contracts from client draft through milestone acceptance, freelancer consent, final client acknowledgement, dispute holds, audit and notification records; no payment or escrow.
- Developer read-only access scopes, token hashing and one-time secret display, password-confirmed revocation, immediate API denial after revocation, and role restrictions.
- Existing account notification read/unread flows, user-owned activity filtering, and denial of another account's records.

## Acceptance layers

1. **Quality**: PHP syntax, Blade compilation, general feature tests and frontend build.
2. **MySQL installer**: five-step installation and private vault acceptance using isolated MySQL.
3. **Browser emulation**: authenticated responsive workflow checks in Chromium, not physical-device acceptance.
4. **Operational acceptance**: the role-authenticated end-to-end MySQL HTTP and state-transition suites listed above.
5. **Production staging**: a separate release operation, only after all four checks pass and the installed production baseline is confirmed.

## Mandatory limitations

Tests use deterministic **test-only scanner doubles** for positive/negative malware verdicts, not proof of genuine ClamAV screening in production. Legally independent license review, external provider or merchant onboarding, real-money payments, independent security penetration testing, physical-device inspection, and customer aaPanel/cPanel installation are **not** certified by CI. Payments and escrow remain disabled.

Do not create customer data, grant real entitlements, or execute operational acceptance writes on production. If a live read-only smoke probe is used, it must not mutate account or financial state.

## Release criteria

A release is not approved merely because a script compiles. Require all four independent GitHub workflows to succeed on the **exact same SHA**; verify the CI installer artifact belongs to that SHA; compare installed files against a known baseline; create a recoverable backup; and require a successful dry-run before any deployment.
