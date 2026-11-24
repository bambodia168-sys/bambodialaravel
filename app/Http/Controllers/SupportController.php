<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\MarketplaceOrder;
use App\Models\AuditEvent;
use App\Support\OrderReference;
use App\Support\SupportMessageContent;
use App\Support\SupportTicketTable;
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
        return view('support.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'message' => ['required', 'string', 'max:40000'],
            'order_number' => ['nullable', 'string', 'regex:/^(?:'.OrderReference::ROUTE.')$/D'],
        ]);

        $message = SupportMessageContent::prepare($data['message'], 10);

        $ticket = DB::transaction(function () use ($request, $data, $message): SupportTicket {
            $order = isset($data['order_number'])
                ? MarketplaceOrder::query()->matchingReference($data['order_number'])
                    ->where(fn ($query) => $query->where('buyer_id', $request->user()->id)
                        ->orWhere('seller_id', $request->user()->id))
                    ->where('status', 'completed')->whereHas('paidAttempt')->whereHas('serviceInquiry')->firstOrFail()
                : null;
            $ticket = SupportTicket::query()->create([
                'user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'status' => 'open',
                'context_type' => $order ? 'order' : null,
                'context_id' => $order?->id,
            ]);
            $ticket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $message,
            ]);
            AuditEvent::record('support.ticket.created', $request->user(), $request);

            return $ticket;
        });

        return redirect()->route($request->user()->supportTicketRoutePrefix().'.show', $ticket->public_id)
            ->with('status', 'Support ticket created.');
    }

    private function owned(Request $request, string $ticket): SupportTicket
    {
        return SupportTicket::query()->where('public_id', $ticket)
            ->where('user_id', $request->user()->id)->firstOrFail();
    }

    public function show(Request $request, string $ticket): View
    {
        $record = $this->owned($request, $ticket)->load(['messages.author:id,name,role', 'assignee:id,name']);

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
        ]);
    }

    public function update(Request $request, string $ticket): RedirectResponse
    {
        $record = $this->owned($request, $ticket);
        abort_unless($record->ownerCanRevise(), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'message' => ['required', 'string', 'max:40000'],
        ]);
        $message = SupportMessageContent::prepare($data['message'], 10);

        DB::transaction(function () use ($record, $request, $data, $message): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            abort_unless($locked->ownerCanRevise(), 403);
            $locked->update(['subject' => $data['subject']]);
            $locked->messages()->firstOrFail()->update(['body' => $message]);
            AuditEvent::record('support.ticket.updated', $request->user(), $request);
        });

        return redirect()->route($request->user()->supportTicketRoutePrefix().'.show', $record->public_id)
            ->with('status', 'Ticket updated.');
    }

    public function destroy(Request $request, string $ticket): RedirectResponse
    {
        $record = $this->owned($request, $ticket);

        DB::transaction(function () use ($record, $request): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            abort_unless($locked->ownerCanRevise(), 403);
            $locked->delete(); // Keep messages for audit/retention; never cascade-delete.
            AuditEvent::record('support.ticket.deleted', $request->user(), $request);
        });

        return redirect()->route($request->user()->supportTicketRoutePrefix().'.index')
            ->with('status', 'Ticket removed from your workspace.');
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $record = $this->owned($request, $ticket);
        abort_if($record->status === 'closed', 403);

        $data = $request->validate(['message' => ['required', 'string', 'max:40000']]);
        $message = SupportMessageContent::prepare($data['message'], 2);

        DB::transaction(function () use ($record, $request, $message): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            abort_if($locked->status === 'closed', 403);
            $locked->messages()->create(['user_id' => $request->user()->id, 'body' => $message]);
            $locked->update(['status' => 'open']);
            AuditEvent::record('support.customer.replied', $request->user(), $request);
        });

        return back()->with('status', 'Your reply was added.');
    }
}
