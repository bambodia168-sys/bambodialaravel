<?php

namespace App\Support;

/**
 * Supplemental, optional legal-review drafts. These are neither legal advice
 * nor live obligations and never become published without individual review.
 */
final class SupplementalLegalPolicyDrafts
{
    public static function all(): array
    {
        return [
            self::make('Payments and Billing Policy', 'commerce', [
                'Scope and contract parties' => 'This draft describes Bambodia marketplace billing and the respective responsibilities of customers, independent sellers, and any regulated external payment processor. Identify the merchant of record in the final checkout and invoice. Bambodia must not promise to process a payment or hold customer funds until the actual processor and operating permissions have been confirmed.',
                'Price and checkout disclosures' => 'Show the product or service price, selected license tier, currency, applicable disclosed taxes and fees, payment schedule, delivery conditions, and seller identity before checkout confirmation. Display a clear order summary and the applicable refund and cancellation policy. Do not add unannounced charges after consent.',
                'Payment authorization and security' => 'Charge only through approved enabled payment methods. Do not collect card secrets in general support tickets or knowledge base forms. External payment providers may apply their own terms, identity screening, authentication, fraud checks, and settlement schedules.',
                'Receipts and records' => 'Provide an order confirmation and appropriate transaction record once payment has been verified. Correct invoice details through authorized workflows and retain records only for lawful operational and accounting needs. Purchases must never be marked paid solely on the basis of an unverified browser redirect.',
                'Payment failure and disputes' => 'If authorization fails, the order remains unpaid and paid download entitlements must not be granted. Provide a support process for duplicate transactions and mistaken charges. Chargebacks, reversals, refunds and disputes require provider-confirmed evidence, notice where appropriate, and preservation of mandatory consumer rights.',
                'Restrictions and availability' => 'Actual payment methods, currencies, geographic eligibility, and invoicing capability vary by provider and jurisdiction. Until provider onboarding and acceptance are complete, describe payment features as unavailable rather than implying that live transactions are supported.',
            ]),
            self::make('Subscription and Automatic Renewal Policy', 'commerce', [
                'When this document applies' => 'This policy applies only if Bambodia activates a product or service that genuinely renews. One-time licensed digital purchases are not recurring subscriptions merely because technical support is sold for a fixed period.',
                'Clear subscription terms' => 'Before an eligible subscription begins, display its recurring price, currency, interval, any trial terms, payment method, material limitations, and the identity of the billing provider. Obtain the consent legally required for recurring charges and preserve an auditable record of acceptance.',
                'Renewal and communications' => 'Explain the next charge date and how to view upcoming billing. Send renewal reminders when required by law or promised in the offered plan. Do not describe a recurring payment as successful until the processor confirms it.',
                'Cancellation' => 'Provide clear steps to stop future renewal and state the effective date, retained access, and any applicable legal refund rights. A request to cancel recurring charges must not silently terminate a separate perpetual software license.',
                'Failed charges and reinstatement' => 'When a payment fails, disclose any grace period, retry schedule, and effect on access. Restore access only after verified payment or authorized manual resolution. Do not invent debt or penalties not disclosed and legally enforceable.',
                'Changes and consumer protection' => 'Changes to prices or material renewal terms require appropriate prior notice and consent where required. Preserve mandatory consumer cancellation, refund and dispute rights in every applicable market.',
            ]),
            self::make('Marketplace Fees and Creator Payout Policy', 'commerce', [
                'Fees and merchant status' => 'Any applicable Bambodia listing, commission, subscription, or transaction fee must be clearly disclosed to creators before they commit to a chargeable service. The final contract must identify whether Bambodia, a seller, or an approved payment processor collects money from buyers.',
                'Eligibility and verification' => 'Creator payouts, if and when activated, may require verified identity, lawful account ownership, tax information, and provider eligibility. Bambodia must not promise an unavailable cash-out method or a payment timeline before approved payment infrastructure exists.',
                'Revenue records and deductions' => 'A creator dashboard should show transaction references, gross amounts, disclosed platform fees, taxes or withholding when legally required, refunds, chargebacks and net amounts. Disputed or reversed transactions must not be represented as settled earnings.',
                'Payout schedules and holds' => 'Approved providers may impose settlement cycles, payout thresholds, fraud checks and compliance holds. Disclose the grounds for a hold, available review channel and expected process without promising a fixed period that the provider has not approved.',
                'Refunds and adjustments' => 'Refunds, reversals and chargebacks may adjust the creator balance subject to the recorded agreement and mandatory rights. Do not debit unrelated sales without a lawful contractual basis and documented reconciliation.',
                'Cross-border and tax obligations' => 'Creators remain responsible for accurate tax and legal identity information. Cross-border payments depend on the approved provider, applicable sanctions and financial rules, currency availability, and the jurisdiction of every participant.',
            ]),
            self::make('Reviews and Ratings Integrity Policy', 'terms', [
                'Genuine customer feedback' => 'Product and service ratings should represent authentic first-hand experiences. Distinguish verified purchases from unverified feedback where supporting transaction evidence exists. A rating is an opinion and not a guarantee of future results.',
                'Prohibited manipulation' => 'Do not buy positive reviews, sell negative reviews, organize undisclosed incentivized feedback, impersonate customers, coordinate false complaints, or threaten users to change accurate assessments.',
                'Moderation and appeals' => 'Bambodia may investigate reports of harassment, fabricated claims, sensitive personal information, malware links, and review manipulation. Removal or restriction should be proportionate, documented and accompanied by an available appeal channel where feasible.',
                'Conflicts and incentives' => 'Creators, employees and business partners must disclose relationships that may materially affect the credibility of feedback. Any benefit offered in exchange for a review must not be conditioned on a favorable rating.',
                'Corrections and disputes' => 'Report suspicious reviews through the Support Center with relevant URLs and evidence, not private credentials or payment details. Disputes about workmanship or delivery belong in the relevant contract or refund procedure.',
                'Retention and privacy' => 'Review evidence must be accessed only for an authorized purpose and retained according to the applicable Privacy Policy and operational needs.',
            ]),
            self::make('Advertising and Sponsored Listings Policy', 'terms', [
                'Identification of advertising' => 'Any paid placement, sponsored recommendation, or promotional ranking should be clearly identified as advertising or sponsored content before users make transactional decisions.',
                'Advertiser responsibilities' => 'Advertisers must possess necessary rights to the product, service, brand and media promoted. Claims about features, prices, licensing, availability, and performance must be accurate, substantiated, and regularly updated.',
                'Prohibited advertising' => 'Misleading endorsements, impersonation, unauthorized copyrighted media, malicious downloads, deceptive landing pages, and unlawful regulated products or services are prohibited.',
                'Review and enforcement' => 'Bambodia may reject, pause, remove or investigate advertising in line with the published terms and applicable law. The marketplace should offer a documented process for correcting factual errors and appealing erroneous restrictions.',
                'Privacy and targeting' => 'Personalized advertising, cookies and measurement technologies are subject to the Privacy and Cookie Policies, relevant consent requirements, and actual installed platform capabilities. Do not claim a targeted-advertising practice exists if not implemented.',
                'Fees and refunds' => 'Advertising budgets, billing models, cancellations and any refunds must be stated before an advertising order is accepted. Do not imply that advertising inventory is currently on sale until the administrative capability is enabled.',
            ]),
            self::make('Account Suspension and Appeals Policy', 'terms', [
                'Reasons and proportionality' => 'An account or listing may be restricted for documented security threats, fraud indicators, prohibited content, credible infringement reports, illegal activity or material contractual violations. Support expiration by itself does not end a valid perpetual digital-product license.',
                'Notice and urgent action' => 'Where lawful and practicable, inform the affected user about the measure, general reason, affected features and review options. Immediate temporary action may be needed to prevent harm, protect credentials or meet binding legal instructions.',
                'Appeals and correction' => 'Users may request review through the Support Center. Explain the affected account or listing and supply relevant transaction or license identifiers without posting secrets. Staff should review available evidence and record an outcome.',
                'Payments and licensed content' => 'A platform account suspension must not silently replace or rewrite historical product-license grants. Any payment hold or entitlement restriction requires a separate documented lawful basis and available correction procedure.',
                'Evidence and privacy' => 'Investigations should limit access to the information needed, avoid publication of private allegations, and retain appropriate audit records subject to privacy and legal constraints.',
                'Final remedies' => 'This draft does not override statutory complaint routes, contractual dispute procedures or mandatory consumer protection remedies. Legal counsel must review notice, appeal and termination clauses before publication.',
            ]),
            self::make('Cross-Border Transactions and Tax Policy', 'commerce', [
                'Geographic eligibility' => 'Availability of products, services, currencies and regulated payment functions depends on the relevant seller, customer location, rights holder, bank or payment provider and applicable law. A globally accessible website does not guarantee acceptance in every country.',
                'Taxes and invoices' => 'Prices may be subject to legally required sales, value-added, withholding or other taxes depending on the transaction. Identify who calculates, collects and remits applicable taxes before checkout. Do not claim Bambodia issues compliant international invoices until that process has been independently verified.',
                'Currency and conversion' => 'Where a provider offers conversion, disclose the displayed currency and applicable exchange-rate method, fees and settlement currency. Any estimated conversion must be distinguished from the final verified processor amount.',
                'Trade and service restrictions' => 'Creators and customers must not use the marketplace to violate applicable export controls, sanctions, intellectual-property restrictions or sector licensing rules. A user may be asked to confirm lawful eligibility for restricted products or services.',
                'Receipts and required records' => 'Retain transaction records and provide required confirmations according to the applicable law, the disclosed merchant relationship and the Privacy Policy. Avoid collecting unnecessary tax identification information through public support forms.',
                'Dispute and legal priority' => 'Consumers retain mandatory rights that cannot be excluded by online marketplace terms. Obtain local advice before offering automatic tax treatment or particular cross-border payment services.',
            ]),
        ];
    }

    private static function make(string $title, string $category, array $sections): array
    {
        $body = '# '.$title."\n\nVersion 1.0.0 — Draft for legal review. This template is not effective until reviewed and individually published.\n";
        foreach ($sections as $heading => $text) {
            $body .= "\n## ".$heading."\n".$text."\n";
        }
        $body .= "\n## Contact and governing documents\nQuestions and requests for correction can be raised through Bambodia Support Center or legal@bambodia.com. The published platform Terms of Service, Privacy Policy, applicable product-specific agreement, and mandatory law control as appropriate. This draft must be checked against actual payment, tax, security, and customer-service operations before publication.\n";
        return ['title' => $title, 'category' => $category, 'jurisdiction' => 'Cambodia / international customers — qualified legal review required', 'body' => $body];
    }
}
