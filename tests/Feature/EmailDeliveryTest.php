<?php

namespace Tests\Feature;

use App\Models\CommerceCart;
use App\Models\CreatorBadge;
use App\Models\EmailCampaign;
use App\Models\EmailDelivery;
use App\Models\EmailTemplate;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Notifications\CheckoutVerification;
use App\Support\EmailOutbox;
use App\Support\EmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_configure_switch_preview_and_restore_email_copy(): void
    {
        $event = 'commerce.receipt';
        $index = route('dashboard.email-templates.index');
        $routes = fn ($action) => route('dashboard.email-templates.'.$action, $event);
        $this->actingAs($this->user())->get($index)->assertForbidden();
        $this->actingAs($this->user('admin'))->get($index)->assertForbidden();
        $admin = $this->user('super_admin');

        $this->actingAs($admin)->get($index)->assertOk()
            ->assertSee('Email Templates')->assertSee('Purchase receipt')->assertSee('Configure Purchase receipt');
        $this->from($index)->put($routes('update'), [
            'subject' => 'Receipt for {{title}}', 'heading' => 'Your receipt',
            'body' => "Hello {{name}}\n\nPaid {{amount}} <script>alert(1)</script>", 'button' => '',
        ])->assertRedirect($index)->assertSessionHasNoErrors();
        $this->get($routes('preview'))->assertOk()->assertSee('USD 10.00')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->patch($routes('status'), ['enabled' => 0])->assertRedirect();
        $this->delete($routes('destroy'))->assertRedirect();
        $this->assertFalse(EmailTemplate::query()->findOrFail($event)->enabled);
        $this->assertNull(EmailTemplate::query()->findOrFail($event)->subject);
        $this->get($routes('preview'))->assertOk()->assertSee('Payment confirmed');
        $this->get(route('dashboard.email-campaigns.index'))->assertOk()->assertSee('New campaign');
    }

    public function test_delivery_is_deduplicated_and_mail_has_a_private_order_link(): void
    {
        $buyer = $this->user();
        $data = ['title' => 'Example listing', 'amount' => 'USD 0.30', 'payment_method' => 'Bakong',
            'order_number' => 'order-reference', 'receipt_number' => 'receipt-reference'];
        foreach (range(1, 2) as $check) {
            EmailOutbox::queue('commerce.receipt', $buyer->id, 'verified-fixture', $data, '/account/orders/fixture/receipt');
        }

        $this->assertDatabaseCount('email_deliveries', 1);
        $transport = Mail::mailer('array')->getSymfonyTransport();
        Artisan::call('mail:deliver-platform');
        Artisan::call('mail:deliver-platform');
        $this->assertSame('sent', EmailDelivery::query()->sole()->status);
        $envelope = $transport->messages()->sole();
        $this->assertSame($buyer->email, $envelope->getEnvelope()->getRecipients()[0]->getAddress());
        $mail = $envelope->getOriginalMessage();
        $this->assertStringContainsString('USD 0.30', $mail->getHtmlBody());
        $this->assertStringContainsString('Bakong', $mail->getTextBody());
        $this->assertStringContainsString('/account/orders/fixture/receipt', $mail->getHtmlBody());
    }

    public function test_listing_campaign_requires_an_enabled_template_and_delivers_only_to_verified_subscribers(): void
    {
        $admin = $this->user('super_admin');
        $subscriber = $this->user();
        $otherSubscriber = $this->user();
        $this->user();
        $unverified = $this->user();
        $unverified->forceFill(['email_verified_at' => null, 'marketing_subscribed_at' => now()])->save();

        $preference = route('account.marketing.update');
        foreach ([$subscriber, $otherSubscriber] as $recipient) {
            $this->actingAs($recipient)->patch($preference, ['enabled' => 1])->assertRedirect();
        }
        $listing = MarketplaceListing::query()->create([
            'user_id' => $admin->id, 'type' => 'service', 'title' => 'Published email fixture', 'price' => '5.00',
            'description' => 'An approved campaign destination.', 'status' => 'published', 'currency' => 'USD',
        ]);
        $template = EmailTemplates::PROMOTION;
        $this->actingAs($admin)->post(route('dashboard.email-campaigns.store'), [
            'event' => $template, 'title' => 'Explore this new service', 'message' => 'An original campaign.',
            'kind' => 'listing', 'listing' => $listing->public_id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $campaign = EmailCampaign::query()->sole();
        $queue = route('dashboard.email-campaigns.queue', $campaign);
        $this->post($queue, ['confirm_audience' => '1'])->assertSessionHas('error');
        $this->assertNull($campaign->fresh()->queued_at);
        $this->patch(route('dashboard.email-templates.status', $template), ['enabled' => 1])->assertRedirect();
        $this->post($queue, ['confirm_audience' => '1'])->assertSessionHas('status');
        $this->post($queue, ['confirm_audience' => '1'])->assertSessionHas('error');

        $url = URL::signedRoute('marketing.unsubscribe', ['user' => $otherSubscriber->public_id], absolute: false);
        auth()->logout();
        $this->get('/email/unsubscribe/'.$otherSubscriber->public_id)->assertForbidden();
        $this->get($url)->assertOk()->assertSee('Unsubscribe');
        $this->assertNotNull($otherSubscriber->fresh()->marketing_subscribed_at);
        $this->post($url)->assertOk()->assertSee('You’re unsubscribed');

        Artisan::call('mail:deliver-platform');
        $delivery = $campaign->deliveries()->sole();
        $this->assertSame($subscriber->id, $delivery->user_id);
        $email = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Explore this new service', $email->getSubject());
        $this->assertStringContainsString('Unsubscribe', $email->getHtmlBody());
        $this->assertStringContainsString('/email/unsubscribe/'.$subscriber->public_id, $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
    }

    public function test_builder_saves_safe_formatted_copy_and_previews_without_changing_saved_copy(): void
    {
        $event = 'commerce.receipt';
        $path = fn ($action) => route('dashboard.email-templates.'.$action, $event);
        $values = [
            'subject' => 'Receipt for {{title}}',
            'heading' => 'Receipt details',
            'body_html' => '<p onclick="alert(1)">Amount <strong>{{amount}}</strong></p>'
                .'<iframe src="https://www.youtube-nocookie.com/embed/1a2b3c4d5ef"></iframe>'
                .'<a href="https://example.test/{{name}}">Unsafe link token</a>',
            'button' => 'View receipt',
        ];

        $this->actingAs($this->user())->get($path('edit'))->assertForbidden();
        $this->actingAs($this->user('super_admin'))->get($path('edit'))->assertOk()
            ->assertSee('Message formatting')->assertSee('Preview draft')->assertSee('Personalization')
            ->assertSee('data-email-token="{{amount}}"', false);
        $this->post($path('preview-draft'), $values)->assertOk()
            ->assertSee('<strong>USD 10.00</strong>', false)->assertDontSee('<iframe', false)
            ->assertDontSee('onclick', false)->assertDontSee('href="https://example.test/', false);
        $this->assertDatabaseCount('email_templates', 0);
        $this->from($path('edit'))->put($path('update'), $values)->assertRedirect($path('edit'))
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('Amount {{amount}}', EmailTemplate::query()->findOrFail($event)->body);
        $this->get($path('preview'))->assertOk()->assertSee('<strong>USD 10.00</strong>', false);
        $this->delete($path('destroy'))->assertRedirect();
        $this->assertNull(EmailTemplate::query()->findOrFail($event)->body_html);
    }

    public function test_verification_template_uses_the_existing_signed_link(): void
    {
        $member = $this->user();
        $verification = new CheckoutVerification;
        $mail = $verification->toMail($member);
        $this->assertStringContainsString('/verify-email/', $mail->actionUrl);
        $this->assertStringContainsString('signature=', $mail->actionUrl);
        $this->assertSame($member->email, $mail->to[0]['address']);
        $this->actingAs($this->user('super_admin'))->patch(
            route('dashboard.email-templates.status', EmailTemplates::VERIFY), ['enabled' => 0]
        )->assertConflict();
    }

    public function test_badge_email_is_queued_only_when_the_award_is_new(): void
    {
        $creator = $this->user('freelancer');
        $badge = CreatorBadge::query()->where('enabled', true)->firstOrFail();
        $this->actingAs($this->user('super_admin'));
        $route = route('dashboard.badges.grant', $creator);
        foreach (['Details from the initial review.', 'Updated notes after the review.'] as $note) {
            $this->post($route, ['badge_id' => $badge->id, 'evidence_note' => $note])->assertRedirect();
        }
        $delivery = EmailDelivery::query()->where('event', 'creator.badge_awarded')->sole();
        $this->assertSame($creator->id, $delivery->user_id);
        $this->assertSame($badge->name, $delivery->context['badge']);
    }

    public function test_cart_reminders_are_opt_in_and_recheck_the_cart_at_delivery(): void
    {
        config(['commerce.enabled' => true]);
        $recipient = $this->user();
        $recipient->forceFill(['marketing_subscribed_at' => now()->subWeeks(2)])->save();
        $other = $this->user();
        foreach ([$recipient, $other] as $user) {
            $cart = CommerceCart::query()->create(['user_id' => $user->id, 'items' => ['123456789' => ['tier' => 'standard']]]);
            $cart->forceFill(['updated_at' => now()->subHours(26)])->saveQuietly();
        }
        $event = 'commerce.abandoned_cart';
        EmailTemplate::query()->create(['event' => $event, 'enabled' => true]);
        Artisan::call('mail:queue-reminders');
        Artisan::call('mail:queue-reminders');
        $delivery = EmailDelivery::query()->sole();
        $this->assertSame($recipient->id, $delivery->user_id);
        CommerceCart::query()->findOrFail($recipient->id)->update(['items' => []]);
        Artisan::call('mail:deliver-platform');
        $this->assertSame('skipped', $delivery->fresh()->status);
    }

    private function user(string $role = 'customer'): User
    {
        $user = User::query()->create([
            'name' => 'Email fixture', 'email' => Str::uuid().'@example.test', 'password' => 'EmailFixtureOnly123!', 'role' => $role,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
