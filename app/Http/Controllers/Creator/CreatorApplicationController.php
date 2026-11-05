<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CreatorApplicationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->isCreator()) {
            return redirect('/studio?='.$request->user()->public_id);
        }

        abort_unless(in_array($request->user()->role, ['customer', 'subscriber'], true), 403);

        $application = CreatorApplication::query()
            ->where('user_id', $request->user()->id)->first();

        return view('account.creator-application', compact('application'));
    }

    public function submit(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['customer', 'subscriber'], true), 403);

        $data = $request->validate([
            'specialization' => ['required', 'string', 'min:4', 'max:180'],
            'experience' => ['required', 'string', 'min:60', 'max:6000'],
            'portfolio_url' => ['nullable', 'url', 'starts_with:https://', 'max:400'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $application = CreatorApplication::query()
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()->first();

            abort_if($application && $application->status !== 'rejected', 409, 'This creator application has already been submitted.');

            if ($application) {
                $application->update([
                    ...$data,
                    'status' => 'pending',
                    'reviewer_id' => null,
                    'review_note' => null,
                    'reviewed_at' => null,
                ]);
            } else {
                CreatorApplication::query()->create([
                    ...$data,
                    'user_id' => $request->user()->id,
                    'status' => 'pending',
                ]);
            }

            AuditEvent::record('creator.application.submitted', $request->user(), $request);
        });

        return redirect()->route('creator.apply')->with('status', 'Application submitted for review.');
    }
}
