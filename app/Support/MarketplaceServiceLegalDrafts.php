<?php

namespace App\Support;

/**
 * Optional marketplace-specific legal-review templates; never publish automatically.
 */
final class MarketplaceServiceLegalDrafts
{
    /** @return array<int, array{title: string, category: string, jurisdiction: string, body: string}> */
    public static function all(): array
    {
        return [
            self::make('Trader and Creator Identity Disclosure Policy', 'terms', [
                'Purpose and roles' => 'This document concerns business sellers, creators, and service providers offering products or services through Bambodia. The final contract must accurately distinguish the platform operator, a seller, an independent professional, and the merchant of record. A marketplace profile does not by itself establish a verified trading identity.',
                'Required seller information' => 'Before enabling chargeable transactions, obtain identity, contact, business-registration, authorization, and other trader information required in each applicable jurisdiction. Restrict access to confidential verification documents. Request only proportionate evidence through an authenticated process, not public message threads.',
                'Customer-facing transparency' => 'Show the responsible seller or provider and a usable complaint contact where legally required before a binding purchase. Do not conceal material seller identity, business status, delivery restrictions, or who supplies customer refunds and technical support. Correction requests must be handled without misrepresenting checks as government certification.',
                'Verification and enforcement' => 'If mandated information is absent or inconsistent, do not enable the affected trading activity until legally required checks have been completed. A reported fake identity should trigger investigation, access restrictions proportionate to risk, a correction or appeal channel, and limited evidence retention.',
                'Activation review' => 'Verify real seller onboarding, public listing information, business registration, moderation, country restrictions, and provider eligibility before publishing. EU trader-traceability rules may apply when offering services to EU consumers; qualification must be reviewed by counsel.',
            ]),
            self::make('Product Listing Accuracy and Buyer Information Policy', 'commerce', [
                'Accurate descriptions' => 'Each listing must identify the digital files, version, relevant software prerequisites, support and update rights, known limitations, demo restrictions, applicable license tier, and whether source code or editable assets are included. Do not advertise sample-only features as operational.',
                'Price and contract disclosures' => 'Before a binding purchase disclose who sells the product, the total price and currency, taxes and fees when applicable, delivery and access rules, cancellation or refund limitations, and any material third-party charges. Until payments are enabled, do not imply a listing creates a paid order or entitlement.',
                'Prohibited representations' => 'Creators must avoid fictitious ratings, misleading scarcity, false third-party certification, unsupported compatibility or security promises, copied branding without permission, and fabricated customer endorsements. Advertising and sponsored placements must be properly distinguished when offered.',
                'Corrections and purchase history' => 'Material changes should be reflected in listing versions and buyer notices where required. Do not retroactively reduce previously accepted license rights or silently substitute unrelated deliverables. Complaints should be assessed against saved listing facts and the applicable transaction terms.',
                'Legal verification' => 'Confirm actual listing fields, product demos, checkout disclosures, tax and invoice support, consumer communications, and Khmer-language information duties where applicable. Publishing this policy does not confirm that any dormant commerce feature is live.',
            ]),
            self::make('Digital Product Quality, Malware and File Safety Policy', 'commerce', [
                'Covered items' => 'These draft rules cover scripts, templates, themes, source code, plugins, digital designs, presentations, and other downloadable goods. They define seller obligations without claiming the platform has performed security certification or malware scans where no such controls have been independently verified.',
                'Creator submission duties' => 'Creators must have distribution rights, provide the files represented in the listing, document relevant setup requirements, remove exposed passwords, and not embed credential theft, undisclosed remote control, malicious redirects, cryptomining, spyware, or unauthorized access mechanisms.',
                'Versioning and downloads' => 'Where supported, uploaded versions should be identifiable and retrievable only under valid download entitlements. Disclose material compatibility or safety changes in release notes. A published upload or marketplace badge is not an assurance that the item is free of every defect.',
                'Reports and remedies' => 'Provide a private reporting method for suspected harmful files. The service should investigate credible reports, proportionately restrict affected versions when necessary, preserve evidence lawfully, and determine customer notices, remediation, replacement, refunds, and rights under the governing agreement.',
                'Publication gate' => 'Check the actual upload system, file vault, audit events, malware controls, version management, authorized download delivery, incident notifications, and support staffing. Remove every promise that cannot presently be met.',
            ]),
            self::make('Third-Party Components, Dependencies and Compatibility Policy', 'commerce', [
                'Separate component rights' => 'A software product may include libraries, fonts, photos, APIs, model outputs, payment adapters or services owned by others. Sellers must comply with the applicable component licenses, include notices or source disclosures where required, and not suggest that a marketplace license overrides third-party rights.',
                'System requirements' => 'Clearly identify tested framework and language versions, operating environments, browsers, databases, hosting limitations, required extensions, and setup dependencies when material. Do not promise compatibility with every provider, future version, or customer customization.',
                'External providers and costs' => 'Declare required paid accounts, third-party APIs, separate provider terms, regional onboarding eligibility, rate limits, and recurring costs before a purchase. Integration code does not mean a provider supports the buyer or is authorized for live payment processing.',
                'Support and updates' => 'The accepted license and support record govern the duration and scope of assistance. External changes can cause incompatibility; the creator should disclose known issues and available mitigations without claiming indefinite updates, guaranteed functionality, or ownership of outside services.',
                'Remedies and review' => 'Incorrect material claims should be corrected and lawful buyer remedies preserved. Before publication, verify actual product requirements, third-party notices, included asset licenses, release checks, and support promises.',
            ]),
            // MORE_MARKETPLACE_POLICIES
        ];
    }

    /** @param array<string, string> $sections */
    private static function make(string $title, string $category, array $sections): array
    {
        $body = "# ".$title."\n\nDraft for legal review — NOT PUBLISHED\nVersion 1.0.0. This is proposed text, not confirmation of live functionality.\n";
        foreach ($sections as $heading => $text) {
            $body .= "\n## ".$heading."\n".$text."\n";
        }
        $body .= "\n## Legal review and controlling rights\nBefore publication, confirm the operating entity, correct contact channels, actual workflows, effective date, intended jurisdictions and local-language requirements. Rights under applicable mandatory consumer, privacy, intellectual-property and other laws are not waived. This draft is not legal advice or certification of compliance.\n";
        return [
            'title' => $title,
            'category' => $category,
            'jurisdiction' => 'Cambodia / international customers — qualified legal review required',
            'body' => $body,
        ];
    }
}
