<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Models\SiteBrandAsset;
use App\Models\SiteSeoPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    public function appearance(Request $request): View { return $this->index($request,'appearance'); }
    public function system(Request $request): View { return $this->index($request,'system'); }
    public function identity(Request $request): View { return $this->index($request,'identity'); }
    public function seo(Request $request): View { return $this->index($request,'seo'); }
    public function updateIdentity(Request $request): RedirectResponse { return $this->update($request,'identity'); }
    public function updateSeo(Request $request): RedirectResponse { return $this->update($request,'seo'); }
    public function updateAppearance(Request $request): RedirectResponse { return $this->update($request,'appearance'); }
    public function updateSystem(Request $request): RedirectResponse { return $this->update($request,'system'); }

    public function index(Request $request,string $group): View
    {
        abort_unless(in_array($group,['appearance','system','identity','seo'],true),404);
        $definitions=array_filter(SiteSetting::DEFINITIONS,fn($definition)=>$definition['group']===$group);
        $saved=SiteSetting::query()->whereIn('key',array_keys($definitions))->pluck('value','key');
        if ($group === 'identity') {
            return view('dashboard.site-identity', [
                'definitions'=>$definitions,'saved'=>$saved,'group'=>$group,
                'assets'=>SiteBrandAsset::query()->get()->keyBy('slot'),
            ]);
        }
        if ($group === 'seo') {
            return view('dashboard.site-seo', [
                'definitions'=>$definitions,'saved'=>$saved,'group'=>$group,
                'pages'=>SiteSeoPage::query()->orderByDesc('updated_at')->paginate(10)->withQueryString(),
            ]);
        }
        return view('dashboard.site-settings',compact('definitions','saved','group'));
    }

    public function update(Request $request,string $group): RedirectResponse
    {
        abort_unless(in_array($group,['appearance','system','identity','seo'],true),404);
        $data=$request->validate(['key'=>['required','string',Rule::in(array_keys(SiteSetting::DEFINITIONS))]]);
        $key=$data['key'];
        $definition=SiteSetting::DEFINITIONS[$key];
        abort_unless($definition['group']===$group,403);

        $rule=match($definition['type']){
            'bool'=>['required','boolean'],
            'select'=>['required','string',Rule::in($definition['options'])],
            'email'=>['nullable','email','max:'.$definition['max']],
            'url'=>['nullable','url','starts_with:https://','max:'.$definition['max']],
            'optional_text'=>['nullable','string','max:'.$definition['max']],
            'textarea'=>['nullable','string','max:'.$definition['max']],
            default=>['required','string','min:3','max:'.$definition['max']],
        };
        $values=$request->validate(['value'=>$rule]);
        $value=(string)($values['value']??'');
        SiteSetting::query()->updateOrCreate(['key'=>$key],['value'=>$value,'updated_by'=>$request->user()->id]);
        AuditEvent::record('site.setting.changed',$request->user(),$request);
        return back()->with('status',$definition['label'].' updated.');
    }
}
