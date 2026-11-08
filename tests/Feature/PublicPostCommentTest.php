<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPostCommentTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Bamboo Reader',
            'email' => uniqid('reader-', true).'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
        ]);
    }

    private function createPublishedPost(User $author, string $status = 'published'): ContentEntry
    {
        return ContentEntry::query()->create([
            'type' => 'post',
            'author_id' => $author->id,
            'title' => 'An article with a useful discussion',
            'body' => '<p>A secure article with readable and worthwhile content.</p>',
            'body_format' => 'html',
            'category' => 'Design',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    public function test_article_has_edge_to_edge_media_before_title_byline_and_share(): void
    {
        Storage::fake('local');
        $author = $this->member('admin');
        $asset = MediaAsset::query()->create([
            'user_id' => $author->id,
            'original_name' => 'landscape.png',
            'storage_path' => 'marketplace/previews/'.$author->id.'/landscape.png',
            'mime_type' => 'image/png',
            'bytes' => 18,
            'width' => 920,
            'height' => 520,
            'alt_text' => 'Responsive featured image',
            'rights_basis' => 'owned',
            'rights_holder' => 'Bamboo Reader',
            'review_status' => 'pending',
        ]);
        Storage::disk('local')->put($asset->storage_path, 'image-content');
        $post = $this->createPublishedPost($author);
        $post->update(['featured_type' => 'image', 'featured_media_id' => $asset->id]);

        $response = $this->get($post->publicUrl())->assertOk()
            ->assertSee('blog-article-cover', false)
            ->assertSee('class="post-public-image"', false)
            ->assertSee($asset->previewUrl())
            ->assertSee('class="blog-author-avatar"', false)
            ->assertSee('Bamboo Reader')
            ->assertSee('Published '.$post->published_at->format('d M Y'))
            ->assertSee('Design')
            ->assertSee($post->public_id)
            ->assertSee('data-article-share', false)
            ->assertSee('Share article')
            ->assertSee('Comments')
            ->assertDontSee('All posts');

        $html = $response->getContent();
        $media = strpos($html, 'class="post-featured-figure blog-article-cover"');
        $title = strpos($html, '<h1>An article with a useful discussion</h1>');
        $byline = strpos($html, 'class="blog-article-byline"');
        $article = strpos($html, '<div class="editorial-copy policy-formatted-content">');
        $this->assertNotFalse($media);
        $this->assertNotFalse($title);
        $this->assertNotFalse($byline);
        $this->assertNotFalse($article);
        $this->assertTrue($media < $title && $title < $byline && $byline < $article);
    }

    public function test_signed_in_user_can_comment_but_guests_cannot_post(): void
    {
        $author = $this->member('admin');
        $post = $this->createPublishedPost($author);
        $uri = route('blog.comments.store', ['content' => $post->public_id.'_'.$post->slug]);

        $this->get($post->publicUrl())->assertOk()
            ->assertSee('Sign in to comment')
            ->assertSee('No comments yet');
        $this->post($uri, ['body' => 'I learned something today.'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('post_comments', 0);

        $reader = $this->member();
        $this->actingAs($reader)->post($uri, [
            'body' => '  A helpful article with practical guidance.  ',
        ])->assertRedirect($post->publicUrl().'#comments');

        $this->assertDatabaseHas('post_comments', [
            'content_entry_id' => $post->id,
            'user_id' => $reader->id,
            'body' => 'A helpful article with practical guidance.',
        ]);
        $this->get($post->publicUrl())->assertOk()
            ->assertSee('A helpful article with practical guidance.')
            ->assertSee('Comments')
            ->assertSee('Delete comment');
    }

    public function test_rejects_invalid_comments_and_escapes_user_supplied_html(): void
    {
        $reader = $this->member();
        $post = $this->createPublishedPost($reader);
        $url = route('blog.comments.store', ['content' => $post->public_id.'_'.$post->slug]);
        $this->actingAs($reader);

        $this->post($url, ['body' => '   '])->assertSessionHasErrors('body');
        $this->post($url, ['body' => str_repeat('a', 1501)])->assertSessionHasErrors('body');
        $this->assertDatabaseCount('post_comments', 0);

        $this->post($url, ['body' => '<script>alert(1)</script>'])
            ->assertRedirect($post->publicUrl().'#comments');
        $this->get($post->publicUrl())->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_only_comment_author_or_admin_can_delete_and_unpublished_posts_reject_comments(): void
    {
        $author = $this->member();
        $other = $this->member();
        $admin = $this->member('admin');
        $post = $this->createPublishedPost($author);
        $comment = PostComment::query()->create([
            'content_entry_id' => $post->id,
            'user_id' => $author->id,
            'body' => 'Discussion of the article.',
        ]);

        $this->actingAs($other)->delete(route('blog.comments.destroy', $comment))->assertForbidden();
        $this->assertDatabaseHas('post_comments', ['id' => $comment->id]);

        $this->actingAs($author)->delete(route('blog.comments.destroy', $comment))
            ->assertRedirect($post->publicUrl().'#comments');
        $this->assertDatabaseMissing('post_comments', ['id' => $comment->id]);

        $second = PostComment::query()->create([
            'content_entry_id' => $post->id,
            'user_id' => $author->id,
            'body' => 'Another comment to moderate.',
        ]);
        $this->actingAs($admin)->delete(route('blog.comments.destroy', $second))
            ->assertRedirect($post->publicUrl().'#comments');
        $this->assertDatabaseCount('post_comments', 0);

        $draft = $this->createPublishedPost($author, 'draft');
        $this->actingAs($other)->post(route('blog.comments.store', [
            'content' => $draft->public_id.'_'.$draft->slug,
        ]), ['body' => 'Not a public article.'])->assertNotFound();

        $page = ContentEntry::query()->create([
            'type' => 'page',
            'author_id' => $author->id,
            'title' => 'About the company',
            'body' => 'A permanent public information page.',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $this->post(route('blog.comments.store', [
            'content' => $page->public_id.'_'.$page->slug,
        ]), ['body' => 'Not a post.'])->assertNotFound();
        $this->get($page->publicUrl())->assertOk()->assertDontSee('blog-comments', false);
    }

    public function test_comments_are_paginated_and_existing_comments_disappear_after_unpublishing(): void
    {
        $reader = $this->member();
        $post = $this->createPublishedPost($reader);
        foreach (range(1, 9) as $number) {
            PostComment::query()->create([
                'content_entry_id' => $post->id,
                'user_id' => $reader->id,
                'body' => 'Comment '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->get($post->publicUrl())->assertOk()
            ->assertSee('Comment 09')
            ->assertDontSee('Comment 01')
            ->assertSee('1 / 2')
            ->assertSee('comments_page=2');

        $this->get($post->publicUrl().'?comments_page=2')->assertOk()
            ->assertSee('Comment 01')
            ->assertDontSee('Comment 09')
            ->assertSee('2 / 2');

        $post->update(['status' => 'archived', 'published_at' => null]);
        $this->get($post->publicUrl())->assertNotFound();
        $this->actingAs($reader)->post(route('blog.comments.store', [
            'content' => $post->public_id.'_'.$post->slug,
        ]), ['body' => 'Cannot comment after archive.'])->assertNotFound();
    }
}
