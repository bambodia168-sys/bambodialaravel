<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $id = $request->user()->public_id;
        if ($request->server('QUERY_STRING', '') !== '='.$id) {
            return redirect('/account?='.$id);
        }

        return view('account.index', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'locale' => ['required', Rule::in(['en', 'zh', 'km', 'ko', 'ja', 'th', 'vi', 'de'])],
        ]);

        $request->user()->update($data);

        return redirect('/account?='.$request->user()->public_id)
            ->with('status', 'Your profile was updated.');
    }

    public function studio(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $id = $request->user()->public_id;
        if ($request->server('QUERY_STRING', '') !== '='.$id) {
            return redirect('/studio?='.$id);
        }

        return view('account.studio', ['user' => $request->user()]);
    }
}
