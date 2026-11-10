<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\AuditEvent;
use App\Models\SiteLanguage;
use App\Models\SiteSetting;
use App\Models\SiteTranslationEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SiteLanguageController extends Controller
{
    private function english(): array
    {
        return require lang_path('en/ui.php');
    }

    public function index(Request $request): View
    {
        $languages=SiteLanguage::query()->orderBy('code')->get();
        $selected=(string)$request->query('locale','en');
        abort_unless($languages->contains('code',$selected), 404);
        $source=$this->english();
        $entries=SiteTranslationEntry::query()->where('language_code',$selected)
            ->get()->keyBy('translation_key');
        $targetFile=lang_path($selected.'/ui.php');
        $existing=is_file($targetFile) ? require $targetFile : [];
        $available=array_diff_key(SiteLanguage::CATALOG,$languages->keyBy('code')->all());

        return view('dashboard.site-languages', compact(
            'languages','selected','source','entries','existing','available'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $code=$request->validate(['code'=>['required',Rule::in(array_keys(SiteLanguage::CATALOG))]])['code'];
        abort_if($code==='en' || SiteLanguage::query()->whereKey($code)->exists(),422,'Language already configured.');
        DB::transaction(function () use ($request,$code): void {
            SiteLanguage::query()->create([
                'code'=>$code, 'name'=>SiteLanguage::CATALOG[$code], 'is_enabled'=>true,
            ]);
            foreach ($this->english() as $key=>$value) {
                SiteTranslationEntry::query()->create([
                    'language_code'=>$code, 'translation_key'=>$key,
                    'translation_value'=>$value, 'is_translated'=>false,
                ]);
            }
            AuditEvent::record('site.language.added',$request->user(),$request);
        });
        return redirect()->route('dashboard.languages.index',['locale'=>$code])
            ->with('status','English terms copied. Edit translations to publish localized wording; missing terms fall back to English.');
    }

    public function update(Request $request, SiteLanguage $language): RedirectResponse
    {
        $data=$request->validate(['is_enabled'=>['required','boolean']]);
        abort_if($language->code==='en' && ! $data['is_enabled'],422,'English is always enabled.');
        if (! $data['is_enabled'] && SiteSetting::setting('site.default_locale')===$language->code) {
            abort(422,'Choose another default guest language before disabling this one.');
        }
        $language->update(['is_enabled'=>(bool)$data['is_enabled']]);
        AuditEvent::record('site.language.updated',$request->user(),$request);
        return back()->with('status','Language status updated.');
    }

    public function destroy(Request $request, SiteLanguage $language): RedirectResponse
    {
        abort_if($language->code==='en',403,'English cannot be removed.');
        abort_if(SiteSetting::setting('site.default_locale')===$language->code,422,
            'Change the default guest language before deleting this one.');
        $language->delete(); // Cascade all target-language translations and typography overrides.
        AuditEvent::record('site.language.deleted',$request->user(),$request);
        return redirect()->route('dashboard.languages.index')->with('status','Language and its custom translations removed.');
    }

    public function translate(Request $request, SiteLanguage $language): RedirectResponse
    {
        abort_if($language->code==='en',403,'English source strings are maintained in the application.');
        $data=$request->validate([
            'key'=>['required','string','max:130',Rule::in(array_keys($this->english()))],
            'value'=>['nullable','string','max:1500'],
        ]);
        $value=trim((string)($data['value']??''));
        SiteTranslationEntry::query()->updateOrCreate(
            ['language_code'=>$language->code,'translation_key'=>$data['key']],
            ['translation_value'=>$value!==''?$value:null,'is_translated'=>$value!=='']
        );
        AuditEvent::record('site.translation.updated',$request->user(),$request);
        return back()->with('status',$value!==''?'Translation saved.':'Translation reset to English fallback.');
    }
}
