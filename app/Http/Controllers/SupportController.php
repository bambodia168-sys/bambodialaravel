<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:180'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $ticket = DB::transaction(function () use ($request, $data): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'status' => 'open',
            ]);
            $ticket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $data['message'],
            ]);

            return $ticket;
        });

        return redirect()->route('support.show', $ticket->public_id)
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
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:10000']]);

        DB::transaction(function () use ($record, $request, $data): void {
            $record->messages()->create(['user_id' => $request->user()->id, 'body' => $data['message']]);
            $record->update(['status' => 'open']);
        });

        return back()->with('status', 'Your reply was added.');
    }
}
