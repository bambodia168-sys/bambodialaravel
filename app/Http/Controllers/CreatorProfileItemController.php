<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\CreatorProfileItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CreatorProfileItemController extends Controller
{
    private const LIMITS = ['expertise' => 20, 'language' => 10, 'link' => 10];

    private function owner(Request $request, ?User $user): User
    {
        if ($user !== null) {
            abort_unless($request->user()->role === 'super_admin', 403);
            return $user;
        }
        return $request->user();
    }

    private function validateFields(Request $request, string $kind): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'detail' => ['nullable', 'string', 'max:160'],
            'url' => [$kind === 'link' ? 'required' : 'nullable', 'url', 'starts_with:https://', 'max:400'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_visible' => ['required', 'boolean'],
        ]);
    }

    public function storeSelf(Request $request): RedirectResponse
    {
        return $this->store($request, null);
    }

    public function storeAdmin(Request $request, User $user): RedirectResponse
    {
        return $this->store($request, $user);
    }

    private function store(Request $request, ?User $user): RedirectResponse
    {
        $owner = $this->owner($request, $user);
        $kind = $request->validate(['kind' => ['required', Rule::in(CreatorProfileItem::KINDS)]])['kind'];
        $data = $this->validateFields($request, $kind);
        abort_if(CreatorProfileItem::query()->where('user_id', $owner->id)->where('kind', $kind)->count()
            >= self::LIMITS[$kind], 422, 'This profile section has reached its maximum number of entries.');
        CreatorProfileItem::query()->create([
            'user_id' => $owner->id,
            'kind' => $kind,
            'label' => $data['label'],
            'detail' => $data['detail'] ?? null,
            'url' => $kind === 'link' ? $data['url'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_visible' => (bool) $data['is_visible'],
        ]);
        AuditEvent::record('profile.item.created', $request->user(), $request);
        return back()->with('status', 'Profile section item added.');
    }

    public function updateSelf(Request $request, CreatorProfileItem $item): RedirectResponse
    {
        return $this->update($request, null, $item);
    }

    public function updateAdmin(Request $request, User $user, CreatorProfileItem $item): RedirectResponse
    {
        return $this->update($request, $user, $item);
    }

    private function update(Request $request, ?User $user, CreatorProfileItem $item): RedirectResponse
    {
        $owner = $this->owner($request, $user);
        abort_unless((int) $item->user_id === (int) $owner->id, 404);
        $data = $this->validateFields($request, $item->kind);
        $item->update([
            'label' => $data['label'],
            'detail' => $data['detail'] ?? null,
            'url' => $item->kind === 'link' ? $data['url'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_visible' => (bool) $data['is_visible'],
        ]);
        AuditEvent::record('profile.item.updated', $request->user(), $request);
        return back()->with('status', 'Profile section item updated.');
    }

    public function destroySelf(Request $request, CreatorProfileItem $item): RedirectResponse
    {
        return $this->destroy($request, null, $item);
    }

    public function destroyAdmin(Request $request, User $user, CreatorProfileItem $item): RedirectResponse
    {
        return $this->destroy($request, $user, $item);
    }

    private function destroy(Request $request, ?User $user, CreatorProfileItem $item): RedirectResponse
    {
        $owner = $this->owner($request, $user);
        abort_unless((int) $item->user_id === (int) $owner->id, 404);
        $item->delete();
        AuditEvent::record('profile.item.deleted', $request->user(), $request);
        return back()->with('status', 'Profile section item removed.');
    }
}
