<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Support\SupportMessageContent;
use App\Support\SupportTicketTable;
use App\Support\SupportTicketFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportCenterController extends Controller
{
    public function index(Request $request): View
    {
        $filters = SupportTicketTable::filters($request, true);
        $tickets = SupportTicketTable::apply(
            SupportTicket::query()->with(['owner:id,name,email', 'assignee:id,name']),
            $filters, true
        )->latest('updated_at')->paginate($filters['per_page'])->withQueryString();

        $counts = SupportTicket::query()->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $assignees = SupportTicketTable::assignees();
        $status = $filters['status'];
        $search = $filters['q'];

        return view('dashboard.support', compact('tickets', 'filters', 'counts', 'assignees', 'status', 'search'));
    }

    public function show(string $ticket): View
    {
        $record = SupportTicket::query()->where('public_id', $ticket)
            ->with([
                'owner:id,name,email,role', 'assignee:id,name',
                'messages.author:id,name,role', 'messages.attachments', 'credentials',
            ])
            ->firstOrFail();

        return view('support.show', ['ticket' => $record, 'isStaff' => true]);
    }

    /** Administrative edits do not rewrite a customer's message history. */
    public function edit(Request $request, string $ticket): View
    {
        $this->requireAdmin($request);
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();

        return view('support.edit', ['ticket' => $record, 'isStaff' => true]);
    }

    public function update(Request $request, string $ticket): RedirectResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['subject' => ['required', 'string', 'min:5', 'max:180']]);
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();

        DB::transaction(function () use ($record, $data, $request): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            $locked->update(['subject' => $data['subject']]);
            AuditEvent::record('support.ticket.updated', $request->user(), $request);
        });

        return redirect()->route('dashboard.support.show', $record->public_id)
            ->with('status', 'Ticket subject updated.');
    }

    public function destroy(Request $request, string $ticket): RedirectResponse
    {
        $this->requireAdmin($request);
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();

        DB::transaction(function () use ($record, $request): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            $locked->eraseSharedCredentials();
            $locked->delete();
            AuditEvent::record('support.ticket.deleted', $request->user(), $request);
        });

        return redirect()->route('dashboard.support')->with('status', 'Ticket archived.');
    }

    public function assign(Request $request, string $ticket): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'min:1']]);
        $assigneeId = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        abort_if($assigneeId !== null
            && ! SupportTicketTable::assignees()->contains('id', $assigneeId), 422);

        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();
        DB::transaction(function () use ($record, $request, $assigneeId): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            $locked->update(['assigned_to' => $assigneeId]);
            AccountNotification::deliver(
                (int) $locked->user_id, 'support.status_changed', 'Support ticket assigned',
                'Your support request assignment was updated.',
                route(($locked->owner?->supportTicketRoutePrefix() ?? 'account.support').'.show', $locked->public_id, false)
            );
            AuditEvent::record('support.ticket.assigned', $request->user(), $request);
        });

        return back()->with('status', 'Ticket assignment updated.');
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:40000'],
            'status' => ['required', Rule::in(array_keys(SupportTicket::STATUSES))],
        ]);

        $files = SupportTicketFiles::validate($request);
        $body = trim((string) ($data['message'] ?? ''));
        if ($body === '' && $files === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'message' => 'Write a response or attach a reference file.',
            ]);
        }
        $message = SupportMessageContent::prepare(
            $body === '' ? '<p>Reference files attached.</p>' : $body, 2
        );
        DB::transaction(function () use ($record, $data, $request, $message, $files): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            $posted = $locked->messages()->create(['user_id' => $request->user()->id, 'body' => $message]);
            SupportTicketFiles::store($files, $posted, $request->user(), $request);
            $locked->update(['status' => $data['status']]);
            if ($data['status'] === 'closed') {
                $locked->eraseSharedCredentials();
            }
            AccountNotification::deliver(
                (int) $locked->user_id, 'support.replied', 'Support team replied',
                'Your support request has a new response. Open the ticket to read it.',
                route(($locked->owner?->supportTicketRoutePrefix() ?? 'account.support').'.show', $locked->public_id, false)
            );
            AuditEvent::record('support.staff.replied', $request->user(), $request);
        });

        return back()->with('status', 'Response recorded.');
    }

    public function status(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(SupportTicket::STATUSES))]]);

        DB::transaction(function () use ($record, $data, $request): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            if ($locked->status === $data['status']) {
                return;
            }
            $locked->update(['status' => $data['status']]);
            if ($data['status'] === 'closed') {
                $locked->eraseSharedCredentials();
            }
            AccountNotification::deliver(
                (int) $locked->user_id, 'support.status_changed', 'Support ticket updated',
                'The status of your support request is now '.str_replace('_', ' ', $data['status']).'.',
                route(($locked->owner?->supportTicketRoutePrefix() ?? 'account.support').'.show', $locked->public_id, false)
            );
            AuditEvent::record('support.status.changed', $request->user(), $request);
        });

        return back()->with('status', 'Ticket status updated.');
    }

    private function requireAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
    }
}
