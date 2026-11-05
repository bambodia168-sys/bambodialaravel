<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ContractConsent;
use App\Models\ContractDispute;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use App\Models\PrivacyRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ComplianceRegisterController extends Controller
{
    public function index(Request $request): View
    {
        $receipts=PolicyAcknowledgement::query()
            ->with(['user:id,name,public_id','publication:id,policy_id,revision,title,sha256,created_at'])
            ->latest('id')->paginate(10);
        $totalPublications=PolicyPublication::query()->count();
        $totalReceipts=PolicyAcknowledgement::query()->count();
        $contractAcknowledgements=ContractConsent::query()->count();
        $openDisputes=ContractDispute::query()->whereIn('status',['open','under_review'])->count();
        $pendingPrivacyRequests=PrivacyRequest::query()->whereIn('status',['submitted','in_review'])->count();
        $publications=PolicyPublication::query()->with('publishedBy:id,name')
            ->orderByDesc('id')->limit(10)->get();
        return view('dashboard.compliance-register',compact(
            'receipts','totalPublications','totalReceipts','contractAcknowledgements','openDisputes','pendingPrivacyRequests','publications'
        ));
    }
}
