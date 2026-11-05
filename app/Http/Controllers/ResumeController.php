<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\FreelancerResume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ResumeController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->isCreator(), 403);
        $resume = FreelancerResume::query()->where('user_id', $request->user()->id)->first();
        return view('account.resume', compact('resume'));
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $values = $request->validate([
            'headline'=>['required','string','min:8','max:180'],
            'summary'=>['required','string','min:40','max:4000'],
            'experience'=>['required','string','min:40','max:6000'],
            'skills'=>['required','string','min:5','max:2000'],
            'portfolio_url'=>['nullable','url','starts_with:https://','max:400'],
            'is_visible'=>['required','boolean'],
        ]);
        FreelancerResume::query()->updateOrCreate(['user_id'=>$request->user()->id], $values);
        AuditEvent::record('resume.updated', $request->user(), $request);
        return back()->with('status', 'Your professional resume has been saved.');
    }
}
