@extends('layouts.dashboard')
@section('title','Footer Social Links')
@section('content')
  <div class="page-intro">
    <div class="eyebrow">Appearance &amp; Content / Footer</div>
    <h1>Footer Social Links</h1>
    <p>Create, edit, hide, reorder or delete the links in your existing public footer. No new footer section or layout is created.</p>
  </div>
  <section class="panel">
    <div class="panel-head">
      <div><h2>Social profiles <span class="count">{{ $links->total() }}</span></h2><p>Only active HTTPS links appear in the footer, ordered by the number below.</p></div>
      <button type="button" class="btn primary compact" data-modal-open="social-add"><x-icon name="plus" size="16"/> Add</button>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Platform</th><th>Name</th><th>URL</th><th>Order</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($links as $link)
          <tr>
            <td>{{ $platforms[$link->platform]??$link->platform }}</td>
            <td><strong>{{ $link->label }}</strong></td>
            <td><a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="mono">{{ $link->url }}</a></td>
            <td>{{ $link->sort_order }}</td>
            <td><span class="role-pill">{{ $link->is_active?'Visible':'Hidden' }}</span></td>
            <td>
              <x-row-action-menu :id="'social-'.$link->id" :label="$link->label">
                <button type="button" data-modal-open="social-edit-{{ $link->id }}"><x-icon name="pencil" size="16"/> Edit</button>
                <form method="post" action="{{ route('dashboard.social.destroy',$link) }}">
                  @csrf @method('DELETE')
                  <button type="submit"><x-icon name="trash-2" size="16"/> Delete</button>
                </form>
              </x-row-action-menu>
              <dialog class="config-dialog wide-dialog" id="social-edit-{{ $link->id }}">
                <form method="post" action="{{ route('dashboard.social.update',$link) }}">
                  @csrf @method('PATCH')
                  <h2>Edit footer social link</h2>
                  @include('dashboard.partials.site-social-fields',['socialLink'=>$link])
                  <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save</button></div>
                </form>
              </dialog>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">No active social links configured.</td></tr>
        @endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$links"/>
  </section>
  <dialog class="config-dialog wide-dialog" id="social-add">
    <form method="post" action="{{ route('dashboard.social.store') }}">
      @csrf
      <h2>Add footer social link</h2>
      @include('dashboard.partials.site-social-fields')
      <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Add</button></div>
    </form>
  </dialog>
@endsection
