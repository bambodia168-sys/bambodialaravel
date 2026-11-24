<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\SupportTicket;
use App\Models\SupportTicketCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupportTicketSecrets
{
    public static function fields(Request $request): ?array
    {
        $data = $request->validate([
            'credential_label' => ['nullable', 'string', 'max:120'],
            'credential_username' => ['nullable', 'string', 'max:255'],
            'credential_password' => ['nullable', 'string', 'max:4096'],
            'credential_key' => ['nullable', 'string', 'max:4096'],
            'credential_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'credential_days' => ['nullable', 'integer', Rule::in([1, 3, 7])],
        ]);

        $values = [
            'username' => (string) ($data['credential_username'] ?? ''),
            'password' => (string) ($data['credential_password'] ?? ''),
            'key' => (string) ($data['credential_key'] ?? ''),
            'url' => (string) ($data['credential_url'] ?? ''),
        ];
        $any = collect($values)->contains(fn (string $v) => $v !== '');
        if (! $any && empty($data['credential_label'])) {
            return null;
        }
        if (! $any) {
            throw ValidationException::withMessages([
                'credential_password' => 'Include a username, password, access key or HTTPS portal URL.',
            ]);
        }
        if (empty(trim((string) ($data['credential_label'] ?? '')))) {
            throw ValidationException::withMessages([
                'credential_label' => 'Add a label identifying the temporary access request.',
            ]);
        }
        return [
            'label' => trim((string) $data['credential_label']),
            'values' => $values,
            'days' => (int) ($data['credential_days'] ?? 3),
        ];
    }

    public static function store(
        SupportTicket $ticket, User $actor, array $details, Request $request
    ): SupportTicketCredential {
        $credential = SupportTicketCredential::query()->create([
            'support_ticket_id' => $ticket->id,
            'created_by' => $actor->id,
            'label' => $details['label'],
            'ciphertext' => Crypt::encryptString(json_encode(
                $details['values'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )),
            'expires_at' => now()->addDays($details['days']),
        ]);
        AuditEvent::record('support.credential.shared', $actor, $request);
        return $credential;
    }

    /** Only currently authorized support management; staff must be assigned. */
    public static function mayReveal(User $actor, SupportTicket $ticket): bool
    {
        if (! UserManagementAccess::allows($actor, 'support_center')) return false;

        if (in_array($actor->role, ['admin', 'super_admin'], true)) {
            return true;
        }

        return $actor->role === 'staff' && (int) $ticket->assigned_to === (int) $actor->id;
    }

    public static function reveal(SupportTicketCredential $credential, User $actor, Request $request): array
    {
        abort_unless(! $credential->ticket->trashed() && self::mayReveal($actor, $credential->ticket), 403);
        abort_unless($credential->active(), 410, 'This temporary credential has expired or was revoked.');

        $details = json_decode(Crypt::decryptString($credential->ciphertext), true, 512, JSON_THROW_ON_ERROR);

        $credential->forceFill([
            'view_count' => $credential->view_count + 1,
            'last_viewed_at' => now(),
        ])->save();

        AuditEvent::record('support.credential.revealed', $actor, $request);

        return [
            'username' => $details['username'] ?? '',
            'password' => $details['password'] ?? '',
            'key' => $details['key'] ?? '',
            'url' => $details['url'] ?? '',
        ];
    }

    public static function revoke(SupportTicketCredential $credential, User $actor, Request $request): void
    {
        abort_unless(
            (int) $credential->ticket->user_id === (int) $actor->id
            || self::mayReveal($actor, $credential->ticket),
            403
        );
        if (! $credential->active()) return;

        $credential->forceFill(['ciphertext' => null, 'revoked_at' => now()])->save();
        AuditEvent::record('support.credential.revoked', $actor, $request);
    }
}
