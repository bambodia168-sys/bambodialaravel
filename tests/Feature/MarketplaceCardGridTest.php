<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceCardGridTest extends TestCase
{
    use RefreshDatabase;

    private function creator(): User
    {
        return User::query()->create([
            'name'=>'Card Layout QA Creator',
            'email'=>'card-layout-qa@example.test',
            'role'=>'freelancer',
            'password'=>'OnlyForIsolatedTestEnvironment123',
        ]);
    }

    private function asset(User $owner): MediaAsset
    {
        return MediaAsset::query()->create([
            'user_id'=>$owner->id,'original_name'=>'original-layout-art.png',
            'storage_path'=>'test-layout-art.png',
            'mime_type'=>'image/png','bytes'=>68,'width'=>1,'height'=>1,
            'alt_text'=>'Original testing image',
            'rights_basis'=>'owned','rights_holder'=>$owner->name,
            'review_status'=>'approved','reviewed_at'=>now(),
        ]);
    }

    private function listing(User $user, string $type, int $index, ?int $license = null, ?int $image = null): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id'=>$user->id,
            'type'=>$type,
            'title'=>"Sample ".ucfirst($type)." Layout Card $index",
            'description'=>str_repeat('An original sample summary to evaluate two-line clamping and card proportions. ',3),
            'price'=>20+$index,
            'currency'=>'USD',
            'status'=>'published',
            ...($type==='product' ? [
                'license_definition_id'=>$license,
                'cover_media_id'=>$image,
            ] : []),
        ]);
    }

    public function test_homepage_balances_four_types_with_at_most_eight_cards_and_clickable_media_and_title(): void
    {
        Storage::fake('local');
        $user=$this->creator();
        $license=LicenseDefinition::query()->create([
            'code'=>'CARD_QA_REGULAR','name'=>'Card QA Regular License',
            'version'=>'1.0.0','permissions'=>'Only controlled QA use.',
            'restrictions'=>'No redistribution or sales.','status'=>'active',
            'created_by'=>$user->id,
        ]);
        $asset=$this->asset($user);
        $saved=[];
        foreach (MarketplaceListing::TYPES as $type) {
            foreach (range(1,3) as $i) {
                $saved[$type][$i]=$this->listing($user,$type,$i,$license->id,$asset->id);
            }
        }
        $response=$this->get('/')->assertOk()
            ->assertSee('marketplace-home-feed')
            ->assertDontSee('View details');
        $html=$response->getContent();
        $this->assertSame(8,substr_count($html,'<article class="marketplace-card">'));
        foreach (MarketplaceListing::TYPES as $type) {
            $this->assertStringContainsString($saved[$type][2]->title,$html);
            $this->assertStringContainsString($saved[$type][3]->title,$html);
            $this->assertStringNotContainsString($saved[$type][1]->title,$html);
        }
        $product=$saved['product'][3];
        $this->assertStringContainsString(
            'class="marketplace-card-art" href="'.$product->publicUrl().'"', $html
        );
        $this->assertStringContainsString(
            '<a href="'.$product->publicUrl().'" title="'.$product->title.'">', $html
        );
        $this->assertSame(8,substr_count($html,'class="marketplace-card-summary"'));
    }

    public function test_browse_page_paginates_eight_items_and_keeps_image_and_title_links(): void
    {
        $user=$this->creator();
        $service=$this->listing($user,'service',1);
        $asset=$this->asset($user);
        $service->update([
            'featured_kind'=>'image',
            'featured_media_id'=>$asset->id,
        ]);
        foreach (range(2,9) as $i) {
            $this->listing($user,'service',$i);
        }
        $response=$this->get('/search?type=service')->assertOk()
            ->assertSee('Sample Service Layout Card 9')
            ->assertDontSee('Sample Service Layout Card 1')
            ->assertDontSee('View details');
        $this->assertSame(8,substr_count($response->getContent(),'<article class="marketplace-card">'));
        $pageTwo=$this->get('/search?type=service&page=2')->assertOk()
            ->assertSee($service->title)
            ->assertSee('class="marketplace-card-summary"',false)
            ->assertSee('src="'.$asset->previewUrl().'"',false)
            ->assertSee('class="marketplace-card-art" href="'.$service->publicUrl().'"',false)
            ->assertSee('href="'.$service->publicUrl().'" title="'.$service->title.'"',false);
        $this->assertSame(1,substr_count($pageTwo->getContent(),'<article class="marketplace-card">'));
    }
}
