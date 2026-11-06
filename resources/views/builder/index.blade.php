@extends('layouts.main')
@section('title', 'Builder Studio')
@section('content')
@php
  $types = [
    'product'=>['label'=>'Product','icon'=>'package','text'=>'Licensed digital products and original source files'],
    'project'=>['label'=>'Project','icon'=>'layers','text'=>'Present your project with scope and deliverables'],
    'service'=>['label'=>'Service','icon'=>'wrench','text'=>'Offer a professional service to customers'],
    'freelance'=>['label'=>'Freelance','icon'=>'briefcase','text'=>'Showcase your professional freelance offer'],
    'job'=>['label'=>'Job','icon'=>'file-text','text'=>'Publish a job brief and receive proposals'],
  ];
  $editing = $record !== null;
  $isProduct = $type === 'product';
  $isJob = $type === 'job';
  $back = $isJob ? route('jobs.mine') : ($isProduct ? route('studio.products.index') : route('studio.listings'));
  $action = $isJob
    ? ($editing ? url('/builder?='.$record->public_id) : route('jobs.store'))
    : ($isProduct
      ? ($editing ? route('studio.products.update',$record) : route('studio.products.store'))
      : ($editing ? route('studio.listings.update',$record) : route('studio.listings.store')));
  $shareUrl = $editing
    ? ($record->status === 'published' ? $record->publicUrl() : url('/builder?='.$record->public_id))
    : null;
  $selectedKind = old('featured_kind', $record?->featured_kind ?? 'none');
  $selectedMedia = old('featured_media_id', $record?->featured_media_id);
  $selectedColor = old('featured_color', $record?->featured_color ?: '#c2410c');
  $returnTo = $editing ? '/builder?='.$record->public_id : ($type ? '/builder?type='.$type : '/builder');
  $previousHtml = old('description_html', $record?->description_html);
  $previousText = old('description', $record?->description ?? '');
@endphp
<div class="container inner-page builder-studio" data-builder-studio data-builder-readonly="{{ $canEdit ? '0' : '1' }}">
  @if($type !== null)
    <section class="studio-landing-hero studio-editor-hero" aria-labelledby="studio-editor-heading">
      <span class="studio-landing-shield" aria-hidden="true"><x-icon name="shield" size="256"/></span>
      <div class="studio-landing-hero-content">
        <span class="studio-landing-eyebrow"><x-icon name="shield" size="16"/> Creative workspace</span>
        <h1 id="studio-editor-heading">Builder Studio</h1>
        <p>{{ $editing ? 'Review and manage your '.$types[$type]['label'].' submission.' : 'Create a professional '.$types[$type]['label'].' in one focused workspace.' }}</p>
      </div>
    </section>
  @else
    <section class="studio-landing-hero" aria-labelledby="studio-landing-heading">
      <span class="studio-landing-shield" aria-hidden="true"><x-icon name="shield" size="256"/></span>
      <div class="studio-landing-hero-content">
        <span class="studio-landing-eyebrow"><x-icon name="shield" size="16"/> Creative workspace</span>
        <h1 id="studio-landing-heading">Builder Studio</h1>
        <p>One professional place to create products, projects, services, freelance offers and job posts.</p>
      </div>
    </section>
  @endif

  @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="notice error" role="alert">
      <strong>Please correct the following:</strong>
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif

  <nav class="studio-types" aria-label="Builder content type">
    @foreach($types as $key=>$meta)
      @continue(!in_array($key, $allowedTypes, true))
      <a class="studio-type {{ $type===$key?'is-active':'' }}"
         href="{{ route('builder',['type'=>$key]) }}"
         @if($type===$key)aria-current="page"@endif>
        <x-icon :name="$meta['icon']" size="18"/><span>{{ $meta['label'] }}</span>
      </a>
    @endforeach
    @if($type === null)
      <a class="studio-landing-website" href="{{ route('home') }}">
        <x-icon name="globe" size="17"/> Website
      </a>
    @else
      <div class="studio-type-actions" role="group" aria-label="Builder tools">
        <button type="button" class="studio-tool-action" data-builder-preview-toggle>
          <x-icon name="eye" size="17"/> Preview
        </button>
        <a class="studio-tool-action" href="{{ $back }}"><x-icon name="folders" size="17"/> My Content</a>
        <a class="studio-tool-action" href="{{ route('home') }}"><x-icon name="globe" size="17"/> Website</a>
      </div>
    @endif
  </nav>

  @if($type === null)
    @if(count($allowedTypes) === 0)
      <div class="studio-empty panel">
        <h2>Choose your access</h2><p>Creator approval is required for marketplace offers. Clients can create job posts from eligible accounts.</p>
        <a href="{{ route('account') }}" class="btn primary">My account</a>
      </div>
    @else
      <div class="studio-choice-grid">
        @foreach($types as $key=>$meta)
          @continue(!in_array($key, $allowedTypes, true))
          <a class="studio-choice" href="{{ route('builder',['type'=>$key]) }}">
            <span class="studio-choice-icon"><x-icon :name="$meta['icon']" size="24"/></span>
            <strong>{{ $meta['label'] }}</strong>
            <span class="studio-choice-description">{{ $meta['text'] }}</span>
            <span class="studio-choice-link">Create {{ strtolower($meta['label']) }} <x-icon name="chevron-right" size="17"/></span>
          </a>
        @endforeach
      </div>
    @endif

    <section class="studio-landing-guide" aria-labelledby="studio-guide-heading">
      <header class="studio-landing-guide-heading">
        <span class="eyebrow">Discover the marketplace</span>
        <h2 id="studio-guide-heading">What can you create?</h2>
        <p>Choose the right listing type for your work. Every submission is reviewed before becoming visible to the public.</p>
      </header>
      @php
        $guides = [
          'product' => 'Share original digital downloads, such as software, templates, design resources or other licensed files. Buyers receive only the usage rights stated in the approved product license, not ownership of your intellectual property.',
          'project' => 'Present a completed project or a clearly scoped proposal. Explain your objectives, process, deliverables and examples of your work to help potential clients understand what you can build.',
          'service' => 'Offer a defined professional service, with a clear description of the work, expected outcomes and pricing. Customers can review your offer and contact you through the platform’s inquiry process.',
          'freelance' => 'Showcase your independent skills and specialist expertise. Describe the type of assignments you accept, your working approach and the value you can deliver to prospective clients.',
          'job' => 'Post an opportunity with its responsibilities, requirements and optional budget. Eligible freelancers can review approved job listings and submit proposals for your consideration.',
        ];
      @endphp
      <div class="studio-landing-guide-grid">
        @foreach($types as $key=>$meta)
          <article class="studio-landing-guide-item">
            <div class="studio-landing-guide-icon"><x-icon :name="$meta['icon']" size="20"/></div>
            <div>
              <h3>{{ $meta['label'] }}</h3>
              <p>{{ $guides[$key] }}</p>
            </div>
          </article>
        @endforeach
      </div>

      <section class="studio-landing-license" aria-labelledby="studio-license-heading">
        <div class="studio-landing-license-header">
          <span class="studio-landing-license-icon"><x-icon name="shield" size="23"/></span>
          <div>
            <span class="eyebrow">Product licensing</span>
            <h2 id="studio-license-heading">Choose the rights that fit your use</h2>
            <p>Products use administrator-reviewed license terms. The permissions and restrictions in the published license determine what a buyer can do with the files.</p>
          </div>
        </div>
        <div class="studio-landing-license-grid">
          <article class="studio-landing-license-option">
            <h3><x-icon name="file-check" size="19"/> Regular License</h3>
            <p>Generally suited to one project or end product under the specific product’s published permissions. Reuse, redistribution and commercial restrictions depend on those written terms.</p>
          </article>
          <article class="studio-landing-license-option">
            <h3><x-icon name="shield" size="19"/> Extended License</h3>
            <p>Designed for products whose published terms grant broader commercial or monetized use. Extended rights are not assumed: only the permissions expressly stated in the selected license apply.</p>
          </article>
        </div>
        <p class="studio-landing-license-note"><x-icon name="shield" size="17"/> After verified purchases become available, the platform is intended to generate the corresponding product license automatically. Checkout and automatic license delivery are not currently enabled.</p>
      </section>
    </section>
  @else
    <div class="studio-section-heading">
      <div>
        <span class="eyebrow">{{ $types[$type]['label'] }} / {{ $editing ? 'Management' : 'New submission' }}</span>
        <h2>{{ $editing ? 'Edit '.$types[$type]['label'] : 'Create '.$types[$type]['label'] }}</h2>
        @if($editing)<p>Public ID <span class="mono">{{ $record->public_id }}</span> · {{ ucfirst($record->status) }}</p>@endif
      </div>
      @if($editing)<span class="studio-status">{{ ucfirst($record->status) }}</span>@endif
    </div>

    @if(!$canEdit)
      <div class="notice">This {{ strtolower($types[$type]['label']) }} is {{ $record->status }}. You can inspect its content but cannot change it directly. Administrator review and publication safeguards remain active.</div>
    @endif

    <div class="studio-columns">
      <div class="studio-primary">
        <form data-builder-form method="post" action="{{ $action }}" class="studio-form">
          @csrf
          @if($editing)@method('PATCH')@else<input type="hidden" name="builder_mode" value="1">@endif
          @if(!$editing && !$isJob && !$isProduct)<input type="hidden" name="type" value="{{ $type }}">@endif
          <fieldset>
            <section class="studio-card" aria-labelledby="studio-basic-title">
              <div class="studio-card-heading">
                <div><span class="studio-step">01</span><h3 id="studio-basic-title">Basic information</h3></div>
                <span class="studio-step-caption">Essential details</span>
              </div>
              <div class="studio-field">
                <label for="studio-title">{{ $isJob ? 'Job title' : $types[$type]['label'].' title' }} <span class="studio-required">*</span></label>
                <div class="studio-title-control">
                  <input id="studio-title" name="title" type="text" @readonly(!$canEdit)
                         minlength="{{ $isJob ? 8 : 4 }}" maxlength="180" required
                         value="{{ old('title',$record?->title ?? '') }}"
                         placeholder="Write a clear and professional title">
                  <button type="button" class="studio-copy-control" data-builder-copy-url
                          @if($shareUrl)data-copy-url="{{ $shareUrl }}" title="Copy full {{ $record->status === 'published' ? 'public' : 'builder' }} URL" @else disabled title="Save your {{ strtolower($types[$type]['label']) }} to generate its full URL" @endif
                          aria-label="Copy full listing URL"><x-icon name="copy" size="18"/></button>
                </div>
                <small class="studio-copy-feedback" data-copy-feedback aria-live="polite">{{ $editing ? 'Copy the full item URL using the icon at the end of the title.' : 'A copyable URL is generated once your submission has been saved.' }}</small>
                @error('title')<small class="builder-field-error">{{ $message }}</small>@enderror
              </div>
              <div class="studio-form-grid">
                <div class="studio-field">
                  <label for="studio-category">{{ $isJob ? 'Job category' : 'Marketplace category' }}</label>
                  @if($isJob)
                    <select name="category" id="studio-category" required @disabled(!$canEdit)>
                      @foreach(['development','design','writing','marketing','business','other'] as $category)
                        <option value="{{ $category }}" @selected(old('category',$record?->category)===$category)>{{ ucfirst($category) }}</option>
                      @endforeach
                    </select>
                  @else
                    <select name="category_id" id="studio-category" @disabled(!$canEdit)>
                      <option value="">Uncategorized</option>
                      @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string)old('category_id',$record?->category_id)===(string)$category->id)>{{ $category->name }}</option>
                      @endforeach
                    </select>
                  @endif
                </div>
                <div class="studio-field">
                  <label for="studio-price">{{ $isJob ? 'Budget' : 'Listed price' }} (USD, optional)</label>
                  <input id="studio-price" name="{{ $isJob ? 'budget' : 'price' }}" type="number" @readonly(!$canEdit) min="0" max="9999999999.99" step="0.01"
                         value="{{ old($isJob ? 'budget' : 'price',$isJob ? $record?->budget : $record?->price) }}"
                         placeholder="0.00">
                </div>
              </div>
            </section>

            <section class="studio-card" aria-labelledby="studio-editor-title">
              <div class="studio-card-heading">
                <div><span class="studio-step">02</span><h3 id="studio-editor-title">{{ $isJob ? 'Job description' : 'Description & details' }} <span class="studio-required">*</span></h3></div>
                <span class="studio-step-caption">Rich text editor</span>
              </div>
              <div class="studio-editor" data-studio-editor>
                <div class="studio-editor-toolbar" data-editor-toolbar hidden role="toolbar" aria-label="Rich text formatting">
                  <select class="studio-editor-format" data-editor-block aria-label="Paragraph format">
                    <option value="p">Paragraph</option>
                    <option value="h2">Heading 2</option>
                    <option value="h3">Heading 3</option>
                    <option value="h4">Heading 4</option>
                    <option value="blockquote">Blockquote</option>
                    <option value="pre">Preformatted</option>
                  </select>
                  @foreach([
                    ['bold','bold','Bold'],['italic','italic','Italic'],['underline','underline','Underline'],
                    ['strikeThrough','strikethrough','Strikethrough'],['superscript','superscript','Superscript'],
                    ['subscript','subscript','Subscript'],['insertUnorderedList','list','Bullet list'],
                    ['insertOrderedList','list-ordered','Numbered list'],['outdent','outdent','Decrease indent'],
                    ['indent','indent','Increase indent'],['justifyLeft','align-left','Align left'],
                    ['justifyCenter','align-center','Align center'],['justifyRight','align-right','Align right'],
                    ['justifyFull','align-justify','Justify text']
                  ] as [$command,$icon,$label])
                    <button type="button" class="studio-editor-icon" data-editor-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}"><x-icon :name="$icon" size="17"/></button>
                  @endforeach
                  <button type="button" class="studio-editor-icon" data-editor-action="link" title="Insert hyperlink" aria-label="Insert hyperlink"><x-icon name="link" size="17"/></button>
                  <button type="button" class="studio-editor-icon" data-editor-command="unlink" title="Remove hyperlink" aria-label="Remove hyperlink"><x-icon name="unlink" size="17"/></button>
                  <button type="button" class="studio-editor-icon" data-editor-action="table" title="Insert table" aria-label="Insert table"><x-icon name="table" size="17"/></button>
                  <button type="button" class="studio-editor-icon" data-editor-action="rule" title="Insert horizontal rule" aria-label="Insert horizontal rule"><x-icon name="minus" size="17"/></button>
                  <label class="studio-editor-color" title="Text color" aria-label="Text color"><x-icon name="palette" size="17"/><input data-editor-color="foreColor" type="color" value="#c2410c" aria-label="Text color"></label>
                  <label class="studio-editor-color" title="Highlight color" aria-label="Highlight color"><x-icon name="highlighter" size="17"/><input data-editor-color="hiliteColor" type="color" value="#fff1a8" aria-label="Highlight color"></label>
                  @foreach([['undo','undo','Undo'],['redo','redo','Redo'],['removeFormat','eraser','Clear formatting']] as [$command,$icon,$label])
                    <button type="button" class="studio-editor-icon" data-editor-command="{{ $command }}" title="{{ $label }}" aria-label="{{ $label }}"><x-icon :name="$icon" size="17"/></button>
                  @endforeach
                  <button type="button" class="studio-editor-icon" data-editor-action="source" title="View HTML source (read-only)" aria-label="View HTML source"><x-icon name="code" size="17"/></button>
                </div>
                <div class="studio-editor-surface" data-editor-surface contenteditable="true" role="textbox"
                     aria-label="Formatted description" aria-multiline="true" spellcheck="true"
                     data-placeholder="Describe the scope, benefits, deliverables and requirements" hidden>
                  @if($previousHtml){!! \App\Support\BuilderContent::sanitize($previousHtml) !!}@else{!! nl2br(e($previousText)) !!}@endif
                </div>
                <textarea data-editor-fallback name="description" rows="10" @readonly(!$canEdit)
                          minlength="{{ $isJob ? 70 : 40 }}" maxlength="10000" required
                          placeholder="Describe the scope, benefits, deliverables and requirements">{{ $previousText }}</textarea>
                <input type="hidden" data-editor-html name="description_html" value="{{ $previousHtml }}">
                <textarea class="studio-editor-source" data-editor-source aria-label="Sanitized HTML source preview" readonly hidden></textarea>
                <div class="studio-editor-footer">
                  <span>Headings · Lists · Links · Tables · Formatting</span>
                  <span data-editor-counter>0 / 10,000</span>
                </div>
              </div>
              <small class="hint">At least {{ $isJob ? 70 : 40 }} characters. Formatting is cleaned securely when saved.</small>
              @error('description')<small class="builder-field-error">{{ $message }}</small>@enderror
            </section>

            <section class="studio-card" aria-labelledby="studio-featured-title">
              <div class="studio-card-heading">
                <div><span class="studio-step">03</span><h3 id="studio-featured-title">Featured media</h3></div>
                <span class="studio-step-caption">Cover your {{ strtolower($types[$type]['label']) }}</span>
              </div>
              <p class="studio-help">Choose an approved image, animated GIF, video, or a solid color. Media remains private until it passes review.</p>
              <div class="studio-media-types" role="group" aria-label="Featured media type">
                @foreach([
                  'none'=>['label'=>'None','icon'=>'slash'],
                  'image'=>['label'=>'Image','icon'=>'image'],
                  'video'=>['label'=>'Video','icon'=>'video'],
                  'animated'=>['label'=>'Animate','icon'=>'sparkles'],
                  'color'=>['label'=>'Color','icon'=>'palette']
                ] as $kind=>$meta)
                  <label class="studio-media-option">
                    <input type="radio" name="featured_kind" value="{{ $kind }}" @checked($selectedKind === $kind) @disabled(!$canEdit)>
                    <span><x-icon :name="$meta['icon']" size="17"/>{{ $meta['label'] }}</span>
                  </label>
                @endforeach
              </div>
              <div class="studio-media-chooser" data-featured-media-select>
                <div class="studio-field">
                  <label for="studio-featured-media">Select reviewed media from your library</label>
                  <select id="studio-featured-media" name="featured_media_id" data-featured-media @disabled(!$canEdit)>
                    <option value="">Choose approved media</option>
                    @foreach($assets as $asset)
                      <option value="{{ $asset->id }}" data-media-mime="{{ $asset->mime_type }}" data-media-url="{{ $asset->previewUrl() }}"
                              data-media-alt="{{ $asset->alt_text }}" @selected((string)$selectedMedia===(string)$asset->id)>
                        {{ $asset->alt_text }} · {{ $asset->public_id }} ({{ strtoupper(explode('/',$asset->mime_type)[1] ?? 'media') }})
                      </option>
                    @endforeach
                  </select>
                </div>
                @if($canEdit)<button class="studio-tool-action" type="button" data-modal-open="studio-media-upload"><x-icon name="upload" size="16"/> Upload new media</button>@endif
                <small class="hint">Files uploaded here must pass rights review before they can be selected.</small>
              </div>
              <div class="studio-field studio-media-color" data-featured-color-wrap>
                <label for="studio-color">Featured background color</label>
                <div class="studio-color-control">
                  <input id="studio-color" name="featured_color" type="color" data-featured-color @disabled(!$canEdit)
                         value="{{ preg_match('/^#[a-fA-F0-9]{6}$/',$selectedColor) ? $selectedColor : '#c2410c' }}">
                  <span data-featured-color-label>{{ $selectedColor }}</span>
                </div>
              </div>
              <div class="studio-featured-preview" data-featured-preview aria-label="Selected featured media preview">
                <span><x-icon name="image" size="25"/> Featured media preview</span>
              </div>
              @error('featured_media_id')<small class="builder-field-error">{{ $message }}</small>@enderror
            </section>

            @if($isProduct)
              <section class="studio-card" aria-labelledby="studio-license-title">
                <div class="studio-card-heading">
                  <div><span class="studio-step">04</span><h3 id="studio-license-title">Product license and preview</h3></div>
                  <span class="studio-step-caption">Publishing requirements</span>
                </div>
                <div class="studio-form-grid">
                  <div class="studio-field">
                    <label for="studio-license">Active product license</label>
                    <select id="studio-license" name="license_definition_id" @disabled(!$canEdit)>
                      <option value="">Select an active license</option>
                      @foreach($licenses as $license)
                        <option value="{{ $license->id }}" @selected((string)old('license_definition_id',$record?->license_definition_id)===(string)$license->id)>{{ $license->name }} · {{ $license->version }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="studio-field">
                    <label for="studio-cover">Required static product preview</label>
                    <select id="studio-cover" name="cover_media_id" @disabled(!$canEdit)>
                      <option value="">Select an approved static image</option>
                      @foreach($assets as $asset)
                        @if(in_array($asset->mime_type,['image/jpeg','image/png','image/webp'],true))
                          <option value="{{ $asset->id }}" @selected((string)old('cover_media_id',$record?->cover_media_id)===(string)$asset->id)>{{ $asset->alt_text }} · {{ $asset->public_id }}</option>
                        @endif
                      @endforeach
                    </select>
                  </div>
                </div>
                <p class="hint">A product can feature video, animation or color, but still requires an active license and approved static preview to submit for publication.</p>
              </section>
            @endif

            @unless($isJob)
              <section class="studio-card" aria-labelledby="studio-terms-title">
                <div class="studio-card-heading">
                  <div><span class="studio-step">{{ $isProduct ? '05' : '04' }}</span><h3 id="studio-terms-title">Supplemental terms</h3></div>
                  <span class="studio-step-caption">Optional</span>
                </div>
                <div class="studio-field">
                  <label for="studio-terms">Additional usage and delivery conditions</label>
                  <textarea id="studio-terms" name="license_terms" rows="4" maxlength="20000" @readonly(!$canEdit)
                            placeholder="Additional rights, usage limitations or delivery details">{{ old('license_terms',$record?->license_terms ?? '') }}</textarea>
                </div>
                @if($isProduct)<p class="hint">These terms cannot expand the rights granted by the active product license.</p>@endif
              </section>
            @endunless

            @if($isProduct)
              <section class="studio-card studio-final-action">
                <label for="studio-next-status">Next action</label>
                <select id="studio-next-status" name="status" @disabled(!$canEdit)>
                  <option value="draft" @selected(old('status','draft')==='draft')>Save draft</option>
                  <option value="pending" @selected(old('status','draft')==='pending')>Submit for review</option>
                </select>
              </section>
            @endif
          </fieldset>
          <div class="studio-submit-bar">
            <span><x-icon name="shield-check" size="17"/> Your work is protected by account ownership and content review.</span>
            <div class="studio-submit-actions">
              <a class="studio-tool-action" href="{{ $back }}">Cancel</a>
              @if($canEdit)
                <button class="btn primary" type="submit"><x-icon name="save" size="17"/> {{ $isProduct ? 'Save product' : ($editing ? 'Save changes' : 'Submit for review') }}</button>
              @endif
            </div>
          </div>
        </form>
      </div>

      <aside class="studio-aside" aria-label="Builder tools and publishing guidance">
        <section class="studio-aside-card">
          <span class="eyebrow">Workspace summary</span>
          <h3>{{ $editing ? $record->title : 'New '.$types[$type]['label'] }}</h3>
          <dl class="studio-summary">
            <div><dt>Type</dt><dd>{{ $types[$type]['label'] }}</dd></div>
            <div><dt>Status</dt><dd>{{ $editing ? ucfirst($record->status) : 'Not saved' }}</dd></div>
            <div><dt>Visibility</dt><dd>Private until approved</dd></div>
          </dl>
          @if($editing && $record->status === 'published')<a class="studio-tool-action" href="{{ $record->publicUrl() }}"><x-icon name="external-link" size="16"/> View published page</a>@endif
        </section>
        <section class="studio-aside-card">
          <span class="eyebrow">Publishing checklist</span>
          <h3>Ready to publish?</h3>
          <div class="studio-checklist">
            <div><x-icon name="check-circle" size="18"/> Clear title and description</div>
            <div><x-icon name="image" size="18"/> Approved media and rights</div>
            <div><x-icon name="shield" size="18"/> Follow platform policies</div>
            <div><x-icon name="clipboard-check" size="18"/> Administrator review</div>
          </div>
          <p class="hint">Creating a listing or job does not charge a customer, enable checkout or fund escrow.</p>
        </section>
        @if($editing && $isProduct && $record->status==='published')
          <a class="studio-tool-action" href="{{ route('studio.vault.index',$record) }}"><x-icon name="archive" size="17"/> Manage secure file releases</a>
        @endif
        @if($editing && $isJob)
          <a class="studio-tool-action" href="{{ route('jobs.proposals',$record) }}"><x-icon name="users" size="17"/> Review job proposals</a>
        @endif
      </aside>
    </div>

    <section class="studio-preview-panel" data-studio-preview hidden aria-label="Draft preview">
      <div class="studio-card-heading"><h3>Editor preview</h3><button type="button" class="studio-tool-action" data-builder-preview-toggle><x-icon name="x" size="16"/> Close</button></div>
      <h2 data-preview-title></h2>
      <div data-preview-description class="studio-preview-description"></div>
      <p class="hint">Preview only. Content remains private until reviewed and published.</p>
    </section>

    <dialog id="studio-media-upload" class="studio-upload-dialog">
      <form method="post" action="{{ route('builder.media.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        <div class="studio-card-heading"><h3>Upload featured media</h3><button type="button" class="studio-editor-icon" data-modal-close title="Close"><x-icon name="x" size="19"/></button></div>
        <p>Files stay private until approved. Static image: 4 MB · GIF: 8 MB · MP4/WebM: 20 MB.</p>
        <div class="studio-field">
          <label for="studio-upload-file">Image, animation or video</label>
          <input id="studio-upload-file" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required>
        </div>
        <div class="studio-field">
          <label for="studio-upload-alt">Description</label>
          <input id="studio-upload-alt" name="alt_text" type="text" minlength="4" maxlength="180" required>
        </div>
        <div class="studio-form-grid">
          <div class="studio-field">
            <label for="studio-upload-rights">Basis of rights</label>
            <select id="studio-upload-rights" name="rights_basis"><option value="owned">I own this media</option><option value="licensed">I have a license</option></select>
          </div>
          <div class="studio-field">
            <label for="studio-upload-holder">Rights holder</label>
            <input id="studio-upload-holder" name="rights_holder" minlength="2" maxlength="180" required>
          </div>
        </div>
        <div class="studio-field">
          <label for="studio-upload-note">Rights evidence or permission details</label>
          <textarea id="studio-upload-note" name="rights_note" rows="3" maxlength="3000"></textarea>
        </div>
        <div class="studio-submit-actions"><button class="studio-tool-action" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit"><x-icon name="upload" size="16"/> Upload privately</button></div>
      </form>
    </dialog>
  @endif
</div>
@endsection
