<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Support\CreatorStudioAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class StudioAnalyticsController extends Controller
{
    public function data(Request $request, CreatorStudioAnalytics $analytics): JsonResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $values = $request->validate([
            'period' => ['required', Rule::in(CreatorStudioAnalytics::PERIODS)],
            'type' => ['required', Rule::in(CreatorStudioAnalytics::TYPES)],
        ]);

        return response()->json($analytics->report(
            (int) $request->user()->id, $values['period'], $values['type']
        ))->header('Cache-Control', 'private, no-store');
    }

    public function preferences(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $values = $request->validate([
            'monthly_goal_usd' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'weekly_capacity_hours' => ['nullable', 'numeric', 'min:1', 'max:168'],
        ]);
        $now = now();
        DB::table('creator_studio_preferences')->upsert([
            ['user_id' => $request->user()->id,
             'monthly_goal_usd' => $values['monthly_goal_usd'] ?? null,
             'weekly_capacity_hours' => $values['weekly_capacity_hours'] ?? null,
             'created_at' => $now, 'updated_at' => $now]
        ], ['user_id'], ['monthly_goal_usd', 'weekly_capacity_hours', 'updated_at']);
        return $this->back($request, 'Your dashboard goals have been saved.');
    }

    public function logTime(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $values = $request->validate([
            'logged_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2020-01-01'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'is_billable' => ['required', 'boolean'],
            'work_type' => ['required', Rule::in(array_diff(CreatorStudioAnalytics::TYPES, ['all']))],
            'description' => ['nullable', 'string', 'max:240'],
        ]);
        DB::table('creator_studio_time_entries')->insert([
            'user_id' => $request->user()->id,
            'logged_on' => $values['logged_on'],
            'hours' => $values['hours'],
            'is_billable' => (bool) $values['is_billable'],
            'work_type' => $values['work_type'],
            'description' => $values['description'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->back($request, 'Work hours recorded.');
    }

    public function logExpense(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $values = $request->validate([
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2020-01-01'],
            'amount_usd' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'work_type' => ['required', Rule::in(array_diff(CreatorStudioAnalytics::TYPES, ['all']))],
            'category' => ['required', Rule::in(['software', 'contractor', 'marketing', 'equipment', 'other'])],
            'description' => ['nullable', 'string', 'max:240'],
        ]);
        DB::table('creator_studio_expenses')->insert([
            'user_id' => $request->user()->id,
            'spent_on' => $values['spent_on'],
            'amount_usd' => $values['amount_usd'],
            'work_type' => $values['work_type'],
            'category' => $values['category'],
            'description' => $values['description'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->back($request, 'Expense recorded.');
    }

    public function deleteTime(Request $request, int $entry): RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        abort_unless(DB::table('creator_studio_time_entries')
            ->where('user_id', $request->user()->id)->where('id', $entry)->delete() === 1, 404);

        return $this->back($request, 'Time entry deleted.');
    }

    public function deleteExpense(Request $request, int $entry): RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        abort_unless(DB::table('creator_studio_expenses')
            ->where('user_id', $request->user()->id)->where('id', $entry)->delete() === 1, 404);

        return $this->back($request, 'Expense deleted.');
    }

    private function back(Request $request, string $message): RedirectResponse
    {
        return redirect('/studio?='.$request->user()->public_id.'#studio-activity')
            ->with('status', $message);
    }
}
