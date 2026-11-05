@extends('layouts.dashboard')
@section('title','Feature Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Platform control</div><h1>Feature Manager</h1><p>These switches are connected to actual backend access checks. Changes take effect on new requests.</p></div>
<section class="panel">
  <div class="panel-head"><h2>Operational features <span class="count">{{ $features->total() }}</span></h2></div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Feature</th><th>Purpose</th><th>State</th><th>Toggle</th><th>Action</th></tr></thead><tbody>
  @foreach($features as $feature)
    <tr>
      <td><strong>{{ $available[$feature->key]['label'] }}</strong></td>
      <td>{{ $available[$feature->key]['description'] }}</td>
      <td><span class="role-pill">{{ $feature->enabled ? 'On' : 'Off' }}</span></td>
      <td>
        <form action="{{ route('dashboard.features.update',$feature) }}" method="post">
          @csrf @method('PATCH')
          <input type="hidden" name="enabled" value="{{ $feature->enabled ? '0' : '1' }}">
          <button class="btn compact {{ $feature->enabled ? 'subtle' : 'primary' }}" type="submit" aria-label="{{ $feature->enabled ? 'Disable' : 'Enable' }} {{ $available[$feature->key]['label'] }}">{{ $feature->enabled ? 'Turn off' : 'Turn on' }}</button>
        </form>
      </td>
      <td>
        <button class="btn subtle compact" type="button" data-modal-open="feature-{{ $feature->id }}"><x-icon name="settings" size="15"/> Configure</button>
        <dialog class="config-dialog" id="feature-{{ $feature->id }}">
          <form action="{{ route('dashboard.features.update',$feature) }}" method="post">@csrf @method('PATCH')
            <h2>{{ $available[$feature->key]['label'] }}</h2><p>{{ $available[$feature->key]['description'] }}</p>
            <label for="feature-status-{{ $feature->id }}">Operational status</label>
            <select name="enabled" id="feature-status-{{ $feature->id }}"><option value="1" @selected($feature->enabled)>On</option><option value="0" @selected(!$feature->enabled)>Off</option></select>
            <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save configuration</button></div>
          </form>
        </dialog>
      </td>
    </tr>
  @endforeach
  </tbody></table></div><x-pagination :paginator="$features"/>
</section>
@endsection
