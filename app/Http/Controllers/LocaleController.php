<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SetLocale::SUPPORTED)]]);
        $request->session()->put('locale', $data['locale']);
        if ($request->user()) {
            $request->user()->update(['locale' => $data['locale']]);
        }

        return back();
    }
}
