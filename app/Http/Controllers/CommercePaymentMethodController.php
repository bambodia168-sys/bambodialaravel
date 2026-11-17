<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $values = $request->validate(['available' => ['required', 'boolean']]);
        DB::transaction(function () use ($method, $values, $request): void {
            $locked = CommercePaymentMethod::query()->lockForUpdate()->findOrFail($method->id);
            $locked->update($values);
            if ($locked->wasChanged('available')) {
                AuditEvent::record('commerce.payment_method.availability_changed', $request->user(), $request);
            }
        });

        return back()->with('status', 'Payment method availability updated.');
    }
}
