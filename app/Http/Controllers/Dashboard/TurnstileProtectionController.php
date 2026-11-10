<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\TurnstileBotProtection;
use App\Support\Security\TurnstileVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class TurnstileProtectionController extends Controller
{
    public function index(): View
    {
        return view('dashboard.turnstile-protection', [
            'config'=>TurnstileBotProtection::current(),
        ]);
    }

    public function configure(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'site_key'=>['required','string','min:8','max:128','regex:/\A[0-9A-Za-z_-]+\z/'],
            'secret_key'=>['nullable','string','min:8','max:256'],
        ]);

        $existing=TurnstileBotProtection::current();
        $secret=trim((string)($data['secret_key']??'')) ?: ($existing?->secret_key??'');
        abort_unless($secret!=='',422,'A Turnstile secret key is required for a new connection.');

        TurnstileBotProtection::query()->updateOrCreate(['id'=>1],[
            'site_key'=>$data['site_key'],'secret_key'=>$secret,
            'is_enabled'=>false,'tested_at'=>null,
            'protect_login'=>$existing?->protect_login??true,
            'protect_registration'=>$existing?->protect_registration??true,
            'protect_password_reset'=>$existing?->protect_password_reset??true,
            'updated_by'=>$request->user()->id,
        ]);
        AuditEvent::record('security.turnstile.configured',$request->user(),$request);

        return back()->with('status',
            'Turnstile keys saved encrypted. Protection remains disabled until you pass a test and enable it.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $config=TurnstileBotProtection::current();
        abort_unless($config,404);

        if (! TurnstileVerifier::verifyToken($config,$request,'admin_test')) {
            return back()->withErrors(['turnstile'=>'Turnstile verification failed. Check the hostname and keys.']);
        }
        $config->update(['tested_at'=>now(),'updated_by'=>$request->user()->id]);
        AuditEvent::record('security.turnstile.tested',$request->user(),$request);
        return back()->with('status','Turnstile site and secret keys verified for this host.');
    }

    public function status(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'is_enabled'=>['required','boolean'],
            'protect_login'=>['required','boolean'],
            'protect_registration'=>['required','boolean'],
            'protect_password_reset'=>['required','boolean'],
        ]);
        $config=TurnstileBotProtection::current();
        abort_unless($config,404);

        if ($data['is_enabled']) {
            abort_unless(
                $config->tested_at && $config->tested_at->greaterThan(now()->subMinutes(30)),
                422,'Complete a successful Turnstile test within 30 minutes before enabling protection.'
            );
            abort_unless(
                $data['protect_login'] || $data['protect_registration'] || $data['protect_password_reset'],
                422,'Select at least one action to protect.'
            );
        }
        $config->update([
            'is_enabled'=>(bool)$data['is_enabled'],
            'protect_login'=>(bool)$data['protect_login'],
            'protect_registration'=>(bool)$data['protect_registration'],
            'protect_password_reset'=>(bool)$data['protect_password_reset'],
            'updated_by'=>$request->user()->id,
        ]);
        AuditEvent::record('security.turnstile.status.changed',$request->user(),$request);
        return back()->with('status',$config->is_enabled
            ? 'Turnstile protection enabled for selected forms.'
            : 'Turnstile protection disabled.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $config=TurnstileBotProtection::current();
        abort_unless($config,404);
        $config->delete();
        AuditEvent::record('security.turnstile.removed',$request->user(),$request);
        return back()->with('status','Turnstile keys removed; original authentication remains available.');
    }
}
