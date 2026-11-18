<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Support\SystemSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SystemSecurityController extends Controller
{
    public function index(): View
    {
        return view('dashboard.system-security', ['states' => SystemSecurity::states(array_keys(SystemSecurity::CONTROLS))]);
    }

    public function update(Request $request, string $control): RedirectResponse
    {
        abort_unless(isset(SystemSecurity::CONTROLS[$control]), 404);
        $request->validate(['enabled' => ['required', 'boolean']]);
        if ($control === 'passkeys' && $request->boolean('enabled')) {
            abort_unless(parse_url(config('app.url'), PHP_URL_SCHEME) === 'https'
                || app()->environment(['local', 'testing']), 422, 'Configure an HTTPS application URL to enable passkeys.');
        }

        DB::transaction(function () use ($request, $control): void {
            SiteSetting::query()->updateOrCreate(
                ['key' => SystemSecurity::key($control)],
                ['value' => $request->boolean('enabled') ? '1' : '0', 'updated_by' => $request->user()->id],
            );
            AuditEvent::record('site.setting.changed', $request->user(), $request);
        });

        return back()->with('status', SystemSecurity::CONTROLS[$control][0].' updated.');
    }
}
