<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\AuditEvent;
use App\Support\CommerceGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommercePaymentMethodController extends Controller
{
    public function index(): View
    {
        abort_unless(config('commerce.enabled'), 404);
        $methods = CommercePaymentMethod::query()->orderBy('name')->paginate(10);

        return view('commerce.methods', compact('methods'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $values = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('commerce_payment_methods')],
            'provider' => ['sometimes', Rule::in(array_keys(CommercePaymentMethod::PROVIDERS))],
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
        $values = $request->validate([
            'available'=>['required', 'boolean'], 'instructions'=>['sometimes', 'nullable', 'string', 'max:2500'],
            'qr'=>['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_qr'=>['nullable', 'boolean'],
        ]);
        abort_if($method->automatic() && ($request->hasFile('qr') || array_key_exists('instructions', $values)), 422);
        $path = $request->file('qr')?->store('commerce/payment-qr', 'local');
        abort_if($path === false, 503);
        try {
            $previous = DB::transaction(function () use ($method, $values, $path, $request): ?string {
                $locked = CommercePaymentMethod::query()->lockForUpdate()->findOrFail($method->id);
                $old = $locked->qr_path;
                $locked->fill(['available'=>$values['available']]);
                if (array_key_exists('instructions', $values)) {
                    $locked->instructions = $values['instructions'];
                }
                if ($path || $request->boolean('remove_qr')) {
                    $locked->qr_path = $path;
                }
                if ($locked->available && config('commerce.payments_enabled')) {
                    $live = (bool) config('commerce.live_gateways');
                    abort_unless($locked->automatic()
                        ? CommerceGateway::ready($locked->provider, $live) && ($live || ! app()->environment('production'))
                        : filled($locked->instructions), 422, 'Configure the method before making it available.');
                }
                $locked->save();
                if ($locked->wasChanged()) {
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
}
