<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('public_id', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('dashboard.users', ['users' => $users, 'search' => $search, 'roles' => User::ROLES]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);
        abort_if($user->is($request->user()) && $data['role'] !== 'super_admin', 422, 'You cannot change your own administrator role.');
        if ($user->role === 'super_admin' && $data['role'] !== 'super_admin') {
            abort_if(User::query()->where('role', 'super_admin')->count() < 2, 422, 'The last super administrator cannot be removed.');
        }

        $user->update(['role' => $data['role']]);

        return back()->with('status', 'User role updated.');
    }
}
