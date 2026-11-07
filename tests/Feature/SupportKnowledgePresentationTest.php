<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportKnowledgePresentationTest extends TestCase
{
    use RefreshDatabase;

    private function publish(string $title, string $category, string $excerpt): KnowledgeArticle
    {
        $author = User::query()->firstOrCreate(
            ['email' => 'support-presentation@example.test'],
            ['name' => 'Support Presentation QA', 'password' => 'test-only-password', 'role' => 'super_admin']
        );

        return KnowledgeArticle::query()->create([
            'title' => $title,
            'excerpt' => $excerpt,
            'body' => str_repeat('This private fixture explains the support workflow. ', 3),
            'body_format' => 'markdown',
            'category' => $category,
            'status' => 'published',
            'published_at' => now(),
            'author_id' => $author->id,
            'reviewed_by' => $author->id,
        ]);
    }

    public function test_public_support_hero_uses_one_clean_transparent_generated_portrait(): void
    {
        $response = $this->get(route('support'));
        $response->assertOk()
            ->assertSee('images/support-agent-hero.avif', false)
            ->assertDontSee('images/support-agent-photo.webp', false)
            ->assertDontSee('images/support-agent-photo-dark.webp', false)
            ->assertDontSee('images/support-agent-hero.svg', false);
        $image = public_path('images/support-agent-hero.avif');
        $this->assertFileExists($image);
        $this->assertSame('01665887c851ed475f52ac6bdd9274f6bb73e7e34a65e79f867517e260c6b82d',
            hash_file('sha256', $image));
        $info = getimagesize($image);
        $this->assertIsArray($info);
        $this->assertSame(480, $info[0]);
        $this->assertSame(501, $info[1]);
        $this->assertSame('image/avif', $info['mime']);
    }

    public function test_category_cards_have_eight_distinct_svg_icons_and_blended_numbers(): void
    {
        $html = $this->get(route('support'))->assertOk()->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " support-category-card ")]');

        $this->assertCount(8, $cards);
        $icons = [];
        foreach ($cards as $i => $card) {
            $number = $xpath->query('.//span[contains(@class, "support-category-number")]', $card)->item(0);
            $icon = $xpath->query('.//*[local-name()="svg"]', $card)->item(0);
            $this->assertNotNull($number);
            $this->assertSame(sprintf('%02d', $i + 1), trim($number->textContent));
            $this->assertNotNull($icon);
            $icons[] = $dom->saveXML($icon);
        }
        $this->assertCount(8, array_unique($icons));
    }

    public function test_public_help_cards_display_excerpt_and_background_icon_number(): void
    {
        $article = $this->publish(
            'Find your Bambodia product downloads',
            'downloads',
            'A short summary should appear underneath the article title on responsive cards.'
        );

        $response = $this->get(route('support'));
        $response->assertOk()
            ->assertSee($article->title)
            ->assertSee('class="support-article-card-glyph"', false)
            ->assertSee('class="support-article-card-number"', false)
            ->assertSee('class="support-article-excerpt"', false)
            ->assertSee($article->excerpt)
            ->assertSee('class="support-contact-cta"', false)
            ->assertSee('Still need help?');
    }

    public function test_single_article_hides_summary_and_groups_ticket_button_with_share_actions(): void
    {
        $article = $this->publish(
            'Secure your Bambodia account access',
            'accounts',
            'HIDDEN ARTICLE SUMMARY MUST NOT APPEAR ON THE PUBLIC DETAIL PAGE.'
        );

        $guest = $this->get($article->publicUrl());
        $guest->assertOk()
            ->assertDontSee($article->excerpt)
            ->assertSee('class="support-article-action-row"', false)
            ->assertSee('Sign in to contact support')
            ->assertSee('Copy link')
            ->assertSee('Print')
            ->assertSee('Share');

        $authenticated = $this->actingAs(User::query()->firstOrFail())
            ->get($article->publicUrl())->assertOk();
        $html = $authenticated->getContent();
        $start = strpos($html, 'class="support-article-action-row"');
        $ticket = strpos($html, 'Create support ticket', $start);
        $copy = strpos($html, 'Copy link', $start);
        $share = strpos($html, 'Share', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($ticket);
        $this->assertNotFalse($copy);
        $this->assertLessThan($copy, $ticket);
        $this->assertLessThan($share, $copy);
    }
    public function test_sidebar_lists_published_guides_across_categories_without_exposing_drafts_or_current_article(): void
    {
        $current = $this->publish(
            'How to create a Bambodia account',
            'getting-started',
            'Registration, account security, and dashboard basics.'
        );
        $different = $this->publish(
            'Understanding Regular and Extended Licenses',
            'licenses',
            'Learn what each product license permits.'
        );
        $author = User::query()->firstOrFail();
        $draft = KnowledgeArticle::query()->create([
            'title' => 'Unreviewed internal account procedure',
            'excerpt' => 'An internal workflow that must remain private until reviewed.',
            'body' => str_repeat('Not yet approved for public publication. ', 3),
            'body_format' => 'markdown',
            'category' => 'getting-started',
            'status' => 'draft',
            'author_id' => $author->id,
        ]);
        $archived = KnowledgeArticle::query()->create([
            'title' => 'Retired staff administration guide',
            'excerpt' => 'An archived workflow that should never appear as a guide.',
            'body' => str_repeat('This guide is no longer public. ', 3),
            'body_format' => 'markdown',
            'category' => 'licenses',
            'status' => 'archived',
            'author_id' => $author->id,
        ]);

        $html = $this->get($current->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Other guides', $html);
        $this->assertStringContainsString($different->title, $html);
        $this->assertStringContainsString($different->publicUrl(), $html);
        $this->assertStringNotContainsString($draft->title, $html);
        $this->assertStringNotContainsString($archived->title, $html);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $sidebar = $xpath->query('//aside[contains(@class, "support-article-sidebar")]')->item(0);
        $this->assertNotNull($sidebar);
        $this->assertCount(1, $xpath->query('.//a[contains(@class,"support-related-link")]', $sidebar));
        $numbers = $xpath->query('.//a[contains(@class,"support-related-link")]/span[contains(@class,"support-related-number")]', $sidebar);
        $this->assertCount(1, $numbers);
        $this->assertSame('01', trim($numbers->item(0)->textContent));
        $this->assertCount(0, $xpath->query('.//a[contains(@class,"support-related-link")]//*[local-name()="svg"]', $sidebar));
        $this->assertStringNotContainsString($current->title, $dom->saveHTML($sidebar));
    }

    public function test_sidebar_numbers_continue_across_related_and_other_guide_groups(): void
    {
        $current = $this->publish('Account help navigation', 'accounts', 'Information about user account support.');
        $this->publish('Account security guide', 'accounts', 'A guide in the same category as the current one.');
        $this->publish('Licensed product downloads', 'downloads', 'A guide in a different category.');
        $this->publish('Regular and Extended license types', 'licenses', 'A second guide from another category.');

        $html = $this->get($current->publicUrl())->assertOk()->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $sidebar = $xpath->query('//aside[contains(@class,"support-article-sidebar")]')->item(0);
        $this->assertNotNull($sidebar);

        $numbers = $xpath->query('.//a[contains(@class,"support-related-link")]/span[contains(@class,"support-related-number")]', $sidebar);
        $this->assertCount(3, $numbers);
        $this->assertSame(['01', '02', '03'], array_map(
            static fn ($node) => trim($node->textContent),
            iterator_to_array($numbers)
        ));
        $this->assertCount(0, $xpath->query('.//a[contains(@class,"support-related-link")]//*[local-name()="svg"]', $sidebar));
    }

}
