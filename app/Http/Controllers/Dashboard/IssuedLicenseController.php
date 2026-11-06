<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\ProductEntitlement;
use App\Models\ProductLicense;
use App\Support\ProductLicenseLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IssuedLicenseController extends Controller
{
    public function issue(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'super_admin',403,
            'Only the platform owner may issue complimentary, non-payment licenses.');

        $data=$request->validate([
            'product_entitlement_id'=>['required','integer',Rule::exists('product_entitlements','id')],
            'tier'=>['required',Rule::in(['regular','extended'])],
            'reason'=>['required','string','min:15','max:500'],
            'confirm_complimentary'=>['required','accepted'],
        ]);

        $license=ProductLicenseLifecycle::issueManual(
            ProductEntitlement::query()->findOrFail($data['product_entitlement_id']),
            $data['tier'], $request->user(), $data['reason']
        );
        AccountNotification::deliver(
            (int)$license->user_id,'license.issued','Product license issued',
            'A complimentary license linked to an approved digital product is available in My Licenses.',
            '/account/licenses'
        );
        AuditEvent::record('license.instance.issued',$request->user(),$request);
        return redirect()->route('dashboard.licenses.index')
            ->with('status','Complimentary product license issued. No purchase or payment was recorded.');
    }

    public function status(Request $request, ProductLicense $license): RedirectResponse
    {
        $data=$request->validate([
            'action'=>['required',Rule::in(['suspend','activate','revoke'])],
            'reason'=>['required','string','min:12','max:500'],
            'confirm_change'=>['required','accepted'],
        ]);
        ProductLicenseLifecycle::status($license,$data['action'],$request->user(),$data['reason']);
        AccountNotification::deliver((int)$license->user_id,'license.status.changed','Product license updated',
            'Your product license status was changed to '.$data['action'].'. Review its details in My Licenses.',
            '/account/licenses');
        AuditEvent::record('license.instance.status.changed',$request->user(),$request);
        return back()->with('status','License status updated with an audit record.');
    }

    public function upgrade(Request $request, ProductLicense $license): RedirectResponse
    {
        $data=$request->validate([
            'reason'=>['required','string','min:12','max:500'],
            'confirm_manual_upgrade'=>['required','accepted'],
        ]);
        ProductLicenseLifecycle::upgrade($license,$request->user(),$data['reason']);
        AccountNotification::deliver((int)$license->user_id,'license.status.changed','License tier upgraded',
            'Your product license was manually upgraded to Extended by an administrator.',
            '/account/licenses');
        AuditEvent::record('license.instance.upgraded',$request->user(),$request);
        return back()->with('status','Manual upgrade recorded. No payment was processed.');
    }

    public function changeDomain(Request $request, ProductLicense $license): RedirectResponse
    {
        $data=$request->validate([
            'domain'=>['nullable','string','max:253'],
            'reason'=>['required','string','min:12','max:500'],
        ]);
        $domain=filled($data['domain']??null) ? ProductLicenseLifecycle::domain($data['domain']) : null;
        ProductLicenseLifecycle::changeDomain($license,$domain,$request->user(),$data['reason']);
        AuditEvent::record('license.instance.domain.changed',$request->user(),$request);
        return back()->with('status','Active domain released. The new installation must activate with its license key.');
    }

    public function rotate(Request $request, ProductLicense $license): RedirectResponse
    {
        $data=$request->validate([
            'reason'=>['required','string','min:12','max:500'],
            'confirm_rotate'=>['required','accepted'],
        ]);
        ProductLicenseLifecycle::rotate($license,$request->user(),$data['reason']);
        AccountNotification::deliver((int)$license->user_id,'license.status.changed','License key rotated',
            'Your previous installation key has been invalidated. Retrieve the replacement from My Licenses.',
            '/account/licenses');
        AuditEvent::record('license.instance.key.rotated',$request->user(),$request);
        return back()->with('status','Key rotated. The old key can no longer authenticate.');
    }
}
