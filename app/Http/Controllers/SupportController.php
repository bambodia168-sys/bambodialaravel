<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Support\SupportMessageContent;
use App\Models\MarketplaceOrder;
use App\Models\AuditEvent;
use App\Support\OrderReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request): View
    {
        $tickets = SupportTicket::query()
            ->where('user_id', $request->user()->id)
            ->latest()->paginate(10);

        return view('support.index', compact('tickets'));
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

    public function show(Request $request, string $ticket): View
    {
        $record = SupportTicket::query()
            ->where('public_id', $ticket)
            ->where('user_id', $request->user()->id)
            ->with('messages.author:id,name,role')
            ->firstOrFail();

        return view('support.show', ['ticket' => $record, 'isStaff' => false]);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()
            ->where('public_id', $ticket)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $data = $request->validate(['message' => ['required', 'string', 'max:40000']]);
        $message = SupportMessageContent::prepare($data['message'], 2);

        DB::transaction(function () use ($record, $request, $message): void {
            $locked = SupportTicket::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($record->id);
            $locked->messages()->create(['user_id' => $request->user()->id, 'body' => $message]);
            $locked->update(['status' => 'open']);
            AuditEvent::record('support.customer.replied', $request->user(), $request);
        });

        return back()->with('status', 'Your reply was added.');
    }
}
