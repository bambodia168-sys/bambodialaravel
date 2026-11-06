<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\ProductLicense;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductLicensesController extends Controller
{
    public function index(Request $request): View
    {
        $licenses=ProductLicense::query()
            ->whereHas('product',fn($q)=>$q->where('user_id',$request->user()->id)->where('type','product'))
            ->with(['product:id,title,public_id,type,user_id',
                'version:id,marketplace_listing_id,version',
                'definition:id,name,version',
                'customer:id,public_id',
                'activations:id,product_license_id,domain,status,activated_at',
                'events:id,product_license_id,action,domain,created_at'])
            ->latest()->paginate(12);
        return view('account.creator-licenses',compact('licenses'));
    }
}
