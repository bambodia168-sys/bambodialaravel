<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketplaceManagerController extends Controller
{
    public function index(Request $request, string $type): View
    {
        abort_unless(in_array($type,['project','freelance','service'],true),404);
        $status=(string)$request->query('status','all');
        abort_unless(in_array($status,['all','pending','published','rejected','draft','withdrawn'],true),404);
        $search=mb_substr(trim((string)$request->query('q','')),0,120);
        $listings=MarketplaceListing::query()->where('type',$type)
            ->with(['creator:id,public_id,name','category:id,name'])
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->when($search!=='',fn($q)=>$q->where(function($q)use($search){
                $q->where('title','like','%'.$search.'%')->orWhere('public_id','like','%'.$search.'%');
            }))
            ->latest()->paginate(10)->withQueryString();
        return view('dashboard.marketplace',compact('listings','type','status','search'));
    }
}
