<?php

namespace App\Support;

final class LegalPolicyDrafts
{
    /** @return array<int, array{title: string, category: string, jurisdiction: string, body: string}> */
    public static function all(): array
    {
        return [
            [
                'title' => 'Regular License',
                'category' => 'commerce',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Regular License
Version 1.0.0 — Draft for legal review

## Grant and scope
A purchase grants a non-exclusive, non-transferable right to use the licensed digital product in one end product for the purchaser or one client. One end product means one identifiable production website, application, or project, with associated non-production development and staging environments. Each independent production project requires its own license.

## Permitted usage
You may install, configure, modify, and operate the product in the licensed end product for personal, educational, nonprofit, and commercial purposes. You may build a client website, business site, portfolio, or store, including selling independent goods and services. A legitimate migration of the same end product may be handled through authorized domain management.

## Monetization boundary
You must not charge end users specifically for access to functionality or content substantially supplied by the licensed product, unless product-specific terms expressly allow it. A paid SaaS, paid membership, or subscription service based on the product normally requires an Extended License.

## Restrictions
Do not resell, redistribute, sublicense, share, publish, or package the original product or substantially equivalent derivative as a downloadable item. Do not use one license across unrelated production projects, circumvent activation, disclose private license credentials, or claim ownership of the original product. Open-source and other third-party components retain their separate license rights.

## Client work and ownership
Delivery of the one authorized end product to a client does not grant additional independent installations. The creator or lawful rights holder retains copyright in the original product; you retain rights in your original additions to the extent permitted by law.

## Lifetime use, support, updates
Usage is perpetual while the license remains in good standing. Standard technical support runs six months from the recorded purchase or issuance date, unless the license record grants 12 months, five years, or lifetime support. Support expiry does not end usage rights. Lifetime use does not guarantee continuing releases, perpetual hosting, compatibility, or third-party services. Updates and support follow the product-specific published policies.

## Enforcement, legal rights, and acceptance
Material breach may result in proportionate enforcement or termination after notice and an opportunity to cure where required by law. Mandatory consumer rights and statutory remedies remain intact. The applicable version is the version disclosed and accepted at purchase, retained with the issued license record.
POLICY,
            ],
            [
                'title' => 'Extended License',
                'category' => 'commerce',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Extended License
Version 1.0.0 — Draft for legal review

## Grant and scope
The Extended License grants the rights of the Regular License plus permission to monetize access to functionality or content substantially supplied by the purchased product within one authorized end product. This is a limited, non-exclusive, non-transferable grant, not ownership of the original source code.

## Monetized use
You may operate one revenue-generating website, subscription service, paid membership platform, hosted SaaS, learning platform, booking service, or marketplace. You may collect lawful subscriptions, service fees, commissions, or access charges from customers using that one authorized end product. Multiple users or tenants on the same shared service do not alone constitute multiple end products.

## Installation limits
One license covers one identifiable production end product and associated development or staging instances. Separate branded products, customer installations, deployable software copies, or independent production environments require separate authorization. Legitimate domain migration may be approved under the platform's domain-change policy.

## Restrictions
An Extended License does not authorize resale, sublicensing, redistribution, publication, or white-label distribution of original licensed source-code packages. Do not distribute separately deployable copies to subscribers, circumvent license controls, reuse the license for other client projects, or claim original intellectual property rights. Third-party open-source, media, and component licenses remain applicable.

## Client work and modifications
One client end product may be built and delivered, but the developer must not reuse the license for another independently operating project. Permitted customization does not transfer original product copyright. Rights in separately authored additions remain subject to applicable law.

## Support, duration, and external compliance
Authorized use remains perpetual while compliant. Standard support is six months from recorded purchase or issuance unless the specific record grants 12 months, five years, or lifetime support. Support and update policies are separate from license duration. Merchant authorization, taxes, payments, privacy, hosting, and third-party provider eligibility remain the operator's responsibility.

## Enforcement and acceptance
Material misuse may trigger proportionate enforcement, with notice and cure when required. Mandatory statutory and consumer protections are preserved. The accepted version at purchase controls the licensed transaction.
POLICY,
            ],
            [
                'title' => 'Terms of Service',
                'category' => 'terms',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Terms of Service
Draft for legal review

## Parties and service
Bambodia operates a marketplace for digital products, software, creative services, projects, and freelance opportunities. These terms govern platform access by visitors, customers, creators, and service providers. Identify the verified contracting legal entity, registered address, and effective date before publication.

## Accounts and security
Provide accurate registration details, safeguard credentials, and promptly report suspicious access. Users must not impersonate others, evade account restrictions, abuse platform functionality, scrape protected material, distribute malicious code, or use the service unlawfully.

## Marketplace roles
Creators are responsible for ensuring they have rights to offer listed products, assets, and services; accurate descriptions; support commitments; and required consumer disclosures. Customers must review product specifications, compatibility, licensing, and applicable fees before purchasing. A platform listing is not a guarantee of fitness, regulatory approval, or third-party compatibility.

## Orders and contracts
Disclose final price, currency, applicable taxes, payment conditions, delivery method, license scope, and materially important restrictions before checkout. Transactions become binding only through the disclosed order-confirmation process. Freelance contracts may have separate milestone, acceptance, and dispute terms.

## Moderation and enforcement
Bambodia may investigate alleged fraud, intellectual-property violations, security threats, or abuse using proportionate and documented enforcement. Provide a meaningful appeal or dispute route where appropriate.

## Availability and liability
Service availability and features may change; mandatory warranties and consumer remedies are not waived. Liability limits, governing law, and dispute forum require jurisdiction-specific legal approval. Include verified customer support and legal-notice contact channels before publication.

## Changes
Material updates should be dated, versioned, and communicated as legally required. Do not apply newly published terms retroactively to completed purchases where this would undermine acquired rights.
POLICY,
            ],
            [
                'title' => 'Privacy Policy',
                'category' => 'privacy',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Privacy Policy
Draft for legal review

## Controller and contact
Identify Bambodia's verified operating legal entity, registered address, and privacy contact before publication. This notice applies to visitors, registered members, creators, and purchasers.

## Data we collect
Depending on use, we may process identity and contact details, account credentials stored securely, creator profiles, orders, license entitlements, activation domains, device and security logs, support communications, service inquiries, and privacy requests. Payment providers may process payment data separately; do not claim Bambodia stores card details unless verified.

## Purpose and basis
Use information to provide accounts, deliver purchased files, validate licenses, manage contracts, prevent abuse, respond to support requests, meet legal obligations, and communicate optional marketing only under applicable rules. Disclose the relevant legal basis or permission mechanism for each processing activity.

## Sharing and international handling
Information may be shared with hosting providers, transactional-email providers, payment processors, security vendors, and necessary counterparties to fulfill requested services. Document actual vendors, countries, retention safeguards, and transfer arrangements before publishing.

## Retention and security
Retain records only as long as needed for identified business, security, tax, contract, or legal purposes. Employ access controls, encryption where appropriate, audit logs, backups, and incident response. No internet service can promise absolute security.

## User requests
Provide channels for access, correction, deletion, objection, consent withdrawal where relevant, and complaint handling subject to applicable law and lawful retention duties. Do not promise universal GDPR rights where GDPR does not apply.

## Cookies and updates
Refer to the Cookie Policy for tracking details and choices. Publish the effective date and notify users of material changes as required.
POLICY,
            ],
            [
                'title' => 'Cookie Policy',
                'category' => 'cookies',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Cookie Policy
Draft for legal review

## Cookies and related technologies
Bambodia may use cookies, local storage, or similar technologies for session authentication, account security, language and theme preferences, traffic measurement, and optional marketing functions.

## Categories
Strictly necessary technologies may support login, fraud protection, shopping carts, and security. Preference technologies remember interface choices. Analytics and marketing technologies must be documented separately with their actual providers, purposes, retention periods, and consent requirements.

## Your choices
Where applicable, provide a functional consent interface that allows users to accept or reject non-essential categories and change their decision later. Withdrawing consent must not disable strictly necessary platform functionality.

## Browser controls
Users may also manage cookies through their browsers; disabling essential cookies may affect login and checkout. Do not claim that all optional trackers are blocked unless implementation is tested.

## Inventory and updates
Before publication, verify every production cookie, its provider, duration, and category; link the preference control and update this policy when the inventory changes.
POLICY,
            ],
            [
                'title' => 'Refund and Cancellation Policy',
                'category' => 'commerce',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Refund and Cancellation Policy
Draft for legal review

## Clear pre-purchase disclosure
Before checkout, disclose the digital product description, compatibility, price and currency, taxes and fees, delivery timing, license type, support duration, and material access restrictions.

## Digital products
Refund eligibility depends on the actual defect, non-delivery, misleading description, duplicate charge, unauthorized transaction, applicable consumer protections, and any legally valid waiver or exception. Download or activation alone must not be treated as eliminating mandatory consumer rights.

## Requests
Provide a verifiable support contact and case reference. Request an order ID, affected product, issue description, and relevant evidence without demanding unnecessary personal data. Acknowledge requests and explain review outcomes and appeal routes within reasonable published timelines.

## Cancellations and subscriptions
If recurring products are introduced, provide clear renewal disclosures, accessible cancellation controls, and the legally required treatment of charges after cancellation. Do not claim automatic refunds or recurring billing exist unless implemented.

## Chargebacks and remedies
Payment disputes may be investigated with transaction and entitlement records consistent with privacy obligations. Statutory remedies remain available regardless of platform policy. Verify payment provider and local jurisdiction rules before publication.
POLICY,
            ],
            [
                'title' => 'Digital Product Delivery and Download Policy',
                'category' => 'commerce',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Digital Product Delivery and Download Policy
Draft for legal review

## Delivery
After a confirmed and authorized payment, eligible digital goods may be made available through account download entitlements. An order confirmation alone is not proof of successful payment or entitlement.

## Secure access
Downloads may require a signed-in account, valid order, and unexpired or valid download permission. Private packages, license keys, and customer-specific assets must not be exposed through public file paths.

## Versions and updates
Where provided, downloaded packages may have version histories. Updates may be subject to the creator's published update policy, compatibility requirements, and continued entitlement.

## Problems and recovery
If an authorized purchaser cannot access a purchased file, support should verify the order and restore access when appropriate. Do not promise perpetual file hosting or indefinite availability beyond the written service commitment.

## Prohibited distribution
Access to a file does not transfer intellectual-property ownership or permit unauthorized file sharing. The controlling product license defines the purchaser's allowed usage.
POLICY,
            ],
            [
                'title' => 'Creator and Seller Agreement',
                'category' => 'terms',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Creator and Seller Agreement
Draft for legal review

## Eligibility and ownership
Creators must have the legal capacity and rights needed to list, license, and support their products or services. Provide accurate identity and business information and complete verification where legally or commercially required.

## Listing requirements
Descriptions must accurately represent features, dependencies, tested compatibility, documentation, support scope, licensing, pricing, and included third-party assets. Creators must disclose known material restrictions and may not market infringing, malicious, deceptive, or illegally distributed items.

## Licenses and buyer rights
Product-specific license terms must be disclosed before purchase. Creators must not silently narrow already-issued rights or alter existing transactions retroactively. Rights in third-party components remain subject to their source licenses.

## Quality and support
Creators must provide promised deliverables and honor disclosed support periods. Vulnerability reports, defects, and substantiated complaints should receive an appropriate response.

## Financial terms
Commission, creator payout eligibility, payment schedules, tax responsibilities, reserves, refunds, and chargebacks must be stated in a separately approved financial schedule before live sales. No payout or escrow entitlement should be implied before verification of actual functionality.

## Moderation and disputes
Bambodia may review listings and investigate rights violations or fraud. Documented dispute and appeal paths should be offered. Termination does not erase lawful obligations to existing customers.
POLICY,
            ],
            [
                'title' => 'Acceptable Use and Prohibited Content Policy',
                'category' => 'general',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Acceptable Use and Prohibited Content Policy
Draft for legal review

## Responsible use
Users must obey applicable laws and respect others' rights, platform security, and contractual restrictions.

## Prohibited conduct
Do not upload malware, credential theft tools, stolen or infringing materials, impersonation content, deceptive offers, fraudulent reviews, unauthorized personal information, or material prohibited by applicable law. Do not attempt account takeover, payment fraud, bulk scraping, denial-of-service, unauthorized security testing, or license circumvention.

## Product quality and disclosure
Listings must not misrepresent commercial rights, assets, supported versions, or buyer outcomes. Repeated misuse of private downloads or license credentials may result in proportionate action.

## Reporting and enforcement
Provide a route to report abuse, identify the affected listing or activity, and include supporting information. The platform may remove material or restrict access following appropriate assessment, notice, and appeal opportunities where required.

## Preservation
Security and fraud evidence may be retained only for legitimate purposes under the Privacy Policy and applicable law.
POLICY,
            ],
            [
                'title' => 'Intellectual Property and Takedown Policy',
                'category' => 'general',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Intellectual Property and Takedown Policy
Draft for legal review

## Ownership
Creators retain rights they lawfully own and warrant they possess sufficient permission to upload, display, and license listed materials. Bambodia receives only the operational rights necessary to display and distribute listings under the platform agreement.

## Infringement notices
Rights holders may submit the copyrighted work or protected mark, specific allegedly infringing URL, evidence of ownership or authorization, contact information, and a good-faith explanation. A verified reporting address must be supplied before publication.

## Review and responses
Bambodia should assess reports fairly, restrict clearly infringing material where appropriate, inform affected creators as legally permitted, and offer a correction or counter-notice process. Repeat and serious infringement may justify account restrictions.

## Third-party and open-source rights
A proprietary product license cannot revoke permissions that users receive directly from valid open-source licenses. Creators must identify and comply with third-party code, fonts, images, and assets.

## No false legal claims
Do not describe this as a U.S. DMCA statutory process unless a qualifying designated-agent procedure and relevant obligations are actually established.
POLICY,
            ],
            [
                'title' => 'Support, Updates and Maintenance Policy',
                'category' => 'commerce',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Support, Updates and Maintenance Policy
Draft for legal review

## Standard period
Unless the recorded license provides otherwise, eligible purchased digital products receive six months of creator-provided standard technical support from the purchase or license-issuance date recorded in the entitlement.

## Customized support
Administrators may approve 12 months, five years, or lifetime support for individual purchases. The customer-facing license record controls the assigned period. Support duration never changes the underlying lifetime usage license or expands Regular into Extended permissions.

## Included assistance
Published support may include installation guidance, documented feature explanations, reproducible original defects, and license activation assistance.

## Exclusions
Unless expressly purchased, support excludes custom feature development, full website construction, unrelated hosting administration, third-party service integration, and recovery from unauthorized code changes.

## Updates
Product updates and security releases are subject to the creator's published availability and product lifecycle policy. Lifetime licensing does not guarantee indefinite updates, future compatibility, or continuous third-party services.

## Service standards
Creators should publish accessible channels and reasonable response goals without promising response times or security guarantees they cannot fulfill.
POLICY,
            ],
            [
                'title' => 'Freelance Services and Dispute Policy',
                'category' => 'terms',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Freelance Services and Dispute Policy
Draft for legal review

## Scope and agreements
Buyers and freelancers should document the project scope, deliverables, milestones, price, payment conditions, deadlines, revision allowances, and acceptance criteria before beginning work.

## Changes and communications
Material scope changes should be agreed in a recorded channel. Respect privacy, confidentiality, intellectual-property allocations, and platform rules.

## Delivery and approval
Milestone submissions should be documented and reviewed against the agreed acceptance criteria. A missed response deadline does not automatically transfer ownership or release payment unless the agreed contract and applicable law authorize that result.

## Disputes
Parties should attempt direct resolution, then use platform dispute tools to submit messages and relevant evidence. Bambodia may mediate or administer only the dispute powers it actually holds; do not represent the platform as a licensed escrow provider or binding tribunal without authorization.

## Refunds and liability
Cancellation, remedies, transfer of rights, payment release, and dispute outcomes are subject to the parties' signed terms, applicable law, and the verified payment flow.
POLICY,
            ],
            [
                'title' => 'Security and Vulnerability Disclosure Policy',
                'category' => 'general',
                'jurisdiction' => 'Cambodia / international users — legal review required',
                'body' => <<<'POLICY'
# Security and Vulnerability Disclosure Policy
Draft for legal review

## Reporting
Report suspected vulnerabilities through the verified security contact provided by Bambodia. Include a clear description, affected URLs or components, and minimal reproduction information without including passwords, unnecessary personal data, or exploited customer records.

## Good-faith boundaries
Do not access other users' accounts, exfiltrate data, degrade availability, conduct mass scanning, or publish a working exploit before coordinated assessment. Nothing here grants permission for unlawful testing.

## Handling
Bambodia should acknowledge valid reports through an operational contact, prioritize based on risk, investigate, and coordinate fixes and appropriate disclosure. No bounty, safe-harbor promise, or fixed remediation deadline applies unless separately stated and legally approved.

## Incident communication
Where applicable, affected persons and authorities will be notified as required by law, contractual commitments, and incident severity. Exact regulatory commitments must be approved before publication.
POLICY,
            ],
        ];
    }
}
