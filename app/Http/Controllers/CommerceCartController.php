<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\FeatureFlag;
use App\Models\MarketplaceListing;
use App\Models\PolicyPublication;
use App\Models\User;
use App\Notifications\CheckoutVerification;
use App\Support\CheckoutCart;
use App\Support\CommerceCollection;
use App\Support\CommerceOffers;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommerceCartController extends Controller
{
    public function cart(Request $request): Response|JsonResponse
    {
        abort_unless(config('commerce.enabled'), 404);

        if ($request->expectsJson()) {
            return $this->preview($request);
        }

        $response = $this->page($request, false);
        $response->setVary('Accept', false);

        return $response;
    }

    public function checkout(Request $request): Response|RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        if (CheckoutCart::items($request) === []) {
            return redirect()->route('cart');
        }
        if ($request->user() && ! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('checkout.verification');
        }
        if (! $request->user()) {
            $request->session()->put(CheckoutCart::INTENT, true);
        }

        return $this->page($request, true);
    }

    public function add(Request $request, string $listing): RedirectResponse|JsonResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $data = $request->validate(['tier' => ['required', Rule::in(['regular', 'extended', 'standard'])]]);
        $record = MarketplaceListing::query()->publiclyAvailable()->where('public_id', $listing)
            ->with(['creator', 'licenseDefinition', 'extendedLicenseDefinition'])->firstOrFail();
        abort_unless(CommerceOffers::eligible($record, $request->user()?->id), 404);
        $quote = CommerceOffers::quote($record, $data['tier']);
        if (! $quote) {
            throw ValidationException::withMessages(['tier' => 'This option is unavailable. Reload the listing and try again.']);
        }
        CheckoutCart::change($request, function (array $items) use ($listing, $record, $quote, $data): array {
            if (! isset($items[$listing]) && count($items) >= CheckoutCart::LIMIT) {
                throw ValidationException::withMessages(['cart' => 'Remove an item before adding another listing.']);
            }
            $items[$listing] = [
                'tier' => $data['tier'],
                'request_key' => ($items[$listing]['tier'] ?? null) === $data['tier']
                    ? ($items[$listing]['request_key'] ?? (string) Str::uuid()) : (string) Str::uuid(),
                'title' => $record->title,
                'quote' => $quote['signature'],
            ];

            return $items;
        });

        if ($request->expectsJson()) {
            return $this->preview($request, $record->title);
        }

        return redirect()->route('cart')->with('cart_added', $record->title);
    }

    private function preview(Request $request, ?string $added = null): JsonResponse
    {
        $items = CheckoutCart::items($request);
        $response = response()->json([
            'count' => count($items),
            'items' => array_values(array_map(fn (array $item) => $item['title'], $items)),
            'selections' => (object) array_map(fn (array $item) => $item['tier'], $items),
            'added' => $added,
        ], headers: ['Cache-Control' => 'private, no-store']);
        $response->setVary('Accept', false);

        return $response;
    }

    public function remove(Request $request, string $listing): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        CheckoutCart::change($request, function (array $items) use ($listing): array {
            unset($items[$listing]);

            return $items;
        });

        return redirect()->route('cart');
    }

    public function verification(Request $request): Response|RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('cart');
        }

        return response()->view('commerce.verify-email')->header('Cache-Control', 'private, no-store');
    }

    public function sendVerification(Request $request): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);

        return self::verificationEmail($request->user());
    }

    public static function verificationEmail(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('cart');
        }
        $response = redirect()->route('checkout.verification');
        if (app()->environment('production') && in_array(app('mail.manager')->getDefaultDriver(), ['log', 'array', null], true)) {
            return $response->withErrors(['email' => 'Verification email delivery is temporarily unavailable. Your cart is saved.']);
        }

        try {
            $user->notify(new CheckoutVerification);
        } catch (Throwable $exception) {
            report($exception);

            return $response->withErrors(['email' => 'The verification email could not be sent. Your cart is saved. Try again shortly.']);
        }

        return $response->with('status', 'Verification email sent.');
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $user = $request->user();
        abort_unless(hash_equals((string) $user->getKey(), $id)
            && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('cart')->with('status', 'Email verified. Your saved cart is ready.');
    }

    private function page(Request $request, bool $checkout): Response
    {
        abort_unless(config('commerce.enabled'), 404);
        $items = CheckoutCart::items($request);
        $records = MarketplaceListing::query()->publiclyAvailable()->whereIn('public_id', array_keys($items))
            ->with(['creator', 'licenseDefinition', 'extendedLicenseDefinition'])->get()->keyBy('public_id');
        $lines = [];
        $totals = [];
        foreach ($items as $id => $item) {
            $record = $records->get($id);
            $quote = $record && CommerceOffers::eligible($record, $request->user()?->id)
                ? CommerceOffers::quote($record, $item['tier']) : null;
            $lines[$id] = $item + ['listing' => $record, 'current' => $quote];
            if ($quote) {
                $currency = $quote['currency'];
                $totals[$currency] = ($totals[$currency] ?? 0) + (int) str_replace('.', '', $quote['total']);
            }
        }

        $mode = $checkout && ! $request->user() ? $request->query('account') : null;
        $canRegister = FeatureFlag::isEnabled('registration');
        $termsPublication = $mode === 'register' && $canRegister ? PolicyPublication::currentRegistrationTerms() : null;
        $collectingPayments = $checkout && CommerceCollection::enabled();
        $methods = $collectingPayments && $request->user()?->hasVerifiedEmail()
            ? CommercePaymentMethod::query()->where('available', true)->orderBy('name')->get()
                ->filter(fn (CommercePaymentMethod $method) => $method->availableForCheckout())
            : collect();

        return response()->view('commerce.cart', compact(
            'lines', 'totals', 'checkout', 'mode', 'canRegister', 'termsPublication', 'methods', 'collectingPayments',
        ))->header('Cache-Control', 'private, no-store');
    }
}
