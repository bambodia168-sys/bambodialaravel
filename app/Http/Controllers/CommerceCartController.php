<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Models\CommercePaymentMethod;
use App\Models\FeatureFlag;
use App\Models\MarketplaceListing;
use App\Models\PolicyPublication;
use App\Models\User;
use App\Support\CheckoutCart;
use App\Support\CommerceCollection;
use App\Support\CommerceOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            return EmailVerificationController::requireForCheckout($request);
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

    // Legacy checkout URLs continue to work, but never send users to an empty cart.
    public function verification(Request $request): RedirectResponse
    {
        return redirect()->route('verification.notice');
    }

    public function sendVerification(Request $request): RedirectResponse
    {
        return EmailVerificationController::sendFor($request->user());
    }

    public static function verificationEmail(User $user): RedirectResponse
    {
        return EmailVerificationController::sendFor($user);
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        return app(EmailVerificationController::class)->verify($request, $id, $hash);
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
