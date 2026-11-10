<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\CreatorProfile;
use App\Models\CreatorProfileItem;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\CreatorBadgeAwards;
use App\Support\ManualAccountReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ProfileEditorController extends Controller
{
    public function editSelf(Request $request): View
    {
        $workspace = $request->routeIs('studio.profile.edit', 'localized.*.studio.profile.edit') ? 'creator' : 'account';
        return $this->render($request->user(), $workspace, false);
    }

    public function editAdmin(Request $request, User $user): View
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        return $this->render($user, 'admin', true);
    }

    private function render(User $owner, string $workspace, bool $admin): View
    {
        $owner->load(['userProfile', 'creatorProfile']);
        $items = CreatorProfileItem::query()->where('user_id', $owner->id)
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('kind');

        return view('account.profile-editor', [
            'member' => $owner,
            'personal' => $owner->userProfile,
            'creatorProfile' => $owner->creatorProfile,
            'items' => $items,
            'workspace' => $workspace,
            'adminProfile' => $admin,
            'awards' => $owner->isCreator() ? CreatorBadgeAwards::earned($owner) : collect(),
        ]);
    }

    public function updateSelf(Request $request): RedirectResponse
    {
        return $this->save($request, $request->user());
    }

    public function updateAdmin(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        return $this->save($request, $user);
    }

    private function save(Request $request, User $owner): RedirectResponse
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'public_location' => ['nullable', 'string', 'max:120'],
            'availability' => ['nullable', Rule::in(['available', 'busy', 'unavailable'])],
        ];
        if ($owner->isCreator()) {
            $rules += [
                'headline' => ['required', 'string', 'min:8', 'max:160'],
                'biography' => ['required', 'string', 'min:80', 'max:5000'],
                'website_url' => ['nullable', 'url', 'starts_with:https://', 'max:400'],
                'is_public' => ['required', 'boolean'],
            ];
        }
        $data = $request->validate($rules);

        // Store only on the public disk with a server-generated random filename.
        $newAvatar = $request->hasFile('avatar')
            ? $request->file('avatar')->store('profile-avatars', 'public') : null;
        $previousAvatar = $owner->userProfile?->avatar_path;
        $removeAvatar = (bool) ($data['remove_avatar'] ?? false);

        try {
            DB::transaction(function () use ($request, $owner, $data, $newAvatar, $removeAvatar): void {
                $locked = User::query()->lockForUpdate()->findOrFail($owner->id);
                if ($locked->name !== $data['name']) {
                    ManualAccountReview::invalidateForChange(
                        $request, $locked, 'Account holder changed public name; a new review is required.'
                    );
                }
                $locked->name = $data['name'];
                $locked->save();

                $personal = UserProfile::query()->firstOrNew(['user_id' => $locked->id]);
                $personal->public_location = $data['public_location'] ?? null;
                $personal->availability = $data['availability'] ?? null;
                if ($newAvatar) {
                    $personal->avatar_path = $newAvatar;
                } elseif ($removeAvatar) {
                    $personal->avatar_path = null;
                }
                $personal->save();

                if ($locked->isCreator()) {
                    CreatorProfile::query()->updateOrCreate(
                        ['user_id' => $locked->id],
                        [
                            'headline' => $data['headline'],
                            'biography' => $data['biography'],
                            'website_url' => $data['website_url'] ?? null,
                            'is_public' => (bool) $data['is_public'],
                        ]
                    );
                }
                AuditEvent::record('profile.public.updated', $request->user(), $request);
            });
        } catch (\Throwable $error) {
            if ($newAvatar) Storage::disk('public')->delete($newAvatar);
            throw $error;
        }

        if (($newAvatar || $removeAvatar) && $previousAvatar
            && str_starts_with($previousAvatar, 'profile-avatars/')) {
            Storage::disk('public')->delete($previousAvatar);
        }

        return back()->with('status', 'Profile changes saved. Public information follows the visibility setting.');
    }
}
