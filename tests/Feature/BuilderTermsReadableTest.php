<?php

namespace Tests\Feature;

use App\Models\BuilderTermsAcceptance;
use App\Models\MarketplaceListing;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\SiteLanguage;
use App\Models\User;
use App\Support\BuilderSubmissionTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BuilderTermsReadableTest extends TestCase
{
    use RefreshDatabase;

    private function creator(): User
    {
        return User::query()->create([
            'name' => 'Policy Reader',
            'email' => uniqid('builder_policy_', true).'@example.test',
            'password' => 'SecureReaderPassword123!',
            'role' => 'super_admin',
        ]);
    }

    private function publication(string $title, string $category, string $body, ?string $format): PolicyPublication
    {
        $policy = Policy::query()->create([
            'title' => $title, 'body' => $body, 'body_format' => $format ?? 'markdown',
            'category' => $category, 'status' => 'published',
            'version' => '1.0.0',
        ]);
        return PolicyPublication::query()->create([
            'policy_id' => $policy->id, 'revision' => 1, 'title' => $title,
            'category' => $category, 'jurisdiction' => 'Cambodia',
            'body_snapshot' => $body, 'body_format' => $format,
            'sha256' => PolicyPublication::digestFor($policy),
            'published_by' => $this->creator()->id,
            'created_at' => now(), 'version' => '1.0.0',
        ]);
    }

    public function test_builder_accept_terms_renders_sanitized_html_and_markdown_without_changing_agreement(): void
    {
        $html = '<h1>Verified Marketplace Terms</h1>'
            .'<h2>Important responsibilities</h2>'
            .'<p>Creators must provide <strong>accurate</strong> content and proof of usage rights.</p>'
            .'<ul><li>Respect ownership</li><li>Submit original work</li></ul>'
            .'<script>window.unsafeCall()</script>'
            .'<img src="bad" onerror="window.unsafeCall()">'
            .'<iframe src="https://evil.example/embed/x"></iframe>';
        $markup = $this->publication('Readable HTML Terms', 'terms', $html, 'html');

        $markdown = "# Seller Responsibilities\n\n## Account conduct\n\n- Original assets\n- Valid licenses";
        $this->publication('Readable Markdown Commerce', 'commerce', $markdown, 'markdown');

        $digestBefore = BuilderSubmissionTerms::digest();
        $snapshotBefore = $markup->body_snapshot;

        foreach (['product', 'job'] as $type) {
            $response = $this->actingAs($this->creator())
                ->get('/builder?type='.$type)
                ->assertOk()
                ->assertSee('id="studio-terms-popup"', false)
                ->assertSee('builder-terms-readable.css?v=1', false)
                ->assertSee('<h1>Verified Marketplace Terms</h1>', false)
                ->assertSee('<h2>Important responsibilities</h2>', false)
                ->assertSee('<strong>accurate</strong>', false)
                ->assertSee('<li>Respect ownership</li>', false)
                ->assertSee('<h1>Seller Responsibilities</h1>', false)
                ->assertSee('<li>Original assets</li>', false)
                ->assertSee('data-builder-terms-accept', false)
                ->assertSee('name="terms_sha256"', false);

            preg_match('~<dialog\s+id="studio-terms-popup"[^>]*>.*?</dialog>~s', $response->getContent(), $matches);
            $modal = $matches[0] ?? '';
            $this->assertNotSame('', $modal);
            $this->assertStringNotContainsString('&lt;h1&gt;Verified Marketplace Terms', $modal);
            $this->assertStringNotContainsString('&lt;h2&gt;Important responsibilities', $modal);
            $this->assertStringNotContainsString('window.unsafeCall', $modal);
            $this->assertStringNotContainsString('<script>', $modal);
            $this->assertStringNotContainsString('<iframe', $modal);
            $this->assertStringContainsString($digestBefore, $response->getContent());
        }

        $this->assertSame($snapshotBefore, $markup->fresh()->body_snapshot);
        $this->assertSame($digestBefore, BuilderSubmissionTerms::digest());
        $this->assertDatabaseCount('builder_terms_acceptances', 0);
    }

    public function test_the_template_stays_editable_and_short_when_published_policies_are_large(): void
    {
        $regular = $this->publication('Regular License', 'commerce', str_repeat('Long policy body. ', 1500), 'markdown');
        $newer = $regular->replicate();
        $newer->revision = 2;
        $newer->save();
        $withdrawn = $this->publication('Unpublished policy', 'commerce', 'Removed content.', 'markdown');
        $withdrawn->policy->update(['status' => 'draft']);
        $this->actingAs($this->creator());

        foreach (['en', 'km', 'zh'] as $locale) {
            SiteLanguage::query()->updateOrCreate(['code' => $locale], [
                'name' => SiteLanguage::CATALOG[$locale], 'is_enabled' => true,
            ]);
            $copy = require lang_path($locale.'/builder_terms.php');
            $response = $this->get("/$locale/builder?type=product")->assertOk()
                ->assertSee($copy['field'])
                ->assertSee($copy['policies']['regular-license'] ?? 'Regular License')
                ->assertSee(str_replace(':revision', '2', $copy['revision']))
                ->assertDontSee('Unpublished policy');
            $value = $this->supplementalValue($response->getContent());
            $this->assertStringContainsString($copy['template']['digital']['heading'], $value);
            $this->assertStringContainsString("\n\n", $value);
            $this->assertStringNotContainsString('Long policy body.', $value);
            $this->assertLessThan(20000, mb_strlen($value));
        }

        $service = $this->get('/en/builder?type=service')->assertOk();
        $this->assertStringContainsString('Scope and delivery', $this->supplementalValue($service->getContent()));
        $this->get('/en/builder?type=job')->assertOk()->assertDontSee('name="license_terms"', false);
    }

    public function test_existing_terms_and_validation_input_take_precedence_over_the_template(): void
    {
        $creator = $this->creator();
        $listing = MarketplaceListing::query()->create([
            'user_id' => $creator->id, 'type' => 'service', 'title' => 'Creator’s custom service',
            'description' => 'Service description', 'status' => 'draft',
            'license_terms' => "Creator's own terms.\nSecond paragraph.",
        ]);
        $url = '/builder?='.$listing->public_id;
        $this->actingAs($creator);

        $response = $this->get($url)->assertOk();
        $this->assertSame($listing->license_terms, $this->supplementalValue($response->getContent()));

        $listing->update(['license_terms' => null]);
        $response = $this->get($url)->assertOk();
        $this->assertSame('', $this->supplementalValue($response->getContent()));

        $response = $this->withSession(['_old_input' => ['license_terms' => 'Unsaved custom text']])
            ->get('/builder?type=product')->assertOk();
        $this->assertSame('Unsaved custom text', $this->supplementalValue($response->getContent()));
    }

    private function supplementalValue(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $editor = $document->getElementById('studio-terms');
        $this->assertNotNull($editor);

        return $editor->textContent;
    }

    public function test_legacy_snapshot_with_missing_format_renders_common_safe_html(): void
    {
        $legacy = '<h1>Legacy Published Agreement</h1><p>Read the immutable published agreement.</p>';
        // Old ORM snapshots may be missing format metadata altogether.
        // The current SQL schema defaults non-null format to markdown.
        $publication = new PolicyPublication([
            'title' => 'Legacy Terms Snapshot',
            'body_snapshot' => $legacy,
            'body_format' => null,
        ]);

        $html = BuilderSubmissionTerms::renderPublication($publication);
        $this->assertStringContainsString('<h1>Legacy Published Agreement</h1>', $html);
        $this->assertStringContainsString('<p>Read the immutable published agreement.</p>', $html);
        $this->assertStringNotContainsString('&lt;h1&gt;', $html);
        $this->assertNull($publication->body_format);
    }

    public function test_legacy_html_snapshot_mislabeled_as_markdown_is_readable_without_mutating_source(): void
    {
        $body = '<h1>Advertising and Sponsored Listings</h1><h2>Disclosure requirements</h2>'
            .'<p>Sponsored content must be clearly identified.</p>';
        $publication = $this->publication('Mislabeled Old Terms', 'terms', $body, 'markdown');
        $html = BuilderSubmissionTerms::renderPublication($publication);

        $this->assertStringContainsString('<h1>Advertising and Sponsored Listings</h1>', $html);
        $this->assertStringContainsString('<h2>Disclosure requirements</h2>', $html);
        $this->assertStringNotContainsString('&lt;h1&gt;', $html);
        $this->assertSame($body, $publication->fresh()->body_snapshot);
        $this->assertSame('markdown', $publication->fresh()->body_format);
    }
}
