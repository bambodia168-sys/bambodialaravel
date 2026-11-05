<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryManagerController extends Controller
{
    public function index(Request $request): View
    {
        $type=(string)$request->query('type','all');
        abort_unless($type==='all'||in_array($type,MarketplaceListing::TYPES,true),404);
        $categories=MarketplaceCategory::query()->withCount('listings')
            ->when($type!=='all',fn($query)=>$query->where('type',$type))
            ->orderBy('sort_order')->orderBy('name')->paginate(10)->withQueryString();
        return view('dashboard.categories',compact('categories','type'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$this->validated($request);
        $exists=MarketplaceCategory::query()->where('type',$data['type'])
            ->where('slug',\Illuminate\Support\Str::slug($data['name']))->exists();
        if($exists){return back()->withErrors(['name'=>'This category already exists for this marketplace type.']);}
        MarketplaceCategory::query()->create([...$data,'created_by'=>$request->user()->id]);
        AuditEvent::record('category.created',$request->user(),$request);
        return back()->with('status','Marketplace category created.');
    }

    public function update(Request $request, MarketplaceCategory $category): RedirectResponse
    {
        $data=$this->validated($request, $category);
        if($data['type']!==$category->type && $category->listings()->exists()){
            abort(409,'Referenced categories cannot switch marketplace type.');
        }
        $exists=MarketplaceCategory::query()->where('type',$data['type'])
            ->where('slug',\Illuminate\Support\Str::slug($data['name']))
            ->where('id','!=',$category->id)->exists();
        if($exists){return back()->withErrors(['name'=>'Category name already exists in this type.']);}
        $category->update($data);
        AuditEvent::record('category.updated',$request->user(),$request);
        return back()->with('status','Category settings updated.');
    }

    private function validated(Request $request, ?MarketplaceCategory $existing=null): array
    {
        return $request->validate([
            'type'=>['required',Rule::in(MarketplaceListing::TYPES)],
            'name'=>['required','string','min:3','max:120'],
            'description'=>['nullable','string','max:2000'],
            'is_active'=>['required','boolean'],
            'sort_order'=>['required','integer','min:0','max:100000'],
        ]);
    }
}
