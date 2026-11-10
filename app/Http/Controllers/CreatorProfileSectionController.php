<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\CreatorProfileItem;
use App\Models\CreatorProfileSection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CreatorProfileSectionController extends Controller
{
    private function owner(Request $request, ?User $member): User
    {
        if ($member !== null) {
            abort_unless($request->user()->role === 'super_admin', 403);
            return $member;
        }
        return $request->user();
    }

    private function section(User $owner, CreatorProfileSection $section): CreatorProfileSection
    {
        abort_unless((int) $section->user_id === (int) $owner->id, 404);
        return $section;
    }

    public function storeSelf(Request $request): RedirectResponse { return $this->store($request, null); }
    public function storeAdmin(Request $request, User $user): RedirectResponse { return $this->store($request, $user); }

    private function store(Request $request, ?User $member): RedirectResponse
    {
        $owner = $this->owner($request, $member);
        $data = $request->validate([
            'title' => ['required','string','min:2','max:100'],
            'description' => ['nullable','string','max:200'],
        ]);
        DB::transaction(function () use ($owner, $data, $request): void {
            CreatorProfileSection::ensureDefaults($owner);
            $count = CreatorProfileSection::query()->where('user_id', $owner->id)
                ->where('section_key', 'custom')->lockForUpdate()->count();
            abort_if($count >= 8, 422, 'Maximum eight additional profile sections.');
            $last = CreatorProfileSection::query()->where('user_id', $owner->id)->max('sort_order') ?? 0;
            CreatorProfileSection::query()->create([
                'user_id' => $owner->id, 'section_key' => 'custom',
                'title' => $data['title'], 'description' => $data['description'] ?? null,
                'sort_order' => min(65000, $last + 10), 'is_visible' => true,
            ]);
            AuditEvent::record('profile.section.created', $request->user(), $request);
        });
        return back()->with('status', 'Custom profile section added.');
    }

    public function updateSelf(Request $request, CreatorProfileSection $section): RedirectResponse { return $this->update($request, null, $section); }
    public function updateAdmin(Request $request, User $user, CreatorProfileSection $section): RedirectResponse { return $this->update($request, $user, $section); }

    private function update(Request $request, ?User $member, CreatorProfileSection $section): RedirectResponse
    {
        $owner = $this->owner($request, $member);
        $this->section($owner, $section);
        $data = $request->validate([
            'title' => ['required','string','min:2','max:100'],
            'description' => ['nullable','string','max:200'],
            'is_visible' => ['required','boolean'],
        ]);
        $section->update($data);
        AuditEvent::record('profile.section.updated', $request->user(), $request);
        return back()->with('status', 'Profile section updated.');
    }

    public function destroySelf(Request $request, CreatorProfileSection $section): RedirectResponse { return $this->destroy($request, null, $section); }
    public function destroyAdmin(Request $request, User $user, CreatorProfileSection $section): RedirectResponse { return $this->destroy($request, $user, $section); }

    private function destroy(Request $request, ?User $member, CreatorProfileSection $section): RedirectResponse
    {
        $owner = $this->owner($request, $member);
        $this->section($owner, $section);
        if ($section->section_key === 'custom') {
            $section->delete();
        } else {
            $section->update(['is_visible' => false]);
        }
        AuditEvent::record('profile.section.deleted', $request->user(), $request);
        return back()->with('status', 'Section removed from public profile. Built-in sections may be restored.');
    }

    public function orderSelf(Request $request): JsonResponse { return $this->order($request, null); }
    public function orderAdmin(Request $request, User $user): JsonResponse { return $this->order($request, $user); }

    private function order(Request $request, ?User $member): JsonResponse
    {
        $owner = $this->owner($request, $member);
        $data = $request->validate(['ids' => ['required','array','min:3','max:11'], 'ids.*' => ['required','integer','distinct']]);
        DB::transaction(function () use ($owner, $request, $data): void {
            CreatorProfileSection::ensureDefaults($owner);
            $available = CreatorProfileSection::query()->where('user_id', $owner->id)
                ->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $submitted = array_map('intval', $data['ids']);
            $sorted = $submitted;
            sort($sorted);
            abort_unless($sorted === $available, 422, 'Profile section order is incomplete or contains foreign IDs.');
            foreach ($submitted as $index => $id) {
                CreatorProfileSection::query()->where('user_id', $owner->id)
                    ->whereKey($id)->update(['sort_order' => ($index + 1) * 10]);
            }
            AuditEvent::record('profile.section.reordered', $request->user(), $request);
        });
        return response()->json(['ok' => true]);
    }

    public function orderItemsSelf(Request $request): JsonResponse { return $this->orderItems($request, null); }
    public function orderItemsAdmin(Request $request, User $user): JsonResponse { return $this->orderItems($request, $user); }

    private function orderItems(Request $request, ?User $member): JsonResponse
    {
        $owner = $this->owner($request, $member);
        $data = $request->validate([
            'section_id' => ['required','integer','min:1'],
            'ids' => ['required','array','max:20'],
            'ids.*' => ['required','integer','distinct'],
        ]);
        $section = CreatorProfileSection::query()->where('user_id', $owner->id)
            ->findOrFail($data['section_id']);
        DB::transaction(function () use ($owner, $request, $section, $data): void {
            $query = CreatorProfileItem::query()->where('user_id', $owner->id);
            if ($section->section_key === 'custom') {
                $query->where('kind', 'custom')->where('profile_section_id', $section->id);
            } else {
                $query->where('kind', $section->section_key)->whereNull('profile_section_id');
            }
            $available = (clone $query)->lockForUpdate()->pluck('id')
                ->map(fn ($id) => (int) $id)->sort()->values()->all();
            $submitted = array_map('intval', $data['ids']);
            $sorted = $submitted;
            sort($sorted);
            abort_unless($sorted === $available, 422, 'Item order is incomplete or contains foreign IDs.');
            foreach ($submitted as $index => $id) {
                (clone $query)->whereKey($id)->update(['sort_order' => ($index + 1) * 10]);
            }
            AuditEvent::record('profile.item.reordered', $request->user(), $request);
        });
        return response()->json(['ok' => true]);
    }
}
