<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MailDeliverySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SmtpSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = MailDeliverySetting::query()->findOrFail(MailDeliverySetting::SINGLETON);
        $draft = $settings->draft ?? [];
        $active = $settings->active;

        return response()->view('dashboard.smtp-settings', [
            'draft' => Arr::except($draft, ['password']),
            'hasPassword' => filled($draft['password'] ?? null),
            'testedAt' => $settings->tested_at,
            'revision' => $settings->draft_revision,
            'activeSender' => $active['from_address'] ?? null,
            'hasUnappliedDraft' => $settings->draft_revision !== $settings->active_revision,
            'environmentMailer' => config('mail.driver') ?? config('mail.default'),
            'defaultRecipient' => $request->user()->email,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'host' => ['required', 'string', 'max:253', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $fail('Enter an SMTP hostname or IPv4 address.');
                }
            }],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['starttls', 'tls'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:4096'],
            'from_address' => ['required', 'email', 'max:254'],
            'from_name' => ['required', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($values, $request): void {
            $settings = MailDeliverySetting::query()->lockForUpdate()->findOrFail(MailDeliverySetting::SINGLETON);
            $previous = $settings->draft ?? [];
            $profile = [
                'host' => strtolower($values['host']),
                'port' => (int) $values['port'],
                'encryption' => $values['encryption'],
                'username' => $values['username'] ?? null,
                'from_address' => $values['from_address'],
                'from_name' => $values['from_name'],
            ];

            $connection = ['host', 'port', 'encryption', 'username'];
            $sameConnection = Arr::only($profile, $connection) === Arr::only($previous, $connection);
            $profile['password'] = $values['password'] ?? ($sameConnection ? ($previous['password'] ?? null) : null);
            if ($profile['username'] !== null && $profile['password'] === null) {
                throw ValidationException::withMessages(['password' => 'Enter the password for this SMTP connection.']);
            }
            if ($profile['username'] === null && $profile['password'] !== null) {
                throw ValidationException::withMessages(['username' => 'Enter the username associated with this password.']);
            }

            if ($profile !== $previous) {
                $settings->forceFill([
                    'draft' => $profile, 'draft_revision' => (string) Str::uuid(), 'tested_at' => null,
                ])->save();
                AuditEvent::record('mail.smtp.draft_saved', $request->user(), $request);
            }
        }, 3);

        return back()->with('status', 'SMTP draft saved.');
    }

    public function testDelivery(Request $request): RedirectResponse
    {
        $values = $request->validate(['recipient' => ['required', 'email', 'max:254'], 'revision' => ['required', 'uuid']]);
        $settings = MailDeliverySetting::query()->findOrFail(MailDeliverySetting::SINGLETON);
        if ($settings->draft === null) {
            return back()->withErrors(['recipient' => 'Save an SMTP draft first.']);
        }

        if ($settings->draft_revision !== $values['revision']) {
            return back()->withErrors(['recipient' => 'The SMTP draft changed. Reload the page before testing.']);
        }

        try {
            $sent = app('mail.manager')->buildSmtp($settings->draft, 'dashboard-smtp-test')->raw(
                'Your SMTP server accepted this Bambodia test message.',
                static fn (Message $message) => $message->to($values['recipient'])->subject('Bambodia SMTP test'),
            );
        } catch (Throwable) {
            $sent = null;
        }

        $result = DB::transaction(function () use ($settings, $sent, $request): string {
            $current = MailDeliverySetting::query()->lockForUpdate()->findOrFail(MailDeliverySetting::SINGLETON);
            if ($current->draft_revision !== $settings->draft_revision) {
                return 'changed';
            }

            $current->forceFill(['tested_at' => $sent !== null ? now() : null])->save();
            AuditEvent::record($sent !== null ? 'mail.smtp.test_passed' : 'mail.smtp.test_failed', $request->user(), $request);

            return $sent !== null ? 'passed' : 'failed';
        }, 3);

        return match ($result) {
            'passed' => back()->with('status', 'SMTP server accepted the test email. Check the recipient inbox.'),
            'changed' => back()->withErrors(['recipient' => 'The draft changed during the test. Test the current draft.']),
            default => back()->withErrors(['recipient' => 'SMTP test failed. Check the server, TLS setting, credentials, and sender address.']),
        };
    }

    public function activate(Request $request): RedirectResponse
    {
        $values = $request->validate(['revision' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $values): void {
            $settings = MailDeliverySetting::query()->lockForUpdate()->findOrFail(MailDeliverySetting::SINGLETON);
            if ($settings->draft_revision !== $values['revision'] || $settings->tested_at === null) {
                throw ValidationException::withMessages(['recipient' => 'Send a successful test email before enabling this draft.']);
            }

            $settings->forceFill(['active' => $settings->draft, 'active_revision' => $settings->draft_revision])->save();
            AuditEvent::record('mail.smtp.activated', $request->user(), $request);
        }, 3);

        return back()->with('status', 'Dashboard SMTP is now active.');
    }

    public function disable(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $settings = MailDeliverySetting::query()->lockForUpdate()->findOrFail(MailDeliverySetting::SINGLETON);
            $settings->forceFill(['active' => null, 'active_revision' => null])->save();
            AuditEvent::record('mail.smtp.disabled', $request->user(), $request);
        }, 3);

        return back()->with('status', 'Mail now uses the server’s environment configuration.');
    }
}
