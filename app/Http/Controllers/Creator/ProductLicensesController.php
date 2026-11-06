<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\ProductLicense;
use App\Models\ProductLicenseEvent;
use App\Models\User;
use App\Models\AccountNotification;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductLicensesController extends Controller
{
    public function requestReview(Request $request, ProductLicense $license): RedirectResponse
    {
        abort_unless((int)$license->product?->user_id === (int)$request->user()->id, 404);
        $data=$request->validate([
            'action'=>['required',Rule::in(['domain_review','upgrade_review','suspension_review','revocation_review'])],
            'reason'=>['required','string','min:20','max:500'],
        ]);
        // Creator can request review, never unilaterally invalidate a customer's
        // purchased rights, change domains, or inspect the secret installer key.
        ProductLicenseEvent::query()->create([
            'product_license_id'=>$license->id,
            'actor_id'=>$request->user()->id,
            'action'=>'creator.review.requested',
            'reason'=>$data['action'].': '.$data['reason'],
            'created_at'=>now(),
        ]);
        foreach (User::query()->whereIn('role',['super_admin','admin'])->limit(20)->get(['id']) as $reviewer) {
            AccountNotification::deliver(
                (int)$reviewer->id,'license.creator.review.requested','Product license review requested',
                'A product creator requested administrative license review. Check License Manager history.',
                '/dashboard/license-manager'
            );
        }
        AuditEvent::record('license.creator.review.requested',$request->user(),$request);
        return back()->with('status','License review requested. Only an administrator can change customer licensing rights.');
    }

    public function index(Request $request): View
    {
        $licenses=ProductLicense::query()
            ->whereHas('product',fn($q)=>$q->where('user_id',$request->user()->id)->where('type','product'))
            ->with(['product:id,title,public_id,type,user_id',
                'version:id,marketplace_listing_id,version',
                'definition:id,name,version',
                'customer:id,public_id',
                'activations:id,product_license_id,domain,status,activated_at',
                'events:id,product_license_id,action,domain,created_at',
                'supportRequests:id,product_license_id,term,status,created_at'])
            ->latest()->paginate(10);
        return view('account.creator-licenses',compact('licenses'));
    }
}
