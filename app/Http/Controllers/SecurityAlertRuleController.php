<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\SecurityAlertRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SecurityAlertRuleController extends Controller
{
    private function validated(Request $request, ?SecurityAlertRule $rule = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'event' => [
                'required', 'string',
                Rule::in(array_keys(SecurityAlertRule::MONITORED_EVENTS)),
                Rule::unique('security_alert_rules', 'event')->ignore($rule?->id),
            ],
            'threshold' => ['required','integer','between:1,100'],
            'window_minutes' => ['required','integer',Rule::in(SecurityAlertRule::WINDOWS)],
            'cooldown_minutes' => ['required','integer',Rule::in(SecurityAlertRule::COOLDOWNS)],
            'is_enabled' => ['required','boolean'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SecurityAlertRule::query()->create($this->validated($request));
        AuditEvent::record('security.alert.rule.created', $request->user(), $request);

        return back()->with('status', 'Audit alert rule added. Run the security monitor to evaluate enabled rules.');
    }

    public function update(Request $request, SecurityAlertRule $rule): RedirectResponse
    {
        $values = $this->validated($request, $rule);
        if ($rule->event !== $values['event']) {
            // A new event selection starts with a fresh evaluation/cooldown.
            $values['last_checked_at'] = null;
            $values['last_triggered_at'] = null;
        }
        $rule->update($values);
        AuditEvent::record('security.alert.rule.updated', $request->user(), $request);

        return back()->with('status', 'Audit alert rule updated.');
    }

    public function destroy(Request $request, SecurityAlertRule $rule): RedirectResponse
    {
        $rule->delete(); // Existing alert history remains, with a null rule FK.
        AuditEvent::record('security.alert.rule.deleted', $request->user(), $request);

        return back()->with('status', 'Rule deleted. Previously recorded alerts remain available.');
    }
}
