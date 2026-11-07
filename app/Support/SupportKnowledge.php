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

    /**
     * Supplemental customer-facing help templates. This method returns data only;
     * the administrator must explicitly create drafts, review, then publish.
     */
    public static function additionalArticles(): array
    {
        return [
            self::article('Getting around the Bambodia marketplace', 'getting-started', 'Learn where to find products, projects, services, freelance opportunities, and support.', [
                'Start with the main navigation' => 'The public navigation groups the marketplace into Product, Project, Service and Freelance areas. Select a section that matches what you want to explore. An item may not appear publicly until its creator has completed the required review process.',
                'Use search carefully' => 'Search by a descriptive keyword, then use the available category and type filters to narrow the results. Read an item\'s complete details rather than relying on a title or image alone.',
                'Where to get help' => 'If you are unsure about a product\'s conditions or how a feature works, consult the published help articles and policies. Sign in to create a private support ticket for an account-specific question.',
            ]),
            self::article('Choosing a digital product before purchase', 'getting-started', 'A checklist for reviewing requirements, ownership rights, updates, and support.', [
                'Review the product details' => 'Read the description, supported runtime or software requirements, release information, screenshots, documentation, and any creator notes. Check that the product matches your intended end use.',
                'Understand the license' => 'Compare the published Regular and Extended License definitions with the product-specific terms. Your license governs permitted usage; purchasing a digital file does not automatically transfer its copyright.',
                'Check support and delivery' => 'Confirm the applicable support period and whether updates are included. Availability of payment and checkout options may vary by region and verification status. Ask a private question before proceeding when something is unclear.',
            ]),
            self::article('Finding help for a marketplace problem', 'getting-started', 'Choose between public help articles, policies, and a private support ticket.', [
                'Start with the knowledge base' => 'Use the Support Center search bar and category filters. Public articles answer general questions about licensing, downloads, security and marketplace workflows.',
                'Check the published policies' => 'Policy Center contains the authoritative documents that have been reviewed and published. Read the version relevant to your transaction or account question.',
                'Open a private ticket when needed' => 'Sign in and create a support ticket for account-specific records, access issues or disputes. Provide the relevant product or ticket reference without including passwords, full license keys or payment-card details.',
            ]),
            self::article('Resetting your account password securely', 'accounts', 'Use password recovery safely and reduce the risk of credential theft.', [
                'Start from the official sign-in page' => 'Use the Forgot password option on the official Bambodia sign-in page. Enter the email address associated with your account and follow the instructions provided by the site.',
                'Check the recovery message' => 'Look for the recovery email, including spam or filtered folders, and allow for delivery delays. Do not share reset links or one-time codes. A valid recovery link should lead back to the expected Bambodia domain.',
                'When recovery does not work' => 'Avoid repeatedly guessing passwords. If the email is unavailable or recovery fails, create a private support request using the documented account-recovery process. Support should not need your existing password.',
            ]),
            self::article('Reviewing your account information and notifications', 'accounts', 'Find your personal workspace and understand where account notices appear.', [
                'Open your account' => 'After signing in, use the profile menu to navigate to your account workspace. Available options depend on your role, permissions and enabled site features.',
                'Review important notices' => 'Check the in-app notification area for account updates and support activity. A notification is not, by itself, proof that a payment has settled or an entitlement has been granted.',
                'Protect personal records' => 'Review information before sharing screenshots with support. Hide email addresses, private license keys, reset tokens and payment identifiers that are not needed to diagnose the issue.',
            ]),
            self::article('Recognizing phishing and suspicious marketplace messages', 'accounts', 'Identify warning signs before clicking links or sharing sensitive information.', [
                'Watch for unexpected requests' => 'Treat messages asking for a password, recovery code, full payment-card details or private license key as suspicious. Be cautious with urgent requests to move a conversation or payment off-platform.',
                'Check the destination' => 'Verify the website address before signing in. Do not assume a familiar logo, sender name or screenshot proves that an email or message is genuine.',
                'Report the concern' => 'Use the site\'s official contact or support route to report suspicious content. Include a non-sensitive description and message context. Do not send secrets or open unknown attachments just to investigate.',
            ]),
            self::article('Understanding a license record after checkout', 'licenses', 'Identify the product, license type, status, and permitted use in your account.', [
                'Find the issued license' => 'Open your account\'s license area and select the matching product. Check the recorded license identifier, type, status, domain and support period rather than relying only on an invoice or email subject.',
                'Confirm the applicable terms' => 'Read the current published definitions and any product-specific license conditions. Your issued record and accepted terms determine the rights you actually received.',
                'Handle inconsistencies privately' => 'If the recorded product or domain seems incorrect, open a support ticket with the non-secret license reference. Do not publish license keys or request usage beyond the issued entitlement.',
            ]),
            self::article('Moving an authorized product to a new domain', 'licenses', 'Prepare a legitimate domain migration without using one license on unrelated sites.', [
                'Identify the original installation' => 'Locate the product license record and document the currently authorized production domain. Back up the installation and its configuration before making a change.',
                'Request the domain change' => 'Use the applicable account or support workflow to request the new domain. Explain whether it replaces the old authorized end product or represents an additional deployment.',
                'Verify activation after approval' => 'Only activate on a new domain once the allowed change is reflected in the license record. Do not assume temporary staging or test domains are automatically permitted; consult the applicable license.',
            ]),
            self::article('Checking license support eligibility', 'licenses', 'Learn how license duration, technical support, and updates can differ.', [
                'Find the support period' => 'Open your issued license and review its recorded support start, end date and current status. A perpetual usage license is not necessarily perpetual technical support.',
                'Review the product\'s conditions' => 'Check whether the issue concerns an included update, documentation, installation help or a separate paid service. Support entitlements can vary by product and by individually approved administrative assignment.',
                'Ask for clarification' => 'If coverage is disputed, submit a private support request with the product and non-secret license reference. Keep proof of purchase available through your account.',
            ]),
            self::article('Checking a download before installing it', 'downloads', 'Protect your device and application when receiving digital product files.', [
                'Use your authorized account link' => 'Download files from the product\'s official Bambodia entitlement area when available. Avoid unofficial mirrors, forwarded archives or links shared in unsolicited messages.',
                'Inspect the contents' => 'Check the product documentation, supported platform versions and expected folder structure before running an installer or script. Use the security tools appropriate to your operating system.',
                'Keep a recovery copy' => 'Back up your existing files and database before installation or upgrade. Do not overwrite a working deployment until you understand the product\'s upgrade and rollback instructions.',
            ]),
            self::article('Preparing to update an installed digital product', 'downloads', 'Plan an update with a backup, compatibility review, and rollback option.', [
                'Read the release notes' => 'Compare your current version with the available release and check platform, runtime, plugin and database compatibility. Confirm whether the update is covered by your entitlement.',
                'Test safely first' => 'Take a verified backup of files and database content. Where possible, test the update on an authorized staging installation before touching the live application.',
                'Recover if an update fails' => 'Use the creator\'s documented rollback procedure and your backup. Capture the error message and affected version for a private support ticket; exclude passwords and environment secrets.',
            ]),
            self::article('Troubleshooting a missing or unavailable download', 'downloads', 'Find common reasons an authorized digital-product download may be missing.', [
                'Confirm the right account' => 'Sign in with the account associated with the purchase or authorized entitlement. Open My Downloads and inspect the relevant product record.',
                'Check entitlement status' => 'A pending order or unverified transaction may not grant access. File availability and eligible versions can also depend on product release and license conditions.',
                'Request assistance' => 'If a verified entitlement is missing, open a private ticket with the product title, approximate purchase date and non-secret order reference. Do not attach complete payment credentials.',
            ]),
            self::article('Writing a clear product listing in Creator Studio', 'creators', 'Prepare accurate product information before submitting a listing for review.', [
                'Explain what the product does' => 'Use a specific title and describe included features, supported platforms, system requirements, installation expectations and known limitations. Avoid claiming features that are not implemented.',
                'Use clear release details' => 'Provide a version, relevant documentation and a change history when those fields are available. Distinguish tested functionality from features that are planned.',
                'Check the final presentation' => 'Review the listing as a customer would. Fix misleading screenshots, broken links and unclear license references before submitting for review. Saving a draft does not make the listing publicly visible.',
            ]),
            self::article('Preparing product screenshots and media responsibly', 'creators', 'Use approved images and accurate previews when building a product listing.', [
                'Use assets you may distribute' => 'Upload your own product images or materials for which you have appropriate permission. Do not use someone else\'s brand imagery or confidential customer information without authorization.',
                'Show the real product' => 'Select screenshots that match the delivered version. Remove passwords, private license keys and personal data from every image before upload.',
                'Follow review requirements' => 'Check the current media and product submission controls for file type, size, rights and approval status. An uploaded asset may remain private or pending until the applicable checks pass.',
            ]),
            self::article('Understanding creator listing review and visibility', 'creators', 'Know why a submitted or edited listing may not appear in the public marketplace.', [
                'Save versus publish' => 'Creator Studio may distinguish draft, submitted, approved and rejected work. A saved listing is not automatically available to customers.',
                'Respond to review feedback' => 'If an administrator requests revisions, update the affected listing and supporting evidence. Correct any claims about licenses, features, authorship or uploaded files.',
                'Verify the public result' => 'After approval, use the published listing URL to confirm what visitors see. If the item still cannot be found, check its status and raise a private support request.',
            ]),
            self::article('Preparing a clear freelance project brief', 'freelance', 'Describe deliverables, timeline, and acceptance criteria before engaging a freelancer.', [
                'Define the desired outcome' => 'Explain the problem to solve, intended deliverables, relevant technology and any constraints. Avoid placing passwords or confidential source files in a public job description.',
                'Specify milestones' => 'Where the contract workflow supports it, record each milestone, expected date, review process and acceptance criteria. Make the distinction between included work and optional changes clear.',
                'Agree before work starts' => 'Review the relevant marketplace and freelance terms. Document mutual agreement through authorized contract or messaging features rather than relying solely on informal promises.',
            ]),
            self::article('Evaluating a freelancer proposal', 'freelance', 'Compare proposed scope, schedule, communication, and evidence of relevant work.', [
                'Check the scope' => 'Assess whether the freelancer understands the requested outcome and has described the work they intend to deliver. Clarify assumptions and exclusions before accepting a proposal.',
                'Discuss realistic milestones' => 'Consider project timing, review cycles and dependencies. Confirm how feedback and changes would be handled in the recorded agreement.',
                'Protect your project' => 'Use the authorized communication and contract workflow. Share sensitive access only through suitable secure channels when necessary and with least privilege.',
            ]),
            self::article('Requesting revisions and accepting freelance deliverables', 'freelance', 'Keep a clear review trail for project changes, milestone submissions, and acceptance.', [
                'Review against the agreement' => 'Compare each deliverable to the recorded scope and acceptance criteria. Document specific defects or missing work rather than issuing vague rejection comments.',
                'Record change requests' => 'Agree on additional scope and timing before treating requested work as part of the original contract. Preserve the conversation, version history and relevant evidence.',
                'Escalate unresolved issues' => 'Use the available contractual dispute pathway when a disagreement cannot be resolved. Read the published freelance dispute terms before making formal claims.',
            ]),
            self::article('Understanding pending orders and verified payments', 'payments', 'Learn why an order may not immediately grant a download or license.', [
                'Order state is not settlement' => 'An order confirmation page or browser redirect alone does not establish that a payment provider has verified a transaction. Access to paid files depends on the recorded verified entitlement.',
                'Check enabled payment methods' => 'The actual methods, currencies and regions available to your account can differ and may be disabled pending provider or compliance checks. Follow only the live checkout options presented by the platform.',
                'Resolve discrepancies safely' => 'For an unexpected charge or pending order, use the account or private support workflow with a non-secret reference. Never provide payment-card numbers or banking passwords.',
            ]),
            self::article('Preparing a safe billing support request', 'payments', 'Provide information that helps resolve a billing issue without exposing financial secrets.', [
                'Identify the transaction' => 'Record the product or service title, order identifier, approximate date, amount and currency where available. Explain what the account currently shows.',
                'Describe the concern' => 'State whether the problem is a duplicate charge, unverified payment, unavailable entitlement, inaccurate receipt or refund question. Include only relevant, redacted evidence.',
                'Protect sensitive information' => 'Use a private support ticket and avoid publishing full bank statements, payment-card numbers, access tokens or private credentials. Refund and remedy decisions follow the applicable published terms and law.',
            ]),
            self::article('Finding refund rules for a digital purchase', 'payments', 'Understand where to find transaction-specific refund conditions and request review.', [
                'Read the published policy' => 'Consult the current Refund and Cancellation Policy, the product\'s license and any applicable offer terms. Eligibility may depend on the transaction and governing law.',
                'Gather relevant records' => 'Use the associated order, product and license record to explain the concern. Provide accurate details of the delivery or functionality issue without sending sensitive financial information.',
                'Submit a request for review' => 'Use a private support ticket or the applicable refund-request workflow if one is available. The existence of a support ticket does not itself guarantee a refund.',
            ]),
            self::article('Finding the current version of a marketplace policy', 'policies', 'Identify published policy revisions and avoid relying on outdated drafts.', [
                'Use Policy Center' => 'Visit Bambodia\'s Policy Center from the public footer or Support Center. Only individually approved, published documents should be treated as current public statements.',
                'Check version and dates' => 'Review the document title, publication or effective date, and any revision metadata shown. If you saved a previous copy, compare it with the live published version.',
                'Ask when terms are unclear' => 'Use the official support route for a clarification request. General help articles explain workflows but do not replace the legally controlling published policy.',
            ]),
            self::article('Reporting suspected intellectual property infringement', 'policies', 'Report unauthorized use of creative or software materials through the proper channel.', [
                'Describe the affected work' => 'Identify the original work, the specific Bambodia listing or content at issue, and why you believe the material is unauthorized. Provide your relationship to the work.',
                'Submit evidence responsibly' => 'Use the site\'s documented intellectual-property reporting or support process. Include only necessary links and evidence; remove credentials and irrelevant personal data.',
                'Understand the review process' => 'Reports require assessment under the applicable intellectual-property and marketplace policies. A report does not automatically establish ownership or justify immediate removal without review.',
            ]),
            self::article('Requesting access to or correction of personal information', 'policies', 'Learn how to submit a privacy request and keep sensitive details out of public discussions.', [
                'Review the Privacy Policy' => 'Read the current published Privacy Policy to understand available contact routes, stated data practices and any jurisdiction-dependent rights.',
                'Use your account request channel' => 'Where available, open the Privacy Requests area in your account and select the request type appropriate to your concern. Submit only the information needed to identify the relevant records.',
                'Follow up securely' => 'Keep the request identifier and monitor official account or support updates. Verification and response timelines depend on the request and applicable requirements; never post identity documents publicly.',
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
