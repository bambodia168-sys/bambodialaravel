# Bambodia

Bambodia is a Laravel-based marketplace for licensed digital products and professional freelance services.

- Project: https://bambodia.com
- Author: Ton Samith (https://tonsamith.com)
- Version: 0.0.1
- Backend: Laravel 13 (PHP 8.3+)
- UI: Tailwind CSS and JavaScript
- Repository: https://github.com/bambodia168-sys/bambodialaravel

## Status

New application under development. This repository is **not yet production-deployable**. Marketplace purchases, payments, and legal compliance must pass end-to-end acceptance before being activated. Never deploy to a live domain without backup and environment review.

## Licensing

Copyright (c) 2026 Ton Samith. All rights reserved.

Bambodia-specific application code is proprietary and is not licensed for redistribution without written permission. The Laravel application skeleton and third-party packages retain their own licenses. Marketplace products will have individual license terms that must be displayed and accepted before purchase; code repository licensing does not grant product resale rights.


## Working marketplace administration

The current codebase includes Creator Product Manager, private preview Media Library and reviewed License Manager alongside earlier authentication, CMS, Policy Center, Support Center and Developer/Security workspaces. Review the module-specific publication gates in docs/architecture.md.

Source-file delivery, paid checkout and financial processing are not implemented. No live website deployment has been performed.


## Additional real workflows
Admin-moderated jobs, Freelancer Resume Builder, owner-only proposals, private service/project inquiries with messaging, in-app notifications and analytics, marketplace Category Manager, and separate Project/Freelance/Service management are now implemented in the Laravel codebase. Checkout, banking, legal compliance certification, escrow, payout settlement, and verified provider email delivery remain disabled and out of scope until independently accepted.


## Appearance and system settings
Administrators can change actual homepage text, homepage search/category visibility, default theme and guest locale, and the displayed support email through saved database settings. Settings are restricted to an explicit allowlist, validated, and audited. This does not enable SMTP, licensed plugins or payment processing.


## Freelance contracts and compliance

Authenticated freelance contracts now support itemized milestones, frozen offer digests, client and freelancer acknowledgements, original private ZIP/PPTX/PDF deliverables, revision requests, completion confirmations and reported disputes. The compliance and privacy modules add versioned published policies, voluntary reading receipts and restricted data-subject request handling.

No real-money transfers, escrow, payouts, automatic erasure or verified legal e-signatures are claimed. See docs/contract-workflows.md for the complete acceptance criteria.


## Frontend and account expansion

The homepage now displays approved marketplace listings, category data and open job briefs. Marketplace and job search support real filters and sorting. Every authenticated role can keep private Saved Listings and Saved Jobs collections. Creators can publish an optional professional portfolio profile and are discoverable in a searchable creator directory. Account and Creator Studio dashboards show actual per-user activity and notifications. See docs/frontend-workspaces.md for privacy, routing and release acceptance.

No payments, refunds, escrow or financial transactions have been enabled.


## Digital File Vault production release gates

Approved product versions now require actual ClamAV screening with recent signatures and scan timestamp tied to the SHA-256 original, and a separately documented rights review. Missing/infected/outdated scan states and rights revocation block all entitlement-controlled downloads. Existing versions are **not grandfathered**.

For staging installation and hosting evidence, see docs/production-release-gates.md. Read-only support tools included in installer packaging:
- support/hosting-preflight.php: verifies essential aaPanel/cPanel PHP, private storage and ClamAV prerequisites.
- support/vault-scan-acceptance.php: checks the real scanner detects a harmless EICAR signature.
- support/http-release-probe.php: checks actual staging HTTPS, installer lockout and private-path security.

A Chromium browser-emulation CI workflow and MySQL vault tests are included. Actual aaPanel/cPanel production acceptance, real physical-device checks, legal rights approval and payment-provider onboarding remain separate.
