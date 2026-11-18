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
        $this->get(route('cart'))->assertOk()->assertSee('Added to cart')->assertSee('USD 30.25');
        $this->post(route('cart.add', $second->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout', ['account' => 'login']))->assertOk()
            ->assertSee('Sign in to checkout')->assertSee('USD 45.30')->assertDontSee('Continue to payment');
        $this->delete(route('cart.remove', $first->public_id))->assertRedirect(route('cart'));
        $this->get(route('cart'))->assertOk()->assertSee('USD 15.05')->assertDontSee('Logo setup');
        $this->assertGuest();
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
        ])->assertRedirect(route('checkout.verification'));

        $user = User::query()->where('email', 'checkout-customer@example.test')->sole();
        $this->assertArrayHasKey($offer->public_id, CommerceCart::query()->findOrFail($user->id)->items);
        Notification::assertSentTo($user, CheckoutVerification::class);
        $this->get(route('checkout'))->assertRedirect(route('checkout.verification'));
        $this->post(route('localized.en.checkout.place', $offer->public_id))
            ->assertRedirect(route('localized.en.checkout.verification'));
        $url = (new CheckoutVerification)->url($user);
        Auth::logout();
        $this->flushSession();
        $this->get($url)->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'CheckoutFixturePassword123!'])
            ->assertRedirect($url);
        $this->get($url)->assertRedirect(route('cart'));
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

    public function test_checking_out_one_item_opens_existing_payment_selection_idempotently(): void
    {
        config(['commerce.enabled' => true, 'commerce.payments_enabled' => true]);
        $buyer = $this->user('customer');
        $offer = $this->listing('Payment choice fixture', '15.00');
        CommercePaymentMethod::query()->create([
            'name' => 'Local transfer', 'provider' => 'bank_transfer', 'available' => true,
            'instructions' => 'Follow the verified transfer instructions.',
        ]);
        $this->actingAs($buyer)->post(route('cart.add', $offer->public_id), ['tier' => 'standard'])->assertRedirect(route('cart'));
        $this->get(route('checkout'))->assertOk()->assertSee('Local transfer')->assertSee('Continue to payment');

        $form = $this->orderForm($buyer, $offer);
        $this->post(route('checkout.place', $offer->public_id), $form)->assertRedirect();
        $order = MarketplaceOrder::query()->sole();
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertSame([], CommerceCart::query()->findOrFail($buyer->id)->items);
        $this->post(route('checkout.place', $offer->public_id), $form)
            ->assertRedirect(route('account.orders.show', $order->number));
        $this->get(route('account.orders.show', $order->number))->assertOk()->assertSee('Pay for this order')->assertSee('Local transfer');
        $this->assertDatabaseCount('marketplace_orders', 1);
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
