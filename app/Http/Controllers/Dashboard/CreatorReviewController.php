<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorApplication;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CreatorReviewController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'approved', 'rejected', 'all'], true), 404);

        $applications = CreatorApplication::query()->with('applicant:id,public_id,name,email,role')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()->paginate(10)->withQueryString();

        return view('dashboard.creator-applications', compact('applications', 'status'));
    }

    public function decide(Request $request, CreatorApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:3000', 'required_if:decision,rejected'],
        ]);

        DB::transaction(function () use ($application, $data, $request): void {
            $application = CreatorApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($application->status === 'pending', 409, 'The application is not pending review.');

            $applicant = User::query()->lockForUpdate()->findOrFail($application->user_id);
            abort_unless(in_array($applicant->role, ['customer', 'subscriber'], true), 409, 'The applicant is not eligible for a creator role change.');

            if ($data['decision'] === 'approved') {
                $applicant->update(['role' => 'freelancer']);
            }

            $application->update([
                'status' => $data['decision'],
                'review_note' => $data['review_note'] ?? null,
                'reviewer_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            AuditEvent::record('creator.application.'.$data['decision'], $request->user(), $request);
        });

        return back()->with('status', 'Creator application '.$data['decision'].'.');
    }
}
