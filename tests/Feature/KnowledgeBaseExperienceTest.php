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
