<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\EmailDelivery;
use App\Models\EmailTemplate;
use App\Support\BuilderContent;
use App\Support\EmailOutbox;
use App\Support\EmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class EmailTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $catalog = collect(EmailTemplates::catalog());
        $categories = $catalog->pluck('category')->unique()->sort()->values();
        $filters = $request->validate([
            'category' => ['nullable', Rule::in($categories)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $category = $filters['category'] ?? '';
        $search = $filters['q'] ?? '';
        $rows = $catalog->when($category, fn ($rows) => $rows->where('category', $category))
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn ($row, $event) => str_contains(mb_strtolower($row['label'].' '.$event), mb_strtolower($search))
            ));
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

        return view('dashboard.email-templates', compact('templates', 'categories', 'category', 'search', 'counts', 'deliveries'))
            ->with('deliveryReady', EmailOutbox::deliveryReady());
    }

    public function edit(string $event): View
    {
        $template = $this->template($event);
        $html = old('body_html', $template['body_html'] ?? null);
        $text = old('body', $template['body']);
        $bodyHtml = BuilderContent::sanitize(is_string($html) ? $html : null, email: true);
        if ($bodyHtml === null) {
            $bodyHtml = collect(preg_split('/\R{2,}/', is_string($text) ? $text : $template['body']))
                ->map(fn ($p) => '<p>'.nl2br(e($p)).'</p>')->implode('');
        }

        $customized = EmailTemplate::query()->find($event)?->subject !== null;
        $fields = [];
        foreach (EmailTemplates::EDITABLE as $field) {
            $input = old($field, $template[$field]);
            $fields[$field] = is_string($input) ? $input : $template[$field];
        }

        return view('dashboard.email-builder', compact('event', 'template', 'bodyHtml', 'customized', 'fields'))
            ->with('deliveryReady', EmailOutbox::deliveryReady());
    }

    public function update(Request $request, string $event): RedirectResponse
    {
        $template = $this->template($event);
        $values = $this->copy($request, $event);
        EmailTemplate::query()->firstOrCreate(['event' => $event], ['enabled' => $template['enabled']])->update($values);
        AuditEvent::record('mail.template.updated', $request->user(), $request);

        return back()->with('status', 'Email template saved.');
    }

    private function copy(Request $request, string $event): array
    {
        $values = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'heading' => ['required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:6000'],
            'body_html' => ['nullable', 'string', 'max:30000'],
            'button' => [$event === EmailTemplates::VERIFY ? 'required' : 'nullable', 'string', 'max:90'],
        ]);
        $html = BuilderContent::sanitize($values['body_html'] ?? null, email: true);
        $text = $html === null ? ($values['body'] ?? '') : BuilderContent::plainText($html);
        if ($text === '' || mb_strlen($text) > 6000 || strlen($html ?? '') > 60000) {
            throw ValidationException::withMessages(['body' => 'Enter a message with up to 6,000 characters.']);
        }

        return [...$values, 'body' => $text, 'body_html' => $html, 'button' => $values['button'] ?? ''];
    }

    public function status(Request $request, string $event): RedirectResponse
    {
        $template = $this->template($event);
        abort_if($event === EmailTemplates::VERIFY || $template['category'] === 'Workflow required', 409);
        $request->validate(['enabled' => ['required', 'boolean']]);
        EmailTemplate::query()->updateOrCreate(['event' => $event], ['enabled' => $request->boolean('enabled')]);
        AuditEvent::record('mail.template.updated', $request->user(), $request);

        return back()->with('status', 'Email status updated.');
    }

    public function destroy(Request $request, string $event): RedirectResponse
    {
        $defaults = EmailTemplates::defaults($event);
        abort_unless($defaults, 404);
        EmailTemplate::query()->whereKey($event)->update(array_fill_keys([...EmailTemplates::EDITABLE, 'body_html'], null));
        AuditEvent::record('mail.template.updated', $request->user(), $request);

        return back()->with('status', 'Custom copy deleted. The default template is restored.');
    }

    public function preview(Request $request, string $event): Response
    {
        return $this->renderPreview($request, $this->template($event));
    }

    public function previewDraft(Request $request, string $event): Response
    {
        return $this->renderPreview($request, [...$this->template($event), ...$this->copy($request, $event)]);
    }

    private function renderPreview(Request $request, array $template): Response
    {
        $message = EmailOutbox::message($template, $request->user(), $this->sample());

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
            'payment_method' => 'Bakong', 'badge' => 'Rising Talent', 'years' => '3 years', 'target_path' => route('home', absolute: false),
        ];
    }
}
