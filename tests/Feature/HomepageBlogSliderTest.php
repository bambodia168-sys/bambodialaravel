<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageBlogSliderTest extends TestCase
{
    use RefreshDatabase;

    private function author(): User
    {
        return User::query()->create([
            'name'=>'Public Blog Editor',
            'email'=>'home-blog-editor@example.test',
            'password'=>'OnlyForIsolatedAcceptance123!',
            'role'=>'super_admin',
        ]);
    }

    private function entry(User $author,string $title,string $status='published',string $type='post',?int $hoursAgo=1): ContentEntry
    {
        return ContentEntry::query()->create([
            'author_id'=>$author->id,
            'type'=>$type,'title'=>$title,
            'body'=>str_repeat('Original editorial guidance for the Bambodia community. ',3),
            'excerpt'=>'Summary for '.$title,'category'=>'Technology',
            'status'=>$status,
            'published_at'=>$hoursAgo===null?null:($hoursAgo<0?now()->addHours(-$hoursAgo):now()->subHours($hoursAgo)),
        ]);
    }

    public function test_homepage_selects_ten_latest_published_posts_only(): void
    {
        $author=$this->author();
        $posts=[];
        foreach(range(1,12) as $number) {
            $posts[$number]=$this->entry($author,'Published slider article '.$number,'published','post',$number);
        }
        $this->entry($author,'Unpublished Blog Secret','draft');
        $this->entry($author,'Archived Blog Secret','archived');
        $this->entry($author,'Private Future Post','published','post',-5);
        $this->entry($author,'Unscheduled Post','published','post',null);
        $this->entry($author,'Published page not article','published','page',1);

        $response=$this->get('/')->assertOk()
            ->assertSee('id="bambodia-blog"',false)
            ->assertSee('id="home-blog-track"',false)
            ->assertSee('Latest from the Blog')
            ->assertSee('View all posts')
            ->assertSee('1–5 of 10')
            ->assertDontSee('Published slider article 11')
            ->assertDontSee('Published slider article 12')
            ->assertDontSee('Unpublished Blog Secret')
            ->assertDontSee('Archived Blog Secret')
            ->assertDontSee('Private Future Post')
            ->assertDontSee('Unscheduled Post')
            ->assertDontSee('Published page not article');

        $html=$response->getContent();
        $this->assertSame(10,substr_count($html,'class="blog-post-card home-blog-card"'));
        $this->assertSame(1,substr_count($html,'id="home-blog-track"'));
        $this->assertTrue(strpos($html,'id="bambodia-jobs"')<strpos($html,'id="bambodia-blog"'));
        $this->assertTrue(strpos($html,'id="bambodia-blog"')<strpos($html,'id="bambodia-advantage"'));
        $this->assertTrue(strpos($html,'id="bambodia-advantage"')<strpos($html,'id="bambodia-join"'));
        $response->assertViewHas('recentPosts',function($items) use($posts){
            return $items->count()===10 &&
                $items->first()->id===$posts[1]->id &&
                $items->last()->id===$posts[10]->id;
        });
        $response->assertSee(e($posts[1]->publicUrl()),false);
        $response->assertSee(e($posts[10]->publicUrl()),false);
    }

    public function test_blog_empty_state_and_small_feeds_do_not_invent_content(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Blog posts will appear here soon.')
            ->assertSee('Visit the Blog')
            ->assertDontSee('data-home-blog-track');

        $author=$this->author();
        $post=$this->entry($author,'The only published article');
        $response=$this->get('/')->assertOk()
            ->assertSee('The only published article')
            ->assertSee('1–1 of 1')
            ->assertSee('href="'.$post->publicUrl().'"',false)
            ->assertDontSee('Blog posts will appear here soon.');
        $this->assertSame(1,substr_count($response->getContent(),'class="blog-post-card home-blog-card"'));
    }
}
