<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ServiceInquiry;
use App\Models\TrackingOneTimeNote;
use App\Models\User;
use App\Support\VaultMalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TrackingConversationExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' '.Str::random(5),
            'email' => Str::uuid().'@example.test',
            'password' => 'Str0ngExamplePassword!',
            'role' => $role,
        ]);
    }

    private function workspace(): array
    {
        $buyer = $this->account('customer');
        $creator = $this->account('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $creator->id, 'type' => 'freelance',
            'title' => 'Private conversation workspace',
            'description' => 'Verified purchase for chat security tests.',
            'status' => 'published', 'price' => '150.00', 'currency' => 'USD',
        ]);
        $method = CommercePaymentMethod::query()->firstOrCreate(
            ['name'=>'Tracking chat payment'],['available'=>true]
        );
        $order = MarketplaceOrder::query()->create([
            'number'=>(string)Str::uuid(), 'request_key'=>(string)Str::uuid(),
            'buyer_id'=>$buyer->id,'seller_id'=>$creator->id,
            'marketplace_listing_id'=>$listing->id,'listing_title'=>$listing->title,
            'buyer_name'=>$buyer->name,'seller_name'=>$creator->name,
            'total'=>'150.00','currency'=>'USD','status'=>'completed','completed_at'=>now(),
        ]);
        $order->payments()->create([
            'number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'method_name'=>$method->name,'provider'=>'bank_transfer',
            'status'=>'paid','live_mode'=>true,
            'settlement_reference'=>'TRACKING-CHAT-'.$order->id,
        ]);
        $inquiry = $order->serviceInquiry()->create([
            'marketplace_listing_id'=>$listing->id,'client_id'=>$buyer->id,
            'creator_id'=>$creator->id,'subject'=>$listing->title,
            'status'=>'open','currency'=>'USD',
        ]);
        return [$buyer,$creator,$order,$inquiry];
    }

    public function test_conversation_is_first_below_progress_and_rich_composer_has_private_attachment_controls(): void
    {
        config(['commerce.enabled'=>true]);
        [$buyer,$creator,$order]=$this->workspace();
        $html=$this->actingAs($creator)->get(route('tracking.orders.show',$order->reference()))
            ->assertOk()->assertSee('data-tracking-chat-form',false)
            ->assertSee('data-ticket-editor',false)
            ->assertSee('data-chat-attach-button',false)
            ->assertSee('One-time handover')
            ->assertSee('tracking-conversation.js')
            ->getContent();
        $this->assertTrue(strpos($html,'id="tracking-conversation"') <
            strpos($html,'id="tracking-tasks-title"'));
        $this->assertTrue(strpos($html,'id="tracking-conversation"') <
            strpos($html,'id="tracking-delivery"'));
        $this->actingAs($buyer)->get(route('tracking.orders.show',$order->reference()))
            ->assertOk()->assertSee('data-tracking-chat-form',false);
    }

    public function test_ajax_message_returns_rendered_bubble_without_flash_and_old_conversations_still_work(): void
    {
        config(['commerce.enabled'=>true]);
        [$buyer,$creator,$order,$inquiry]=$this->workspace();
        $url=route('tracking.conversation.send',$order->reference());
        $response=$this->actingAs($creator)->postJson($url,[
            'message'=>'<p><strong>Project is underway.</strong> Please review the plan.</p>',
        ])->assertCreated()->assertJsonStructure(['message','id','total']);
        $this->assertStringContainsString('tracking-chat-item is-own',$response->json('message'));
        $this->assertStringContainsString('aria-label="Your message"',$response->json('message'));
        $this->assertStringContainsString('tracking-chat-footer',$response->json('message'));
        $this->assertStringContainsString('<strong>Project is underway.</strong>',$response->json('message'));
        $this->assertSame(1,DB::table('service_inquiry_messages')->where('content_format','html')->count());
        $this->assertEmpty(session('status'));
        $poll=$this->actingAs($buyer)->getJson(route('tracking.conversation.messages',$order->reference()).'?after=0')
            ->assertOk()->assertJsonPath('total',1);
        $this->assertStringContainsString('Project is underway',$poll->json('html'));
        $this->assertStringContainsString('tracking-chat-item is-other',$poll->json('html'));
        $this->assertStringContainsString('Creator message from',$poll->json('html'));
        $this->assertStringContainsString('tracking-chat-footer',$poll->json('html'));

        $creatorHtml = $this->actingAs($creator)->get(route('tracking.orders.show',$order->reference()))
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-chat-count>1</span>', $creatorHtml);
        $this->assertStringContainsString('data-chat-count-label>message</span>', $creatorHtml);
        $this->assertStringNotContainsString('data-chat-count-label>messages</span>', $creatorHtml);
        $this->actingAs($buyer)->get(route('inquiries.show',$inquiry))->assertOk()
            ->assertSee('Project is underway');
        $this->actingAs($creator)->postJson($url,[
            'message'=>'<script>alert("unsafe")</script><p>Safe content</p>',
        ])->assertCreated();
        $this->get(route('tracking.orders.show',$order->reference()))->assertOk()
            ->assertDontSee('<script>alert',false)->assertSee('Safe content');
    }

    public function test_private_message_attachments_are_scanned_and_order_scoped(): void
    {
        config(['commerce.enabled'=>true]);
        Storage::fake('local');
        app()->instance(VaultMalwareScanner::class,new class extends VaultMalwareScanner {
            public function scanFile(string $absoluteFile): array
            {
                return ['status'=>'clean','note'=>'Clean test fixture'];
            }
        });
        [$buyer,$creator,$order]=$this->workspace();
        $file=UploadedFile::fake()->image('proof.png');
        $this->actingAs($creator)->withHeaders(['Accept'=>'application/json'])
            ->post(route('tracking.conversation.send',$order->reference()),[
                'message'=>'<p>Reference image attached.</p>',
                'attachments'=>[$file],
            ])->assertCreated();
        $attachment=DB::table('tracking_message_attachments')->sole();
        $this->assertSame('clean',$attachment->scan_status);
        $this->assertNotEmpty($attachment->sha256);
        $download=route('tracking.conversation.attachment',[$order->reference(),$attachment->public_id]);
        $this->actingAs($buyer)->get($download)->assertOk();
        $this->actingAs($this->account('customer'))->get($download)->assertNotFound();
    }

    public function test_scanner_failure_prevents_message_and_attachment_storage(): void
    {
        config(['commerce.enabled'=>true]);
        Storage::fake('local');
        app()->instance(VaultMalwareScanner::class,new class extends VaultMalwareScanner {
            public function scanFile(string $absoluteFile): array
            {
                return ['status'=>'error','note'=>'No test scanner'];
            }
        });
        [$buyer,$creator,$order]=$this->workspace();
        $this->actingAs($creator)->withHeaders(['Accept'=>'application/json'])
            ->post(route('tracking.conversation.send',$order->reference()),[
                'message'=>'<p>Message must fail with unsafe file.</p>',
                'attachments'=>[UploadedFile::fake()->image('bad.png')],
            ])->assertUnprocessable();
        $this->assertDatabaseCount('service_inquiry_messages',0);
        $this->assertDatabaseCount('tracking_message_attachments',0);
    }

    public function test_one_time_handover_is_encrypted_restricted_and_consumed_once(): void
    {
        config(['commerce.enabled'=>true]);
        [$buyer,$creator,$order]=$this->workspace();
        $secret='One-time review instructions 789';
        $url=route('tracking.one-time.share',$order->reference());
        $this->actingAs($creator)->postJson($url,[
            'label'=>'Review instructions','content'=>$secret,
        ])->assertCreated()->assertJsonStructure(['note','id']);
        $record=TrackingOneTimeNote::query()->sole();
        $this->assertNotSame($secret,$record->ciphertext);
        $this->actingAs($creator)->get(route('tracking.orders.show',$order->reference()))
            ->assertOk()->assertDontSee($secret);
        $open=route('tracking.one-time.open',[$order->reference(),$record->public_id]);
        $this->actingAs($creator)->postJson($open)->assertNotFound();
        $this->actingAs($this->account('customer'))->postJson($open)->assertNotFound();
        $this->actingAs($buyer)->postJson($open)->assertOk()->assertJsonPath('content',$secret);
        $this->postJson($open)->assertStatus(410);
        $record->refresh();
        $this->assertNull($record->ciphertext);
        $this->assertNotNull($record->opened_at);
        $this->get(route('tracking.orders.show',$order->reference()))->assertOk()->assertDontSee($secret);
    }

    public function test_one_time_revocation_and_unpaid_order_access_are_enforced(): void
    {
        config(['commerce.enabled'=>true]);
        [$buyer,$creator,$order]=$this->workspace();
        $this->actingAs($buyer)->postJson(route('tracking.one-time.share',$order->reference()),[
            'label'=>'Final handover','content'=>'One-use information',
        ])->assertCreated();
        $record=TrackingOneTimeNote::query()->sole();
        $revoke=route('tracking.one-time.revoke',[$order->reference(),$record->public_id]);
        $this->actingAs($creator)->postJson($revoke)->assertNotFound();
        $this->actingAs($buyer)->postJson($revoke)->assertOk()->assertJsonPath('revoked',true);
        $this->assertNull($record->fresh()->ciphertext);
        $this->actingAs($creator)->postJson(route('tracking.one-time.open',[$order->reference(),$record->public_id]))
            ->assertStatus(410);
        $order->payments()->update(['status'=>'pending']);
        $this->getJson(route('tracking.conversation.messages',$order->reference()))->assertNotFound();
        $this->postJson(route('tracking.conversation.send',$order->reference()),[
            'message'=>'Cannot message an unpaid order',
        ])->assertNotFound();
    }
    public function test_youtube_only_embed_sends_without_text_and_uses_playable_referrer_policy(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $creator, $order] = $this->workspace();
        $videoId = 'p2UE5ZVUcQc';
        $response = $this->actingAs($creator)->postJson(
            route('tracking.conversation.send', $order->reference()), [
                'message' => '<p><iframe src="https://www.youtube-nocookie.com/embed/'.$videoId.'"></iframe></p>',
            ]
        )->assertCreated()->assertJsonPath('total', 1);

        $markup = $response->json('message');
        $this->assertStringContainsString('/embed/'.$videoId, $markup);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $markup);
        $this->assertStringContainsString('Watch on YouTube', $markup);
        $this->assertStringNotContainsString('referrerpolicy="no-referrer"', $markup);
        $this->assertDatabaseHas('service_inquiry_messages', ['content_format' => 'html']);

        $customer = $this->actingAs($buyer)->get(
            route('tracking.orders.show', $order->reference())
        )->assertOk()->getContent();
        $this->assertStringContainsString('tracking-chat-item is-other', $customer);
        $this->assertStringContainsString('/embed/'.$videoId, $customer);
        $this->assertStringContainsString('Watch on YouTube', $customer);
    }

    public function test_pasted_youtube_watch_link_and_old_plain_links_show_video_previews(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $creator, $order, $inquiry] = $this->workspace();

        $response = $this->actingAs($creator)->postJson(
            route('tracking.conversation.send', $order->reference()), [
                'message' => '<p>https://www.youtube.com/watch?v=p2UE5ZVUcQc</p>',
            ]
        )->assertCreated();
        $this->assertStringContainsString(
            'tracking-youtube-card', $response->json('message')
        );
        $this->assertStringContainsString(
            'https://www.youtube-nocookie.com/embed/p2UE5ZVUcQc',
            $response->json('message')
        );

        // Preserve previously stored plain inquiry messages: no rewrite needed.
        $inquiry->messages()->create([
            'sender_id' => $buyer->id,
            'body' => 'Please review https://youtu.be/M7lc1UVf-VE',
        ]);
        $html = $this->actingAs($creator)->get(
            route('tracking.orders.show', $order->reference())
        )->assertOk()->getContent();
        $this->assertStringContainsString(
            'https://www.youtube-nocookie.com/embed/M7lc1UVf-VE', $html
        );
        $this->assertStringContainsString('Watch on YouTube', $html);
    }

    public function test_youtube_only_rejects_untrusted_iframe_origins_and_invalid_ids(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $creator, $order] = $this->workspace();
        $route = route('tracking.conversation.send', $order->reference());

        $this->actingAs($creator)->postJson($route, [
            'message' => '<iframe src="https://youtube.com.evil.example/embed/p2UE5ZVUcQc"></iframe>',
        ])->assertUnprocessable();

        $this->postJson($route, [
            'message' => '<iframe src="javascript:alert(1)"></iframe>',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('service_inquiry_messages', 0);

        $this->assertNull(\App\Support\TrackingYouTubeContent::videoId(
            'https://www.youtube.com.evil.example/watch?v=p2UE5ZVUcQc'
        ));
        $this->assertNull(\App\Support\TrackingYouTubeContent::videoId(
            'https://youtu.be/not-a-valid-video-id'
        ));
        foreach ([
            'https://www.youtube.com/watch?v=p2UE5ZVUcQc',
            'https://youtu.be/p2UE5ZVUcQc',
            'https://www.youtube.com/shorts/p2UE5ZVUcQc',
            'https://www.youtube.com/live/p2UE5ZVUcQc',
            'https://www.youtube-nocookie.com/embed/p2UE5ZVUcQc',
        ] as $url) {
            $this->assertSame('p2UE5ZVUcQc',
                \App\Support\TrackingYouTubeContent::videoId($url));
        }
    }

}
