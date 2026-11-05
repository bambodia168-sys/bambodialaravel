# Bambodia Production Release Gates — Digital File Vault

**Current status:** SOURCE IMPLEMENTATION COMPLETE FOR THIS HARDENING MILESTONE; PRODUCTION ACTIVATION BLOCKED.

Bambodia uses an explicitly gated Laravel 13 digital product file vault. The release is not approved for production until independent staging evidence is recorded for the actual aaPanel and cPanel host environments, actual installed antivirus engine, rights review, hosting-level private file transfers, and physical devices.

Passing a GitHub unit test or a MySQL test is NOT the same as approving a customer deployment. Browser emulation does not replace device testing. No payment gateway, verified paid entitlement, checkout, refund, tax invoice, escrow, payout, or real-money transaction is enabled.

## 1. Security policy built into the source

- Downloadable product originals (ZIP, PPTX, PDF, maximum 25 MiB) are stored on the Laravel local disk at storage/app/private/marketplace/originals/, outside public/. Laravel's local disk has serve=false; callers must use authorization-checked download controllers.
- The creator must identify rights-holder and distribution provenance before an original can be uploaded. The creator may update rights evidence on pending or previously approved versions; doing so invalidates previous rights clearance. Binary versions cannot be silently overwritten.
- An authorized administrator runs actual ClamAV against the original file from the private storage path. No shell command is built from user input. The scanner must be an executable absolute path; missing executable/signatures, outdated definitions, scan errors and threats produce non-clean outcomes.
- Scanner version and definition database revision/date are checked. Definitions older than VAULT_MAX_DEFINITION_AGE_DAYS (default 7) fail closed. Clean scan results are valid for VAULT_MAX_SCAN_AGE_DAYS (default 30), with a stored timestamp, engine information and SHA-256 of the exact inspected file.
- Inspecting the original for approval is blocked until there is a fresh clean scan. The inspector's account is logged.
- An independent authorized reviewer records a documented rights-verification or rejection decision after inspecting the original. The source rights evidence and actual written reasoning are retained. "Verified" means an operator recorded an assessment; it is not an external legal opinion.
- Release approval requires a current clean scan, a matching SHA-256, independent rights review, and affirmative approval by the administrator who inspected the artifact.
- Previously approved versions that lack these new fields are **not** grandfathered. Customer downloads are blocked until real rescreening and rights documentation are completed.
- A later positive threat detection automatically rejects a pending release or disables an approved release. Missing/stale results, invalid rights or changed evidence prevent download even if an entitlement exists.
- Only Super Admins may grant or revoke temporary manual non-payment entitlements; recipient roles remain Customer, Subscriber and Freelancer. Entitlements remain linked to a specific approved immutable version. No automatic paid access is created.

**Malware limitations:** ClamAV is signature-based and cannot guarantee the absence of every malicious file. ZIP structure, compressed-entry size and checksum checks are additional controls, not an antivirus substitute. Advanced sandbox/dynamic analysis, SBOM, software supply-chain attestations and third-party IP clearance are not automated in this milestone.

## 2. Host preflight — separately on aaPanel and cPanel

Run on isolated staging hosts installed from the intended customer ZIP. The operator must supply the host's actual document root, not infer it from a domain name.

Read-only command (run from the Laravel project root):

    php support/hosting-preflight.php --webroot=/path/to/laravel/public --json --strict

A nonzero exit is a **release blocker**, not a warning to ignore. This checks PHP version/extensions, private storage path and permissions, exact public document root, installer retirement, production/debug settings, PHP upload/body limits, ClamAV executable and definition age. It also lists requirements that can only be established with real HTTP requests and reviewer evidence.

For aaPanel, independently check that the domain's Nginx configuration points to /public and that the actual PHP-FPM worker, not only root's PHP CLI, can invoke ClamAV, write private originals, and handle the 25 MiB body. The Nginx client_max_body_size and PHP-FPM request/memory limits must permit the configured upload size.

For cPanel, confirm that the account can set its web document root to the application's actual /public directory and that user-owned PHP-FPM may invoke a genuine maintained ClamAV binary. Some shared hosting providers prohibit process execution or do not provide ClamAV. In those environments, **do not disable the security gate**. The vault is not production-ready until an approved scanning integration has been independently built and validated.

Do not run the fresh installer on an existing database. Back up customer data and private file storage. Separately test non-destructive Laravel migration upgrades on both hosts.

## 3. Actual antivirus engine smoke acceptance

Run on each isolated staging host, with permission from the server operator:

    php support/vault-scan-acceptance.php --scanner=/usr/bin/clamscan

The script runs real ClamAV on a temporary harmless clean text fixture and an EICAR test-signature fixture assembled in memory, then deletes the temporary fixtures. It must report a clean verdict for the first and a detected test signature for the second. A mocked scanner used by unit tests does not satisfy this requirement.

Store the engine version, definition revision/date, timestamp and test result with the host acceptance evidence. Confirm signature updates are scheduled and reliable. Run one additional controlled scan from the Admin Digital File Vault UI on a benign upload to verify that PHP-FPM can invoke the engine (CLI alone is not enough).

**Do not run unknown malicious samples or untrusted executable files as part of acceptance.** The harmless EICAR signature is sufficient for scanner integration smoke testing but not a guarantee that new threats are detected.

## 4. HTTPS staging and private path exposure

After staging installation:

    php support/http-release-probe.php --url=https://staging.example.test --json --strict

This read-only probe checks the HTTPS certificate, public pages, installer retirement, and that environment files, vendor directories and private file paths are not accessible over the web. It does not send credentials or download customers' private files.

Confirm the same results through the real reverse proxy or CDN, without bypassing the normal live route. Additional hosting file operations require authenticated browser acceptance.

## 5. Exact upload/download acceptance matrix

Execute on both aaPanel and cPanel with separate staging Client, Creator, Admin and Super Admin accounts. Use a fresh database, verified permission groups and a benign original file:

1. Creator uploads a valid ZIP, PPTX and PDF as separate versions of an actually published licensed product. Test 0-byte, unsupported extension, invalid archive, symlink and traversal attempts are rejected. Confirm private storage location and SHA-256 of each accepted original.
2. Confirm a pending file cannot be downloaded by a client, even with a manually supplied guessed URL. The original must not be reachable under /storage or via a temporary URL. No public symlink must expose private originals.
3. With ClamAV absent, erroring, carrying stale definitions or returning a threat, confirm an Admin cannot approve. Confirm the same gate blocks manual entitlement grant and customer download.
4. Install, update and verify the real ClamAV engine. Run scanning from the Admin dashboard under the actual PHP-FPM account. Record engine, revision, scan date, original SHA-256 and pass/fail evidence.
5. After the clean scan, the Admin inspects the original on a safe workstation, records real rights-holder/license evidence and grants an independent rights review. Confirm the user cannot self-approve.
6. Approve the version. Super Admin issues a **complimentary/manual** time-limited entitlement to one Customer account, including a reason. Verify the customer may download only the exact entitled version.
7. Confirm a different customer, anonymous visitor, Admin without a granted entitlement, and a second version with no grant cannot download. Check status codes, no-store/nosniff headers, filename sanitation, no leaked file path and audit download events.
8. Revoke the entitlement, expire it and disable the version in separate scenarios. Each must immediately block a new download. Run a file-tampering test only on a disposable staging fixture; any SHA mismatch must block download.
9. Test the upper supported upload size and 1 MiB, 10 MiB and 25 MiB files through the actual HTTP stack, not only PHP tests. Verify nginx/Apache limits, PHP-FPM execution timeout, memory and private disk capacity. Check interrupted upload behavior and that the response never sends a truncated file as successful.
10. Update the creator's rights declaration, or set the previous scan timestamp beyond its validity. Existing customer entitlement must temporarily become unusable until the required checks are repeated.

These acceptance records must include host name, platform, PHP version, MySQL version, test-user role, sanitized file identifier, version and SHA-256, scanner revision, tester, timestamp, screenshot or log path, and explicit PASS/FAIL result. Never store passwords, private keys, sessions or user personal data in evidence attachments.

## 6. Browser and physical-device acceptance

CI runs Chromium **emulation** at desktop 1440×900, tablet 820×1180, iPhone 390×844 and Android 412×915 sizes. It visits homepage, search, jobs, creators and login, checks for obvious horizontal overflow, and verifies an authenticated account can save a listing. Screenshots are retained as short-lived GitHub workflow artifacts.

The following remains **manual and unverified** until an operator records actual evidence from devices:

- Physical iPhone Safari: portrait/landscape, menu, dialogs, form focus, file picker, authenticated download and open-in-files.
- Physical Android Chrome: orientation, file picker/large upload, notifications, CSRF forms and a real ZIP/PDF download.
- Physical tablet Safari/Chrome: navigation, 10-row tables, admin Configure dialogs, rights note fields and upload review.
- Accessibility: keyboard-only dialogs, focus visibility and return focus, labels, screen-reader announcement, reduced motion, zoom up to 200%, language selection and RTL if later supported.
- Resumable mobile connection/network interruptions and browser security download warnings.

## 7. Release gate register

| Gate | Source/CI evidence | Independent production status |
| --- | --- | --- |
| Upload format, archive inspection, SHA-256 and role authorization | Laravel security tests | Staging HTTP/hosting proof required |
| Fresh-install MySQL | GitHub MySQL installer workflow | aaPanel/cPanel fresh installs not independently verified |
| MySQL vault upload, rights, entitlement, download | CI MySQL tests | Real PHP-FPM host upload/download outstanding |
| Real ClamAV execution on stored originals | Implemented in backend; unit test scanner injected | Missing until per-host scanner+signature smoke passes |
| Rights provenance | Explicit creator declaration and reviewer decision | Rights-holder documentation and legal clearance outstanding |
| Responsive browser coverage | Chromium viewport emulation workflow | Physical Apple/Android/tablet unverified |
| Private root / PHP permissions / HTTPS | CLI preflight and HTTP probes supplied | Must run against actual staging host |
| Paid checkout, refunds, invoices, payouts | Not in this release | Disabled and blocked pending separate eligibility and compliance |

**Approval decision:** A green CI run advances source quality, not live payment eligibility or a customer release. The operator must separately sign off each real host and physical device after storing evidence. Do not deploy to bambodia.com as part of this acceptance milestone without explicit production change authorization.
