@extends('layouts.main')
@section('title',$listing->title)
@section('content')
@php
  $kind = $listing->type;
  $kindTitle = ucfirst($kind);
  $isProduct = $kind === 'product';
  $layoutSample = str_starts_with($listing->title, '[LAYOUT TEST] ');
  $canInquire = ! $layoutSample && in_array($kind,['project','service','freelance'],true);
  $creatorBadges = $listing->creator
    ? \App\Support\CreatorBadgeAwards::earned($listing->creator) : collect();
  $ownerName = $listing->creator?->name ?? 'Bambodia creator';
  $pageSections = [
    ['id'=>'overview','label'=>'Overview'],
    ...($isProduct ? [['id'=>'license','label'=>'License']] : [['id'=>'workflow','label'=>'How it works']]),
    ['id'=>'publisher','label'=>'Creator'],
    ...($relatedListings->isNotEmpty() ? [['id'=>'related','label'=>'Related']] : []),
  ];
  $inquiryTitle = match ($kind) {
    'project' => 'Discuss this project',
    'service' => 'Request service details',
    'freelance' => 'Contact this freelancer',
    default => 'Contact creator',
  };
  $regularPrice = $listing->regular_price ?? $listing->price;
  $extendedLicense = $isProduct && $listing->extendedLicenseDefinition?->status === 'active' && $listing->extended_price !== null
    ? $listing->extendedLicenseDefinition : null;
  $typeIcon = match ($kind) {
    'product' => 'package',
    'project' => 'layers',
    'service' => 'settings',
    default => 'briefcase',
  };
@endphp
<div class="container inner-page listing-detail premium-detail premium-detail-{{ $kind }}" data-detail-root>
  <x-detail-tabs :kind="$kind" :reference="$listing->public_id" :slug="$listing->slug"
    :public-url="$listing->publicUrl()" :sections="$pageSections" active="overview"/>

  <div class="premium-detail-layout">
    <main class="premium-detail-main">
      <x-detail-featured-media :item="$listing" :kind="$kind" :type-icon="$typeIcon"
        :fallback-title="$listing->category?->name ?? $kindTitle"
        fallback-text="Explore this listing below"
        :product-cover="$isProduct ? $listing->coverAsset : null"/>

      <h1 class="premium-detail-below-title">{{ $listing->title }}</h1>
      <div class="premium-showcase-actions">
        <div class="marketplace-detail-actions">
          @auth
            @if($isSaved)
              <form method="post" action="{{ route('saved-listings.destroy',$listing) }}">
                @csrf @method('DELETE')
                <button class="btn subtle" type="submit"><x-icon name="bookmark" size="16"/> Saved · Remove</button>
              </form>
            @else
              <form method="post" action="{{ route('saved-listings.store',$listing) }}">
                @csrf
                <button class="btn subtle" type="submit"><x-icon name="bookmark" size="16"/> Save listing</button>
              </form>
            @endif
            <a class="btn subtle" href="{{ route('account.saved-listings') }}"><x-icon name="star" size="16"/> My saved listings</a>
          @else
            <a class="btn subtle" href="{{ route('login') }}"><x-icon name="bookmark" size="16"/> Sign in to save</a>
          @endauth
        </div>
        <a class="premium-action-link" href="{{ route('marketplace.search',['type'=>$kind]) }}">More {{ $kindTitle }} listings <x-icon name="chevron-right" size="15"/></a>
      </div>


      @if($screenshots->isNotEmpty())
        <section class="panel premium-content-section" id="screenshots">
          <div class="panel-head"><div><span class="eyebrow">Visual resources</span><h2>Screenshots &amp; gallery</h2></div></div>
          <div class="builder-public-gallery">
            @foreach($screenshots as $shot)
              <a href="{{ $shot->previewUrl() }}" target="_blank" rel="noopener noreferrer"
                 aria-label="View screenshot: {{ $shot->alt_text }}">
                <img src="{{ $shot->previewUrl() }}" alt="{{ $shot->alt_text }}" loading="lazy">
              </a>
            @endforeach
          </div>
        </section>
      @endif

      <section class="panel premium-content-section" id="overview">
        <div class="panel-head"><div><span class="eyebrow">About this {{ $kind }}</span><h2>Overview</h2></div></div>
        <div class="listing-body premium-description">{!! $listing->description_html ? \App\Support\BuilderContent::sanitize($listing->description_html) : nl2br(e($listing->description)) !!}</div>
      </section>

      @if($isProduct)
        @if($listing->software_version || $listing->software_published_on || $listing->software_updated_on)
          <section class="panel premium-content-section" id="software-release">
            <div class="panel-head"><div><span class="eyebrow">Creator-provided release information</span><h2>Software details</h2></div></div>
            <dl class="premium-info-list">
              @if($listing->software_version)<div><dt>Software version</dt><dd>{{ $listing->software_version }}</dd></div>@endif
              @if($listing->software_published_on)<div><dt>Published date</dt><dd>{{ $listing->software_published_on->format('d M Y') }}</dd></div>@endif
              @if($listing->software_updated_on)<div><dt>Updated date</dt><dd>{{ $listing->software_updated_on->format('d M Y') }}</dd></div>@endif
            </dl>
            <p class="hint">Release dates and version are provided by the creator; they do not independently verify automatic updates or file approval.</p>
          </section>
        @endif
        <section class="panel premium-content-section" id="license">
          <div class="panel-head">
            <div><span class="eyebrow">Published terms</span><h2>Product license</h2></div>
            @if($listing->licenseDefinition)
              <a class="btn subtle compact" href="{{ $listing->licenseDefinition->publicUrl() }}">Read full license <x-icon name="chevron-right" size="15"/></a>
            @endif
          </div>
          <div class="listing-body premium-description">
            @if($listing->licenseDefinition)
              <div class="premium-license-summary"><x-icon name="file-check" size="22"/>
                <div><strong>Regular License · {{ $listing->licenseDefinition->name }}</strong>
                  <span>Version {{ $listing->licenseDefinition->version }}
                    @if($listing->regular_price !== null) · USD &#36;{{ number_format((float)$listing->regular_price, 2) }} @endif
                  </span></div>
              </div>
            @endif
            @if($listing->extendedLicenseDefinition && $listing->extended_price !== null)
              <div class="premium-license-summary"><x-icon name="shield" size="22"/>
                <div><strong>Extended License · {{ $listing->extendedLicenseDefinition->name }}</strong>
                  <span>Version {{ $listing->extendedLicenseDefinition->version }}
                    · USD &#36;{{ number_format((float)$listing->extended_price, 2) }}
                  </span></div>
              </div>
              <p><a href="{{ $listing->extendedLicenseDefinition->publicUrl() }}">Read the Extended License terms</a></p>
            @endif
            @if($listing->license_terms)<p>{!! nl2br(e($listing->license_terms)) !!}</p>@endif
            <p class="hint">Licensing rights are governed by the linked published license definition. File delivery requires a verified entitlement; direct checkout and payments are not available yet.</p>
          </div>
        </section>
      @else
        <section class="panel premium-content-section" id="workflow">
          <div class="panel-head"><div><span class="eyebrow">A private discussion</span><h2>How it works</h2></div></div>
          <div class="premium-steps">
            <div><span class="premium-step-number">01</span><div><strong>Review the listing</strong><p>Read the published scope and background before contacting the creator.</p></div></div>
            <div><span class="premium-step-number">02</span><div><strong>Discuss your requirements</strong><p>Send a private inquiry with your needs and any proposed budget.</p></div></div>
            <div><span class="premium-step-number">03</span><div><strong>Agree on the next steps</strong><p>Continue the conversation in your account. An inquiry does not create a payment, contract or booking.</p></div></div>
          </div>
        </section>
      @endif


    </main>

    <aside class="premium-detail-sidebar" aria-label="Listing information and actions">
      <section class="premium-side-card premium-price-card premium-offer-v3" aria-labelledby="detail-offer-heading">
        <div class="premium-side-card-body">
          @if($isProduct)
            <div class="premium-offer-heading">
              <div>
                <span class="eyebrow">PRODUCT LICENSE</span>
                <h2 id="detail-offer-heading" class="premium-offer-title">Choose your license</h2>
              </div>
              @if($layoutSample)<span class="premium-offer-status">Preview listing</span>@endif
            </div>

            <div class="premium-license-choice">
              <label class="premium-license-label" for="detail-license-tier">License type</label>
              <select id="detail-license-tier" class="premium-license-tier-select" data-detail-license-tier
                aria-describedby="detail-license-scope">
                <option value="regular"
                  data-price="{{ $regularPrice !== null ? '$'.number_format((float)$regularPrice,2) : 'Price on request' }}"
                  data-description="{{ $listing->licenseDefinition?->permissions ?: \App\Models\LicenseDefinition::tierDescription('regular') }}"
                  data-terms-url="{{ $listing->licenseDefinition?->publicUrl() }}">Regular License</option>
                @if($extendedLicense)
                  <option value="extended"
                    data-price="{{ '$'.number_format((float)$listing->extended_price,2) }}"
                    data-description="{{ $extendedLicense->permissions ?: \App\Models\LicenseDefinition::tierDescription('extended') }}"
                    data-terms-url="{{ $extendedLicense->publicUrl() }}">Extended License</option>
                @endif
              </select>
            </div>

            <div class="premium-offer-pricing">
              <div class="premium-offer-pricing-main">
                <span class="premium-offer-pricing-label">{{ $layoutSample ? 'Reference price' : 'Creator-listed price' }}</span>
                <strong data-detail-license-price aria-live="polite">{{ $regularPrice !== null ? '$'.number_format((float)$regularPrice,2) : 'Price on request' }}</strong>
              </div>
              <span class="premium-offer-currency">{{ $listing->currency ?: 'USD' }}</span>
            </div>

            <ul class="premium-feature-checklist" aria-label="Product access and license information">
              @if($listing->licenseDefinition?->status === 'active')
                <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Published license terms available</span></li>
              @endif
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Downloads protected by verified entitlements</span></li>
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Save this product to your account</span></li>
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Member comments and private support requests</span></li>
            </ul>

            <details class="premium-license-scope" id="detail-license-scope">
              <summary>License scope <x-icon name="chevron-down" size="16"/></summary>
              <p data-detail-license-description>{{ $listing->licenseDefinition?->permissions ?: \App\Models\LicenseDefinition::tierDescription('regular') }}</p>
            </details>

            <div class="premium-offer-actions">
              @if($listing->licenseDefinition)
                <a class="btn primary premium-full-action" data-detail-license-terms
                   href="{{ $listing->licenseDefinition->publicUrl() }}">
                  <x-icon name="file-check" size="17"/> View license details <x-icon name="arrow-up-right" size="16"/>
                </a>
              @endif
              @auth
                <a class="premium-offer-secondary-link" href="{{ route('account.downloads') }}">My entitled downloads <x-icon name="arrow-up-right" size="15"/></a>
              @endauth
            </div>
            <p class="premium-offer-footnote"><x-icon name="lock" size="15"/> Direct checkout is not enabled. Download access requires an existing verified entitlement.</p>
          @else
            <div class="premium-offer-heading">
              <div>
                <span class="eyebrow">{{ strtoupper($kindTitle) }} LISTING</span>
                <h2 id="detail-offer-heading" class="premium-offer-title">{{ $kindTitle }} overview</h2>
              </div>
              @if($layoutSample)<span class="premium-offer-status">Preview listing</span>@endif
            </div>
            <div class="premium-offer-pricing">
              <div class="premium-offer-pricing-main">
                <span class="premium-offer-pricing-label">{{ $listing->price !== null ? ($layoutSample ? 'Reference price' : 'Creator-listed price') : 'Price' }}</span>
                <strong>{{ $listing->price !== null ? '$'.number_format((float)$listing->price,2) : 'On request' }}</strong>
              </div>
              <span class="premium-offer-currency">{{ $listing->currency ?: 'USD' }}</span>
            </div>

            <ul class="premium-feature-checklist" aria-label="{{ $kindTitle }} listing features">
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Published marketplace listing</span></li>
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Save to your account for later</span></li>
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Member comments and private support requests</span></li>
              @if($canInquire)
                <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Private creator inquiries for eligible clients</span></li>
              @endif
            </ul>

            <div class="premium-offer-actions">
              @if($canInquire)
                @auth
                  @if(in_array(auth()->user()->role,['customer','subscriber'],true) && (int)auth()->id() !== (int)$listing->user_id)
                    <button type="button" class="btn primary premium-full-action" data-modal-open="request-service"><x-icon name="message" size="17"/> {{ $inquiryTitle }}</button>
                    <a class="premium-offer-secondary-link" href="{{ route('account.inquiries') }}">My conversations <x-icon name="arrow-up-right" size="15"/></a>
                  @elseif((int)auth()->id() === (int)$listing->user_id)
                    <a class="btn subtle premium-full-action" href="{{ route('studio.inquiries') }}">Manage inquiries <x-icon name="arrow-up-right" size="16"/></a>
                  @else
                    <p class="premium-offer-footnote">Private inquiries are available to eligible client accounts.</p>
                  @endif
                @else
                  <a class="btn primary premium-full-action" href="{{ route('login') }}"><x-icon name="log-in" size="17"/> Sign in to contact <x-icon name="arrow-up-right" size="16"/></a>
                @endauth
              @else
                <a class="btn subtle premium-full-action" href="{{ route('marketplace.search',['type'=>$kind]) }}">Explore {{ $kindTitle }} listings <x-icon name="arrow-up-right" size="16"/></a>
              @endif
            </div>
            @if($layoutSample)
              <p class="premium-offer-footnote"><x-icon name="info" size="15"/> Preview listing — not an active commercial offer.</p>
            @endif
          @endif
        </div>
      </section>
      <section class="premium-side-card">
        <div class="premium-side-card-heading"><h2>Information</h2></div>
        <dl class="premium-info-list">
          <div><dt>Type</dt><dd>{{ $kindTitle }}</dd></div>
          <div><dt>Category</dt><dd>{{ $listing->category?->name ?? 'Not categorized' }}</dd></div>
          <div><dt>Created</dt><dd>{{ $listing->created_at->format('d M Y') }}</dd></div>
          <div><dt>Last updated</dt><dd>{{ $listing->updated_at->format('d M Y') }}</dd></div>
          <div><dt>Currency</dt><dd>{{ $listing->currency ?: 'USD' }}</dd></div>
          @if($isProduct && $listing->licenseDefinition)
            <div><dt>License</dt><dd>{{ $listing->licenseDefinition->name }}</dd></div>
          @endif
          <div><dt>Reference</dt><dd>{{ $listing->public_id }}</dd></div>
        </dl>
      </section>
      <section class="premium-side-card premium-side-creator" id="publisher" aria-labelledby="publisher-heading">
        <div class="premium-side-card-heading"><h2 id="publisher-heading">Published by</h2></div>
        <div class="premium-side-card-body">
          <div class="premium-side-creator-row">
            <div class="premium-creator-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($ownerName,0,1)) }}</div>
            <div>
              @if($listing->creator?->isCreator())
                <a class="premium-side-creator-name" href="{{ route('creators.show',$listing->creator->public_id) }}" aria-label="View {{ $ownerName }}'s public creator profile">{{ $ownerName }}</a>
              @else
                <strong>{{ $ownerName }}</strong>
              @endif
              <small>Bambodia creator</small>
            </div>
          </div>
          @if($creatorBadges->isNotEmpty())
            <x-creator-badges :badges="$creatorBadges" variant="compact" mode="badge"/>
          @endif
          @if($listing->creator?->isCreator())
            <a class="btn subtle premium-full-action" href="{{ route('creators.show',$listing->creator->public_id) }}">
              <x-icon name="user-round" size="16"/> View creator profile <x-icon name="arrow-up-right" size="15"/>
            </a>
          @endif
        </div>
      </section>
    </aside>
  </div>

  @if($canInquire)
    @auth
      @if(in_array(auth()->user()->role,['customer','subscriber'],true) && (int)auth()->id() !== (int)$listing->user_id)
        <dialog class="config-dialog wide-dialog" id="request-service">
          <form method="post" action="{{ route('inquiries.store',$listing) }}">@csrf
            <h2>Contact this creator privately</h2>
            <p>Describe the work you would like to discuss. Only you and the creator can read the conversation.</p>
            <label for="inquiry-subject">Subject</label><input id="inquiry-subject" name="subject" minlength="6" maxlength="180" value="{{ old('subject') }}" required>
            <label for="inquiry-body">Project brief</label><textarea id="inquiry-body" name="body" minlength="40" maxlength="5000" rows="7" required>{{ old('body') }}</textarea>
            <label for="inquiry-budget">Proposed budget (USD, optional)</label><input id="inquiry-budget" name="proposed_budget" type="number" min="0" step="0.01" value="{{ old('proposed_budget') }}">
            <p class="hint">The inquiry starts a private conversation only. There is no payment processing or automatic contract.</p>
            <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Send inquiry</button></div>
          </form>
        </dialog>
      @endif
    @endauth
  @endif

  @if($relatedListings->isNotEmpty())
    <section class="marketplace-section premium-related" id="related">
      <div class="marketplace-section-heading">
        <div><span class="eyebrow">Continue exploring</span><h2>Related approved listings</h2></div>
        <a class="btn subtle compact" href="{{ route('marketplace.search',['type'=>$kind]) }}">Browse {{ $kindTitle }} listings</a>
      </div>
      <div class="marketplace-card-grid premium-related-grid" data-related-grid>
        @foreach($relatedListings->take(8) as $related)
          <x-listing-card :listing="$related" :is-saved="in_array((int)$related->id,$savedRelatedIds,true)"/>
        @endforeach
      </div>
    </section>
  @endif
</div>
@endsection
