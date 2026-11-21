<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\BuilderSubmissionTerms;
use App\Support\MarketplaceCardPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceCompactCardsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name'=>'Original marketplace '.$role,
            'email'=>uniqid($role.'-',true).'@example.test',
            'password'=>'OnlyForIndependentMarketplaceTest123!',
            'role'=>$role,
        ]);
    }

    private function listing(User $creator,string $type,string $title): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>$type,'title'=>$title,
            'description'=>str_repeat('This is private detail-page descriptive content and is not a card excerpt. ',2),
            'price'=>'78.00','currency'=>'USD','status'=>'published',
        ]);
    }

    private function product(User $creator): MarketplaceListing
    {
        $asset=MediaAsset::query()->create([
            'user_id'=>$creator->id,'original_name'=>'original-preview.png',
            'storage_path'=>'test/compact-product.png','mime_type'=>'image/png',
            'width'=>1,'height'=>1,'bytes'=>70,'alt_text'=>'Original product thumbnail',
            'rights_basis'=>'owned','rights_holder'=>$creator->name,
            'review_status'=>'approved','reviewed_at'=>now(),
        ]);
        $license=LicenseDefinition::query()->create([
            'code'=>'COMPACT_CARD_REGULAR',
            'name'=>'Independent regular license','version'=>'1.0.0',
            'status'=>'active','permissions'=>'Original project license grants specific usage rights.',
            'restrictions'=>'No redistribution','created_by'=>$creator->id,
        ]);
        $item=$this->listing($creator,'product','Original product with a real creator preview');
        $item->update([
            'cover_media_id'=>$asset->id,'license_definition_id'=>$license->id,
            'regular_price'=>'28.00',
        ]);
        return $item;
    }

    public function test_compact_cards_render_no_summary_and_only_persisted_product_metadata(): void
    {
        $creator=$this->member('freelancer');
        $product=$this->product($creator);
        $this->listing($creator,'project','Original project card');
        $this->listing($creator,'freelance','Original freelance card');
        $this->listing($creator,'service','Original service card');

        $response=$this->get('/')->assertOk()
            ->assertDontSee('class="marketplace-card-summary"',false)
            ->assertDontSee('class="marketplace-card-rating"',false)
            ->assertDontSee('marketplace-card-featured',false)
            ->assertDontSee('marketplace-card-live-preview',false);
        $html=$response->getContent();
        $this->assertSame(4,substr_count($html,'class="marketplace-card-price"'));
        $this->assertStringContainsString('$28.00',$html);
        $this->assertStringNotContainsString('This is private detail-page descriptive content',$html);

        $product->update(['preview_url'=>'https://preview.example.com/original-ui','is_featured'=>true]);
        $html=$this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('marketplace-card-featured',$html);
        $this->assertStringContainsString('Featured',$html);
        $this->assertStringContainsString('Live Preview',$html);
        $this->assertStringContainsString('https://preview.example.com/original-ui',$html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow external"',$html);

        $product->update(['status'=>'pending']);
        $this->get('/')->assertOk()->assertDontSee($product->title);
        $this->get('/search?type=product')->assertOk()->assertDontSee($product->title);
    }

    public function test_card_ratings_count_only_public_reviews_from_verified_delivered_orders(): void
    {
        config(['commerce.enabled' => true]);
        $creator = $this->member('freelancer');
        $buyer = $this->member('customer');
        $listing = $this->listing($creator, 'service', 'Reviewed service card');
        $unrated = $this->listing($creator, 'service', 'Unrated service card');
        $method = CommercePaymentMethod::query()->where('provider', 'bank_transfer')->sole();

        foreach ([
            [5, 'completed', true, 'accepted', false],
            [4, 'completed', true, 'accepted', false],
            [1, 'completed', true, 'accepted', true],
            [1, 'refunded', true, 'accepted', false],
            [1, 'completed', false, 'accepted', false],
            [1, 'completed', true, 'delivered', false],
        ] as [$score, $status, $paid, $delivery, $hidden]) {
            $order = MarketplaceOrder::query()->create([
                'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
                'buyer_id' => $buyer->id, 'seller_id' => $creator->id, 'marketplace_listing_id' => $listing->id,
                'listing_title' => $listing->title, 'buyer_name' => $buyer->name, 'seller_name' => $creator->name,
                'total' => '78.00', 'currency' => 'USD', 'status' => $status,
            ]);
            if ($paid) {
                $order->payments()->create([
                    'number' => (string) Str::uuid(), 'commerce_payment_method_id' => $method->id,
                    'provider' => $method->provider, 'method_name' => $method->name, 'status' => 'paid',
                    'settlement_reference' => (string) Str::uuid(),
                ]);
            }
            $order->serviceInquiry()->create([
                'marketplace_listing_id' => $listing->id, 'client_id' => $buyer->id, 'creator_id' => $creator->id,
                'subject' => $listing->title, 'delivery_status' => $delivery,
            ]);
            $order->review()->create(['rating' => $score, 'hidden_at' => $hidden ? now() : null]);
        }

        foreach (['/', '/search?type=service', route('creators.show', $creator->public_id), $unrated->publicUrl()] as $url) {
            $html = $this->get($url)->assertOk()
                ->assertSee('aria-label="4.5 out of 5 from 2 verified purchase ratings"', false)
                ->assertDontSee('aria-label="0.0 out of 5', false)
                ->getContent();
            $this->assertSame(1, substr_count($html, 'class="marketplace-card-rating"'));
        }

        $this->get($listing->publicUrl())->assertOk()
            ->assertSee('Verified purchase reviews')
            ->assertDontSee('class="marketplace-card-rating"', false);
        config(['commerce.enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('class="marketplace-card-rating"', false);
    }

    public function test_currencies_use_real_symbols_without_fabricating_other_exchange_rates(): void
    {
        $this->assertSame('$28.00',MarketplaceCardPresentation::price('28.00','USD'));
        $this->assertSame('៛1,250',MarketplaceCardPresentation::price('1250','KHR'));
        $this->assertSame('฿1,500.00',MarketplaceCardPresentation::price('1500','THB'));
        $this->assertSame('€19.00',MarketplaceCardPresentation::price('19','EUR'));
        $this->assertSame('NZD 45.00',MarketplaceCardPresentation::price('45','NZD'));
        $this->assertSame('Price on request',MarketplaceCardPresentation::price(null,'USD'));
    }

    public function test_only_public_https_creator_previews_are_accepted(): void
    {
        foreach([
            'https://example.com/working-app',
            'https://preview.example.com/app?theme=dark',
        ] as $link) $this->assertSame($link,MarketplaceCardPresentation::safePreviewUrl($link));

        foreach([
            'javascript:alert(1)','http://example.com/demo',
            'https://localhost/private','https://127.0.0.1/a',
            'https://192.168.0.1/a','https://preview.internal/a',
            'https://user:secret@example.com/path',
            'https://example.com:8443/preview',
            'https://example.com.evil.test/phishing',
            'https://example.com/\nscript',
        ] as $link) $this->assertNull(MarketplaceCardPresentation::safePreviewUrl($link),$link);

        $creator=$this->member('freelancer');
        $this->actingAs($creator);
        $payload=[
            'builder_mode'=>'1','accept_terms'=>'1',
            'terms_sha256'=>BuilderSubmissionTerms::digest(),
            'title'=>'Original starter template with live preview',
            'description'=>str_repeat('Original independent code with real documentation and preview information. ',2),
            'status'=>'draft','regular_price'=>'15.00',
        ];
        $this->post('/studio/products',[
            ...$payload,'preview_url'=>'https://demo.example.com/preview'
        ])->assertRedirect();
        $product=MarketplaceListing::query()->where('type','product')->firstOrFail();
        $this->assertSame('https://demo.example.com/preview',$product->preview_url);
        $this->get('/builder?='.$product->public_id)->assertOk()
            ->assertSee('name="preview_url"',false)
            ->assertSee('https://demo.example.com/preview');

        $this->post('/studio/products',[
            ...$payload,'preview_url'=>'https://localhost:4444/private'
        ])->assertSessionHasErrors('preview_url');
        $this->assertDatabaseCount('marketplace_listings',1);
    }

    public function test_only_administrators_can_feature_published_listings_or_jobs(): void
    {
        $creator=$this->member('freelancer');
        $admin=$this->member('admin');
        $listing=$this->listing($creator,'project','Original featured eligible project');
        $job=MarketplaceJob::query()->create([
            'client_id'=>$this->member('customer')->id,
            'title'=>'Published opportunity with real instructions',
            'description'=>str_repeat('Original complete job brief for independent work. ',3),
            'budget'=>'125.00','currency'=>'USD','category'=>'development','status'=>'published',
        ]);
        $this->actingAs($creator)
            ->patch(route('dashboard.card-features.listing',$listing),['is_featured'=>'1'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->patch(route('dashboard.card-features.listing',$listing),['is_featured'=>'1'])
            ->assertRedirect();
        $this->assertTrue($listing->fresh()->is_featured);
        $this->patch(route('dashboard.card-features.job',$job),['is_featured'=>'1'])->assertRedirect();
        $this->assertTrue($job->fresh()->is_featured);
        $this->get($listing->publicUrl())->assertOk();
        $this->get($job->publicUrl())->assertOk();
        $html=$this->get('/jobs')->assertOk()->getContent();
        $this->assertStringContainsString('bambodia-job-badge--featured',$html);
        $this->assertStringNotContainsString('bambodia-job-excerpt',$html);
        $this->assertStringNotContainsString('USD · Project budget',$html);

        $this->patch(route('dashboard.card-features.listing',$listing),['is_featured'=>'0'])->assertRedirect();
        $this->assertFalse($listing->fresh()->is_featured);
        $listing->update(['status'=>'pending']);
        $this->patch(route('dashboard.card-features.listing',$listing),['is_featured'=>'1'])->assertStatus(409);
        $job->update(['status'=>'closed']);
        $this->patch(route('dashboard.card-features.job',$job),['is_featured'=>'1'])->assertStatus(409);
    }
}
