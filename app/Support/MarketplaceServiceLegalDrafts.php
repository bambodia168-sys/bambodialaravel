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
            self::make('Jobs and Recruitment Listings Policy', 'terms', [
                'Purpose' => 'This policy covers jobs, freelance opportunities, recruitment listings, applications, proposals, applicant profiles, and work-related communications. Distinguish independent freelance projects from employment; a job listing does not establish that Bambodia is the employer or guarantees placement.',
                'Accurate offers' => 'Posters must identify the legitimate hiring party where required and describe duties, work location or remote status, eligibility, compensation or rate basis when known and required, and any genuine application conditions. No fictitious positions, bait-and-switch compensation, unauthorized recruitment fees, impersonation, coercion or discriminatory unlawful practices.',
                'Privacy and documents' => 'Applicants should share only necessary career information through approved private channels. Avoid uploading passports, payment credentials or identity documents before verifying the requester and necessity. Employers must comply with applicable labor, immigration, recruitment, anti-discrimination and personal-information laws.',
                'Reporting and protections' => 'Use the Support Center to report fake employers, fraudulent application fees, leaked applicant details or other harmful activity. The platform may investigate and restrict unsafe listings, provide correction or appeal paths, and retain limited lawful evidence; do not promise background checks not actually implemented.',
                'Before publication' => 'Verify Job Manager, applicant visibility, proposal privacy, content review, staff response capacity, geography, actual billing, and available safety reporting. No recruiting, visa sponsorship, or salary guarantee may be implied without confirmed authorization.',
            ]),
            self::make('Service Inquiries, Quotes and Scope of Work Policy', 'terms', [
                'Service inquiries' => 'Service listings and inquiries are requests for information, not binding orders unless a clear offer-and-acceptance procedure has been completed. Identify the service provider and distinguish platform administration from independent contract performance.',
                'Quotes and deliverables' => 'A formal quote should describe scope, deliverables, excluded work, timeline, revision allowance, acceptance method, intellectual-property rights, dependencies, price and currency if relevant, and any fees, taxes, or terms for cancellation. Customer-supplied materials must be accurate and lawful.',
                'Changes and approval' => 'Record material change orders and obtain documented agreement before expanding scope or charging additional fees. Silence should not be interpreted as consent. Do not claim escrow, guaranteed delivery, mandatory deposits, or payment release features unless those functions and any required permissions are independently verified.',
                'Files and confidentiality' => 'Use authorized private channels for proposals, work documents, source code and credentials. Providers must respect third-party licenses and confidentiality. Project handover should clearly define who receives deliverables and which original rights stay with creators.',
                'Disputes and review' => 'Complaint handling should use the accepted quote, contract and preserved messages; mandatory remedies remain available. Before publication, verify real inquiry, quotes, contracting, notifications, payment eligibility, and support workflows.',
            ]),
            self::make('Freelance Milestones, Deliverables and Handover Policy', 'terms', [
                'Milestone agreement' => 'Set clear deliverables, deadlines, review criteria and revision rules for each agreed stage. A milestone status or completion badge must not imply cleared payment, escrow protection, ownership transfer or dispute resolution without supporting transaction and contract records.',
                'Submission and customer review' => 'Freelancers should submit work through the permitted contract tools. Clients should compare output to accepted requirements, accept appropriate work, or give specific, documented reasons for requesting revisions. Material extra work requires a recorded change order.',
                'Ownership and transfer' => 'Document who owns pre-existing assets, the scope of any transfer or license, required attributions, and separately licensed dependencies. Final handover should include the agreed deliverables, usable source or editable files when sold, documentation and authorized access details via secure channels.',
                'Disputes and unfinished work' => 'Maintain evidence of offers, submissions, feedback and acceptance. Where disputes arise, support a fair record-based review, recognizing that Bambodia is not automatically an arbitral tribunal, regulated escrow provider, or guarantor of payment.',
                'Service readiness' => 'Review the live contract states, milestone privacy, revision requests, submission downloads, confidentiality, completion records and appeal channel before publishing. State only the functions the platform actually offers.',
            ]),
            self::make('License Activation, Domain Changes and Client Transfers Policy', 'commerce', [
                'Authorized entitlement' => 'An issued license records the product, tier, buyer or designated client, one permitted end product where applicable, activation information, support period, and accepted terms. Paid entitlements must arise from verified purchase or authorized administrative assignment, not an unverified payment redirect.',
                'Activation and staging' => 'Respect license-specific boundaries for production, development and migration environments. An installation copied for a different independently operated product may require a separate license. Private keys and activation credentials must not be shared publicly.',
                'Domains and transfer requests' => 'Owners may request lawful domain migration, correction of activation mistakes, or approved client handover through authenticated support. Check the license record, existing usage, recipient authority and prior approvals proportionately. Do not promise automatic transfer if no such feature is available.',
                'Security and revocation' => 'Suspected compromised keys may require secure rotation and review. Support expiration, a temporary activation fault or a legitimate migration request alone should not extinguish perpetual usage rights. Material abuse should be addressed consistently with notice, applicable cure rights and accepted licenses.',
                'Before publishing' => 'Verify actual license records, activation settings, entitlement guard, key rotation, domain-change logs, client-transfer controls and support process. Explain any unimplemented functionality accurately.',
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
