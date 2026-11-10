@extends('layouts.dashboard')
@section('title','Typography')
@section('content')
<div class="page-intro"><div class="eyebrow">Appearance &amp; Content / Typography</div><h1>Typography</h1><p>Assign fonts, sizes, weights, line height and alignment per language and element. Unconfigured elements retain your existing layout and design.</p></div>
<section class="panel"><div class="panel-head"><div><h2>Language styles <span class="count">{{ $rules->total() }}</span></h2><p>Language overrides inherit English when no local rule exists.</p></div><button class="btn primary compact" type="button" data-modal-open="type-add"><x-icon name="plus" size="16"/> Add</button></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Language</th><th>Element</th><th>Font</th><th>Size</th><th>Weight</th><th>Alignment</th><th>Action</th></tr></thead><tbody>
@forelse($rules as $rule)
<tr><td>{{ \App\Models\SiteLanguage::CATALOG[$rule->language_code]??$rule->language_code }}</td><td>{{ \App\Models\SiteTypographyRule::ELEMENTS[$rule->element]??$rule->element }}</td><td>{{ \App\Models\SiteTypographyRule::FONTS[$rule->font_family]??$rule->font_family }}</td><td>{{ $rule->font_size }}px</td><td>{{ $rule->font_weight }}</td><td>{{ ucfirst($rule->alignment) }}</td><td>
<x-row-action-menu :id="'type-'.$rule->id" :label="$rule->element"><button type="button" data-modal-open="type-edit-{{ $rule->id }}"><x-icon name="pencil" size="16"/> Edit</button><form method="post" action="{{ route('dashboard.typography.destroy',$rule) }}">@csrf @method('DELETE')<button type="submit"><x-icon name="trash-2" size="16"/> Delete</button></form></x-row-action-menu>
<dialog class="config-dialog wide-dialog" id="type-edit-{{ $rule->id }}"><form method="post" action="{{ route('dashboard.typography.update',$rule) }}">@csrf @method('PATCH')<h2>Edit typography</h2>@include('dashboard.partials.site-typography-fields',['rule'=>$rule])<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save</button></div></form></dialog>
</td></tr>
@empty<tr><td class="empty-cell" colspan="7">No overrides configured. Existing fonts and layouts remain unchanged.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$rules"/></section>
<dialog class="config-dialog wide-dialog" id="type-add"><form method="post" action="{{ route('dashboard.typography.store') }}">@csrf<h2>Add typography rule</h2>@include('dashboard.partials.site-typography-fields')<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Add</button></div></form></dialog>
@endsection
