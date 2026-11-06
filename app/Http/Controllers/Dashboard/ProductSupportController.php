<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ProductLicenseSupportRequest;
use App\Support\ProductLicenseSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductSupportController extends Controller
{
    public function review(Request $request, ProductLicenseSupportRequest $renewal): RedirectResponse
    {
        abort_unless($request->user()->role === 'super_admin',403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approve','reject'])],
            'reason'=>['required','string','min:15','max:500'],
            'confirm_manual'=>['required','accepted'],
        ]);
        ProductLicenseSupport::review($renewal,$request->user(),$data['decision'],$data['reason']);
        AuditEvent::record('license.support.reviewed',$request->user(),$request);
        return back()->with('status','Support request '.$data['decision'].'d and recorded in license history.');
    }
}
