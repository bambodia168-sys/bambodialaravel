@extends('layouts.dashboard')
@section('title',$group==='appearance'?'Appearance':'System Settings')
@section('content')
<div class="page-intro">
<div class="eyebrow">{{ $group==='appearance'?'Front Customizer':'System Center' }}</div>
<h1>{{ $group==='appearance'?'Appearance':'System Settings' }}</h1>
<p>Each configuration below is connected to actual page rendering, locale selection or the public support contact. Changes are stored in the database.</p></div>
<section class="panel"><div class="panel-head"><h2>Configuration <span class="count">{{ count($definitions) }}</span></h2></div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Setting</th><th>Purpose</th><th>Current value</th><th>Action</th></tr></thead><tbody>
@foreach($definitions as $key=>$definition)
@php
  $value=(string)($saved[$key]??$definition['default']);
  $fieldId='setting-'.str_replace('.','-',$key);
@endphp
<tr>
<td><strong>{{ $definition['label'] }}</strong></td><td>{{ $definition['description'] }}</td>
<td>
@if($definition['type']==='bool')<span class="role-pill">{{ $value==='1'?'On':'Off' }}</span>
@else<span class="settings-preview">{{ $value!==''?$value:'Not configured' }}</span>@endif
</td>
<td><button class="btn subtle compact" type="button" data-modal-open="{{ $fieldId }}"><x-icon name="settings" size="16"/> Configure</button>
<dialog class="config-dialog wide-dialog" id="{{ $fieldId }}">
<form method="post" action="{{ $group==='appearance'?route('dashboard.appearance.update'):route('dashboard.system-settings.update') }}">
@csrf @method('PATCH')<input type="hidden" name="key" value="{{ $key }}">
<h2>{{ $definition['label'] }}</h2><p>{{ $definition['description'] }}</p>
<label for="{{ $fieldId }}-value">Configuration</label>
@if($definition['type']==='bool')
  <select id="{{ $fieldId }}-value" name="value"><option value="1" @selected($value==='1')>On</option><option value="0" @selected($value==='0')>Off</option></select>
@elseif($definition['type']==='select')
  <select id="{{ $fieldId }}-value" name="value">@foreach($definition['options'] as $option)<option value="{{ $option }}" @selected($value===$option)>{{ $option }}</option>@endforeach</select>
@elseif($definition['type']==='email')
  <input id="{{ $fieldId }}-value" name="value" type="email" value="{{ $value }}" maxlength="{{ $definition['max'] }}" placeholder="Optional official contact email">
@elseif($definition['max']>200)
  <textarea id="{{ $fieldId }}-value" name="value" rows="5" maxlength="{{ $definition['max'] }}" required>{{ $value }}</textarea>
@else
  <input id="{{ $fieldId }}-value" name="value" maxlength="{{ $definition['max'] }}" value="{{ $value }}" required>
@endif
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save configuration</button></div>
</form></dialog></td>
</tr>
@endforeach
</tbody></table></div>
</section>
@endsection
