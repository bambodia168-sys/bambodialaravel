<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\DetailComment;
use App\Models\DetailSupportResource;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetailDiscussionExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role='customer'): User
    {
        return User::query()->create([
            'name'=>'Bambodia '.$role.' '.uniqid(),
            'email'=>uniqid('detail-test-',true).'@example.test',
            'password'=>'IsolatedDetailTestPassword123!',
            'role'=>$role,
        ]);
    }

    private function listing(User $owner, string $type, ?string $status='published'): MarketplaceListing
    {
        $data=[
            'user_id'=>$owner->id,'type'=>$type,
            'title'=>'A complete premium demonstration of a very long and clearly readable '.$type.' title for digital clients',
            'description'=>str_repeat('An original independent scope and background for detailed portfolio previews. ',3),
            'status'=>$status, 'currency'=>'USD','price'=>'80.00',
        ];
        if ($type==='product') {
            $license=LicenseDefinition::query()->create([
                'code'=>strtoupper('DETAIL_'.uniqid()),'name'=>'Detail Test Regular License',
                'version'=>'1.0.0','permissions'=>'Test permitted usage',
                'restrictions'=>'No redistribution','status'=>'active','created_by'=>$owner->id,
            ]);
            $asset=MediaAsset::query()->create([
                'user_id'=>$owner->id,'original_name'=>'test-cover.png',
                'storage_path'=>'details-test-cover.png','mime_type'=>'image/png','bytes'=>68,
                'width'=>1,'height'=>1,'alt_text'=>'Preview image',
                'rights_basis'=>'owned','rights_holder'=>$owner->name,
                'review_status'=>'approved','reviewed_at'=>now(),
            ]);
            $data['cover_media_id']=$asset->id;
            $data['license_definition_id']=$license->id;
        }
        return MarketplaceListing::query()->create($data);
    }

    private function job(User $owner, string $status='published'): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id'=>$owner->id,
            'title'=>'An original engineering job with a detailed title that must wrap fully after the featured media',
            'description'=>str_repeat('The client expects a reliable response and professional delivery. ',3),
            'category'=>'development','budget'=>'600.00','currency'=>'USD','status'=>$status,
        ]);
    }

    private function params(MarketplaceListing|MarketplaceJob $record): array
    {
        return [
            'kind'=>$record instanceof MarketplaceJob?'job':$record->type,
            'reference'=>$record->public_id,
            'slug'=>$record->slug,
        ];
    }

    public function test_detail_stylesheet_contains_floating_badge_wrapped_title_and_discussion_styles(): void
    {
        $css=file_get_contents(resource_path('css/app.css'));
        $this->assertNotFalse($css);
        foreach (['.premium-detail-floating-type', '.premium-detail-below-title', '.detail-workspace-grid'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }
        $this->assertStringContainsString('white-space:normal', $css);
        $this->assertStringContainsString('overflow-wrap:break-word', $css);
    }

    public function test_five_public_detail_pages_keep_title_below_media_and_hide_guest_discussions(): void
    {
        $creator=$this->member('freelancer');
        $client=$this->member('customer');
        $items=[];
        foreach(MarketplaceListing::TYPES as $type)$items[]=$this->listing($creator,$type);
        $items[]=$this->job($client);

        foreach($items as $item){
            $html=$this->get($item->publicUrl())->assertOk()->getContent();
            $this->assertStringNotContainsString('class="marketplace-breadcrumb"',$html);
            $this->assertStringNotContainsString('class="premium-detail-intro"',$html);
            $this->assertSame(1,substr_count($html,'class="premium-detail-floating-type"'));
            $this->assertSame(1,substr_count($html,'class="premium-detail-below-title"'));
            $this->assertTrue(strpos($html,'class="premium-showcase"')<strpos($html,'class="premium-detail-below-title"'));
            $this->assertStringContainsString('class="premium-detail-below-title">'.$item->title.'</h1>',$html);
            $this->assertStringNotContainsString('href="'.route('detail.comments',$this->params($item)).'"',$html);
            $this->assertStringNotContainsString('href="'.route('detail.support',$this->params($item)).'"',$html);
            $this->get(route('detail.comments',$this->params($item)))->assertRedirect(route('login'));
            $this->get(route('detail.support',$this->params($item)))->assertRedirect(route('login'));
        }

        $this->actingAs($client);
        foreach($items as $item){
            $this->get($item->publicUrl())->assertOk()
                ->assertSee('href="'.route('detail.comments',$this->params($item)).'"',false)
                ->assertSee('href="'.route('detail.support',$this->params($item)).'"',false);
            $this->get(route('detail.comments',$this->params($item)))->assertOk()->assertSee('Write a comment');
            $this->get(route('detail.support',$this->params($item)))->assertOk()->assertSee('New support request');
        }
    }

    public function test_comment_create_reply_update_delete_notifications_and_cross_resource_isolation(): void
    {
        $owner=$this->member('freelancer');
        $alice=$this->member('customer');
        $bob=$this->member('customer');
        $admin=$this->member('admin');
        $listing=$this->listing($owner,'project');
        $other=$this->listing($owner,'service');
        $p=$this->params($listing);

        $this->actingAs($alice)->post(route('detail.comments.store',$p),[
            'body'=>'This would be helpful for a growing business.',
        ])->assertRedirect(route('detail.comments',$p));
        $root=DetailComment::firstOrFail();
        $this->assertSame('listing',$root->subject_type);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$owner->id,'event'=>'detail.comment.received',
        ]);
        $this->actingAs($bob)->post(route('detail.comments.store',$p),[
            'body'=>'Could you clarify the project timeline?',
            'parent_id'=>$root->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('detail_comments',2);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$alice->id,'event'=>'detail.comment.received',
        ]);
        $reply=DetailComment::query()->whereNotNull('parent_id')->firstOrFail();
        $this->get(route('detail.comments',$p))->assertOk()
            ->assertSee('Could you clarify the project timeline?');

        $this->patch(route('detail.comments.update',[...$p,'comment'=>$root->id]),[
            'body'=>'Unauthorized comment edit',
        ])->assertForbidden();
        $this->delete(route('detail.comments.destroy',[...$p,'comment'=>$root->id]))
            ->assertForbidden();
        $this->post(route('detail.comments.store',$this->params($other)),[
            'body'=>'Cross item reply should be rejected','parent_id'=>$root->id,
        ])->assertNotFound();
        $this->patch(route('detail.comments.update',[...$this->params($other),'comment'=>$reply->id]),[
            'body'=>'Wrong resource',
        ])->assertNotFound();

        $this->actingAs($alice)->patch(route('detail.comments.update',[...$p,'comment'=>$root->id]),[
            'body'=>'I updated this comment after reviewing the full scope.',
        ])->assertRedirect();
        $this->assertSame('I updated this comment after reviewing the full scope.',$root->fresh()->body);
        $this->actingAs($admin)->delete(route('detail.comments.destroy',[...$p,'comment'=>$root->id]))
            ->assertRedirect();
        $this->assertDatabaseMissing('detail_comments',['id'=>$root->id]);
        $this->assertNull($reply->fresh()->parent_id);
        $this->assertDatabaseHas('audit_events',['event'=>'detail.comment.deleted','actor_id'=>$admin->id]);
        $this->actingAs($bob)->get(route('detail.comments',$p))->assertOk()
            ->assertSee('Could you clarify the project timeline?');
    }

    public function test_private_context_tickets_reuse_support_replies_and_hide_other_members(): void
    {
        $owner=$this->member('freelancer');
        $client=$this->member('customer');
        $stranger=$this->member('customer');
        $staff=$this->member('staff');
        $listing=$this->listing($owner,'service');
        $job=$this->job($client);
        $p=$this->params($listing);

        $this->actingAs($client)->post(route('detail.support.store',$p),[
            'subject'=>'Assistance with service details',
            'message'=>'I have a question about this service listing and its scope.',
        ])->assertRedirect();
        $ticket=SupportTicket::firstOrFail();
        $this->assertSame('listing',$ticket->context_type);
        $this->assertSame($listing->id,(int)$ticket->context_id);
        $this->assertSame($client->id,(int)$ticket->user_id);
        $this->assertSame(1,$ticket->messages()->count());
        $this->get(route('detail.support',$p))->assertOk()->assertSee($ticket->subject);
        $this->get(route('detail.support',$this->params($job)))->assertOk()
            ->assertDontSee($ticket->subject);

        $this->actingAs($stranger)->get(route('detail.support',$p))->assertOk()
            ->assertDontSee($ticket->subject);
        $this->get(route('support.show',$ticket->public_id))->assertNotFound();

        $this->actingAs($staff)->post(route('dashboard.support.reply',$ticket->public_id),[
            'message'=>'Please send the precise scope so we can help.',
            'status'=>'in_progress',
        ])->assertRedirect();
        $this->assertSame('in_progress',$ticket->fresh()->status);

        $this->actingAs($client)->get(route('support.show',$ticket->public_id))
            ->assertOk()->assertSee('Please send the precise scope');
        $this->post(route('support.reply',$ticket->public_id),[
            'message'=>'Here is the requested additional information.',
        ])->assertRedirect();
        $this->assertSame('open',$ticket->fresh()->status);
        $this->assertSame(3,$ticket->messages()->count());
    }

    public function test_unpublished_records_are_inaccessible_and_invalid_comments_are_rejected(): void
    {
        $owner=$this->member('freelancer');
        $client=$this->member('customer');
        $draft=$this->listing($owner,'project','draft');
        $closed=$this->job($client,'closed');
        $published=$this->listing($owner,'freelance');
        $this->actingAs($client);
        foreach([$draft,$closed] as $item){
            $this->get(route('detail.comments',$this->params($item)))->assertNotFound();
            $this->get(route('detail.support',$this->params($item)))->assertNotFound();
        }
        $this->post(route('detail.comments.store',$this->params($published)),[
            'body'=>' ',
        ])->assertSessionHasErrors('body');
        $this->post(route('detail.support.store',$this->params($published)),[
            'subject'=>'X','message'=>'short',
        ])->assertSessionHasErrors(['subject','message']);
        $this->assertDatabaseCount('detail_comments',0);
        $this->assertDatabaseCount('support_tickets',0);
    }

    public function test_shared_detail_tabs_remain_visible_when_switching_to_comments_and_support(): void
    {
        $creator=$this->member('freelancer');
        $client=$this->member('customer');
        $items=[];
        foreach(MarketplaceListing::TYPES as $type) $items[]=$this->listing($creator,$type);
        $items[]=$this->job($client);

        $this->actingAs($client);
        foreach($items as $item){
            $params=$this->params($item);
            $detail=$this->get($item->publicUrl())->assertOk();
            $detail->assertSee('data-detail-root',false)
                ->assertSee('data-detail-tabs',false)
                ->assertSee('data-detail-route',false)
                ->assertSee('Comment')
                ->assertSee('Support');
            $comment=$this->get(route('detail.comments',$params))->assertOk();
            $comment->assertSee('premium-detail-tabs--shared',false)
                ->assertSee('aria-current="page"',false)
                ->assertSee('data-detail-route',false)
                ->assertSee('Community conversation');
            $support=$this->get(route('detail.support',$params))->assertOk();
            $support->assertSee('premium-detail-tabs--shared',false)
                ->assertSee('aria-current="page"',false)
                ->assertSee('data-detail-route',false)
                ->assertSee('Support resources');
        }
    }

    public function test_published_product_license_sidebar_uses_actual_tiers_and_never_offers_checkout(): void
    {
        $owner=$this->member('freelancer');
        $product=$this->listing($owner,'product');
        $extended=LicenseDefinition::query()->create([
            'code'=>'DETAIL_EXT_'.uniqid(),'tier'=>'extended',
            'name'=>'Additional Usage License','version'=>'2.1.0',
            'permissions'=>'Additional permitted installations subject to written terms',
            'restrictions'=>'No redistribution','status'=>'active','created_by'=>$owner->id,
        ]);
        $product->update([
            'regular_price'=>'15.00','extended_price'=>'45.00',
            'extended_license_definition_id'=>$extended->id,
        ]);
        $response=$this->get($product->publicUrl())->assertOk();
        $response->assertSee('data-detail-license-tier',false)
            ->assertSee('Regular License')
            ->assertSee('Extended License')
            ->assertSee('data-price="$15.00"',false)
            ->assertSee('data-price="$45.00"',false)
            ->assertSee('View license details')
            ->assertSee('Direct checkout is not enabled')
            ->assertDontSee('Buy Now')
            ->assertDontSee('PayPal')
            ->assertDontSee('Quality checked by');
        $product->update(['extended_price'=>null]);
        $this->get($product->publicUrl())->assertOk()
            ->assertDontSee('value="extended"',false);
    }

    public function test_author_resources_can_be_enabled_edited_and_disabled_without_leaking_to_other_items(): void
    {
        $publisher=$this->member('freelancer');
        $viewer=$this->member('customer');
        $other=$this->member('customer');
        $listing=$this->listing($publisher,'service');
        $another=$this->listing($publisher,'project');
        $params=$this->params($listing);
        $body=[
            'kind'=>'documentation','title'=>'Official service documentation',
            'description'=>'Detailed installation and configuration guidance',
            'url'=>'https://docs.example.org/guides/service','is_enabled'=>'1',
        ];

        $this->actingAs($viewer)
            ->post(route('detail.support.resources.store',$params),$body)->assertForbidden();
        $this->actingAs($publisher)
            ->post(route('detail.support.resources.store',$params),$body)->assertRedirect();
        $item=DetailSupportResource::query()->firstOrFail();
        $this->assertSame($publisher->id,(int)$item->author_id);
        $this->assertTrue($item->is_enabled);
        $this->assertDatabaseHas('audit_events',['event'=>'detail.support.resource.created']);

        $this->actingAs($viewer)->get(route('detail.support',$params))->assertOk()
            ->assertSee($body['title'])->assertSee($body['url'])
            ->assertDontSee('Manage publisher resources');
        $this->get(route('detail.support',$this->params($another)))->assertOk()
            ->assertDontSee($body['title']);
        $this->actingAs($other)->patch(route('detail.support.resources.update',[...$params,'resource'=>$item->id]),$body)
            ->assertForbidden();
        $this->actingAs($publisher)
            ->patch(route('detail.support.resources.update',[...$params,'resource'=>$item->id]),[
                ...$body,'title'=>'Updated service documentation','is_enabled'=>'0',
            ])->assertRedirect();
        $this->assertFalse($item->fresh()->is_enabled);
        $this->actingAs($viewer)->get(route('detail.support',$params))->assertOk()
            ->assertDontSee('Updated service documentation');
        $this->actingAs($publisher)->get(route('detail.support',$params))->assertOk()
            ->assertSee('Updated service documentation')->assertSee('Not published');
        $this->post(route('detail.support.resources.store',$params),[
            ...$body,'url'=>'http://unsafe.example.org',
        ])->assertSessionHasErrors('url');
        $this->delete(route('detail.support.resources.destroy',[
            ...$this->params($another),'resource'=>$item->id,
        ]))->assertNotFound();
        $this->delete(route('detail.support.resources.destroy',[
            ...$params,'resource'=>$item->id,
        ]))->assertRedirect();
        $this->assertDatabaseCount('detail_support_resources',0);
    }

    public function test_client_can_publish_help_for_jobs_and_member_can_read_only_enabled_resources(): void
    {
        $client=$this->member('customer');
        $freelancer=$this->member('freelancer');
        $job=$this->job($client);
        $params=$this->params($job);
        $data=[
            'kind'=>'faq','title'=>'Application guidelines',
            'url'=>'https://help.example.org/job-guidelines','is_enabled'=>'1',
        ];
        $this->actingAs($client)
            ->post(route('detail.support.resources.store',$params),$data)->assertRedirect();
        $this->actingAs($freelancer)->get(route('detail.support',$params))->assertOk()
            ->assertSee('Application guidelines');
        $this->post(route('detail.support.resources.store',$params),$data)->assertForbidden();
        $this->assertDatabaseCount('detail_support_resources',1);
    }

    public function test_premium_offer_has_verified_checkmarks_compact_license_scope_and_no_unverified_benefits(): void
    {
        $owner=$this->member('freelancer');
        $product=$this->listing($owner,'product');
        $service=$this->listing($owner,'service');
        $job=$this->job($this->member('customer'));

        $html=$this->get($product->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('premium-offer-v3',$html);
        $this->assertStringContainsString('premium-feature-checklist',$html);
        $this->assertStringContainsString('Published license terms available',$html);
        $this->assertStringContainsString('Downloads protected by verified entitlements',$html);
        $this->assertStringContainsString('<details class="premium-license-scope"',$html);
        $this->assertStringContainsString('data-detail-license-price',$html);
        $this->assertStringContainsString('data-detail-license-description',$html);
        $this->assertStringContainsString('Direct checkout is not enabled',$html);
        $this->assertStringNotContainsString('Free support',$html);
        $this->assertStringNotContainsString('Future product updates',$html);
        $this->assertStringNotContainsString('Quality checked by Codester',$html);
        $this->assertStringNotContainsString('Buy Now',$html);

        $html=$this->get($service->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('premium-offer-v3',$html);
        $this->assertStringContainsString('premium-feature-checklist',$html);
        $this->assertStringContainsString('Published marketplace listing',$html);
        $this->assertStringContainsString('Member comments and private support requests',$html);
        $this->assertStringContainsString('Private creator inquiries for eligible clients',$html);

        $html=$this->get($job->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('premium-offer-v3',$html);
        $this->assertStringContainsString('premium-feature-checklist',$html);
        $this->assertStringContainsString('Published job opportunity',$html);
        $this->assertStringNotContainsString('Buy Now',$html);
    }

    public function test_preview_listing_does_not_show_repeated_demo_notices_or_fake_purchase_actions(): void
    {
        $owner=$this->member('freelancer');
        $listing=$this->listing($owner,'project');
        $listing->update(['title'=>'[LAYOUT TEST] Internal project placeholder']);
        $html=$this->get($listing->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Preview listing',$html);
        $this->assertStringNotContainsString('LAYOUT TEST ONLY — no purchases',$html);
        $this->assertStringNotContainsString('Checkout, escrow and payouts remain disabled',$html);
        $this->assertStringNotContainsString('Sign in to contact',$html);
        $this->assertStringNotContainsString('Buy Now',$html);
    }
}
