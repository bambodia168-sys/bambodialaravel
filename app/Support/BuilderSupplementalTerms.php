<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class BuilderSupplementalTerms
{
    private const POLICIES = [
        'usage' => [
            'regular-license', 'extended-license', 'terms-of-service',
            'creator-and-seller-agreement',
        ],
        'delivery' => [
            'digital-product-delivery-and-download-policy', 'support-updates-and-maintenance-policy',
            'freelance-services-and-dispute-policy', 'product-listing-accuracy-and-buyer-information-policy',
            'digital-product-quality-malware-and-file-safety-policy',
        ],
        'billing' => [
            'payments-and-billing-policy', 'refund-and-cancellation-policy',
            'subscription-and-automatic-renewal-policy', 'marketplace-fees-and-creator-payout-policy',
            'cross-border-transactions-and-tax-policy',
        ],
        'marketplace' => [
            'trader-and-creator-identity-disclosure-policy', 'reviews-and-ratings-integrity-policy',
            'advertising-and-sponsored-listings-policy', 'account-suspension-and-appeals-policy',
            'consumer-complaints-resolution-and-escalation-procedure',
        ],
    ];

    public static function template(string $type): string
    {
        $sections = ['usage', $type === 'product' ? 'digital' : 'commissioned', 'support', 'billing', 'marketplace', 'resolution'];

        return implode("\n\n", array_map(
            fn (string $section) => __("builder_terms.template.$section.heading")."\n".__("builder_terms.template.$section.body"),
            $sections,
        ));
    }

    public static function sources(Collection $publications): array
    {
        $bySlug = $publications->groupBy(fn ($publication) => $publication->policy->slug);
        $groups = [];

        foreach (self::POLICIES as $group => $slugs) {
            foreach ($slugs as $slug) {
                if ($matches = $bySlug->pull($slug)) {
                    $groups[$group] = [...($groups[$group] ?? []), ...$matches];
                }
            }
        }

        if ($bySlug->isNotEmpty()) {
            $groups['other'] = $bySlug->collapse()->all();
        }

        return $groups;
    }
}
