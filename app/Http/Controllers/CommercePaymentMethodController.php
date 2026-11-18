<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\CommerceGatewaySetting;
use App\Models\CommercePayment;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommercePaymentMethodController extends Controller
{
    public function index(): View
    {
        abort_unless(config('commerce.enabled'), 404);
        $methods = CommercePaymentMethod::query()->orderBy('name')->paginate(10);

        return view('commerce.methods', compact('methods'));
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
        $live = (bool) config('commerce.live_gateways');
        $fields = array_keys(CommerceGatewaySetting::FIELDS[$method->provider] ?? []);
        $rules = [
            'available'=>['required', 'boolean'], 'instructions'=>['sometimes', 'nullable', 'string', 'max:2500'],
            'qr'=>['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_qr'=>['nullable', 'boolean'],
            'gateway' => [$fields ? 'array:'.implode(',', $fields) : 'prohibited'],
        ];
        foreach ($fields as $field) {
            $rules['gateway.'.$field] = ['nullable', 'string', 'max:2048', 'regex:/^[!-~]+$/D'];
        }
        if ($method->provider === 'aba') {
            $rules['gateway.merchant_id'] = ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,30}$/D'];
        }
        if ($method->provider === 'bakong') {
            $rules['gateway.account_id'] = ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+$/D', 'max:32'];
            $rules['gateway.merchant_name'] = ['nullable', 'string', 'regex:/^[ -~]+$/D', 'max:25'];
            $rules['gateway.merchant_city'] = ['nullable', 'string', 'regex:/^[ -~]+$/D', 'max:15'];
        }
        $values = $request->validate($rules, attributes: collect(CommerceGatewaySetting::FIELDS[$method->provider] ?? [])
            ->mapWithKeys(fn (string $label, string $key) => ['gateway.'.$key => $label])->all());
        abort_if($method->usesGateway() && ($request->hasFile('qr') || array_key_exists('instructions', $values)
            || $request->boolean('remove_qr')), 422);
        $path = $request->file('qr')?->store('commerce/payment-qr', 'local');
        abort_if($path === false, 503);
        try {
            $previous = DB::transaction(function () use ($method, $values, $path, $request, $live): ?string {
                $locked = CommercePaymentMethod::query()->lockForUpdate()->findOrFail($method->id);
                $old = $locked->qr_path;
                $locked->fill(['available'=>$values['available']]);
                if (array_key_exists('instructions', $values)) {
                    $locked->instructions = $values['instructions'];
                }
                if ($path || $request->boolean('remove_qr')) {
                    $locked->qr_path = $path;
                }
                $credentialsChanged = $locked->usesGateway()
                    && $this->saveCredentials($locked->provider, $live, $values['gateway'] ?? []);
                if ($locked->available && config('commerce.payments_enabled') && ! $locked->availableForCheckout($live)) {
                    throw ValidationException::withMessages(['available' => 'Configure the method before making it available.']);
                }
                $locked->save();
                if ($locked->wasChanged() || $credentialsChanged) {
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

    private function saveCredentials(string $provider, bool $live, array $input): bool
    {
        $new = array_filter($input, fn (?string $value) => filled($value));
        if ($new === []) {
            return false;
        }

        $settings = CommerceGatewaySetting::forMode($provider, $live)->lockForUpdate()->firstOrFail();
        $effective = CommerceGatewaySetting::effective($provider, $live);
        $changed = array_filter($new, fn (string $value, string $field) => ($effective[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return false;
        }
        $credentials = array_replace($effective, $changed);
        if (! CommerceGatewaySetting::configured($provider, $live, $credentials)) {
            throw ValidationException::withMessages(['gateway' => 'Enter all required credentials for this environment.']);
        }

        $rotatingBakongToken = $provider === 'bakong' && array_keys($changed) === ['token'];
        if (! $rotatingBakongToken && CommercePayment::query()->where('provider', $provider)
            ->where('live_mode', $live)->whereIn('status', ['starting', 'checkout_open'])->exists()) {
            throw ValidationException::withMessages(['gateway' => 'Resolve open checkouts before changing gateway credentials.']);
        }

        $settings->credentials = array_intersect_key($credentials, CommerceGatewaySetting::FIELDS[$provider]);
        $settings->save();

        return true;
    }
}
