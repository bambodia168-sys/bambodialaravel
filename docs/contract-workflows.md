# Freelance contracts and compliance acceptance

## Contract workflow

The client selects a shortlisted application on an approved job, creates a private draft, and defines itemized milestones with values that exactly reconcile to the proposed total. A draft is visible only to its client. The client confirms and offers a frozen scope, terms and milestone set. A SHA-256 digest is recorded with the client acknowledgment. The freelancer explicitly accepts or declines the same offer digest. These authenticated account actions are recorded, not independent e-signature or legal identity verification.

In active agreements, the freelancer submits sequential milestone deliverables, and the client either accepts each delivery with feedback or requests a revision. Old submissions and reviews are retained. All milestones must be accepted before the freelancer acknowledges delivery completion. The client then separately confirms completion. No payment or escrow balance is created.

A participant can report a disputed contract. The workflow pauses milestone decisions. Independent Super Admin or Admin reviewers, who are not contract participants, can inspect the restricted case, milestones and private correspondence, then document an operational resume or cancel decision. This is not legal arbitration or a financial settlement.

## Secure deliverable files

Original ZIP, PPTX and PDF milestone files, up to 25 MiB each, use the private Laravel local disk under storage/app/private/marketplace/contract-deliverables/. File paths use random names, and download access is limited to the two authenticated parties. Authorized administrators may inspect files only when a case has been reported. Each original is checked by size and SHA-256 digest before serving, and uploads are checked for ZIP structure and unsafe paths. Files are never distributed using a static public URL. File checks are NOT malware scanning, ownership verification or safe execution certification. Optional HTTPS evidence links are stored as unverified references.

## Communications and administration

Private contract messages are available to the two participants. Administrative ordinary Contract Manager views contain only operational metadata. Restricted reported dispute case views allow authorized, independent reviewers to inspect detailed text and file evidence. Inspections are audited. Contract offers, decisions, milestone delivery and review, messages and dispute dispositions create real in-app notifications.

## Policy provenance and privacy requests

Published policy text is saved in immutable numbered snapshots, with publishing operator, timestamp and exact SHA-256 digest. Authenticated users may voluntarily record a reading acknowledgement for the current published revision. No automatic consent to all data processing is inferred. Old publications remain unchanged after republishing. The Super Admin Compliance Register shows actual revision and acknowledgment records.

Accounts may request data access, correction, erasure, restriction, portability or objection using private nine-digit request references. Super Admins who are not the requesting user may mark cases under review and record substantive responses; users can explicitly close responded cases. There is NO automatic personal data export, alteration or erasure. Case tracking does not by itself fulfill any legal obligation.

## Independent customer release gates

Before production release, separately verify aaPanel and cPanel installations, upgrade migrations and private storage permissions; real ZIP/PPTX/PDF uploads, SHA-256 tamper detection, malware screening and private downloads; contract terms and intellectual-property review; e-signature eligibility by jurisdiction; policy text and data subject request fulfillment procedures; physical-device UI and accessibility; and real provider eligibility.

Do not run the standalone clean-install browser wizard against an existing populated database. The application changes must be migrated under a properly backed-up and independently tested upgrade procedure. Banking, financial escrow, automatic purchase entitlements, payouts, refunds, tax invoices, real-money transaction processing and legal compliance certification remain disabled.
