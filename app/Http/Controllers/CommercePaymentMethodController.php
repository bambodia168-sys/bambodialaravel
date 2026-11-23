<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\CommerceGatewaySetting;
use App\Models\CommercePayment;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Support\CommerceCollection;
use App\Support\CommerceGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommercePaymentMethodController extends Controller
{
    public function index(): Response
    {
        abort_unless(config('commerce.enabled'), 404);
        $methods = CommercePaymentMethod::query()->orderBy('name')->paginate(10);
        $collectingPayments = CommerceCollection::enabled();

        return response()->view('commerce.methods', compact('methods', 'collectingPayments'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function processing(Request $request): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $values = $request->validateWithBag('paymentProcessing', ['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $values['enabled'];
        DB::transaction(function () use ($enabled, $request): void {
            if ($enabled && ! CommercePaymentMethod::query()->where('available', true)->get()
                ->contains(fn (CommercePaymentMethod $method) => $method->availableForCheckout())) {
                throw ValidationException::withMessages(['enabled' => 'Configure and make a payment method available first.'])
                    ->errorBag('paymentProcessing');
            }
            $flag = FeatureFlag::query()->updateOrCreate(['key' => CommerceCollection::FLAG], ['enabled' => $enabled]);
            if ($flag->wasRecentlyCreated || $flag->wasChanged('enabled')) {
                AuditEvent::record('feature.flag.changed', $request->user(), $request);
            }
        }, 3);

        return back()->with('status', $enabled ? 'Payment processing enabled.' : 'New payments paused.');
    }

    public function qrPreview(CommercePaymentMethod $method): Response
    {
        abort_unless(config('commerce.enabled') && ! $method->usesGateway() && $method->qr_path, 404);

        $image = Storage::disk('local')->get($method->qr_path);
        abort_if($image === null, 404);

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image);
        abort_unless(in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return response($image, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $values = $request->validateWithBag('addPaymentMethod', [
            'name' => ['required', 'string', 'max:80', Rule::unique('commerce_payment_methods')],
            'provider' => ['sometimes', Rule::in(array_keys(CommercePaymentMethod::manualProviders()))],
        ]);
        DB::transaction(function () use ($values, $request): void {
            CommercePaymentMethod::query()->create($values);
            AuditEvent::record('commerce.payment_method.created', $request->user(), $request);
        });

        return redirect()->route('dashboard.payment-methods.index')->with('status', 'Payment method added.');
    }

    public function update(Request $request, CommercePaymentMethod $method): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $stripe = $method->provider === 'stripe';
        $baray = $method->provider === 'baray';
        $bakong = $method->provider === 'bakong';
        $fields = array_keys(CommerceGatewaySetting::FIELDS[$method->provider] ?? []);
        $gatewayKeys = $bakong ? [...$fields, 'sandbox', 'live'] : $fields;
        $rules = [
            'available'=>['required', 'boolean'], 'instructions'=>['sometimes', 'nullable', 'string', 'max:2500'],
            'qr'=>['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_qr'=>['nullable', 'boolean'],
            'gateway' => [$gatewayKeys ? 'array:'.implode(',', $gatewayKeys) : 'prohibited'],
            'stripe_environment' => $stripe ? ['sometimes', 'required', Rule::in(['sandbox', 'live'])] : ['prohibited'],
            'activate_stripe_environment' => $stripe ? ['sometimes', 'boolean'] : ['prohibited'],
            'baray_environment' => $baray ? ['sometimes', 'required', Rule::in(['sandbox', 'live'])] : ['prohibited'],
            'activate_baray_environment' => $baray ? ['sometimes', 'boolean'] : ['prohibited'],
            'bakong_environment' => $bakong ? ['nullable', 'string', Rule::in(['sandbox', 'live'])] : ['prohibited'],
            'activate_bakong_environment' => $bakong ? ['sometimes', 'boolean'] : ['prohibited'],
            'bakong_renewal' => $bakong ? ['sometimes', 'array:sandbox,live'] : ['prohibited'],
        ];
        foreach ($fields as $field) {
            $rules['gateway.'.$field] = ['nullable', 'string', 'max:2048', 'regex:/^[!-~]+$/D'];
        }
        if ($method->provider === 'aba') {
            $rules['gateway.merchant_id'] = ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,30}$/D'];
        }
        $labels = collect(CommerceGatewaySetting::FIELDS[$method->provider] ?? [])
            ->mapWithKeys(fn (string $label, string $key) => ['gateway.'.$key => $label])->all();
        if ($bakong) {
            $bakongRules = [
                'account_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+$/D', 'max:32'],
                'merchant_name' => ['nullable', 'string', 'regex:/^[ -~]+$/D', 'max:25'],
                'merchant_city' => ['nullable', 'string', 'regex:/^[ -~]+$/D', 'max:15'],
                'token' => ['nullable', 'string', 'max:2048', 'regex:/^[!-~]+$/D'],
            ];
            foreach (['sandbox' => 'Sandbox', 'live' => 'Production'] as $mode => $label) {
                $rules['gateway.'.$mode] = ['sometimes', 'array:'.implode(',', $fields)];
                $rules['bakong_renewal.'.$mode] = ['nullable', 'email:rfc', 'max:30'];
                $labels['bakong_renewal.'.$mode] = $label.' Bakong registered email';
                foreach ($bakongRules as $field => $constraints) {
                    $rules["gateway.{$mode}.{$field}"] = $constraints;
                    $labels["gateway.{$mode}.{$field}"] = $label.' '.CommerceGatewaySetting::FIELDS['bakong'][$field];
                }
            }
            foreach ($bakongRules as $field => $constraints) {
                $rules['gateway.'.$field] = [...$constraints, 'prohibits:gateway.sandbox,gateway.live'];
            }
        }
        $values = $request->validate($rules, attributes: $labels);
        abort_if($method->usesGateway() && ($request->hasFile('qr') || array_key_exists('instructions', $values)
            || $request->boolean('remove_qr')), 422);
        $path = $request->file('qr')?->store('commerce/payment-qr', 'local');
        abort_if($path === false, 503);
        try {
            $previous = DB::transaction(function () use ($method, $values, $path, $request, $stripe, $baray, $bakong): ?string {
                $locked = CommercePaymentMethod::query()->lockForUpdate()->findOrFail($method->id);
                $old = $locked->qr_path;
                $locked->fill(['available'=>$values['available']]);
                if (array_key_exists('instructions', $values)) {
                    $locked->instructions = $values['instructions'];
                }
                if ($path || $request->boolean('remove_qr')) {
                    $locked->qr_path = $path;
                }
                $currentMode = CommerceGatewaySetting::checkoutIsLive($locked->provider);
                $credentialsMode = $stripe && array_key_exists('stripe_environment', $values)
                    ? $values['stripe_environment'] === 'live'
                    : ($baray && array_key_exists('baray_environment', $values)
                        ? $values['baray_environment'] === 'live' : $currentMode);
                $gateway = $values['gateway'] ?? [];
                $credentialsChanged = $bakong
                    ? $this->saveBakongCredentials($currentMode, $gateway, $values['bakong_renewal'] ?? [])
                    : $locked->usesGateway() && $this->saveCredentials($locked->provider, $credentialsMode, $gateway);
                $activationChanged = match (true) {
                    $stripe && ($values['activate_stripe_environment'] ?? false)
                        => $this->activateEnvironment('stripe', $currentMode, $credentialsMode),
                    $baray && ($values['activate_baray_environment'] ?? false)
                        => $this->activateEnvironment('baray', $currentMode, $credentialsMode),
                    $bakong && isset($values['bakong_environment']) && ($values['activate_bakong_environment'] ?? true)
                        => $this->activateEnvironment('bakong', $currentMode, $values['bakong_environment'] === 'live'),
                    default => false,
                };
                if ($locked->available && ((! $stripe && ! $baray && ! $bakong) || $locked->isDirty('available'))
                    && CommerceCollection::enabled() && ! $locked->availableForCheckout()) {
                    $message = $bakong && app()->isProduction() && ! CommerceGatewaySetting::checkoutIsLive('bakong')
                        ? 'Bakong Sandbox can be used for checkout on a test installation. Select Production and configure its credentials to make Bakong available here.'
                        : 'Configure the method before making it available.';
                    throw ValidationException::withMessages(['available' => $message]);
                }
                $locked->save();
                if ($locked->wasChanged() || $credentialsChanged || $activationChanged) {
                    AuditEvent::record('commerce.payment_method.configured', $request->user(), $request);
                }

                return $old !== $locked->qr_path ? $old : null;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return back()->with('status', 'Payment method settings saved.');
    }

    private function saveBakongCredentials(bool $currentMode, array $input, array $renewal): bool
    {
        if (! array_key_exists('sandbox', $input) && ! array_key_exists('live', $input)) {
            $input = [($currentMode ? 'live' : 'sandbox') => $input];
        }

        $changed = false;
        foreach (['sandbox' => false, 'live' => true] as $mode => $live) {
            $credentials = $input[$mode] ?? [];
            if (array_key_exists($mode, $renewal)) {
                $credentials['renewal_email'] = $renewal[$mode];
            }
            $changed = $this->saveCredentials('bakong', $live, $credentials) || $changed;
        }

        return $changed;
    }

    private function activateEnvironment(string $provider, bool $currentMode, bool $selectedMode): bool
    {
        $field = $provider.'_environment';
        if (! CommerceGateway::ready($provider, $selectedMode)) {
            if ($provider === 'bakong' && $currentMode === $selectedMode) {
                return false;
            }

            throw ValidationException::withMessages([
                $field => match ($provider) {
                    'stripe' => 'Save the API secret key and webhook signing secret for this environment before activating it.',
                    'baray' => 'Save a Baray API key, Base64 AES secret and IV for this environment before activating it.',
                    default => 'Save the Bakong recipient details and API token for this environment before activating it.',
                },
            ]);
        }
        if ($currentMode !== $selectedMode && CommercePayment::query()->where('provider', $provider)
            ->whereIn('status', ['starting', 'checkout_open'])->exists()) {
            throw ValidationException::withMessages([
                $field => 'Resolve open '.CommercePaymentMethod::PROVIDERS[$provider].' checkouts before switching environments.',
            ]);
        }

        $setting = FeatureFlag::query()->updateOrCreate(
            ['key' => CommerceGatewaySetting::ENVIRONMENT_FLAGS[$provider]], ['enabled' => $selectedMode],
        );

        return $setting->wasRecentlyCreated || $setting->wasChanged('enabled');
    }

    private function saveCredentials(string $provider, bool $live, array $input): bool
    {
        $new = array_filter($input, fn (?string $value) => filled($value));
        if ($provider === 'bakong' && array_key_exists('renewal_email', $input)) {
            $new['renewal_email'] = $input['renewal_email'];
        }
        if ($new === []) {
            return false;
        }

        $settings = CommerceGatewaySetting::forMode($provider, $live)->lockForUpdate()->firstOrFail();
        $effective = CommerceGatewaySetting::effective($provider, $live);
        $changed = array_filter($new, fn (?string $value, string $field) => ($effective[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return false;
        }
        $credentials = array_replace($effective, $changed);
        if ($provider === 'stripe' && filled($credentials['secret'] ?? null)
            && ! str_starts_with($credentials['secret'], $live ? 'sk_live_' : 'sk_test_')) {
            throw ValidationException::withMessages(['gateway.secret' => $live
                ? 'Use a Stripe live secret key beginning with sk_live_.'
                : 'Use a Stripe sandbox secret key beginning with sk_test_.']);
        }
        if (! CommerceGatewaySetting::configured($provider, $live, $credentials)) {
            throw ValidationException::withMessages(['gateway' => 'Enter all required credentials for this environment.']);
        }

        $onlyBakongRenewal = $provider === 'bakong' && ! array_diff(array_keys($changed), ['token', 'renewal_email']);
        if (! $onlyBakongRenewal && CommercePayment::query()->where('provider', $provider)
            ->where('live_mode', $live)->whereIn('status', ['starting', 'checkout_open'])->exists()) {
            throw ValidationException::withMessages(['gateway' => 'Resolve open checkouts before changing gateway credentials.']);
        }

        $allowed = CommerceGatewaySetting::FIELDS[$provider];
        if ($provider === 'bakong') {
            $allowed['renewal_email'] = true;
        }
        $settings->credentials = array_intersect_key($credentials, $allowed);
        $settings->save();

        return true;
    }
}
