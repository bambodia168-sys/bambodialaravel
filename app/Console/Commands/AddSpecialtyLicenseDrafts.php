<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\LicenseDefinition;
use App\Models\User;
use App\Support\LicenseTerms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AddSpecialtyLicenseDrafts extends Command
{
    protected $signature = 'licenses:add-specialty-drafts {--admin= : Public ID of the license administrator}';

    protected $description = 'Add the Agency, Evaluation, Media, and OEM license definitions as drafts';

    public function handle(): int
    {
        $publicId = $this->option('admin');
        $admins = User::query()->where('account_status', 'active')
            ->when($publicId === null,
                fn ($query) => $query->where('role', 'super_admin'),
                fn ($query) => $query->where('public_id', $publicId)->whereIn('role', ['super_admin', 'admin']))
            ->limit(2)->get();

        if ($admins->count() !== 1) {
            $this->error('Pass --admin with the public ID of an active admin or super admin.');

            return self::FAILURE;
        }

        $admin = $admins->first();
        $drafts = self::drafts();
        $results = DB::transaction(function () use ($drafts, $admin): array {
            $results = [];
            foreach ($drafts as $code => [$tier, $name, $permissions, $restrictions]) {
                $permissionsHtml = LicenseTerms::sanitize($permissions);
                $restrictionsHtml = LicenseTerms::sanitize($restrictions);
                $definition = LicenseDefinition::query()->firstOrCreate(['code' => $code], [
                    'tier' => $tier,
                    'name' => $name,
                    'version' => '1.0.0',
                    'status' => 'draft',
                    'created_by' => $admin->id,
                    'permissions' => LicenseTerms::plain($permissionsHtml),
                    'permissions_html' => $permissionsHtml,
                    'restrictions' => LicenseTerms::plain($restrictionsHtml),
                    'restrictions_html' => $restrictionsHtml,
                ]);

                if ($definition->tier !== $tier) {
                    throw new \RuntimeException("License code {$code} already belongs to another type.");
                }
                if ($definition->wasRecentlyCreated) {
                    AuditEvent::record('license.definition.created', $admin);
                }
                $results[] = sprintf('%s %s %s %s', $code, $definition->public_id, $definition->status,
                    $definition->wasRecentlyCreated ? 'created' : 'existing');
            }

            return $results;
        }, 3);

        foreach ($results as $result) {
            $this->line($result);
        }

        return self::SUCCESS;
    }

    private static function drafts(): array
    {
        return [
            'BAM_AGENCY_5' => [
                'agency',
                'Agency / Multi-Site License',
                <<<'HTML'
                <p><strong>Five Regular entitlements.</strong> The rights holder permits the named buyer to use the licensed product in up to five independently operated production websites, applications, or projects. The buyer may use the projects internally, deliver them to different clients, or combine those uses. Each project is covered by its own Regular entitlement, which identifies the licensed product, applicable terms, and authorized production deployment.</p>
                <p><strong>Development and client work.</strong> Each project may have private development, testing, staging, and backup copies for that same project. Agencies may charge for development, implementation, and maintenance. A completed project may be handed over to its intended client under the documented transfer process. Handover does not create a new entitlement or make the original slot available for a different project.</p>
                <p><strong>Duration and support.</strong> Each compliant project retains the lifetime usage rights of its Regular entitlement. Standard support is six months from the purchase or issuance date recorded for that entitlement unless its record provides a different term. Support can be renewed separately where offered.</p>
                HTML,
                <<<'HTML'
                <p><strong>Project limit and paid access.</strong> Obtain another entitlement before deploying a sixth independent project. Each entitlement follows the Regular License. If a project charges users for access to functionality or content substantially provided by the licensed product, obtain Extended rights for that project unless its accepted product terms already permit the use.</p>
                <p><strong>Distribution and activation.</strong> Do not sell individual entitlements as standalone items, share keys, or distribute the original package, source files, or a modified substitute. Register or change production domains using the applicable platform process. A migration of the same project does not authorize a second independently operating project. The accepted Regular and product-specific terms continue to apply to each covered project.</p>
                HTML,
            ],
            'BAM_EVALUATION' => [
                'evaluation',
                'Evaluation / Trial License',
                <<<'HTML'
                <p><strong>Private evaluation.</strong> The rights holder grants the named evaluator a limited, non-exclusive right to install and run the trial build in one private, non-production environment to assess suitability, compatibility, and documented features. The grant begins on the start date in the written trial record and ends on its recorded expiry date.</p>
                <p><strong>Testing and demonstrations.</strong> The evaluator may show the trial privately to team members and an invited client for the evaluation. Use synthetic or otherwise authorized test data. Any trial assistance is limited to the scope stated in the trial record.</p>
                HTML,
                <<<'HTML'
                <p><strong>Production and distribution.</strong> Do not use the trial to operate a live service, serve end users, accept payments, complete commercial production work, or deliver a client project. Do not share the installer, source files, trial key, or account credentials. Keep trial access limited to the named evaluator and its authorized personnel.</p>
                <p><strong>Expiry and conversion.</strong> Stop using the software when the trial expires. Remove the trial installation and copies under your control unless retaining a copy is required by law. A separate valid production license must cover any later live deployment or client delivery.</p>
                HTML,
            ],
            'BAM_MEDIA_BROADCAST' => [
                'media',
                'Media / Broadcast License',
                <<<'HTML'
                <p><strong>Use in an approved production.</strong> The rights holder grants a non-exclusive right to synchronize or incorporate the media asset identified in the license record into the named production. The buyer may edit the asset for timing, length, level, or presentation as needed for that production, subject to any restrictions stated by the rights holder.</p>
                <p><strong>Distribution scope.</strong> The license record must identify the approved media and channels, territory, term, and any audience or release limits. Approved channels may include online video, paid advertising, television, radio, or theatrical release. The buyer may distribute the finished production and authorized cuts of that same production within the recorded scope.</p>
                HTML,
                <<<'HTML'
                <p><strong>Standalone use and reuse.</strong> Do not sell, sublicense, upload to a stock library, or give the public access to the asset as a separate download or reusable component. Additional productions or uses outside the recorded channels, territory, term, or limits require a separate grant. Do not claim exclusive ownership of the asset or register it as your own in an automated rights-claim system.</p>
                <p><strong>Third-party rights.</strong> Follow any attribution, cue-sheet, or reporting obligations stated in the grant. Obtain any separate public-performance, collecting-society, performer, or other permissions required for your specific use when those rights are outside the rights holder’s grant.</p>
                HTML,
            ],
            'BAM_OEM_REDISTRIBUTION' => [
                'oem',
                'OEM / Redistribution License',
                <<<'HTML'
                <p><strong>Distribution by written agreement.</strong> The rights holder may authorize the named buyer to incorporate and distribute specified components with an identified product under a separate written agreement. The agreement identifies the permitted components, versions, file formats, end product, distribution channels, recipients, territory, term, and any volume limits or fees.</p>
                <p><strong>Rights for end users.</strong> The buyer may deliver the approved components in the agreed form and give end users the rights stated in the agreement to operate them as part of the named product. The buyer must provide the required notices and end-user terms. Renewal, new versions, or additional distribution arrangements follow the written agreement.</p>
                HTML,
                <<<'HTML'
                <p><strong>Limits of redistribution.</strong> Ship only the files and versions explicitly approved. Standalone resale, reusable SDK or source packages, white-label distribution, and sublicensing beyond the rights recorded for end users require express written permission. The grant transfers no ownership of the original components or the rights holder’s trademarks.</p>
                <p><strong>Third-party terms and compliance.</strong> Preserve applicable copyright notices, attribution, and third-party license terms. Meet any reporting or recordkeeping obligations in the agreement. If the permitted format, recipients, or limits are unclear, obtain written approval before distributing the affected files.</p>
                HTML,
            ],
        ];
    }
}
