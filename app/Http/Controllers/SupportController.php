<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\MarketplaceOrder;
use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Support\OrderReference;
use App\Support\SupportMessageContent;
use App\Support\SupportTicketTable;
use App\Support\SupportTicketContext;
use App\Support\SupportTicketFiles;
use App\Support\SupportTicketSecrets;
use Illuminate\Validation\Rule;
use App\Support\UserManagementAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = SupportTicketTable::filters($request);
        $tickets = SupportTicketTable::apply(
            SupportTicket::query()->where('user_id', $request->user()->id)
                ->with('assignee:id,name'), $filters
        )->latest('updated_at')->paginate($filters['per_page'])->withQueryString();

        $canManage = UserManagementAccess::allows($request->user(), 'support_center');
        $assignees = $canManage ? SupportTicketTable::assignees() : collect();

        return view('support.index', compact('tickets', 'filters', 'canManage', 'assignees'));
    }

    public function create(): View
    {
        return view('support.create', [
            'relatedChoices' => SupportTicketContext::choices(request()->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'message' => ['required', 'string', 'max:40000'],
            'order_number' => ['nullable', 'string', 'regex:/^(?:'.OrderReference::ROUTE.')$/D'],
            'issue_type' => ['nullable', Rule::in(array_keys(SupportTicketContext::ISSUES))],
            'related_item' => ['nullable', 'string', 'max:40'],
        ]);

        $message = SupportMessageContent::prepare($data['message'], 10);
        $files = SupportTicketFiles::validate($request);
        $credentials = SupportTicketSecrets::fields($request);
        $context = SupportTicketContext::resolve($request->user(), $data['related_item'] ?? null);

        $ticket = DB::transaction(function () use ($request, $data, $message, $files, $credentials, $context): SupportTicket {
            $order = isset($data['order_number']) && $context['context_type'] === null
                ? MarketplaceOrder::query()->matchingReference($data['order_number'])
                    ->where(fn ($query) => $query->where('buyer_id', $request->user()->id)
                        ->orWhere('seller_id', $request->user()->id))
                    ->where('status', 'completed')->whereHas('paidAttempt')->whereHas('serviceInquiry')->firstOrFail()
                : null;
            $ticket = SupportTicket::query()->create([
                'user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'status' => 'open',
                'issue_type' => $data['issue_type'] ?? 'general',
                'context_type' => $context['context_type'] ?? ($order ? 'order' : null),
                'context_id' => $context['context_id'] ?? $order?->id,
            ]);
            $firstMessage = $ticket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $message,
            ]);
            if ($credentials !== null) {
                SupportTicketSecrets::store($ticket, $request->user(), $credentials, $request);
            }
            SupportTicketFiles::store($files, $firstMessage, $request->user(), $request);
            AuditEvent::record('support.ticket.created', $request->user(), $request);

            return $ticket;
        });

        return redirect()->route($request->user()->supportTicketRoutePrefixForRequest().'.show', $ticket->public_id)
            ->with('status', 'Support ticket created.');
    }

    private function owned(Request $request, string $ticket): SupportTicket
    {
        return SupportTicket::query()->where('public_id', $ticket)
            ->where('user_id', $request->user()->id)->firstOrFail();
    }

    public function show(Request $request, string $ticket): View
    {
        $record = $this->owned($request, $ticket)->load([
            'messages.author:id,name,role', 'messages.attachments',
            'assignee:id,name', 'credentials',
        ]);

        return view('support.show', ['ticket' => $record, 'isStaff' => false]);
    }

    public function edit(Request $request, string $ticket): View
    {
        $record = $this->owned($request, $ticket);
        abort_unless($record->ownerCanRevise(), 403);

        return view('support.edit', [
            'ticket' => $record,
            'isStaff' => false,
            'firstMessage' => $record->messages()->firstOrFail(),
            'relatedChoices' => SupportTicketContext::choices($request->user()),
        ]);
    }

    public function update(Request $request, string $ticket): RedirectResponse
    {
        $record = $this->owned($request, $ticket);
        abort_unless($record->ownerCanRevise(), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'message' => ['required', 'string', 'max:40000'],
            'issue_type' => ['nullable', Rule::in(array_keys(SupportTicketContext::ISSUES))],
            'related_item' => ['nullable', 'string', 'max:40'],
        ]);
        $message = SupportMessageContent::prepare($data['message'], 10);
        $files = SupportTicketFiles::validate($request);
        $context = SupportTicketContext::resolve($request->user(), $data['related_item'] ?? null);

        DB::transaction(function () use ($record, $request, $data, $message, $files, $context): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            abort_unless($locked->ownerCanRevise(), 403);
            $locked->update([
                'subject' => $data['subject'],
                'issue_type' => $data['issue_type'] ?? 'general',
                'context_type' => $context['context_type'],
                'context_id' => $context['context_id'],
            ]);
            $first = $locked->messages()->firstOrFail();
            $first->update(['body' => $message]);
            SupportTicketFiles::store($files, $first, $request->user(), $request);
            AuditEvent::record('support.ticket.updated', $request->user(), $request);
        });

        return redirect()->route($request->user()->supportTicketRoutePrefixForRequest().'.show', $record->public_id)
            ->with('status', 'Ticket updated.');
    }

    public function destroy(Request $request, string $ticket): RedirectResponse
    {
        $record = $this->owned($request, $ticket);

        DB::transaction(function () use ($record, $request): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            abort_unless($locked->ownerCanRevise(), 403);
            $locked->eraseSharedCredentials();
            $locked->delete(); // Keep messages for audit/retention; never cascade-delete.
            AuditEvent::record('support.ticket.deleted', $request->user(), $request);
        });

        return redirect()->route($request->user()->supportTicketRoutePrefixForRequest().'.index')
            ->with('status', 'Ticket removed from your workspace.');
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $record = $this->owned($request, $ticket);
        abort_if($record->status === 'closed', 403);

        $data = $request->validate(['message' => ['nullable', 'string', 'max:40000']]);
        $files = SupportTicketFiles::validate($request);
        $text = (string) ($data['message'] ?? '');
        if (trim($text) === '' && $files === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'message' => 'Write a response or attach a reference file.',
            ]);
        }
        $message = SupportMessageContent::prepare(
            trim($text) === '' ? '<p>Reference files attached.</p>' : $text, 2
        );

        DB::transaction(function () use ($record, $request, $message, $files): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            abort_if($locked->status === 'closed', 403);
            $posted = $locked->messages()->create(['user_id' => $request->user()->id, 'body' => $message]);
            SupportTicketFiles::store($files, $posted, $request->user(), $request);
            $locked->update(['status' => 'open']);
            if ($locked->assigned_to) {
                AccountNotification::deliver(
                    (int) $locked->assigned_to, 'support.replied', 'Customer replied to ticket',
                    'A support request assigned to you has a new response.',
                    route('dashboard.support.show', $locked->public_id, false)
                );
            }
            AuditEvent::record('support.customer.replied', $request->user(), $request);
        });

        return back()->with('status', 'Your reply was added.');
    }
}
