<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\EmailDelivery;
use App\Models\EmailTemplate;
use App\Support\EmailOutbox;
use App\Support\EmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class EmailTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $catalog = collect(EmailTemplates::catalog());
        $categories = $catalog->pluck('category')->unique()->sort()->values();
        $filters = $request->validate(['category' => ['nullable', Rule::in($categories)]]);
        $category = $filters['category'] ?? '';
        $rows = $catalog->when($category, fn ($rows) => $rows->where('category', $category));
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        $rowsOnPage = $rows->forPage($page, 10);
        $overrides = EmailTemplate::query()->whereIn('event', $rowsOnPage->keys())->get()->keyBy('event');
        $templates = new LengthAwarePaginator(
            $rowsOnPage->map(fn ($row, $event) => EmailTemplates::resolve($event, $overrides->get($event))),
            $rows->count(), 10, $page, ['path' => $request->url(), 'query' => $request->query()],
        );
        $counts = EmailDelivery::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $deliveries = EmailDelivery::query()->select(['id', 'event', 'status', 'created_at', 'sent_at'])
            ->latest('id')->limit(15)->get();

        return view('dashboard.email-templates', compact('templates', 'overrides', 'categories', 'category', 'counts', 'deliveries'))
            ->with('deliveryReady', EmailOutbox::deliveryReady());
    }

    public function update(Request $request, string $event): RedirectResponse
    {
        $template = $this->template($event);
        $values = $request->validateWithBag($event, [
            'subject' => ['required', 'string', 'max:180'],
            'heading' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:6000'],
            'button' => ['nullable', 'string', 'max:90'],
        ]);
        $values['button'] = $values['button'] ?? '';
        EmailTemplate::query()->firstOrCreate(['event' => $event], ['enabled' => $template['enabled']])->update($values);
        AuditEvent::record('mail.template.updated', $request->user(), $request);

        return back()->with('status', 'Email template saved.');
    }

    public function status(Request $request, string $event): RedirectResponse
    {
        $this->template($event);
        $request->validate(['enabled' => ['required', 'boolean']]);
        EmailTemplate::query()->updateOrCreate(['event' => $event], ['enabled' => $request->boolean('enabled')]);
        AuditEvent::record('mail.template.updated', $request->user(), $request);

        return back()->with('status', 'Email status updated.');
    }

    public function destroy(Request $request, string $event): RedirectResponse
    {
        $defaults = EmailTemplates::defaults($event);
        abort_unless($defaults, 404);
        EmailTemplate::query()->whereKey($event)->update(array_fill_keys(EmailTemplates::EDITABLE, null));
        AuditEvent::record('mail.template.updated', $request->user(), $request);

        return back()->with('status', 'Custom copy deleted. The default template is restored.');
    }

    public function preview(Request $request, string $event): Response
    {
        $message = EmailOutbox::message($this->template($event), $request->user(), $this->sample());

        return response()->view('mail.platform-email', [
            'copy' => $message->copy, 'actionUrl' => $message->actionUrl, 'unsubscribeUrl' => null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function test(Request $request, string $event): RedirectResponse
    {
        $template = $this->template($event);
        if (! EmailOutbox::deliveryReady()) {
            return back()->with('error', 'Activate a mail transport in SMTP Setup before sending a test.');
        }

        try {
            $sent = Mail::to($request->user()->email)->send(EmailOutbox::message($template, $request->user(), $this->sample()));
        } catch (\Throwable) {
            $sent = null;
        }

        return $sent ? back()->with('status', 'The mail transport accepted a test for your account email.')
            : back()->with('error', 'The mail transport could not send this test. Check SMTP Setup.');
    }

    public function retry(EmailDelivery $delivery): RedirectResponse
    {
        abort_unless($delivery->retryFailed(), 409);

        return back()->with('status', 'Email delivery has been queued again.');
    }

    private function template(string $event): array
    {
        $template = EmailOutbox::template($event);
        abort_unless($template, 404);

        return $template;
    }

    private function sample(): array
    {
        return [
            'title' => 'Sample marketplace listing', 'message' => 'This example shows how your email will look in an inbox.',
            'order_number' => 'SAMPLE-ORDER', 'receipt_number' => 'SAMPLE-RECEIPT', 'amount' => 'USD 10.00',
            'payment_method' => 'Bakong', 'target_path' => route('home', absolute: false),
        ];
    }
}
