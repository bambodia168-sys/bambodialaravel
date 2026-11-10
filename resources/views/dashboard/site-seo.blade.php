@extends('layouts.dashboard')
@section('title','SEO Settings')
@section('content')
  <div class="page-intro">
    <div class="eyebrow">Appearance &amp; Content / Search</div>
    <h1>SEO Settings</h1>
    <p>Configure real HTML title, description, canonical, robots, Open Graph and Twitter metadata. Page overrides take precedence over defaults and must target public routes.</p>
  </div>
  @include('dashboard.partials.site-settings-table',['heading'=>'Global SEO defaults','settingAction'=>route('dashboard.seo-settings.update')])

  <section class="panel" style="margin-top:20px">
    <div class="panel-head">
      <div><h2>Page SEO overrides <span class="count">{{ $pages->total() }}</span></h2>
        <p>English is the fallback. Changes appear in HTML metadata, not in visible page titles.</p></div>
      <button type="button" class="btn primary compact" data-modal-open="seo-add">
        <x-icon name="plus" size="16"/> Add
      </button>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Path</th><th>Language</th><th>Search title</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          @forelse($pages as $page)
            <tr>
              <td class="mono">{{ $page->path }}</td>
              <td>{{ strtoupper($page->locale) }}</td>
              <td>{{ $page->title }}</td>
              <td><span class="role-pill">{{ $page->is_active?($page->is_indexable?'Index':'Noindex'):'Inactive' }}</span></td>
              <td>
                <x-row-action-menu :id="'seo-page-'.$page->id" :label="$page->title">
                  <button type="button" data-modal-open="seo-edit-{{ $page->id }}"><x-icon name="pencil" size="16"/> Edit</button>
                  <form method="post" action="{{ route('dashboard.seo.pages.destroy',$page) }}">
                    @csrf @method('DELETE')
                    <button type="submit"><x-icon name="trash-2" size="16"/> Delete</button>
                  </form>
                </x-row-action-menu>
                <dialog class="config-dialog wide-dialog" id="seo-edit-{{ $page->id }}">
                  <form method="post" action="{{ route('dashboard.seo.pages.update',$page) }}">
                    @csrf @method('PATCH')
                    <h2>Edit SEO override</h2>
                    @include('dashboard.partials.site-seo-fields',['seoEntry'=>$page])
                    <div class="dialog-actions"><button type="button" data-modal-close class="btn subtle">Cancel</button><button type="submit" class="btn primary">Save</button></div>
                  </form>
                </dialog>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="empty-cell">No page overrides yet. Public pages use your global defaults.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$pages"/>
  </section>
  <dialog class="config-dialog wide-dialog" id="seo-add">
    <form method="post" action="{{ route('dashboard.seo.pages.store') }}">
      @csrf
      <h2>Add SEO override</h2>
      @include('dashboard.partials.site-seo-fields')
      <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Add</button></div>
    </form>
  </dialog>
@endsection
