<?php

namespace App\Support;

/**
 * Reusable, content-only milestone titles for purchased tracking workspaces.
 *
 * These are suggestions, not mandatory workflow steps. The selected title is
 * submitted through the existing owner-authorized task endpoint, and the
 * creator can edit or replace the text before saving.
 */
final class TrackingTaskPresets
{
    private const UNIVERSAL = [
        'Planning & kickoff' => [
            'Confirm project scope and success criteria',
            'Collect client requirements and reference materials',
            'Agree on milestones and delivery expectations',
        ],
        'Quality & handover' => [
            'Complete internal quality assurance review',
            'Share work for customer feedback',
            'Implement agreed revisions and final checks',
            'Submit final deliverables for approval',
            'Provide handover notes and documentation',
        ],
    ];

    private const BY_TYPE = [
        'project' => [
            'Project discovery & design' => [
                'Conduct discovery and requirement analysis',
                'Prepare project plan and implementation roadmap',
                'Create wireframes or initial design concepts',
                'Present design direction for client review',
            ],
            'Project development & delivery' => [
                'Implement approved features and workflows',
                'Integrate required services and components',
                'Test responsive behavior and key user journeys',
                'Complete deployment and acceptance testing',
            ],
        ],
        'service' => [
            'Service preparation' => [
                'Review service requirements and current setup',
                'Complete initial assessment and recommendations',
                'Prepare required access, assets, and configurations',
            ],
            'Service execution' => [
                'Perform agreed service configuration or work',
                'Validate service quality and expected outcomes',
                'Document work completed and recommendations',
                'Deliver service results and supporting evidence',
            ],
        ],
        'freelance' => [
            'Freelance planning & drafts' => [
                'Hold project kickoff and confirm deliverables',
                'Research project materials and references',
                'Prepare the first draft or working prototype',
                'Share initial progress for client feedback',
            ],
            'Freelance delivery & revisions' => [
                'Apply client feedback to the working draft',
                'Conduct final review and quality checks',
                'Package completed work and supporting files',
                'Deliver final assets and usage instructions',
            ],
        ],
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function groupsFor(?string $type): array
    {
        // Preserve useful general presets even when an older listing has
        // an unknown type, but never surface another listing's specializations.
        return array_merge(
            self::BY_TYPE[$type ?? ''] ?? [],
            self::UNIVERSAL,
        );
    }

    /**
     * @return list<string>
     */
    public static function recommendedFor(?string $type): array
    {
        $defaults = [
            'project' => [
                'Conduct discovery and requirement analysis',
                'Create wireframes or initial design concepts',
                'Implement approved features and workflows',
            ],
            'service' => [
                'Review service requirements and current setup',
                'Perform agreed service configuration or work',
                'Deliver service results and supporting evidence',
            ],
            'freelance' => [
                'Hold project kickoff and confirm deliverables',
                'Prepare the first draft or working prototype',
                'Deliver final assets and usage instructions',
            ],
        ];

        return $defaults[$type ?? ''] ?? [
            'Confirm project scope and success criteria',
            'Complete internal quality assurance review',
            'Submit final deliverables for approval',
        ];
    }
}
