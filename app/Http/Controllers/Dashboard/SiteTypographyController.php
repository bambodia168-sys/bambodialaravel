<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteLanguage;
use App\Models\SiteTypographyRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SiteTypographyController extends Controller
{
    public function index(): View
    {
        return view('dashboard.site-typography', [
            'rules'=>SiteTypographyRule::query()->orderBy('language_code')
                ->orderBy('element')->paginate(10)->withQueryString(),
            'languages'=>SiteLanguage::query()->orderBy('code')->get(),
        ]);
    }

    private function validated(Request $request, ?SiteTypographyRule $rule=null): array
    {
        $data=$request->validate([
            'language_code'=>['required',Rule::in(array_keys(SiteLanguage::CATALOG))],
            'element'=>['required',Rule::in(array_keys(SiteTypographyRule::ELEMENTS))],
            'font_family'=>['required',Rule::in(array_keys(SiteTypographyRule::FONTS))],
            'font_size'=>['required','integer','between:10,72'],
            'font_weight'=>['required','integer',Rule::in([400,500,600,700,800])],
            'line_height'=>['required','numeric','between:1,2.5'],
            'alignment'=>['required',Rule::in(['left','center','right','justify'])],
        ]);
        abort_unless(SiteLanguage::query()->whereKey($data['language_code'])->exists(),422,'Language not installed.');
        abort_if(SiteTypographyRule::query()->where('language_code',$data['language_code'])
            ->where('element',$data['element'])->when($rule,fn ($q)=>$q->where('id','!=',$rule->id))
            ->exists(),422,'This element already has a typography rule for that language.');
        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        SiteTypographyRule::query()->create($this->validated($request));
        AuditEvent::record('site.typography.created',$request->user(),$request);
        return back()->with('status','Language typography assigned.');
    }

    public function update(Request $request, SiteTypographyRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request,$rule));
        AuditEvent::record('site.typography.updated',$request->user(),$request);
        return back()->with('status','Typography rule updated.');
    }

    public function destroy(Request $request, SiteTypographyRule $rule): RedirectResponse
    {
        $rule->delete();
        AuditEvent::record('site.typography.deleted',$request->user(),$request);
        return back()->with('status','Typography override removed; language now inherits English or default styles.');
    }
}
