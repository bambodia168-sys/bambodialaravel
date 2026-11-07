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

    public function test_public_help_cards_are_vertical_without_excerpt_and_private_assistance_remains(): void
    {
        $article = $this->publish(
            'Find your Bambodia product downloads',
            'downloads',
            'HIDDEN PRESENTATION SUMMARY MUST NOT APPEAR IN PUBLIC CARDS.'
        );

        $response = $this->get(route('support'));
        $response->assertOk()
            ->assertSee($article->title)
            ->assertSee('class="support-article-card-glyph"', false)
            ->assertSee('class="support-contact-cta"', false)
            ->assertSee('Still need help?')
            ->assertDontSee($article->excerpt);
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
}
