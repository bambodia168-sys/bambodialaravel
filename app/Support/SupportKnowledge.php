<?php

namespace App\Support;

final class SupportKnowledge
{
    public const CATEGORIES = [
        'getting-started' => 'Getting started',
        'accounts' => 'Account & security',
        'licenses' => 'Product licenses',
        'downloads' => 'Downloads & updates',
        'creators' => 'Creator Studio',
        'freelance' => 'Freelance & contracts',
        'payments' => 'Payments & refunds',
        'policies' => 'Policies & privacy',
    ];

    /**
     * These operational help articles are editable drafts until independently
     * reviewed by an administrator. They never expose privileged URLs or keys.
     */
    public static function starterArticles(): array
    {
        return [
            self::article('How to create a Bambodia account', 'getting-started', 'Register, verify account details where requested, and find your personal workspace.', [
                'Create an account' => 'Open Sign Up and complete the registration form with your own email address and an appropriate strong password. Read the current Terms and Privacy Policy before submitting. If a confirmation step is required by the current site configuration, complete it before using restricted services.',
                'Find your account' => 'After signing in, open the profile menu and select My Account. Your dashboard contains your downloads, purchased license records, saved items, notifications, and support links. Features depend on account role and published services.',
                'Keep your login secure' => 'Use a unique password and avoid placing credentials, private license codes, payment details or identity documents in public messages. If you cannot access your account, use Forgot password on the sign-in page.',
            ]),
            self::article('Understanding Regular and Extended Licenses', 'licenses', 'Learn how license tiers, end products and support periods differ.', [
                'Regular License' => 'A Regular License generally permits one identifiable completed end product and associated non-production testing. It may cover commercial use that does not sell access primarily to the purchased software functionality. The exact accepted product-specific definition and issued license record control your rights.',
                'Extended License' => 'An Extended License can authorize monetized access to features or content of the purchased item within one end product, where expressly granted. It does not grant source-code resale or unrestricted redistribution.',
                'Lifetime use and technical support' => 'The product usage license can be perpetual while support has a separately recorded duration. The standard support period is six months unless an authorized administrator assigns a different documented period. Read the applicable published license and your own issued license record for controlling terms.',
            ]),
            self::article('Finding your product license and activation domain', 'licenses', 'Locate product license details and request authorized activation changes.', [
                'Open My Licenses' => 'Sign in, open My Account, then choose My Licenses. Select the relevant product to inspect its license tier, status, domain assignment and support entitlement. Private keys are sensitive and should never be placed in public screenshots.',
                'Request a domain change' => 'Use the available domain-change request workflow for the specific license. A legitimate migration of the same authorized end product is different from using one license for multiple unrelated production sites.',
                'Need assistance?' => 'If an activation fails, open a private support ticket with the non-secret license identifier, product version and a description of the error. Do not submit full license keys or server passwords.',
            ]),
            self::article('Download purchased digital products safely', 'downloads', 'Use authorized downloads and understand versioned product files.', [
                'Authorized downloads' => 'Go to My Account and open My Downloads. Files are available only where a verified entitlement authorizes access. A license record does not by itself guarantee that every released update is included.',
                'File integrity and installation' => 'Download through Bambodia account controls. Review the creator documentation, permitted PHP/runtime versions and the product-specific installation steps. Keep backups before replacing any running application.',
                'Missing a download' => 'Confirm you are using the correct account and that the transaction or entitlement has been verified. Then create a support ticket with the product title and transaction reference, excluding financial secrets.',
            ]),
            self::article('Creating and managing support tickets', 'getting-started', 'Submit a private request, review staff responses and track ticket status.', [
                'Open a ticket' => 'Sign in and visit the Support Center. Select My tickets and choose Create ticket. Enter a descriptive subject and explain the issue with enough detail to reproduce it. Avoid sending passwords, one-time codes, private keys or payment-card data.',
                'Track and reply' => 'Your ticket has a unique reference number. You can read your own ticket conversation and add replies. Support staff can mark the request Open, In progress or Resolved, and updates may appear in account notifications.',
                'Reopen by replying' => 'If a resolved problem returns, reply to the existing conversation rather than opening duplicates. A customer response may move a ticket back to Open. You can only read tickets belonging to your account.',
            ]),
            self::article('Publishing a product from Creator Studio', 'creators', 'Understand listing preparation, review and release visibility.', [
                'Prepare product information' => 'Creators can access Creator Studio after receiving the appropriate permissions. Enter accurate product details, release information, supported technologies, descriptions, media and required license definitions in the product workspace.',
                'Upload safe files' => 'Follow the documented file upload requirements and ensure you have permission to distribute every included source file and media asset. Private release assets remain behind verified authorization and review processes.',
                'Submit for review' => 'Products and changes may be subject to administrative review. A draft, pending or rejected listing is not a public product merely because its content was saved. Resolve any review feedback before expecting public visibility.',
            ]),
            self::article('Understanding freelance contracts and disputes', 'freelance', 'Follow deliverables, milestones and the available dispute pathway.', [
                'Confirm the scope' => 'Record the agreed deliverables, milestones, dates, acceptance criteria and supporting evidence inside the applicable contract workflow. An informal message does not automatically amend agreed contractual terms.',
                'Deliver and review work' => 'Use authorized contract controls to submit deliverables and request review. Keep a clear history of revisions, approvals and disputed issues. Do not place credentials or confidential client information in public listings.',
                'Raise a dispute' => 'Use the contractual dispute process where available and describe the affected milestone and requested remedy. Review the published Freelance Services and Dispute Policy for the controlling rules.',
            ]),
            self::article('Refunds, payment availability and account security', 'payments', 'Understand where to find applicable refunds and payment status information.', [
                'Refund rules' => 'Review the currently published Refund and Cancellation Policy and the terms attached to the specific product or service. Rights can differ by transaction and governing law; product-license duration is not the same as refund eligibility.',
                'Payment verification' => 'Only a verified transaction can authorize a paid entitlement. Do not assume a browser success message means a provider settled a payment. Actual payment methods and currencies may be unavailable until regulated provider acceptance is complete.',
                'Report a problem' => 'Use a private support ticket for a billing discrepancy. Include a non-sensitive order reference, product title and approximate transaction date. Do not paste card numbers, bank passwords or private tokens.',
            ]),
            self::article('Privacy requests and published policies', 'policies', 'Find the authoritative legal documents and submit personal-data requests.', [
                'Read policies' => 'The Policy Center displays documents that administrators have reviewed and individually published. Public policy IDs and version dates help you identify the document you are reading.',
                'Your account history' => 'Where available, the Policy Receipts section records voluntary acknowledgements of specific published revisions. An acknowledgement is not blanket consent to unrelated data processing.',
                'Privacy requests' => 'Use the account Privacy Requests workflow for supported access or correction requests. Avoid supplying sensitive information through publicly visible comments. Rules and response periods depend on applicable law and the site’s published practices.',
            ]),
        ];
    }

    private static function article(string $title, string $category, string $excerpt, array $sections): array
    {
        $body = '# '.$title."\n";
        foreach ($sections as $heading => $text) {
            $body .= "\n## ".$heading."\n".$text."\n";
        }
        return compact('title','category','excerpt','body') + ['body_format' => 'markdown'];
    }
}
