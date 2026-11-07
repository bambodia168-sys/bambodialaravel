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
