<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ServiceInquiry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceManagerController extends Controller
{
    public function index(Request $request): View
    {
        $status=(string)$request->query('status','all');
        abort_unless(in_array($status,['all','open','closed'],true),404);
        $inquiries=ServiceInquiry::query()
            ->with(['listing:id,title,type,public_id','client:id,name,public_id','creator:id,name,public_id'])
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->latest('updated_at')->paginate(10)->withQueryString();
        return view('dashboard.inquiries',compact('inquiries','status'));
    }
}
