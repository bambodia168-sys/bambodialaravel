<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\BuilderFeaturedMedia;
use App\Support\BuilderSubmissionTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedMediaAndRelatedExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name'=>'Media QA '.$role.' '.uniqid(),
            'email'=>uniqid('media-',true).'@example.test',
            'password'=>'IndependentMediaTestPassword123!',
            'role'=>$role,
        ]);
    }

    private function listing(User $user, string $type, string $title): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id'=>$user->id,
            'type'=>$type,'title'=>$title,
            'description'=>str_repeat('An original approved example of a professional public listing. ',3),
            'status'=>'published','price'=>'88.00','currency'=>'USD',
        ]);
    }

    private function job(User $client, string $title): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id'=>$client->id,'title'=>$title,
            'description'=>str_repeat('An independent and professionally written job specification. ',3),
            'category'=>'development','budget'=>'320.00','currency'=>'USD','status'=>'published',
        ]);
    }

    private function asset(User $user, string $mime): MediaAsset
    {
        return MediaAsset::query()->create([
            'user_id'=>$user->id,'original_name'=>'featured-original',
            'storage_path'=>'test/'.$mime.'/original',
            'mime_type'=>$mime,'bytes'=>240,'alt_text'=>'Original featured media',
            'rights_basis'=>'owned','rights_holder'=>$user->name,
            'review_status'=>'approved','reviewed_at'=>now(),
        ]);
    }

    public function test_youtube_input_only_allows_https_canonical_youtube_video_sources(): void
    {
        $id='dQw4w9WgXcQ';
        foreach([
            "https://youtu.be/$id",
            "https://www.youtube.com/watch?v=$id",
            "https://m.youtube.com/watch?v=$id&t=2",
            "https://www.youtube.com/shorts/$id",
            "https://youtube.com/live/$id",
            "https://www.youtube-nocookie.com/embed/$id",
        ] as $url) $this->assertSame($id,BuilderFeaturedMedia::youtubeId($url),$url);

        foreach([
            "http://www.youtube.com/watch?v=$id",
            "https://www.youtube.com.evil.example/watch?v=$id",
            "https://www.youtube.com:444/watch?v=$id",
            "https://user:password@youtube.com/watch?v=$id",
            "https://youtu.be/$id#fragment",
            'javascript:alert(1)',
            'https://youtu.be/too-short',
            'https://example.com/embed/'.$id,
        ] as $url) $this->assertNull(BuilderFeaturedMedia::youtubeId($url),$url);
    }

    public function test_builder_stores_safe_youtube_id_without_external_url_and_can_switch_back_to_color(): void
    {
        $creator=$this->member('freelancer');
        $accepted=[
            'builder_mode'=>'1','accept_terms'=>'1',
            'terms_sha256'=>BuilderSubmissionTerms::digest(),
        ];
        $base=[
            ...$accepted,'type'=>'project',
            'title'=>'Original travel website showcase with media',
            'description'=>str_repeat('A professionally prepared project description with custom deliverables. ',3),
        ];
        $this->actingAs($creator)->get('/builder?type=project')->assertOk()
            ->assertSee('value="youtube"',false)
            ->assertSee('name="featured_youtube_url"',false);

        $this->post('/studio/listings',[
            ...$base,'featured_kind'=>'youtube',
            'featured_youtube_url'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertRedirect();

        $listing=MarketplaceListing::query()->where('type','project')->firstOrFail();
        $this->assertSame('youtube',$listing->featured_kind);
        $this->assertSame('dQw4w9WgXcQ',$listing->featured_youtube_id);
        $this->assertNull($listing->featured_media_id);
        $this->assertNull($listing->featured_color);
        $this->get('/builder?='.$listing->public_id)->assertOk()
            ->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->post('/studio/listings',[
            ...$base,'title'=>'Unsafe external iframe origin example',
            'featured_kind'=>'youtube',
            'featured_youtube_url'=>'https://youtube.com.evil.example/embed/dQw4w9WgXcQ',
        ])->assertSessionHasErrors('featured_youtube_url');
        $this->assertDatabaseCount('marketplace_listings',1);

        $listing->update([
            'status'=>'rejected',
        ]);
        $this->patch(route('studio.listings.update',$listing),[
            ...$base,'title'=>'Original travel website showcase with media',
            'featured_kind'=>'color','featured_color'=>'#13579b',
        ])->assertRedirect();
        $this->assertSame('color',$listing->fresh()->featured_kind);
        $this->assertSame('#13579b',$listing->fresh()->featured_color);
        $this->assertNull($listing->fresh()->featured_youtube_id);
    }

    public function test_featured_media_types_share_one_frame_for_every_public_detail_kind(): void
    {
        $creator=$this->member('freelancer');
        $client=$this->member('customer');
        $cover=$this->asset($creator,'image/png');
        $video=$this->asset($creator,'video/mp4');
        $license=LicenseDefinition::query()->create([
            'code'=>'MEDIA_QA_'.uniqid(),'name'=>'Regular Media QA License','version'=>'1.0.0',
            'permissions'=>'Original controlled image preview and license terms',
            'restrictions'=>'No redistribution','status'=>'active','created_by'=>$creator->id,
        ]);
        $items=[];
        foreach(MarketplaceListing::TYPES as $kind) {
            $listing=$this->listing($creator,$kind,'Original shared media frame '.$kind);
            if($kind==='product'){
                $listing->update(['license_definition_id'=>$license->id,'cover_media_id'=>$cover->id]);
            }
            $items[]=$listing;
        }
        $items[]=$this->job($client,'Original shared media frame job');
        foreach($items as $item) {
            $item->update(['featured_kind'=>'youtube','featured_youtube_id'=>'dQw4w9WgXcQ']);
            $this->get($item->publicUrl())->assertOk()
                ->assertSee('premium-detail-media',false)
                ->assertSee('premium-detail-youtube',false)
                ->assertSee('data-youtube-id="dQw4w9WgXcQ"',false)
                ->assertSee('Watch featured video')
                ->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ',false)
                ->assertDontSee('youtube.com.evil',false);
            $item->update(['featured_kind'=>'color','featured_color'=>'#13579b','featured_youtube_id'=>null]);
            $this->get($item->publicUrl())->assertOk()
                ->assertSee('premium-detail-media-content',false)
                ->assertSee('background-color:#13579b',false)
                ->assertDontSee('premium-detail-youtube',false);
            $item->update(['featured_kind'=>'video','featured_media_id'=>$video->id,'featured_color'=>null]);
            $this->get($item->publicUrl())->assertOk()
                ->assertSee('<video',false)->assertSee('controls',false)
                ->assertSee('playsinline',false);
            $item->update(['featured_kind'=>'image','featured_media_id'=>$cover->id]);
            $this->get($item->publicUrl())->assertOk()
                ->assertSee('premium-showcase-cover',false)
                ->assertSee('Original featured media');
        }
        $css=file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('aspect-ratio:16 / 9',$css);
        $this->assertStringContainsString('bambodia-media-shadow',$css);
        $this->assertStringContainsString('prefers-reduced-motion:reduce',$css);
    }

    public function test_related_listings_show_eight_approved_maximum_with_no_duplicates(): void
    {
        $creator=$this->member('freelancer');
        $selected=$this->listing($creator,'project','Original selected project');
        foreach(range(1,10) as $index){
            $this->listing($creator,'project','Related approved project '.sprintf('%02d',$index));
        }
        $draft=$this->listing($creator,'project','Unpublished hidden project');
        $draft->update(['status'=>'draft']);
        $this->listing($creator,'service','Wrong type excluded');
        $html=$this->get($selected->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('premium-related-grid',$html);
        $this->assertSame(8,substr_count($html,'class="marketplace-card"'));
        foreach(range(3,10) as $index)
            $this->assertStringContainsString('Related approved project '.sprintf('%02d',$index),$html);
        foreach(['Related approved project 01','Related approved project 02','Unpublished hidden project','Wrong type excluded'] as $title)
            $this->assertStringNotContainsString($title,$html);
    }

    public function test_related_jobs_show_eight_published_in_matching_category_only(): void
    {
        $client=$this->member('customer');
        $selected=$this->job($client,'Main approved job');
        foreach(range(1,10) as $index) {
            $this->job($client,'Matching approved job '.sprintf('%02d',$index));
        }
        $draft=$this->job($client,'Hidden draft job');
        $draft->update(['status'=>'pending']);
        $other=$this->job($client,'Other category job');
        $other->update(['category'=>'writing']);
        $html=$this->get($selected->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Related approved jobs',$html);
        $this->assertSame(8,substr_count($html,'premium-related-job-card'));
        foreach(range(3,10) as $index)
            $this->assertStringContainsString('Matching approved job '.sprintf('%02d',$index),$html);
        $this->assertStringNotContainsString('Hidden draft job',$html);
        $this->assertStringNotContainsString('Other category job',$html);
    }
}
