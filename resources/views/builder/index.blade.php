@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Marketplace Builder')
@section('content')
@php
  $labels = ['product'=>'Product', 'project'=>'Project', 'service'=>'Service', 'freelance'=>'Freelance', 'job'=>'Job'];
  $icons = ['product'=>'package','project'=>'layers','service'=>'wrench','freelance'=>'briefcase','job'=>'file-text'];
  $isJob = $type === 'job';
  $isProduct = $type === 'product';
  $editing = $record !== null;
  $back = $isJob ? route('jobs.mine') : ($isProduct ? route('studio.products.index') : route('studio.listings'));
  $action = $isJob
    ? ($editing ? url('/builder?='.$record->public_id) : route('jobs.store'))
    : ($isProduct
      ? ($editing ? route('studio.products.update', $record) : route('studio.products.store'))
      : ($editing ? route('studio.listings.update', $record) : route('studio.listings.store')));
@endphp
<div class="container inner-page marketplace-builder">
  <div class="page-intro builder-intro">
    <div class="eyebrow">Bambodia · Unified Marketplace Builder</div>
    <h1>{{ $type ? ($editing ? 'Manage' : 'Create').' '.strtolower($labels[$type]) : 'What would you like to create?' }}</h1>
    <p>{{ $type ? 'One workspace for real marketplace submissions, with ownership protection and administrator review.' : 'Choose a content type to start. Your available options follow your account permissions.' }}</p>
  </div>
  @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="notice error" role="alert">
      <strong>Please review these fields:</strong>
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif

  <nav class="builder-type-nav" aria-label="Create content">
    @foreach($allowedTypes as $choice)
      <a href="{{ route('builder', ['type'=>$choice]) }}" class="builder-type-link {{ $type===$choice&&!$editing?'is-current':'' }}"
         @if($type===$choice&&!$editing)aria-current="page"@endif>
        <x-icon :name="$icons[$choice]" size="18"/><span>{{ $labels[$choice] }}</span>
      </a>
    @endforeach
  </nav>

  @if($type === null)
    @if(count($allowedTypes) === 0)
      <div class="panel builder-panel">
        <h2>Builder access</h2><p>Creation is available to approved creators and eligible job-posting accounts. You can request creator access from your account.</p>
        <a class="btn subtle" href="{{ route('account') }}">Go to my account</a>
      </div>
    @else
      <div class="builder-choice-grid">
        @foreach($allowedTypes as $choice)
          <a class="builder-choice" href="{{ route('builder', ['type'=>$choice]) }}">
            <span class="builder-choice-icon"><x-icon :name="$icons[$choice]" size="25"/></span>
            <strong>{{ $labels[$choice] }}</strong>
            <span>{{ $choice==='product' ? 'Licensed files, rights and approved previews' : ($choice==='job' ? 'Recruit freelancers for a scoped assignment' : 'Publish your original '.strtolower($labels[$choice]).' offer') }}</span>
            <span class="builder-choice-action">Create {{ strtolower($labels[$choice]) }} <x-icon name="arrow-right" size="15"/></span>
          </a>
        @endforeach
      </div>
    @endif
  @else
  <div class="builder-workspace">
    <div class="builder-main">
      <section class="panel builder-panel">
        <div class="builder-panel-head">
          <div>
            <div class="eyebrow">{{ $labels[$type] }} details</div>
            <h2>{{ $editing ? $record->title : 'New '.$labels[$type] }}</h2>
          </div>
          @if($editing)<span class="role-pill">{{ ucfirst($record->status) }}</span>@endif
        </div>
        @if($editing)<p class="builder-public-id">Public ID: <strong class="mono">{{ $record->public_id }}</strong></p>@endif
        @if(!$canEdit)
          <div class="notice">This {{ $labels[$type] }} is {{ $record->status }}. Direct editing is disabled after submission or publication. Review and publication permissions are unchanged.</div>
        @endif
        <form method="post" action="{{ $action }}" class="builder-form">
          @csrf
          @if($editing)@method('PATCH')@else<input type="hidden" name="builder_mode" value="1">@endif
          @if(!$isJob && !$isProduct && !$editing)<input type="hidden" name="type" value="{{ $type }}">@endif
          <fieldset @disabled(!$canEdit)>
            <div class="builder-field">
              <label for="builder-title">{{ $isJob ? 'Job title' : $labels[$type].' title' }} <span aria-hidden="true">*</span></label>
              <input id="builder-title" name="title" required minlength="{{ $isJob ? 8 : 4 }}" maxlength="180"
                     value="{{ old('title',$record?->title) }}" placeholder="Enter a clear, descriptive title">
              @error('title')<small class="builder-field-error">{{ $message }}</small>@enderror
            </div>
            <div class="builder-field">
              <label for="builder-description">{{ $isJob ? 'Job brief and requirements' : 'Description and deliverables' }} <span aria-hidden="true">*</span></label>
              <textarea id="builder-description" name="description" rows="9" minlength="{{ $isJob ? 70 : 40 }}" maxlength="10000" required
                        placeholder="Explain the scope, deliverables and important details.">{{ old('description',$record?->description) }}</textarea>
              <small class="hint">Minimum {{ $isJob ? 70 : 40 }} characters · Maximum 10,000</small>
              @error('description')<small class="builder-field-error">{{ $message }}</small>@enderror
            </div>
            @if($isJob)
              <div class="builder-form-grid">
                <div class="builder-field">
                  <label for="builder-job-category">Job category <span aria-hidden="true">*</span></label>
                  <select id="builder-job-category" name="category" required>
                    @foreach(['development','design','writing','marketing','business','other'] as $category)
                      <option value="{{ $category }}" @selected(old('category',$record?->category)===$category)>{{ ucfirst($category) }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="builder-field">
                  <label for="builder-budget">Budget (USD, optional)</label>
                  <input id="builder-budget" type="number" name="budget" min="0" max="9999999999.99" step="0.01"
                         value="{{ old('budget',$record?->budget) }}" placeholder="0.00">
                </div>
              </div>
            @else
              <div class="builder-form-grid">
                <div class="builder-field">
                  <label for="builder-category">Marketplace category</label>
                  <select id="builder-category" name="category_id">
                    <option value="">Uncategorized</option>
                    @foreach($categories as $category)
                      <option value="{{ $category->id }}" @selected((string)old('category_id',$record?->category_id)===(string)$category->id)>{{ $category->name }}</option>
                    @endforeach
                  </select>
                  @error('category_id')<small class="builder-field-error">{{ $message }}</small>@enderror
                </div>
                <div class="builder-field">
                  <label for="builder-price">Listed price (USD, optional)</label>
                  <input id="builder-price" type="number" name="price" min="0" max="9999999999.99" step="0.01"
                         value="{{ old('price',$record?->price) }}" placeholder="0.00">
                  @error('price')<small class="builder-field-error">{{ $message }}</small>@enderror
                </div>
              </div>
              @if($isProduct)
                <div class="builder-form-grid">
                  <div class="builder-field">
                    <label for="builder-license">Active product license</label>
                    <select id="builder-license" name="license_definition_id">
                      <option value="">Not selected</option>
                      @foreach($licenses as $license)
                        <option value="{{ $license->id }}" @selected((string)old('license_definition_id',$record?->license_definition_id)===(string)$license->id)>{{ $license->name }} · {{ $license->version }}</option>
                      @endforeach
                    </select>
                    @error('license_definition_id')<small class="builder-field-error">{{ $message }}</small>@enderror
                  </div>
                  <div class="builder-field">
                    <label for="builder-cover">Approved preview image</label>
                    <select id="builder-cover" name="cover_media_id">
                      <option value="">Not selected</option>
                      @foreach($assets as $asset)
                        <option value="{{ $asset->id }}" @selected((string)old('cover_media_id',$record?->cover_media_id)===(string)$asset->id)>{{ $asset->alt_text }} · {{ $asset->public_id }}</option>
                      @endforeach
                    </select>
                    @error('cover_media_id')<small class="builder-field-error">{{ $message }}</small>@enderror
                  </div>
                </div>
                <p class="hint">Product submission requires an active license and an approved image from your own Media Library.</p>
              @endif
              <div class="builder-field">
                <label for="builder-terms">{{ $isProduct ? 'Additional product terms' : 'Supplemental terms' }} (optional)</label>
                <textarea id="builder-terms" name="license_terms" rows="4" maxlength="20000">{{ old('license_terms',$record?->license_terms) }}</textarea>
                @if($isProduct)<small class="hint">Additional terms cannot expand the rights granted by the active license.</small>@endif
              </div>
            @endif
            @if($isProduct)
              <div class="builder-field">
                <label for="builder-status">Next action</label>
                <select id="builder-status" name="status">
                  <option value="draft" @selected(old('status','draft')==='draft')>Save draft</option>
                  <option value="pending" @selected(old('status','draft')==='pending')>Submit for administrator review</option>
                </select>
              </div>
            @endif
          </fieldset>
          @if($canEdit)
            <div class="builder-actions">
              <a class="btn subtle" href="{{ $back }}">Cancel</a>
              <button class="btn primary" type="submit">
                <x-icon name="save" size="17"/> {{ $isProduct ? 'Save product' : ($editing ? 'Save and submit for review' : 'Submit for review') }}
              </button>
            </div>
          @else
            <div class="builder-actions"><a class="btn subtle" href="{{ $back }}">Back to manager</a></div>
          @endif
        </form>
      </section>
    </div>
    <aside class="builder-aside">
      <section class="panel builder-panel">
        <h3>Publication workflow</h3>
        <ol class="builder-checklist">
          <li><x-icon name="check-circle" size="17"/> Add original, accurate details</li>
          <li><x-icon name="shield-check" size="17"/> Verify rights and platform policies</li>
          <li><x-icon name="clipboard-check" size="17"/> Submit for administrator review</li>
          <li><x-icon name="eye" size="17"/> Publication after approval only</li>
        </ol>
        <p class="hint">No checkout, payment collection, job escrow or entitlement is created by this form.</p>
      </section>
      @if($isProduct)
        <section class="panel builder-panel">
          <h3>Product resources</h3>
          <a class="btn subtle builder-full-link" href="{{ route('studio.media.index') }}">Open Media Library</a>
          @if($editing && $record->status==='published')
            <a class="btn subtle builder-full-link" href="{{ route('studio.vault.index',$record) }}">Manage product releases</a>
          @endif
        </section>
      @endif
      @if($isJob && $editing)
        <section class="panel builder-panel">
          <h3>Job management</h3>
          <a class="btn subtle builder-full-link" href="{{ route('jobs.proposals',$record) }}">View incoming proposals</a>
          @if(in_array($record->status,['pending','published'],true))
            <form method="post" action="{{ route('jobs.close',$record) }}">
              @csrf @method('PATCH')
              <button class="btn danger builder-full-link" type="submit">Close job to new proposals</button>
            </form>
          @endif
        </section>
      @endif
      @if($editing && $record->status==='published')
        <a class="btn subtle builder-full-link" href="{{ $record->publicUrl() }}">View published {{ strtolower($labels[$type]) }}</a>
      @endif
    </aside>
  </div>
  @endif
</div>
@endsection
