# Bambodia product licenses

## Scope and purchase limitation

License definitions contain reviewed rights and restrictions; they are NOT customer keys. Issued licenses are secret installer credentials tied to (1) a published downloadable product, (2) an approved private original file, (3) an active customer download entitlement for that exact file, and (4) an active Regular or Extended license definition. A product upload or unpublished draft never creates a key.

Verified checkout and automatic paid-purchase issuance are NOT enabled. The current issuance workflow is a deliberate complimentary grant by the super administrator, based on an existing manually approved entitlement, with a written reason. No payment is recorded. A future paid flow must verify a genuine processor-signed captured payment, unique order, buyer identity, paid tier, refunds, fraud checks and product eligibility before invoking a trusted, idempotent issuance service. Do not let browsers declare an order paid.

## Administrator workflow

First approve the creator's product, static preview, and immutable ZIP/PPTX/PDF original. Independent rights verification, current clean ClamAV SHA-256 scan and file integrity must all succeed. In Download Entitlements, the super administrator can manually grant access to that file for a customer without claiming a payment. Then License Manager can issue a complimentary Regular or Extended key linked to that entitlement. Each entitlement can receive only one issued key.

The License Manager at /dashboard/license-manager can suspend, reactivate, permanently revoke, rotate key, release/transfer domain and manually upgrade a Regular license to an offered active Extended definition. These actions retain audit history. Definitions already attached to products or issued licenses are immutable; create a reviewed revision instead. Hard deletion is intentionally unavailable.

## Creator and customer

Creators: /studio/licenses displays only their own product licenses, customer public identifiers, domains, release versions, tiers, statuses and nonsecret events. Creators may submit an auditable administrator-review request for domain assistance, manual upgrade, suspension investigation or suspected abuse. The request notifies administrators but never changes buyer rights directly. Creators cannot mint, revoke, rotate or reveal keys.

Customers: /account/licenses shows only their issued licenses. The secret is encrypted in storage, and a password-confirmed POST reveals it with a private no-store response. Transfer deactivates the current domain; the new installation must prove possession of the key at activation time. The old domain and event history remain visible.

Never put keys in URLs, source control, screenshots, Javascript bundles or a public web root. Keep them in server-private installer configuration.

## Installer handshake (v1)

Over verified HTTPS, installed scripts POST JSON to:
- https://bambodia.com/api/v1/licenses/activate
- https://bambodia.com/api/v1/licenses/validate

Request JSON (both endpoints):
    {"license_key":"BAM-<48 uppercase hexadecimal characters>","product_id":"123456789","domain":"store.example.com"}

Activate once before attempting validate. A valid 200 response includes valid=true, status, license_id, product_id, tier, release_version, license_version, domain, expires_at, checked_at and check_again_after_seconds=21600. The server requires product_id to match the key. Compare product_id and domain to the expected installed product and configured application host. Wrong domain or invalid/revoked/expired/blocked licenses fail. A second concurrent domain is rejected until the customer releases the old activation. These are POST-only, throttled and HTTPS-only in production. Do not log secret request bodies.

An installed PHP/Laravel product must integrate the license client itself. Bambodia's own marketplace installer is separate. An optional portable PHP reference implementation is in examples/license-validation.php. This is a reference, not an installed product plugin. Live external TLS and domain acceptance, cPanel and aaPanel testing remain release gates.

## Fail-closed behavior

Online validation depends on an active entitlement and accessible, reviewed product original. A scan may expire under the vault policy and require renewal. No offline signature, unverifiable grace period, paid checkout, chargeback automation or refund-triggered license revocation is implemented. Do not claim these features are live.


## Lifetime license and separately renewable support

Product licenses authorize one registered installation for the lifetime of the
license. No duration-based license expiry applies. Suspension, revocation,
product rights and integrity checks continue to apply. A time-limited
download-entitlement grant may separately expire for **new downloads** without
invalidating the previously issued installed-script license. Explicit
entitlement revocation still invalidates authentication.

Product support is an independent term:
- \`six_months\` — 6-month support
- \`twelve_months\` — 12-month support
- \`five_years\` — 5-year support
- \`lifetime\` — lifetime support

New complimentary licenses receive the term explicitly chosen by the super
administrator. Previously issued licenses retain their existing support
state as **Not set**, avoiding an unrequested change to historical benefits.
A support term that reaches its end date displays **Expired** while the license
remains Lifetime. Support expiration does not, by itself, revoke a domain,
disable a working script or change its licensed tier.

Customers choose a new term in **My Licenses**. The request stays **Pending**
until reviewed. The current deployment has no verified payment gateway, so
neither clicking Renew nor submitting a request extends support or charges
the customer. A super administrator can grant a documented **complimentary**
extension or reject the request. Approval starts a new term at the later of
today and the prior support expiry, preventing overlapping periods from
removing already-granted time. Once Lifetime support is granted, further
renewal requests are rejected. Audit events and customer notifications
record requests and decisions.

When paid renewal support is introduced, only a verified server-side
payment event should authorize an appropriate renewal grant; a browser
button or unpaid order must never activate support. Prices, refunds and
payment processing are not part of this release.

## Tables and pagination

Admin License Definitions, Issued Licenses and Support Renewals are each
paginated to 10 records with independent \`page\`, \`issued_page\`, and
\`support_page\` keys. Creator Product Licenses, Customer My Licenses and
My Downloads each show up to 10 rows. The shared Previous/Next/Go To
component preserves other filters and page values without overwriting the
wrong table's page parameter.
