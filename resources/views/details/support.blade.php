@extends('layouts.main')
@section('title','Support · '.$title)
@section('content')
<div class="container inner-page detail-workspace" data-detail-root>
  <x-detail-tabs :kind="$kind" :reference="$params['reference']" :slug="$params['slug']"
    :public-url="$publicUrl" active="support"/>
  <div class="detail-workspace-head">
    <div>
      <span class="eyebrow">Resources and assistance</span>
      <h1>Support</h1>
      <p>Help for <strong>{{ $title }}</strong>, including resources enabled by its publisher and your private requests.</p>
    </div>
    <a class="premium-detail-context-link" href="{{ $publicUrl }}" data-detail-route><x-icon name="arrow-up-left" size="16"/> {{ ucfirst($kind) }} overview</a>
  </div>

  <div class="detail-workspace-grid">
    <div class="detail-resource-column">
      <section class="panel detail-workspace-panel" aria-labelledby="publisher-resources-heading">
        <div class="panel-head">
          <div>
            <span class="eyebrow">From the publisher</span>
            <h2 id="publisher-resources-heading">Support resources</h2>
          </div>
          <span class="count">{{ $resources->where('is_enabled',true)->count() }}</span>
        </div>
        @forelse($resources as $resource)
          <article class="detail-support-resource {{ $resource->is_enabled ? '' : 'detail-support-resource--disabled' }}">
            <div class="detail-support-resource-head">
              <span class="detail-resource-kind">{{ ucfirst($resource->kind) }}</span>
              @if($canManageResources && ! $resource->is_enabled)<span class="premium-offer-status">Not published</span>@endif
            </div>
            <a href="{{ $resource->url }}" target="_blank" rel="noopener noreferrer external" class="detail-resource-link">
              <strong>{{ $resource->title }}</strong><x-icon name="external-link" size="16"/>
            </a>
            @if($resource->description)<p>{{ $resource->description }}</p>@endif
            @if($canManageResources)
              <details class="detail-resource-edit">
                <summary><x-icon name="edit-3" size="15"/> Edit resource</summary>
                <form method="post" action="{{ route('detail.support.resources.update',[...$params,'resource'=>$resource->id]) }}" class="detail-resource-form">
                  @csrf @method('PATCH')
                  <label for="resource-kind-{{ $resource->id }}">Type</label>
                  <select id="resource-kind-{{ $resource->id }}" name="kind" required>
                    @foreach(\App\Models\DetailSupportResource::KINDS as $option)
                      <option value="{{ $option }}" @selected($resource->kind===$option)>{{ ucfirst($option) }}</option>
                    @endforeach
                  </select>
                  <label for="resource-title-{{ $resource->id }}">Title</label>
                  <input id="resource-title-{{ $resource->id }}" name="title" value="{{ $resource->title }}" maxlength="120" minlength="3" required>
                  <label for="resource-url-{{ $resource->id }}">HTTPS URL</label>
                  <input id="resource-url-{{ $resource->id }}" type="url" name="url" value="{{ $resource->url }}" maxlength="2048" pattern="https://.+" required>
                  <label for="resource-description-{{ $resource->id }}">Description (optional)</label>
                  <textarea id="resource-description-{{ $resource->id }}" rows="2" name="description" maxlength="300">{{ $resource->description }}</textarea>
                  <label class="detail-resource-check"><input type="checkbox" name="is_enabled" value="1" @checked($resource->is_enabled)> Enable for signed-in visitors</label>
                  <input type="hidden" name="sort_order" value="{{ $resource->sort_order }}">
                  <button class="btn subtle compact" type="submit">Save changes</button>
                </form>
              </details>
              <form method="post" action="{{ route('detail.support.resources.destroy',[...$params,'resource'=>$resource->id]) }}" class="detail-resource-delete">
                @csrf @method('DELETE')
                <button type="submit" class="detail-comment-delete">Delete resource</button>
              </form>
            @endif
          </article>
        @empty
          <div class="detail-workspace-empty">The publisher has not enabled any support resources for this item.</div>
        @endforelse
      </section>

      <section class="panel detail-workspace-panel" aria-labelledby="detail-tickets-title">
        <div class="panel-head"><h2 id="detail-tickets-title">My private requests</h2><span class="count">{{ $tickets->total() }}</span></div>
        @forelse($tickets as $ticket)
          <article class="detail-support-ticket">
            <div>
              <a href="{{ route('support.show',$ticket->public_id) }}"><strong>{{ $ticket->subject }}</strong></a>
              <span class="hint">#{{ $ticket->public_id }} · Updated {{ $ticket->updated_at->diffForHumans() }}</span>
            </div>
            <div>
              <span class="role-pill">{{ ucwords(str_replace('_',' ',$ticket->status)) }}</span>
              <a class="btn subtle compact" href="{{ route('support.show',$ticket->public_id) }}">Open conversation</a>
            </div>
          </article>
        @empty
          <div class="detail-workspace-empty">No support requests have been created for this item.</div>
        @endforelse
        <x-pagination :paginator="$tickets"/>
      </section>
    </div>

    <aside class="detail-resource-column">
      <section class="panel detail-compose" aria-labelledby="detail-support-form-title">
        <span class="eyebrow">New support request</span>
        <h2 id="detail-support-form-title">Ask Bambodia Support</h2>
        <p>Create a private request related to this item. Your messages are visible only to you and authorized support staff.</p>
        <form method="post" action="{{ route('detail.support.store',$params) }}">
          @csrf
          <label for="detail-support-subject">Subject</label>
          <input id="detail-support-subject" name="subject" minlength="5" maxlength="180" required value="{{ old('subject') }}">
          @error('subject')<p class="field-error">{{ $message }}</p>@enderror
          <label for="detail-support-body">How can we help?</label>
          <textarea id="detail-support-body" name="message" rows="6" minlength="10" maxlength="10000" required>{{ old('message') }}</textarea>
          @error('message')<p class="field-error">{{ $message }}</p>@enderror
          <button type="submit" class="btn primary"><x-icon name="life-buoy" size="16"/> Create support request</button>
        </form>
      </section>

      @if($canManageResources)
        <section class="panel detail-compose" aria-labelledby="resource-add-heading">
          <h2 id="resource-add-heading">Manage publisher resources</h2>
          <p>Add your own documentation, FAQs, tutorials, or other HTTPS resources. Only enabled links appear to members.</p>
          <form method="post" action="{{ route('detail.support.resources.store',$params) }}" class="detail-resource-form">
            @csrf
            <label for="new-resource-kind">Resource type</label>
            <select id="new-resource-kind" name="kind" required>
              @foreach(\App\Models\DetailSupportResource::KINDS as $option)
                <option value="{{ $option }}">{{ ucfirst($option) }}</option>
              @endforeach
            </select>
            <label for="new-resource-title">Title</label>
            <input id="new-resource-title" name="title" minlength="3" maxlength="120" value="{{ old('title') }}" required>
            <label for="new-resource-url">HTTPS URL</label>
            <input id="new-resource-url" type="url" name="url" pattern="https://.+" value="{{ old('url') }}" maxlength="2048" placeholder="https://example.com/guide" required>
            <label for="new-resource-description">Description (optional)</label>
            <textarea id="new-resource-description" name="description" rows="3" maxlength="300">{{ old('description') }}</textarea>
            <label class="detail-resource-check"><input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled')==='1')> Enable for members</label>
            @foreach(['kind','title','url','description'] as $field)
              @error($field)<p class="field-error">{{ $message }}</p>@enderror
            @endforeach
            <button type="submit" class="btn primary"><x-icon name="plus" size="16"/> Add resource</button>
          </form>
          <p class="hint">Maximum 20 resources per item. You can disable or edit a resource at any time.</p>
        </section>
      @endif
    </aside>
  </div>
</div>
@endsection
