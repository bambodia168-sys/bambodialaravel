# Bambodia — Independent Real-Host Acceptance (No Production Customer Data)

## Gate status and boundaries

This is an **operator-run acceptance protocol**. CI browser journeys in
`.github/workflows/isolated-authenticated-journeys.yml` use a disposable
loopback HTTP application, isolated MySQL 8.0 and six simulated **role-real**
account sessions. This is a different acceptance layer from an external
operator logging in to a deployed aaPanel QA host or a physical phone.

**A green CI run cannot be called a successful real-host or independent
user-acceptance session.** Do not alter production accounts or run QA
seeding against the installed Bambodia database.

## 1. Create and verify an isolated staging environment

Use an independently installed **QA** Bambodia instance with:

- Its own origin, dedicated vhost and document root pointing to that QA
  application's `public/` directory. Never reuse the live site's origin.
- A **new empty MySQL database**, new `.env`, unique `APP_KEY`, separate
  private `storage/` and separate session/cache namespace. Never copy the
  live `.env`, session keys, customer tables or vault files.
- The exact GitHub release SHA under acceptance and its compiled build.
- Registration restricted as needed, test-only outbound emails routed to
  a private capture sink, and all external payment/escrow/payout connectors
  disabled. No real commercial transactions or real customer data.
- An independent operator with access to a real browser and correct
  authorized role accounts. Do not paste credentials, session cookies,
  private URLs or tokens into ChatGPT or CI artifacts.

**Stop if isolation cannot be demonstrated.** The repository UAT seed script
`tests/browser/uat-seed.php` is intentionally **not** suitable for a shared
QA database or an aaPanel production installation; it is locked to empty
localhost-only MySQL `bambodia_uat`, `APP_ENV=testing`, and a GitHub
runner's local HTTP app. Do not bypass the guards.

## 2. Dedicated QA identities

Provision six unique test-only accounts through your authorized QA
administration process. Assign the intended roles using approved tooling,
not direct edits to production identities:

| Actor | Role | Acceptance boundary |
| --- | --- | --- |
| QA client | customer | Own inquiries, support requests, contracts and notifications |
| QA creator | freelancer | Own product submissions, versions, messages and contract deliveries |
| QA moderator | admin | Decisions on queued listings and independent file reviews |
| QA support agent | staff | Ticket replies and status changes; no product approval |
| QA security owner | super_admin | Token issuance/revocation and complimentary entitlements |
| QA outsider | customer | Must not see private records belonging to other QA users |

Use unique QA marker titles. Rotate test credentials if an account was
ever exposed. Disable/revoke the identities at the end of the exercise.

## 3. Real-browser acceptance matrix

Record **PASS**, **FAIL**, **BLOCKED**, or **UNTESTED** for every case.
Do not mark PASS based only on a screenshot or an existing CI test.

| ID | Actual authenticated action | Required proof |
| --- | --- | --- |
| MOD-01 | Creator submits a valid service; moderator publishes it | Published visibility, `listing.review.published` audit, creator `listing.reviewed` notification |
| MOD-02 | Moderator rejects a different pending item | No public listing; rejection audit/notification; repeat decision denied |
| MOD-03 | Staff/customer attempt moderation | HTTP 403; no status, audit, or notification mutation |
| PRD-01 | Creator creates draft product, selects approved owned image and active license, submits | Pending product in moderator queue; cross-owner preview denied |
| PRD-02 | Moderator publishes valid product | Public listing; creator notified; queued status updated |
| RLS-01 | Creator uploads benign versioned ZIP using the real browser | Private immutable artifact, checksum, pending status; no public download or entitlement |
| RLS-02 | Attempt to approve without valid scan/rights inspection | Approval denied; no deliverable or entitlement created |
| RLS-03 | Real ClamAV scan, safe independent inspection, documented distribution-rights review | Actual PHP-FPM scanner result/version/signature date, reviewed SHA-256, reviewer identity, audited decisions |
| RLS-04 | Moderator approves qualified version | Version approved and creator notified; distinct creator and reviewer |
| RLS-05 | Super Admin grants one explicitly **non-payment** test entitlement | Recipient downloads only that exact version; outsider/other versions denied; revoke blocks new download |
| MSG-01 | Customer sends private service inquiry; Creator replies and closes | Message visible to participants, correct recipient notification, audit, outsider HTTP 404, further replies HTTP 409 |
| SUP-01 | Customer creates ticket; staff replies and changes status | Corresponding audit/notification, customer sees response; outsider HTTP 404 |
| SUP-02 | Staff repeats identical ticket status | No duplicate audit or notification |
| CON-01 | Client drafts agreement with exactly reconciled milestones, then offers | Immutable offer digest, authenticated offer consent, freelancer notification; outsider HTTP 404 |
| CON-02 | Freelancer explicitly accepts, delivers; client reviews | Active contract, milestone transitions, consents, audit, correct counterpart notifications |
| CON-03 | Freelancer acknowledges accepted work; client completes | Completed contract, events and notifications; no payment, escrow, or payout |
| DEV-01 | Super Admin creates test-only scoped token, performs allowed read-only API request | One-time secret display only, 200 for allowed scope, no token hash in HTML |
| DEV-02 | Owner revokes token after password confirmation | Immediate API 401; audit record; duplicate revoke does not create second event |
| DEV-03 | Admin/outsider or another super admin attempts another owner's token action | Correct 403/404, no token modification |

Never use genuinely malicious archives, production customers' files, real
client messages or real merchant transactions.

## 4. Host / physical-device acceptance

Run **read-only** web checks against the separately deployed QA origin:

```bash
php support/http-release-probe.php --url=https://your-qa-origin.example --strict --json
```

Check the real scanner on the **isolated QA host**:

```bash
php support/vault-scan-acceptance.php --scanner=/usr/bin/clamscan
```

The second probe uses a harmless EICAR test marker and deletes the
temporary fixture. Confirm the actual PHP-FPM worker can invoke the
configured engine; a root CLI-only success is insufficient.

Perform the authenticated journeys on physical iPhone Safari, Android
Chrome and a desktop browser. Confirm no overflow, dialogs/labels,
file pickers, downloads, theme contrast, 2FA/authorization if applicable,
and whether a session survives normal redirects.

## 5. Evidence and sign-off

For each ID retain the following **redacted** fields:

| Field | Meaning |
| --- | --- |
| ID + PASS/FAIL/BLOCKED/UNTESTED | Unique acceptance outcome |
| Tested SHA and host | Exact release, QA host version, PHP/MySQL versions |
| Timestamp + reviewer role | UTC time and which test role performed the action |
| Fixture reference | Non-sensitive QA marker, no real customer record |
| Expected / actual | Action, HTTP status, observed UI and DB state |
| Audit + notification check | Redacted event name, correct recipient role and timestamp |
| Evidence location | Screenshots or logs without passwords, tokens, cookies, private files |
| Retest + reviewer approval | Issue ref, retest outcome and second-person acceptance |

Keep test artifacts out of the public web root. Never upload session
cookies, API secrets, temporary links or a customer database snapshot.

## 6. Release decision

A release may be signed off for real-host operational acceptance only
when (a) every applicable manual case has operator evidence, (b) the five
previous CI workflows plus isolated authenticated browser journey are
green on the exact SHA, (c) no permission bypass, malware/rights gate,
data isolation or private-file exposure issue remains open, and
(d) physical-device and genuine scanner gates are independently signed.

If a real-host test is missing, state **NOT YET ACCEPTED** instead of
equating a GitHub pass with a deployed-site verification. Do not deploy
new code to production solely to run acceptance experiments.
