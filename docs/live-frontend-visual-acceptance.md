# Bambodia — Live Public Frontend Visual Acceptance

This suite reads only **public** pages on https://bambodia.com. It does not
log in, submit forms, trigger API writes, create orders, change settings, or
use a customer database. It checks the homepage, search, four type-filtered
collections, and public jobs in Chromium at desktop/tablet/iPhone/Android
viewport sizes in both light and dark modes.

## Access limitation observed on November 6, 2026

The initial GitHub-hosted Chromium browser received HTTP **403** for the live
homepage. The same public homepage and type-filtered search pages were
retrievable through normal web access. A 403 from GitHub's hosted runner is
an **access-control blocker**, not evidence that the site's layout failed.

Do not bypass the site's bot, firewall or authentication protections or
allowlist all third-party automation IP ranges just to make this check pass.

## Run on your own macOS computer using installed Google Chrome

Open **Terminal on your Mac** (not the aaPanel production server). Have
Node.js and Git installed. Use a separate disposable working folder:

~~~bash
mkdir -p "$HOME/Bambodia-Visual-Acceptance"
cd "$HOME/Bambodia-Visual-Acceptance"
git clone --depth 1 https://github.com/bambodia168-sys/bambodialaravel.git source
cd source
npm install --no-save --ignore-scripts playwright
BAMBODIA_BROWSER_CHANNEL=chrome node tests/browser/live-frontend-readonly.mjs
~~~

The installed browser's normal site access controls remain in place.
If a browser or network blocks the test, the report says **BLOCKED**;
do not interpret that as a layout pass or try to circumvent the block.

The script writes evidence to:

- live-frontend-evidence/acceptance-report.json
- live-frontend-evidence/screenshots/

The report distinguishes **PASS**, **FAIL**, **BLOCKED** and **SKIP**. Device
sizes are automated Chromium emulations, not a physical iPhone or Android
acceptance certificate.

## What to verify manually from screenshots

1. Header height, logo visibility, dropdown clipping, hero proportions,
   section hierarchy and footer spacing in light and dark mode.
2. Search filter width, label readability, the selected type tab and
   the order/stacking of filters versus results at each viewport size.
3. If publicly approved listings exist, check their actual images,
   card hierarchy, prices and preview crop.
4. If public detail URLs exist, check the preview, main/sidebar alignment,
   section anchor targets, the product's actual approved media, correct
   license information and honest payment availability message.
5. Confirm guest-only contact sign-in links on Project, Service and Freelance
   pages. Actual private inquiries and role-based actions require the
   independently isolated authenticated QA process.

## Current production content limitation

At the time of the initial public audit the marketplace reported
**zero approved items** for each type. Those pages can be inspected for
layout and empty-state correctness. The actual product images, listing
cards and individual Product/Project/Service/Freelance pages cannot be
accepted until a real approved listing exists (or the isolated QA host
is deliberately populated with original test records).

No fake production listings should be created merely to make screenshot
automation pass. Use the separate QA-host protocol in
docs/independent-real-host-acceptance.md.

## Release boundary

This is **not** a production upgrade. The workflow and script only add
acceptance tooling to GitHub main. The already deployed Bambodia frontend
and its user data remain unchanged. Approval for physical-device/browser
checks requires operator review of the captured screenshots and real
authorized test sessions, not just automated assertions.
