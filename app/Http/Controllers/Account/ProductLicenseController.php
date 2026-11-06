<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ProductLicense;
use App\Support\ProductLicenseLifecycle;
use App\Support\ProductLicenseSupport;
use App\Models\AccountNotification;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Illuminate\Http\Response;

class ProductLicenseController extends Controller
{
    public function index(Request $request): View
    {
        $licenses=ProductLicense::query()->where('user_id',$request->user()->id)
            ->with(['product:id,title,type,user_id,public_id,status',
                'version:id,marketplace_listing_id,version,status',
                'definition:id,name,version','entitlement:id,status,expires_at,revoked_at',
                'activations','events.actor:id,name','supportRequests:id,product_license_id,term,status,created_at'])
            ->latest()->paginate(10);
        return view('account.licenses',compact('licenses'));
    }

    public function reveal(Request $request, ProductLicense $license): Response
    {
        $this->owner($request,$license);
        $request->validate(['password'=>['required','string']]);
        abort_unless(Hash::check((string)$request->input('password'),(string)$request->user()->password),403,
            'Password verification failed.');
        abort_if($license->status === 'revoked',403,'This license is revoked.');
        $license->load('product');
        $secret=$license->key_ciphertext;
        return response()->view('account.license-key',compact('license','secret'))
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Referrer-Policy','no-referrer');
    }

    public function changeDomain(Request $request, ProductLicense $license): RedirectResponse
    {
        $this->owner($request,$license);
        $data=$request->validate([
            'domain'=>['required','string','max:253'],
            'password'=>['required','string'],
        ]);
        abort_unless(Hash::check($data['password'],(string)$request->user()->password),403);
        $domain=ProductLicenseLifecycle::domain($data['domain']);
        ProductLicenseLifecycle::changeDomain($license,$domain,$request->user(),'Customer-initiated transfer');
        AuditEvent::record('license.instance.domain.changed',$request->user(),$request);
        return back()->with('status','Previous domain deactivated. Activate the new installation using your license key.');
    }

    public function requestSupport(Request $request, ProductLicense $license): RedirectResponse
    {
        $this->owner($request,$license);
        $data=$request->validate(['term'=>['required',Rule::in(array_keys(ProductLicenseSupport::TERMS))]]);
        $renewal=ProductLicenseSupport::request($license,$request->user(),$data['term']);
        foreach (User::query()->where('role','super_admin')->limit(20)->get(['id']) as $reviewer) {
            AccountNotification::deliver(
                (int)$reviewer->id,'license.support.requested','Product support renewal requested',
                'A customer requested renewal of support for an issued product license.',
                '/dashboard/license-manager'
            );
        }
        AuditEvent::record('license.support.requested',$request->user(),$request);
        return back()->with('status','Support request submitted for review. Your license remains lifetime.');
    }

    private function owner(Request $request, ProductLicense $license): void
    {
        abort_unless((int)$license->user_id === (int)$request->user()->id,404);
    }
}
