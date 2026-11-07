<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\Policy;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\SupportKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'customer'): User
    {
        return User::query()->create([
            'name'=>'Knowledge QA', 'email'=>uniqid('kb',true).'@example.test',
            'password'=>'Password123!', 'role'=>$role,
        ]);
    }

    private function article(User $admin, string $status = 'draft', string $title = 'Securely use Bambodia downloads'): KnowledgeArticle
    {
        return KnowledgeArticle::query()->create([
            'title'=>$title, 'excerpt'=>'Understand account downloads and safe software delivery.',
            'body'=>'# Safe downloads'."\n\n".str_repeat('Read the installation instructions and keep a backup. ', 3),
            'body_format'=>'markdown', 'category'=>'downloads', 'status'=>$status,
            'author_id'=>$admin->id, 'published_at'=>$status === 'published' ? now() : null,
        ]);
    }

    public function test_public_support_home_is_available_without_login_but_tickets_require_login(): void
    {
        $this->get(route('support'))->assertOk()
            ->assertSee('How can we help?')
            ->assertSee('Help categories')
            ->assertSee('Sign in to create a ticket');
        $this->get('/support-center/tickets')->assertRedirect(route('login'));
        $this->post('/support-center', [
            'subject'=>'Help finding my license', 'message'=>'Can somebody help locate this record?',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_private_support_conversations_stay_accessible_only_to_owner_or_staff(): void
    {
        $owner=$this->member();
        $other=$this->member();
        $this->actingAs($owner)->post('/support-center', [
            'subject'=>'My private download issue',
            'message'=>'I have a specific issue with my authorized download.',
        ])->assertRedirect();
        $ticket=SupportTicket::query()->firstOrFail();
        $this->actingAs($owner)->get(route('support.tickets'))->assertOk()->assertSee($ticket->public_id);
        $this->actingAs($other)->get(route('support.show',$ticket->public_id))->assertNotFound();
        $this->actingAs($owner)->get(route('support.show',$ticket->public_id))->assertOk();
    }

    public function test_starter_knowledge_articles_are_unique_and_created_only_as_drafts(): void
    {
        $admin=$this->member('super_admin');
        $this->assertCount(9, SupportKnowledge::starterArticles());
        $this->actingAs($admin)->post(route('dashboard.knowledge.seed'))->assertRedirect();
        $this->assertDatabaseCount('knowledge_articles',9);
        $this->assertSame(0,KnowledgeArticle::query()->where('status','published')->count());
        $first=KnowledgeArticle::query()->firstOrFail();
        $first->update(['excerpt'=>'Editorial changes must be preserved during reseeding.']);
        $this->post(route('dashboard.knowledge.seed'))->assertRedirect();
        $this->assertDatabaseCount('knowledge_articles',9);
        $this->assertSame('Editorial changes must be preserved during reseeding.', $first->fresh()->excerpt);
        $this->get(route('support'))->assertDontSee('How to create a Bambodia account');
    }

    public function test_additional_help_templates_are_unique_private_drafts_and_preserve_existing_published_content(): void
    {
        $admin = $this->member('super_admin');
        $customer = $this->member();
        $first = $this->article($admin, 'published', 'An existing reviewed marketplace help article');
        $first->update(['excerpt' => 'An approved public summary that must never be rewritten.']);
        $this->assertCount(24, SupportKnowledge::additionalArticles());
        $this->assertCount(24, collect(SupportKnowledge::additionalArticles())->pluck('title')->unique());

        $this->actingAs($customer)
            ->post(route('dashboard.knowledge.additional-drafts'))
            ->assertForbidden();
        $this->assertDatabaseCount('knowledge_articles', 1);

        $this->actingAs($admin)
            ->post(route('dashboard.knowledge.additional-drafts'))
            ->assertRedirect(route('dashboard.knowledge.index', ['status'=>'draft']));
        $this->assertDatabaseCount('knowledge_articles', 25);
        $this->assertSame(24, KnowledgeArticle::query()->where('status', 'draft')->count());

        $this->post(route('dashboard.knowledge.additional-drafts'))->assertRedirect();
        $this->assertDatabaseCount('knowledge_articles', 25);
        $this->assertSame('published', $first->fresh()->status);
        $this->assertSame('An approved public summary that must never be rewritten.', $first->fresh()->excerpt);

        $new = KnowledgeArticle::query()->where('title', 'Resetting your account password securely')->firstOrFail();
        $this->assertSame('accounts', $new->category);
        $this->assertSame('draft', $new->status);
        $this->get($new->publicUrl())->assertNotFound();
        $this->get(route('support'))->assertDontSee($new->title)->assertSee($first->title);

        $this->assertDatabaseHas('audit_events', [
            'event'=>'knowledge.additional_drafts_created', 'actor_id'=>$admin->id,
        ]);
    }

    public function test_supplemental_templates_have_reviewable_content_across_all_eight_categories(): void
    {
        $templates = SupportKnowledge::additionalArticles();
        $this->assertCount(8, collect($templates)->pluck('category')->unique());
        foreach ($templates as $template) {
            $this->assertArrayHasKey($template['category'], SupportKnowledge::CATEGORIES);
            $this->assertGreaterThanOrEqual(25, mb_strlen($template['excerpt']));
            $this->assertLessThanOrEqual(320, mb_strlen($template['excerpt']));
            $this->assertGreaterThan(250, mb_strlen($template['body']));
            $this->assertSame('markdown', $template['body_format']);
        }
    }

    public function test_admin_review_is_required_and_public_search_only_shows_published_articles(): void
    {
        $admin=$this->member('admin');
        $article=$this->article($admin);
        $this->get($article->publicUrl())->assertNotFound();

        $url=route('dashboard.knowledge.update',$article);
        $payload=[
            'title'=>$article->title, 'excerpt'=>$article->excerpt,
            'body'=>$article->body, 'body_format'=>'markdown',
            'category'=>'downloads', 'status'=>'published',
        ];
        $this->actingAs($admin)->patch($url,$payload)->assertSessionHasErrors('confirm_review');
        $this->assertSame('draft',$article->fresh()->status);
        $this->patch($url,[...$payload,'confirm_review'=>'1'])->assertRedirect();
        $article->refresh();
        $this->assertSame('published',$article->status);
        $this->assertNotNull($article->published_at);
        $this->get(route('support'))->assertOk()->assertSee($article->title);
        $this->get(route('support',['q'=>'downloads']))->assertSee($article->title);
        $this->get(route('support',['q'=>'unrelated-specific-query']))->assertDontSee($article->title);
        $this->get(route('support',['category'=>'licenses']))->assertDontSee($article->title);
        $this->get(route('support',['category'=>'downloads']))->assertSee($article->title);
        $this->get($article->publicUrl())->assertOk()->assertSee('Safe downloads');
        $this->get('/support-center/articles/'.$article->public_code.'-old-name')
            ->assertRedirect($article->publicUrl());
        $this->assertDatabaseHas('audit_events',['event'=>'knowledge.article.published','actor_id'=>$admin->id]);
    }

    public function test_public_knowledge_base_paginates_ten_articles_and_preserves_filters(): void
    {
        $admin = $this->member('super_admin');

        for ($i = 1; $i <= 26; $i++) {
            $article = $this->article(
                $admin, 'published', sprintf('Pagination sample guide %02d', $i)
            );
            $article->update([
                'category' => $i <= 13 ? 'downloads' : 'licenses',
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $this->article($admin, 'draft', 'Pagination sample unpublished draft');
        $this->article($admin, 'archived', 'Pagination sample archived article');

        foreach ([1 => [10, '01', '10'], 2 => [10, '11', '20'], 3 => [6, '21', '26']] as $page => [$expectedCount, $firstNumber, $lastNumber]) {
            $response = $this->get(route('support', ['page' => $page]))->assertOk();
            $paginator = $response->viewData('articles');

            $this->assertSame(26, $paginator->total());
            $this->assertSame(10, $paginator->perPage());
            $this->assertSame(3, $paginator->lastPage());
            $this->assertSame($page, $paginator->currentPage());
            $this->assertCount($expectedCount, $paginator->items());

            $html = $response->getContent();
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $cards = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " support-article-card ")]');
            $numbers = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " support-article-card ")]/span[contains(@class, "support-article-card-number")]');

            $this->assertCount($expectedCount, $cards);
            $this->assertCount($expectedCount, $numbers);
            $this->assertSame($firstNumber, trim($numbers->item(0)->textContent));
            $this->assertSame($lastNumber, trim($numbers->item($expectedCount - 1)->textContent));
            $this->assertStringNotContainsString('Pagination sample unpublished draft', $html);
            $this->assertStringNotContainsString('Pagination sample archived article', $html);
        }

        $filtered = $this->get(route('support', [
            'q' => 'Pagination sample', 'category' => 'downloads', 'page' => 2,
        ]))->assertOk();
        $articles = $filtered->viewData('articles');

        $this->assertSame(13, $articles->total());
        $this->assertSame(10, $articles->perPage());
        $this->assertSame(2, $articles->currentPage());
        $this->assertCount(3, $articles->items());
        $this->assertTrue(collect($articles->items())->every(fn ($article) => $article->category === 'downloads'));

        $previous = $articles->previousPageUrl();
        $this->assertNotNull($previous);
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $params);
        $this->assertSame('Pagination sample', $params['q'] ?? null);
        $this->assertSame('downloads', $params['category'] ?? null);
        $this->assertSame('1', (string) ($params['page'] ?? null));
        $filtered->assertSee('Showing 11', false)->assertSee('of 13');
    }

    public function test_untrusted_html_cannot_inject_scripts_into_public_article(): void
    {
        $admin=$this->member('admin');
        $article=$this->article($admin);
        $this->actingAs($admin)->patch(route('dashboard.knowledge.update',$article),[
            'title'=>$article->title, 'excerpt'=>$article->excerpt,
            'category'=>'downloads', 'status'=>'published', 'confirm_review'=>'1',
            'body_format'=>'html',
            'body'=>'<h2>Safe downloads</h2><p>'.str_repeat('This guide explains how to verify a download. ', 3).
                '</p><script>KB_EVIL_PAYLOAD()</script>',
        ])->assertRedirect();
        $html=$this->get($article->fresh()->publicUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('KB_EVIL_PAYLOAD()', $html);
    }

    public function test_public_cannot_edit_or_preview_drafts_and_staff_cannot_publish(): void
    {
        $admin=$this->member('admin');
        $staff=$this->member('staff');
        $customer=$this->member();
        $article=$this->article($admin);
        $edit=route('dashboard.knowledge.edit',$article);
        $this->get($edit)->assertRedirect(route('login'));
        $this->actingAs($customer)->get($edit)->assertForbidden();
        $this->actingAs($staff)->get($edit)->assertForbidden();
        $this->actingAs($staff)->get(route('dashboard.knowledge.preview',$article))->assertForbidden();
        $this->actingAs($customer)->post(route('dashboard.knowledge.seed'))->assertForbidden();
        $this->actingAs($admin)->get(route('dashboard.knowledge.preview',$article))->assertOk();
        $this->get(route('dashboard.knowledge.index'))->assertOk();
        $this->assertDatabaseHas('knowledge_articles',['id'=>$article->id,'status'=>'draft']);
    }

    public function test_archiving_unpublishes_article_without_deleting_its_id(): void
    {
        $admin=$this->member('super_admin');
        $article=$this->article($admin,'published');
        $url=$article->publicUrl();
        $this->get($url)->assertOk();
        $this->actingAs($admin)->patch(route('dashboard.knowledge.update',$article),[
            'title'=>$article->title,'excerpt'=>$article->excerpt,
            'category'=>$article->category,'body'=>$article->body,
            'body_format'=>'markdown','status'=>'archived',
        ])->assertRedirect();
        $this->assertNull($article->fresh()->published_at);
        $this->get($url)->assertNotFound();
        $this->assertSame(8,strlen($article->fresh()->public_code));
    }

    public function test_supplemental_policies_remain_drafts_even_after_support_article_publication(): void
    {
        $admin=$this->member('super_admin');
        $this->actingAs($admin)->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect();
        $this->assertSame(7,Policy::query()->where('status','draft')->count());
        $this->assertSame(0,Policy::query()->where('status','published')->count());
    }
}
