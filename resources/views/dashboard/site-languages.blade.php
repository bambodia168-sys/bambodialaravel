@extends('layouts.dashboard')
@section('title','Language Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Appearance &amp; Content / Localization</div><h1>Language Manager</h1><p>English is the permanent source. New languages copy its terms; untranslated words fall back to English. Existing translation files are preserved.</p></div>
<section class="panel">
<div class="panel-head"><div><h2>Languages <span class="count">{{ $languages->count() }}</span></h2><p>Enable, disable and manage available language versions.</p></div>
@if(count($available))<button class="btn primary compact" type="button" data-modal-open="lang-add"><x-icon name="plus" size="16"/> Add</button>@endif</div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Language</th><th>Code</th><th>Status</th><th>Edit</th><th>Action</th></tr></thead><tbody>
@foreach($languages as $language)<tr>
<td><strong>{{ $language->name }}</strong></td><td class="mono">{{ $language->code }}</td>
<td><span class="role-pill">{{ $language->code==='en'?'Default English':($language->is_enabled?'Enabled':'Disabled') }}</span></td>
<td><a class="btn subtle compact" href="{{ route('dashboard.languages.index',['locale'=>$language->code]) }}">Translate</a></td>
<td>@if($language->code!=='en')
<x-row-action-menu :id="'lang-'.$language->code" :label="$language->name">
<form method="post" action="{{ route('dashboard.languages.update',$language) }}">@csrf @method('PATCH')<input type="hidden" name="is_enabled" value="{{ $language->is_enabled?'0':'1' }}"><button type="submit"><x-icon name="settings" size="16"/> {{ $language->is_enabled?'Disable':'Enable' }}</button></form>
<form method="post" action="{{ route('dashboard.languages.destroy',$language) }}">@csrf @method('DELETE')<button type="submit"><x-icon name="trash-2" size="16"/> Delete</button></form>
</x-row-action-menu>@else English source @endif</td>
</tr>@endforeach
</tbody></table></div>
</section>
<section class="panel" style="margin-top:20px">
<div class="panel-head"><div><h2>{{ \App\Models\SiteLanguage::CATALOG[$selected]??strtoupper($selected) }} translations <span class="count">{{ $termPages->total() }}</span></h2><p>Terms are translated individually. Blank translations restore the English fallback.</p></div>
<form method="get" action="{{ route('dashboard.languages.index') }}"><select name="locale" aria-label="Select language" onchange="this.form.submit()">
@foreach($languages as $language)<option value="{{ $language->code }}" @selected($selected===$language->code)>{{ $language->name }}</option>@endforeach
</select></form></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Term</th><th>English</th><th>Current</th><th>Status</th><th>Action</th></tr></thead><tbody>
@foreach($termPages as $key=>$english)
@php
  $override=$entries->get($key);
  $current=$override ? ($override->is_translated?$override->translation_value:$english) : ($existing[$key]??$english);
  $status=$override ? ($override->is_translated?'Customized':'English fallback') : (isset($existing[$key])?'Built-in':'English fallback');
  $dialogId='translation-'.str_replace(['.','_'],'-',$key);
@endphp
<tr><td class="mono">{{ $key }}</td><td>{{ $english }}</td><td>{{ $current }}</td><td><span class="role-pill">{{ $status }}</span></td><td>
@if($selected!=='en')
<x-row-action-menu :id="'term-'.$key" :label="$key"><button type="button" data-modal-open="{{ $dialogId }}"><x-icon name="pencil" size="16"/> Edit</button></x-row-action-menu>
<dialog class="config-dialog wide-dialog" id="{{ $dialogId }}"><form method="post" action="{{ route('dashboard.languages.translate',$selected) }}">
@csrf @method('PATCH')<input type="hidden" name="key" value="{{ $key }}"><h2>{{ $key }}</h2><p>English: {{ $english }}</p>
<label for="value-{{ $dialogId }}">Translation</label><textarea id="value-{{ $dialogId }}" name="value" maxlength="1500" rows="4" placeholder="Leave empty for English">{{ $override?->is_translated?$override->translation_value:($existing[$key]??'') }}</textarea>
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save</button></div></form></dialog>
@else Source only @endif
</td></tr>
@endforeach
</tbody></table></div><x-pagination :paginator="$termPages"/>
</section>
@if(count($available))
<dialog class="config-dialog wide-dialog" id="lang-add"><form method="post" action="{{ route('dashboard.languages.store') }}">
@csrf <h2>Add language</h2><p>A complete English dictionary is copied before translation.</p>
<label for="language-code">Language</label><select id="language-code" name="code" required>@foreach($available as $code=>$name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Add</button></div></form></dialog>
@endif
@endsection
