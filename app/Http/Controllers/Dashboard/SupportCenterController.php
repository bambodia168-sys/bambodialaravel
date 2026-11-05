<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
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
        $tickets = SupportTicket::query()->with('owner:id,name,email')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()->paginate(10)->withQueryString();

        return view('dashboard.support', compact('tickets', 'status'));
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
            $record->messages()->create(['user_id' => $request->user()->id, 'body' => $data['message']]);
            $record->update(['status' => $data['status']]);
        });

        return back()->with('status', 'Response recorded.');
    }

    public function status(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()->where('public_id', $ticket)->firstOrFail();
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'resolved'])]]);
        $record->update($data);

        return back()->with('status', 'Ticket status updated.');
    }
}
