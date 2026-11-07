<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBuilderLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_reuses_styled_sidebar_panels_and_highlights_knowledge_navigation(): void
    {
        $admin = User::query()->create([
            'name' => 'Editor Layout QA',
            'email' => 'knowledge-layout@example.test',
            'password' => 'layout-qa-only',
            'role' => 'super_admin',
        ]);

        $article = KnowledgeArticle::query()->create([
            'title' => 'Knowledge Base layout acceptance article',
            'excerpt' => 'A private layout acceptance article for administrator testing.',
            'body' => str_repeat('This editorial preview remains unpublished. ', 3),
            'category' => 'getting-started',
            'status' => 'draft',
            'author_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.knowledge.edit', $article));

        $response->assertOk()
            ->assertSee('class="policy-builder support-knowledge-builder"', false)
            ->assertSee('class="policy-title-input-wrap support-knowledge-title-input"', false)
            ->assertSee('class="support-knowledge-excerpt"', false)
            ->assertSee('class="policy-review-check"', false)
            ->assertSee('aria-label="Knowledge article settings"', false)
            ->assertSee('class="workspace-nav-link active"', false)
            ->assertSee('name="confirm_review"', false);

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'class="panel policy-side-panel"'));
        $this->assertSame(2, substr_count($html, 'class="policy-side-head"'));
    }

    public function test_new_article_uses_same_layout_but_cannot_confirm_a_review_before_saving(): void
    {
        $admin = User::query()->create([
            'name' => 'Editor Creation QA',
            'email' => 'knowledge-create-layout@example.test',
            'password' => 'layout-qa-only',
            'role' => 'admin',
        ]);
        $response = $this->actingAs($admin)->get(route('dashboard.knowledge.create'));
        $response->assertOk()
            ->assertSee('class="policy-title-input-wrap support-knowledge-title-input"', false)
            ->assertSee('class="panel policy-side-panel"', false)
            ->assertDontSee('name="confirm_review"', false);
    }
}
