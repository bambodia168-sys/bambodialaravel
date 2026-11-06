<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\AccountNotification;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportCenterController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        abort_if($status !== '' && ! in_array($status, ['open', 'in_progress', 'resolved'], true), 404);
        $search = trim((string) $request->query('q',''));
        abort_unless(mb_strlen($search) <= 120, 404);
        $tickets = SupportTicket::query()->with('owner:id,name,email')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('subject','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->latest()->paginate(10)->withQueryString();
        $counts = SupportTicket::query()->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total','status');
        return view('dashboard.support', compact('tickets','status','search','counts'));
    }

    public function show(string $ticket): View
    {
        $record = SupportTicket::query()->where('public_id', $ticket)
            ->with(['owner:id,name,email', 'messages.author:id,name,role'])
            ->firstOrFail();

        return view('support.show', ['ticket' => $record, 'isStaff' => true]);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:10000'],
            'status' => ['required', Rule::in(['open', 'in_progress', 'resolved'])],
        ]);

        DB::transaction(function () use ($record, $data, $request): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            $locked->messages()->create(['user_id' => $request->user()->id, 'body' => $data['message']]);
            $locked->update(['status' => $data['status']]);
            AccountNotification::deliver(
                (int) $locked->user_id, 'support.replied', 'Support team replied',
                'Your support request has a new response. Open the ticket to read it.',
                '/support-center/'.$locked->public_id
            );
            AuditEvent::record('support.staff.replied', $request->user(), $request);
        });

        return back()->with('status', 'Response recorded.');
    }

    public function status(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'resolved'])]]);
        DB::transaction(function () use ($record, $data, $request): void {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($record->id);
            if ($locked->status === $data['status']) {
                return;
            }
            $locked->update(['status' => $data['status']]);
            AccountNotification::deliver(
                (int) $locked->user_id, 'support.status_changed', 'Support ticket updated',
                'The status of your support request is now '.str_replace('_', ' ', $data['status']).'.',
                '/support-center/'.$locked->public_id
            );
            AuditEvent::record('support.status.changed', $request->user(), $request);
        });

        return back()->with('status', 'Ticket status updated.');
    }
}
