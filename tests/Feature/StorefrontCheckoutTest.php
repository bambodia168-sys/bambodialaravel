<?php

namespace Tests\Feature;

use App\Models\CommerceCart;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Notifications\CheckoutVerification;
use App\Support\CheckoutCart;
use App\Support\CommerceOffers;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StorefrontCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_add_and_review_multiple_items_with_no_order_or_payment_created(): void
    {
        config(['commerce.enabled' => true]);
        $first = $this->listing('Logo setup', '30.25');
        $second = $this->listing('CSS audit', '15.05');

        $this->get($first->publicUrl())->assertOk()->assertSee('Add to cart');
        $this->post(route('cart.add', $first->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('cart'))->assertOk()->assertSee('Added to cart')->assertSee('USD 30.25')
            ->assertSee('data-step="cart" data-state="current"', false)
            ->assertSee('data-step="review" data-state="upcoming"', false);
        $this->post(route('cart.add', $second->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout', ['account' => 'login']))->assertOk()
            ->assertSee('Sign in to checkout')->assertSee('USD 45.30')->assertDontSee('Continue to payment')
            ->assertSee('data-step="checkout" data-state="current"', false);
        $this->delete(route('cart.remove', $first->public_id))->assertRedirect(route('cart'));
        $this->get(route('cart'))->assertOk()->assertSee('USD 15.05')->assertDontSee('Logo setup');
        $this->assertGuest();
        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_mini_cart_returns_only_the_current_shopper_items_and_keeps_adds_idempotent(): void
    {
        config(['commerce.enabled' => true]);
        $offer = $this->listing('WordPress design package', '250.00');
        $cartUrl = route('cart');
        $addUrl = route('cart.add', $offer->public_id);

        $this->getJson($cartUrl)->assertOk()->assertExactJson(['count' => 0, 'items' => [], 'selections' => [], 'added' => null]);
        $this->postJson($addUrl, ['tier' => 'extended'])->assertUnprocessable();

        $selection = [(string) $offer->public_id => 'standard'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($addUrl, ['tier' => 'standard'])->assertOk()
                ->assertExactJson(['count' => 1, 'items' => [$offer->title], 'selections' => $selection, 'added' => $offer->title]);
        }
        $response = $this->getJson($cartUrl)->assertOk()
            ->assertExactJson(['count' => 1, 'items' => [$offer->title], 'selections' => $selection, 'added' => null]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertContains('Accept', $response->headers->all('Vary'));

        $this->delete(route('cart.remove', $offer->public_id))->assertRedirect($cartUrl);
        $this->getJson($cartUrl)->assertOk()->assertJsonPath('count', 0)->assertJsonPath('selections', []);

        $this->actingAs($this->user('customer'))->postJson($addUrl, ['tier' => 'standard'])->assertOk()
            ->assertJsonPath('count', 1);
        $this->getJson($cartUrl)->assertOk()->assertJsonPath('items.0', $offer->title);
        $this->actingAs($this->user('customer'))->getJson($cartUrl)->assertOk()->assertJsonPath('count', 0);
        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_registration_attaches_the_cart_and_verification_works_after_a_new_session(): void
    {
        config(['commerce.enabled' => true]);
        Notification::fake();
        $offer = $this->listing('Checkout verification fixture', '0.00');
        $terms = $this->terms();
        $privacy = Policy::query()->create([
            'title' => 'Privacy Policy', 'category' => 'privacy', 'status' => 'published',
            'public_code' => '21879964', 'body' => 'Published checkout privacy notice.',
        ]);
        $this->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout', ['account' => 'register']))->assertOk()
            ->assertSee('Create your account')
            ->assertSee('name="terms_publication_id" value="'.$terms->id.'"', false)
            ->assertSeeText('I have read and accept the Terms of Service and Privacy Policy.')
            ->assertSee('href="'.$terms->policy->publicUrl().'"', false)
            ->assertSee('href="'.$privacy->publicUrl().'"', false);
        $this->post(route('register.store'), [
            'name' => 'Checkout Customer',
            'email' => 'checkout-customer@example.test',
            'password' => 'CheckoutFixturePassword123!',
            'password_confirmation' => 'CheckoutFixturePassword123!',
            'terms_accepted' => '1',
            'terms_publication_id' => $terms->id,
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'checkout-customer@example.test')->sole();
        $this->assertArrayHasKey($offer->public_id, CommerceCart::query()->findOrFail($user->id)->items);
        Notification::assertSentTo($user, CheckoutVerification::class);
        $this->get(route('checkout'))->assertRedirect(route('verification.notice'));
        $this->post(route('localized.en.checkout.place', $offer->public_id))
            ->assertRedirect(route('localized.en.verification.notice'));
        $url = (new CheckoutVerification)->url($user);
        Auth::logout();
        $this->flushSession();
        $this->get($url)->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'CheckoutFixturePassword123!'])
            ->assertRedirect($url);
        $this->get($url)->assertRedirect(route('verification.verified'));
        $this->get(route('verification.verified'))->assertOk()->assertSee('Your email verified');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get(route('cart'))->assertOk()->assertSee('Checkout verification fixture');

        $this->actingAs($this->user())->get($url)->assertForbidden();
        $this->actingAs($user)->travel(61)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_login_preserves_guest_cart_and_returns_to_checkout(): void
    {
        config(['commerce.enabled' => true]);
        $existing = $this->user('customer', 'CheckoutLogin123!');
        $offer = $this->listing('Login cart fixture', '0.00');
        $this->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout'))->assertOk()->assertSessionHas(CheckoutCart::INTENT, true);
        $this->post(route('login.store'), ['email' => $existing->email, 'password' => 'CheckoutLogin123!'])
            ->assertRedirect(route('checkout'))->assertSessionMissing(CheckoutCart::SESSION);
        $this->get(route('checkout'))->assertOk()->assertSee('Place free order');
        $this->getJson(route('cart'))->assertOk()->assertJsonPath('items.0', $offer->title);
    }

    public function test_authenticator_challenge_preserves_the_cart_until_sign_in_completes(): void
    {
        config(['commerce.enabled' => true]);
        $password = 'CheckoutLogin123!';
        $buyer = $this->user('customer', $password);
        $secret = Totp::createSecret();
        UserTwoFactor::query()->create([
            'user_id' => $buyer->id, 'secret' => $secret, 'recovery_hashes' => [], 'enabled_at' => now(),
        ]);
        $offer = $this->listing('Authenticator cart fixture', '0.00');
        $this->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout'))->assertOk();

        $this->post(route('login.store'), ['email' => $buyer->email, 'password' => $password])
            ->assertRedirect(route('login.two-factor'))
            ->assertSessionHas(CheckoutCart::INTENT, true);
        $this->assertGuest();
        $this->assertDatabaseCount('commerce_carts', 0);

        $this->post(route('login.two-factor.verify'), ['code' => Totp::code($secret, intdiv(now()->timestamp, 30))])
            ->assertRedirect(route('checkout'))
            ->assertSessionMissing(CheckoutCart::SESSION);
        $this->assertAuthenticatedAs($buyer);
        $this->assertArrayHasKey($offer->public_id, CommerceCart::query()->findOrFail($buyer->id)->items);
    }

    public function test_only_eligible_listings_can_be_added_and_a_changed_quote_cannot_create_an_order(): void
    {
        config(['commerce.enabled' => true]);
        $preview = $this->listing('[LAYOUT TEST] Preview only', '0.00');
        $this->post(route('cart.add', $preview->public_id), ['tier' => 'standard'])->assertNotFound();
        $buyer = $this->user('customer');
        $offer = $this->listing('Checkout price fixture', '0.00');

        $this->actingAs($buyer)->post(route('cart.add', $offer->public_id), ['tier' => 'extended'])->assertSessionHasErrors('tier');
        $this->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $form = $this->orderForm($buyer, $offer);
        $offer->update(['price' => '25.00']);
        $this->post(route('checkout.place', $offer->public_id), $form)->assertConflict();
        $this->get(route('checkout'))->assertOk()->assertSee('The price or terms changed.')
            ->assertSee('Payment is currently unavailable');
        $this->post(route('checkout.place', $offer->public_id), $this->orderForm($buyer, $offer))->assertUnprocessable();
        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_checkout_carries_the_payment_choice_to_an_unpaid_order_idempotently(): void
    {
        config(['commerce.enabled' => true, 'commerce.payments_enabled' => true]);
        $buyer = $this->user('customer');
        $offer = $this->listing('Payment choice fixture', '15.00');
        $method = CommercePaymentMethod::query()->create([
            'name' => 'Local transfer', 'provider' => 'bank_transfer', 'available' => true,
            'instructions' => 'Follow the verified transfer instructions.',
        ]);
        $this->actingAs($buyer)->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout'))->assertOk()
            ->assertSee('data-payment-icon="bank_transfer"', false)
            ->assertSee('type="radio" name="payment_method"', false)
            ->assertSee('Local transfer')->assertSee('Continue to payment');

        $form = $this->orderForm($buyer, $offer) + ['payment_method' => $method->id];
        $this->post(route('checkout.place', $offer->public_id), $form)->assertRedirect();
        $order = MarketplaceOrder::query()->sole();
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame($method->name, $order->payment_method);
        $this->assertSame([], CommerceCart::query()->findOrFail($buyer->id)->items);
        $this->post(route('checkout.place', $offer->public_id), $form)
            ->assertRedirect(route('account.orders.show', $order->number));
        $this->get(route('account.orders.show', $order->number))->assertOk()
            ->assertSee('Pay for this order')
            ->assertSee('data-step="payment" data-state="current"', false)
            ->assertDontSee('Payment pending')
            ->assertSee('data-payment-stamp="awaiting"', false)
            ->assertSee('id="order-payment-method-name">Local transfer</h3>', false)
            ->assertSee('Local transfer');
        $this->getJson(route('cart'))->assertOk()->assertJsonPath('count', 0);
        $this->patch(route('account.orders.cancel', $order->number))->assertRedirect(route('account.orders.show', $order->number));
        $this->getJson(route('cart'))->assertOk()->assertJsonPath('count', 0);
        $this->assertDatabaseCount('marketplace_orders', 1);
        $this->assertDatabaseCount('commerce_payments', 0);
    }

    public function test_checkout_opens_the_selected_bakong_qr_once_and_allows_switching_after_expiry(): void
    {
        config(['commerce.enabled' => true, 'commerce.payments_enabled' => true, 'commerce.live_gateways' => false]);
        Http::fake([
            'https://sit-api-bakong.nbc.gov.kh/v1/check_transaction_by_md5' => Http::response(['responseCode' => 1, 'errorCode' => 1]),
        ]);
        $buyer = $this->user();
        $offer = $this->listing('QR checkout fixture', '0.30');
        $bakong = CommercePaymentMethod::query()->where('provider', 'bakong')->sole();
        $transfer = CommercePaymentMethod::query()->where('provider', 'bank_transfer')->sole();
        $transfer->update(['available' => true, 'instructions' => 'Use the isolated bank account.']);
        $this->actingAs($this->user('super_admin'))->patch(route('dashboard.payment-methods.update', $bakong), [
            'available' => true, 'bakong_environment' => 'sandbox', 'gateway' => ['sandbox' => [
                'account_id' => 'merchant@devb', 'merchant_name' => 'Bambodia',
                'merchant_city' => 'Battambang', 'token' => 'sandbox-fixture-token',
            ]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($buyer)->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $form = $this->orderForm($buyer, $offer) + ['payment_method' => $bakong->id];
        $place = route('checkout.place', $offer->public_id);
        $this->post($place, $form)->assertRedirect()->assertSessionHasNoErrors();
        $order = MarketplaceOrder::query()->sole();
        $payment = $order->payments()->sole();
        $this->assertSame('checkout_open', $payment->status);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertNull($order->receipt_number);
        $page = route('account.orders.show', $order->number);
        $change = route('account.orders.payment-method', $order->number);
        $this->post($place, $form)->assertRedirect($page);
        $this->get($page)->assertOk()
            ->assertSee('data-order-payment-code', false)
            ->assertSee('Change payment method')->assertDontSee('Use selected method')
            ->assertDontSee('Generate QR for Bakong');
        $this->get(route('account.orders.payment.order-qr', [$order->number, $payment->number]))->assertOk();
        $this->assertSame($payment->id, $order->payments()->sole()->id);
        $this->patch($change, ['payment_method' => $transfer->id])->assertConflict();
        Http::assertNothingSent();

        $this->travel(9)->minutes();
        $this->postJson(route('account.orders.payment.verify', [$order->number, $payment->number]))
            ->assertOk()->assertJsonPath('status', 'expired');
        $this->get($page)->assertOk()->assertSee('Use selected method')->assertDontSee('data-order-payment-code', false);
        $this->patch($change, ['payment_method' => $transfer->id])->assertRedirect($page);
        $this->get($page)->assertOk()->assertSee('Upload your transfer receipt');
        $this->assertSame($transfer->name, $order->refresh()->payment_method);
        $this->assertDatabaseCount('commerce_payments', 1);

        $this->patch($change, ['payment_method' => $bakong->id])->assertRedirect($page);
        $this->get($page)->assertOk()->assertSee('data-order-payment-code', false);
        $this->assertDatabaseCount('commerce_payments', 2);
        $this->assertNotSame($payment->remote_id, $order->payments()->latest('id')->firstOrFail()->remote_id);
    }

    public function test_changed_payment_availability_keeps_the_item_in_the_cart(): void
    {
        config(['commerce.enabled' => true, 'commerce.payments_enabled' => true]);
        $buyer = $this->user();
        $offer = $this->listing('Payment availability fixture', '15.00');
        $disabled = CommercePaymentMethod::query()->create([
            'name' => 'Disabled transfer', 'provider' => 'bank_transfer', 'available' => false,
            'instructions' => 'Payment details.',
        ]);
        CommercePaymentMethod::query()->create([
            'name' => 'Available transfer', 'provider' => 'bank_transfer', 'available' => true,
            'instructions' => 'Payment details.',
        ]);
        $this->actingAs($buyer)->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect();
        $this->get(route('checkout'))->assertOk()->assertDontSee('Disabled transfer');
        $this->from(route('checkout'))->post(route('checkout.place', $offer->public_id),
            $this->orderForm($buyer, $offer) + ['payment_method' => $disabled->id])
            ->assertRedirect(route('checkout'))->assertSessionHasErrors('payment_method');
        $this->assertArrayHasKey($offer->public_id, CommerceCart::query()->findOrFail($buyer->id)->items);
        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_review_marks_payment_not_required_for_a_free_order(): void
    {
        config(['commerce.enabled' => true]);
        $buyer = $this->user();
        $offer = $this->listing('Free order progress fixture', '0.00');
        $this->actingAs($buyer)->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect();
        $this->post(route('checkout.place', $offer->public_id), $this->orderForm($buyer, $offer))->assertRedirect();
        $order = MarketplaceOrder::query()->sole();
        foreach (['account.orders.show', 'account.orders.receipt'] as $route) {
            $this->get(route($route, $order->number))->assertOk()
                ->assertSee('data-step="payment" data-state="skipped"', false)
                ->assertSee('data-step="review" data-state="current"', false);
        }
    }

    private function user(string $role = 'customer', string $password = 'CheckoutFixturePassword123!'): User
    {
        $user = User::query()->create([
            'name' => 'Checkout '.ucfirst($role),
            'email' => uniqid('checkout-', true).'@example.test',
            'role' => $role,
            'password' => $password,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function listing(string $title, string $price): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'title' => $title, 'description' => str_repeat('Published storefront fixture. ', 4),
            'type' => 'service', 'status' => 'published', 'currency' => 'USD', 'price' => $price,
            'user_id' => $this->user('freelancer')->id,
        ]);
    }

    private function terms(): PolicyPublication
    {
        $policy = Policy::query()->create([
            'title' => 'Terms of Service', 'category' => 'terms', 'status' => 'published',
            'public_code' => '91204896',
            'jurisdiction' => 'Cambodia', 'body' => 'Marketplace terms for isolated registration tests.',
        ]);

        return PolicyPublication::query()->create([
            'policy_id' => $policy->id, 'revision' => 1, 'title' => $policy->title,
            'category' => 'terms', 'jurisdiction' => 'Cambodia', 'body_snapshot' => $policy->body,
            'sha256' => PolicyPublication::digestFor($policy),
            'published_by' => $this->user('super_admin')->id, 'created_at' => now(),
        ]);
    }

    private function orderForm(User $buyer, MarketplaceListing $offer): array
    {
        $line = CommerceCart::query()->findOrFail($buyer->id)->items[$offer->public_id];

        return [
            'tier' => $line['tier'], 'request_key' => $line['request_key'],
            'quote' => CommerceOffers::quote($offer, $line['tier'])['signature'],
        ];
    }
}
