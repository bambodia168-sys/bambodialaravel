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
