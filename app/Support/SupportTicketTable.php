<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class SupportTicketTable
{
    public static function filters(Request $request, bool $management = false): array
    {
        $requestedStatus = (string) $request->query('status', '');
        abort_if($requestedStatus !== '' && ! array_key_exists($requestedStatus, SupportTicket::STATUSES), 404);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_keys(SupportTicket::STATUSES))],
            'issue_type' => ['nullable', Rule::in(array_keys(SupportTicketContext::ISSUES))],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 30])],
            'updated_from' => ['nullable', 'date_format:Y-m-d'],
            'updated_to' => ['nullable', 'date_format:Y-m-d'],
            'assigned_to' => $management
                ? ['nullable', 'regex:/^(?:unassigned|[1-9][0-9]{0,12})$/D']
                : ['prohibited'],
        ]);

        if (! empty($validated['updated_from']) && ! empty($validated['updated_to'])
            && $validated['updated_to'] < $validated['updated_from']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'updated_to' => 'Updated to must be on or after updated from.',
            ]);
        }

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'issue_type' => (string) ($validated['issue_type'] ?? ''),
            'per_page' => (int) ($validated['per_page'] ?? 10),
            'updated_from' => (string) ($validated['updated_from'] ?? ''),
            'updated_to' => (string) ($validated['updated_to'] ?? ''),
            'assigned_to' => $management ? (string) ($validated['assigned_to'] ?? '') : '',
        ];
    }

    public static function apply(Builder $query, array $filters, bool $management = false): Builder
    {
        $search = $filters['q'];

        return $query
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('public_id', 'like', '%'.$search.'%')
            ))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['issue_type'] !== '', fn (Builder $q) => $q->where('issue_type', $filters['issue_type']))
            ->when($filters['updated_from'] !== '', fn (Builder $q) => $q->whereDate('updated_at', '>=', $filters['updated_from']))
            ->when($filters['updated_to'] !== '', fn (Builder $q) => $q->whereDate('updated_at', '<=', $filters['updated_to']))
            ->when($management && $filters['assigned_to'] === 'unassigned',
                fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when($management && $filters['assigned_to'] !== '' && $filters['assigned_to'] !== 'unassigned',
                fn (Builder $q) => $q->where('assigned_to', (int) $filters['assigned_to']));
    }

    /** Only active support personnel with the capability may receive assignments. */
    public static function assignees(): Collection
    {
        return User::query()
            ->whereIn('role', ['staff', 'admin', 'super_admin'])
            ->where('account_status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'account_status'])
            ->filter(fn (User $user) => UserManagementAccess::allows($user, 'support_center'))
            ->values();
    }
}
