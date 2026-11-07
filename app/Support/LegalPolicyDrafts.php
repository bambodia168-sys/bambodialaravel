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
## 8. License grant conditions and definition of an end product
The license applies only to the item and license tier identified in the customer's issued-license record. A production end product is the completed public-facing or internal project delivered for one beneficiary. A development copy is a non-public copy used by the same development team to build, test, repair, or migrate that project. Repeated use of a package as a starting point for unrelated client sites requires new licenses. A database backup or inactive archive does not create an independent end product. A preview or test deployment used to provide a separately marketed service is a production use, not staging.

## 9. Permitted client handover
The purchaser may assign operation of the single completed end product to its intended client if the license issuer's documented transfer procedure is followed. The same license cannot simultaneously authorize the developer's independent business website and the client's independent site. The license key must not be published in project repositories, issue trackers, screenshots, or documentation visible to unauthorized people. A client may hire another developer to maintain the same end product without generating a separate license solely for maintenance.

## 10. Commercial-use examples and exclusions
A company site advertising services, an agency portfolio, or a retailer using a storefront to sell its own physical goods ordinarily fits the Regular License. Selling registrations or subscriptions whose primary value is access to the purchased software's own features may require an Extended License. Charging for unrelated consultancy or installation services is not itself resale of the software. Licensing third-party data, photographs, fonts, music, or payment services may require separate permission regardless of this product license.

## 11. Support and version treatment
The purchase or issuance record specifies the support start and end dates. The standard period is six months, with individual extensions only if recorded by an authorized administrator. Supported channels and response targets are those explicitly offered for the product; automatic patches, major upgrades, indefinite hosting, and custom development are not guaranteed. When older versions have security problems, users remain responsible for assessing practical mitigation measures and available upgrades.

## 12. Suspension, dispute and correction
Where a domain-change or activation request is flagged for inconsistent use, the license issuer should investigate before any permanent revocation and offer a reasonable correction procedure. Unauthorized additional production use may be remedied by purchasing additional licenses. Evidence and customer communications should be retained only as permitted by the Privacy Policy and applicable law. A support dispute, a technical activation error, or support expiration alone is not a reason to cancel perpetual usage rights.

## 13. Controlling documents
The product-specific license selection shown at checkout and the immutable issued-license record identify the purchased rights. General platform policies govern service use but do not retroactively override more favorable rights already granted in the applicable license. If a third-party component's separate license grants rights that cannot be limited by these terms, that component's license controls its own scope.

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
## 8. Chargeable end-user access
The authorized end product may bill end users for subscriptions, membership access, licensed features, or content delivered through that product, including recurring access fees, transaction charges, and commissions. The purchaser remains the operator of the service and is responsible for customer support, lawful notices, invoices, taxes, subscription cancellation, and any refunds legally owed. This license is not a representation that Bambodia, the creator, or any processor has approved a payment operation.

## 9. SaaS and tenant boundaries
End users may hold separate accounts and logically isolated tenants on one centrally operated authorized SaaS application. The license does not cover distributing installable software archives, reusable source code, or self-hosted instances to tenants. Distinct installations independently operated by each customer are separate end products even if they share the same brand. Multi-region infrastructure replicas solely for reliability of one service should be evaluated under the specific activation and domain policies, not treated as unrestricted multi-product rights.

## 10. Client handover and agency projects
An agency may deliver one commercial or subscription-based end product to a single client under one Extended License. The agency cannot reuse the same purchased source code in separately operated end products for subsequent clients without additional licenses or a separate distribution agreement. On permitted handover, the client assumes compliance with the license while maintenance vendors may access the code to maintain only that end product subject to confidentiality controls.

## 11. Revenue and distribution exclusions
There is no revenue cap unless the particular product's accepted terms state one. However, revenue-generating use does not include the right to repackage and resell the purchased source code, offer the original installer as a downloadable product, sublicense independently deployable copies, claim exclusive ownership, or make a competing stock template or script library. The service may expose user-facing interfaces and public APIs when intended for the one authorized service, but not unrestricted downloads of protected underlying source files.

## 12. Operational responsibilities
The operator is responsible for lawful customer terms, a functioning cancellation method when subscriptions are offered, privacy notices, lawful marketing, security, applicable accessibility requirements, and fulfillment of any regulated business approvals. Separate open-source software and media licenses still apply. A hosting provider, domain registrar, payment processor, cloud platform, or bank may impose additional terms independently.

## 13. Support, lifecycle and license integrity
Perpetual authorized use continues after the recorded support period expires. Renewal of technical support does not create additional production rights and changing the tier must be processed as an authorized license transaction. Evidence of issued entitlements, domain changes, license transfer, and support grants should remain auditable. The licensed original and permitted modifications are subject to the accepted definition at the purchase date, not automatically replaced by later policies.

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
## Definitions and acceptance
"Platform" means the Bambodia website and services operated by the verified legal entity displayed in the final published notice. "Buyer" purchases products or commissions work, "Creator" offers digital goods, and "Freelancer" provides services. Clicking acceptance when required, placing a qualifying order, or using an authenticated feature after conspicuous notice may constitute acceptance, subject to the law governing electronic contracts. Users who do not agree must discontinue the affected service.

## Eligibility and account conduct
Users must meet the minimum legal capacity for binding contracts and provide truthful contact information. Organizations must act through authorized representatives. Account sharing, credential sales, evading suspensions, impersonation, spam, malware, and infringing listings are prohibited. Users should report unauthorized access; Bambodia may require verification proportional to the transaction's risk.

## Pricing, order formation and delivery
Before a binding purchase, the platform should show the seller, item specifications, currency, total price, applicable taxes or additional fees, permitted license tier, delivery method, support period, and material cancellation/refund restrictions. If checkout or payments are disabled, listing visibility alone does not create a paid order or entitlement. Order completion, authorization and fulfillment depend on verified payment and recorded entitlements. Separate payment-provider terms may apply.

## Creator responsibility and intellectual property
A creator must own or possess sufficient rights to distribute every asset, identify third-party license conditions, disclose limitations and dependencies, and deliver the advertised item. A marketplace license grants limited use, not ownership of source code. Infringing items may be suspended after an appropriate rights review. Bambodia's use of uploaded content is limited to operating, marketing, moderating, delivering, and defending the service as authorized by the creator agreement.

## User communications and service contracts
Users may communicate through provided messaging systems about legitimate orders or projects. Off-platform activity may reduce platform verification and dispute protections. Freelance work should use agreed milestones, delivery criteria, lawful payments and acceptance records. Bambodia does not guarantee a project's commercial success or act as an escrow bank unless that specific capability is licensed and available.

## Enforcement and appeals
For material breaches, Bambodia may restrict content or accounts proportionately to the observed risk, maintain necessary evidence, notify users where appropriate, and provide a means to contest erroneous decisions. Emergency measures may be necessary for active fraud or security abuse. Account closure does not automatically erase lawful outstanding refunds, licenses, statutory remedies or tax records.

## Changes, governing law and dispute mechanisms
Material changes must be versioned and displayed with a meaningful effective date. Contract rights already accepted in a specific transaction are not silently removed by later terms. Cambodia is the intended primary operational jurisdiction, subject to mandatory rules in a buyer's location. The final operator identity, service address, complaint contact, and legally reviewed governing-law/dispute wording must be inserted prior to publication.

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
## Data inventory and collection sources
Registration may involve name, email, role, password hash, account status, and verification events. Creator and buyer workflows may capture profile information, product submissions, contracts, support messages, order identifiers, download history, license identifiers, activation domain, transaction state and anti-fraud metadata. Servers may collect IP address, user agent, session timestamps, error events and security/audit logs. Precise inventory must be validated against deployed code, hosting and processor settings.

## Why data is used
Account information supports authentication and service administration. Order and entitlement information supports purchase verification, licensing, downloads, refunds, and support. Communication data supports requested customer service or project collaboration. Audit and network records support investigation of fraud, account compromise and abuse. Optional promotional communications require the applicable consent or other lawful basis and a working opt-out mechanism.

## Vendors and transfers
Hosting, data storage, email, payment, security, analytics, and backup vendors should be listed by role with their actual location and processing purpose after vendor inventory. Do not disclose full license keys or confidential messages to other users. Creator visibility into purchaser information must be minimized to data necessary for fulfillment. When data crosses borders, assess applicable transfer rules and vendor contracts, security standards, and practical avenues for user requests.

## Records, minimization and retention
Order, tax, contracts, security and license records should have category-specific retention schedules; they are not all automatically deleted when an account closes. Unneeded identity documents, support attachments, and inactive logs should be removed or anonymized securely under documented schedules. Backups may expire on a delayed cycle; users should receive a truthful description of this process.

## Rights and privacy requests
Users may submit a privacy request through the available account process or an approved contact channel. Verify identity in a privacy-preserving way before disclosing records. Handle access, correction, deletion, restriction, objection, portability and withdrawal requests where required by the applicable legal regime. Explain refusals based on mandatory legal retention, security needs, or another lawful exception and offer a review route.

## Security and incidents
Access control, password hashing, encrypted transport, monitored backups, audit logs, least privilege and incident response should be employed to the extent appropriate. Where a security incident creates notification duties, affected individuals and authorities should be informed within the applicable legal requirements. No statement guarantees absolute security. Publish the verified controller name and contact details and maintain an accurate vendor and data inventory.

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
## Essential cookies
Session authentication, CSRF protection, load balancing and fraud prevention may require strictly necessary technologies. Their exact names, operators and expirations should be recorded from production browser and server inspections. Rejecting optional cookies must not prevent use of essential account functions.

## Preferences, analytics and advertising
Preference cookies may remember theme or language. Analytics tools may measure visits and errors; advertising tools may associate activity with campaign identifiers. These latter categories must not be described as active unless actual tags or integrations are present. Record provider, name, purpose, duration, first- or third-party status and legal basis for each implemented tracker.

## Consent and withdrawal
Where user consent is required, optional categories should not load before appropriate choice. Users must be able to reject optional cookies without using a misleading button hierarchy and later change or withdraw preferences using an accessible control. Consent decisions should be recorded only as necessary to demonstrate and honor the preference.

## Browser and device controls
Browser settings may delete or block cookies, which can cause login, basket, account and security features to fail if essential cookies are blocked. Mobile and embedded browser storage may use similar technologies. A browser "do not track" signal is handled according to actual implemented behavior and applicable law; do not claim a feature that is unimplemented.

## Inventory ownership
The service operator must audit scripts after each release and whenever analytics, chat widgets or payment providers change. This policy should identify the effective date, current configuration and an operational contact channel before public activation.

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
## Eligibility and assessment
Users may request a remedy for an unfulfilled confirmed order, duplicate charge, incorrect amount, file corruption, material mismatch with the advertised specification, defective functionality, or other legally recognized ground. A valid digital delivery does not defeat non-waivable consumer rights. A change of mind may be subject to disclosed and legally permissible conditions; no universal no-refund or guaranteed-refund rule is implied.

## How to request a review
Provide the order reference, product title, contact email, description of the problem, and a reasonable explanation or screenshot when needed. The service should acknowledge the case, investigate purchase and entitlement logs, offer a solution where suitable, and provide a reasoned written decision. Never ask customers to share passwords, complete payment card details or other unnecessary secrets.

## Remedies
Depending on the facts and applicable law, the remedy may be correction, replacement, access restoration, support, partial reimbursement, full refund, cancellation, or another legally required measure. Any refunded license may be deactivated only where lawful and consistent with fulfilled rights and consumer protections. The timing and payment method of a refund depend on the authorized processor and applicable legal requirements.

## Subscriptions and cancellation
If recurring subscriptions become available, show billing frequency, renewal amount, renewal conditions, trial conversion and a usable cancellation method before enrollment. Cancellation should stop future billing according to accepted terms without misleading obstacles. Cancellation may not automatically refund fees for a period already supplied unless the contract or law provides that remedy.

## Disputes and fraud
Verified duplicate transactions and unauthorized payments should be investigated promptly. The platform should cooperate with legitimate payment disputes, preserve relevant audit data and avoid retaliatory termination simply because a consumer exercises a lawful remedy. Publish actual support contacts and operating times; do not assert service-level deadlines unless operationally supported.

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
## Order-to-file flow
A file entitlement should be issued only after trustworthy authorization of the purchase or an expressly approved manual grant. Order status, price, seller, purchased tier, license identification and download eligibility should be auditable. Availability of a listing or invoice alone is insufficient evidence of an authorized payment.

## Protected download delivery
Private source archives, license secrets, generated download links, and customer documents must be stored outside publicly readable paths or protected by equivalent controls. Each download request should recheck the authenticated user and entitlement. Where links expire, provide a reasonably accessible way for eligible purchasers to request a new link within the applicable fulfillment policy.

## Product versions and compatibility
Listings must identify the supplied version, file types, necessary frameworks or dependencies, and significant compatibility restrictions. A creator may release later versions or security patches under the disclosed update policy. The sale of a version does not mean the purchaser receives unrelated products or custom upgrades for free.

## Interrupted or faulty delivery
If delivery fails, the customer should have a support path to verify the order and restore eligible downloads. Checksums or manifests may help assess corruption; users should verify package authenticity where provided. The platform should not promise indefinite file storage, unlimited downloads or automatic delivery while those functions are unverified.

## Rights and sharing
Downloading does not transfer copyright. Licensed use is controlled by the applicable issued-license snapshot and any embedded third-party licenses. Unauthorized reproduction, public mirroring, and resale are prohibited to the extent lawful. Customers should retain receipts and order identifiers rather than expose private keys.

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
## Onboarding and representation
Each creator must identify themselves accurately, possess contractual capacity, and verify legal authority to list all included software, images, text, branding and media. Company representatives must be authorized to act for the organization. The platform may request proportionate identity and ownership evidence as required by its actual onboarding policy.

## Listings and quality assurance
Before submitting a product, creators should supply accurate title, version, category, compatibility, requirements, screenshots, demo links, changelog or documentation where relevant, rights disclosures, support terms, and license choices. A listing may be rejected for malware, unlicensed content, deceptive claims, unsupported functionality or materially deficient deliverables.

## Licensing responsibilities
Creators must choose only definitions approved for the intended product type and tier. A buyer's issued license and the terms disclosed at purchase govern the buyer's transaction. Creators must not retroactively change that buyer's granted permissions, misrepresent open-source restrictions, or remove mandatory rights by switching product tiers after sale.

## Fulfillment and security
Files should be delivered through the platform's controlled entitlement mechanism when supported. Creators must prevent exposure of embedded credentials, customer datasets, private API tokens, malicious installers or unauthorized third-party packages. Material defects and security issues should be investigated and fixed in keeping with advertised commitments.

## Fees, tax and payouts
Platform commissions, payout calculations, withholding, payout thresholds, payment provider requirements, settlement delays, refunds and tax allocation must be set out in a verified financial schedule before paid transactions. No automatic payout, escrow, recurring billing, or merchant processing should be represented as functioning until independently verified and enabled.

## Complaints and termination
Creators should cooperate with legitimate buyer support, refund investigations, intellectual-property notices, and security incidents. The platform should give reasons for material enforcement where appropriate and maintain an accessible appeal channel. Ending a seller account does not extinguish legitimate outstanding customer rights or legally required records.

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
## Accounts and system integrity
Automated scraping, brute-force login attempts, malicious scanning, denial-of-service attacks, bypassing paywalls or entitlement verification, and misuse of administrative functions are forbidden without express written authorization. Users may not register deceptive duplicate accounts to evade moderation or transaction restrictions.

## Product and content boundaries
Listings must not contain malware, password-stealing software, pirated packages, stolen credentials, unlicensed media, counterfeit branding, deceptive downloads, abusive privacy violations, or unlawful services. Listing artwork and screenshots must depict the advertised item accurately. Creator claims about revenue, performance or guarantees must be substantiated.

## Communications and marketplace conduct
Harassment, spam, fraudulent offers, fabricated reviews, impersonation, coercion, prohibited data brokerage and attempts to trick users into sharing personal or financial secrets are disallowed. Buyers and creators must avoid circumventing platform controls to obtain another person's protected files.

## Reporting, investigation and remedies
Reports should include the URL or listing identifier, alleged conduct, approximate date and available evidence. Investigations should avoid unnecessary disclosure of reporters' personal information. Enforcement may include warning, delisting, temporary suspension, restricted entitlements, or account closure proportionate to risk and applicable law.

## Appeals and lawful rights
Users should be able to contest an enforcement mistake using a documented channel. Nothing here prohibits lawful security research authorized by the owner, lawful criticism, consumer complaints, or rights protected by mandatory law.

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
## Rights representation
Creators represent that they possess the rights to upload, market and license their products and that they have complied with applicable permissions of included code, design resources, media and fonts. Bambodia obtains a limited authorization to host, display, moderate, reproduce for secure delivery, and promote submitted listings as specified in a valid creator agreement.

## Notice content
A rights report should identify the claimant and contact, protected work or mark, exact allegedly infringing product or URL, ownership evidence or authorization, and the basis for the claim. Reporters should avoid disclosing sensitive evidence in public submissions. Knowingly false notices may have legal consequences.

## Review and takedown handling
The platform should acknowledge reports, preserve limited necessary evidence, assess obvious error and lawful licensing, temporarily restrict high-risk content where justified, and notify the listed creator when permitted. The creator may explain licensing or provide a counter-notice. Repeat or serious misconduct may justify additional restrictions, with a proportionate review process.

## Open-source components
A marketplace product may combine proprietary code and third-party open-source components. A platform license applies only to rights the creator can lawfully grant. Source-sharing, notice and attribution conditions of applicable open-source licenses must be respected; the platform cannot cancel permissions a recipient received from an open-source licensor.

## Jurisdiction and contact
Publish the real claims-contact route, response process and applicable governing rules. Do not call the process a registered DMCA designated-agent procedure unless qualifying U.S. statutory prerequisites have actually been completed.

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
## Eligibility and term calculation
Standard support is six calendar months from the purchase or issuance timestamp associated with the individual license. An administrator may expressly grant 12 months, five years, or lifetime support for a specific record. A support grant for one product does not carry over automatically to different purchases or other users.

## Coverage and fair-use handling
Support ordinarily covers how-to questions about documented features, basic installation guidance, reported reproducible original defects, supported license activation, and clarifications about supplied documentation. Creators may request steps to reproduce and technical environment details but should not demand credentials or personal data unnecessary for diagnosis. Reasonable anti-abuse rate limits should be disclosed.

## Exclusions and customization
Requests for unrelated hosting administration, unsupported hardware, bespoke workflows, external integrations, ongoing website management, content migration, third-party payment onboarding, or fixes for unauthorized source modifications may need separate paid agreements. Legal and financial professional advice is not included.

## Updates and product lifecycle
Creators should disclose whether patches, major upgrades and migration tools are included with the purchase and which runtime versions are supported. License lifetime does not itself guarantee perpetual source updates. A critical vulnerability should be handled through a defined reporting and severity process; customers remain responsible for maintaining secure backups and their own operating environments.

## Support disputes
Customers should have a means to challenge an incorrectly calculated expiry date or an unfulfilled advertised support commitment. Changes by administrators should be audit-logged. An expired support entitlement cannot by itself cancel perpetual license use.

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
## Formation of a project contract
Before work begins, each party should identify scope, parties, deliverables, acceptance standard, schedule, payment amount and currency, milestones, revision limits, intellectual-property transfer timing, and any confidentiality needs. These details should be recorded through an authorized workflow or separately signed contract.

## Changes and project communication
Scope additions, deadline changes, substitutions, or price adjustments should be acknowledged by both parties. Messages should remain professional and relevant. Freelancers must not expose client materials or use them outside the contracted purpose. Clients should not demand illegal work or access outside the stated project.

## Submission and acceptance
A milestone delivery should identify the completed deliverables and reference the agreed acceptance criteria. The customer should review within an agreed reasonable period and provide specific defects or acceptance. Automatic acceptance or payment release must not be implied unless an enforceable agreed process and functioning payments system support it.

## Dispute evidence and resolution
Evidence may include project scope, timestamps, messages, delivered files, change requests and acceptance notes. Attempts at direct resolution should precede escalation where practicable. The platform may facilitate a review but must not claim the legal powers of a court, arbitration body or licensed escrow operator without authorization.

## Rights, termination and remedies
Ownership of code, design and deliverables transfers only as stated in the relevant contract and applicable law, often conditioned on agreed payment. Contract cancellation, refund, partial completion, rights in unused work, nonperformance and force majeure should be evaluated against the individual contract and mandatory legal obligations.

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
## Responsible reporting
Use a verified private contact or ticket channel and include affected URL, product version, concise proof of impact, and minimal reproduction steps. Do not submit exploitable secrets, full user databases, unnecessary personal data, or malware as ordinary attachments. Report urgent active incidents immediately through the available priority contact.

## Testing boundaries
Testing must stay within systems and accounts explicitly authorized by their owner. Do not conduct denial-of-service tests, social engineering, spam campaigns, destructive payloads, privilege escalation on third-party accounts, or persistence after demonstrating a problem. Discovery of sensitive data is not permission to retain or distribute it.

## Response and triage
The security team should triage potential authenticity, impact, affected customers and exploitability; protect the reporter's identity as appropriate; and coordinate fixes with relevant creators or service providers. Acknowledgement targets and remediation deadlines must only be published if there is operational capacity to honor them.

## Incident management
Critical incidents may require isolation, key rotation, restored backups, forensic logging, mitigation notices, and communication with affected parties or authorities as legally required. Evidence retention, incident timelines and cross-border notification decisions must follow applicable obligations rather than generic GDPR language for every user.

## Disclosure and protections
Coordination of public disclosure should balance timely fixes and legitimate research rights. No bug bounty, immunity from prosecution or blanket safe harbor is promised by this draft. Any such commitment must be separately authorized by legal counsel and service operators.

POLICY,
            ],
        ];
    }
}
