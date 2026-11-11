<?php

namespace App\Support;

use App\Models\BuilderTermsAcceptance;
use App\Models\PolicyPublication;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class BuilderSubmissionTerms
{
    private const BASIS = 'Bambodia Builder Studio submission agreement 1.0: accurate and original content; '
        .'ownership or lawful authorization for all files and media; no prohibited content; '
        .'independent rights, malware, and publication reviews; no payments or downloads '
        .'without verified eligibility and enabled fulfillment. Supplemental creator terms '
        .'cannot override the published marketplace policies or license definitions.';

    public static function basis(): string
    {
        return self::BASIS;
    }

    public static function publications(): Collection
    {
        return PolicyPublication::query()
            ->whereHas('policy', fn ($q) => $q->where('status', 'published'))
            ->whereIn('category', ['terms', 'commerce'])
            ->orderBy('policy_id')
            ->orderByDesc('revision')
            ->get(['id', 'policy_id', 'revision', 'title', 'category', 'body_snapshot', 'body_format', 'sha256'])
            ->unique('policy_id')->values();
    }

    /**
     * Display the immutable published policy snapshot using the same safe
     * formatting rules as the public Policy Center. Historical snapshots
     * may have no body_format; infer only common document tags in that case.
     * Never change the snapshot, acceptance digest, or legal wording.
     */
    public static function renderPublication(PolicyPublication $publication): string
    {
        $body = (string) $publication->body_snapshot;
        $format = $publication->body_format;

        // Older imported revisions can contain complete HTML documents even
        // when the format was null or incorrectly defaulted to markdown.
        // Only recognize structural document tags (not arbitrary inline '<').
        // PolicyContent sanitizes HTML regardless of the stored label.
        $htmlDocument = preg_match(
            '~^\\s*<(?:h[1-6]|p|div|ul|ol|blockquote|table|section)\\b~i',
            $body
        ) === 1;
        if (! in_array($format, ['html', 'markdown'], true) || $htmlDocument) {
            $format = $htmlDocument ? 'html' : 'markdown';
        }

        return PolicyContent::display($body, $format);
    }

    public static function digest(?Collection $publications = null): string
    {
        $publications ??= self::publications();
        $parts = [$publications->map(fn ($p) => [
            (int) $p->id, (int) $p->revision, $p->sha256,
        ])->all(), self::BASIS];

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public static function requireAcceptance(Request $request): ?string
    {
        if (! $request->boolean('builder_mode')) {
            return null;
        }
        $values = $request->validate([
            'accept_terms' => ['required', 'accepted'],
            'terms_sha256' => ['required', 'string', 'size:64'],
        ]);
        $expected = self::digest();
        if (! hash_equals($expected, (string) $values['terms_sha256'])) {
            throw ValidationException::withMessages([
                'accept_terms' => 'The publishing terms changed. Review the latest terms and accept them again.',
            ]);
        }

        return $expected;
    }

    public static function record(?string $hash, int $userId, string $contentType, int $contentId): void
    {
        if ($hash === null) {
            return; // Existing non-builder management flows remain unchanged.
        }
        BuilderTermsAcceptance::query()->create([
            'user_id' => $userId,
            'content_type' => $contentType,
            'content_id' => $contentId,
            'terms_sha256' => $hash,
            'accepted_at' => now(),
        ]);
    }

    public static function suggestedTerms(?Collection $publications = null): string
    {
        $publications ??= self::publications();
        $text = $publications->map(fn ($p) => trim($p->title)."\n\n".trim($p->body_snapshot))
            ->filter()->implode("\n\n");
        return mb_strlen($text) <= 20000 ? $text : '';
    }
}
