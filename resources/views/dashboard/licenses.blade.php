@extends('layouts.dashboard')
@section('title','License Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Governance</div><h1>License Manager</h1><p>Manage reusable Regular and Extended license terms and the unique product licenses issued to customers.</p></div>
<section class="panel">
  <div class="panel-head">
    <div><h2>License definitions <span class="count">{{ $licenses->total() }}</span></h2>
      <p>These are reusable usage rights for digital products—not customer license keys.</p></div>
    <div class="toolbar-actions">
      <form method="get" class="table-search"><x-icon name="search" size="17"/><input name="search" value="{{ $search }}" aria-label="Search licenses" placeholder="Code or license name"><button class="btn subtle" type="submit">Search</button></form>
      <button class="btn primary" type="button" data-modal-open="license-create">New license</button>
    </div>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Public ID</th><th>License</th><th>Code</th><th>Version</th><th>Status</th><th>Products</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($licenses as $license)
        @php($definitionLocked = $license->listings_count > 0 || $license->extended_listings_count > 0 || $license->issued_licenses_count > 0)
        <tr>
          <td class="mono">{{ $license->public_id }}</td><td><strong>{{ $license->name }}</strong></td>
          <td class="mono">{{ $license->code }}</td><td>{{ $license->version }}</td>
          <td><span class="role-pill">{{ ucfirst($license->status) }}</span></td><td>{{ $license->listings_count }}</td>
          <td>
            <button class="btn subtle compact license-action-trigger" type="button"
              id="license-action-trigger-{{ $license->id }}" popovertarget="license-actions-{{ $license->id }}"
              aria-haspopup="true" aria-label="Actions for {{ $license->name }}">
              <x-icon name="settings" size="15"/> Action <x-icon name="chevron-down" size="14"/>
            </button>
            <div id="license-actions-{{ $license->id }}" popover="auto"
              class="license-action-popover" data-license-action-menu data-license-anchor="license-action-trigger-{{ $license->id }}"
              aria-label="Actions for {{ $license->name }}">
              <button type="button" data-license-action data-modal-open="license-{{ $license->id }}"
                data-license-focus="license-heading-{{ $license->id }}"><x-icon name="eye" size="16"/> View definition</button>
              @unless($definitionLocked)
                <button type="button" data-license-action data-modal-open="license-{{ $license->id }}"
                  data-license-focus="license-code-{{ $license->id }}"><x-icon name="pencil" size="16"/> Edit terms</button>
                <button type="button" data-license-action data-modal-open="license-{{ $license->id }}"
                  data-license-focus="license-status-{{ $license->id }}"
                  data-license-select="{{ $license->status === 'active' ? 'draft' : 'active' }}">
                  <x-icon name="{{ $license->status === 'active' ? 'pause-circle' : 'play-circle' }}" size="16"/>
                  {{ $license->status === 'active' ? 'Deactivate' : 'Activate' }}
                </button>
              @endunless
              @if($license->status === 'active')
                <a href="{{ $license->publicUrl() }}" target="_blank" rel="noopener noreferrer"
                  data-license-action><x-icon name="external-link" size="16"/> Published terms</a>
              @endif
            </div>
            <dialog class="config-dialog wide-dialog" id="license-{{ $license->id }}">
              <form method="post" action="{{ route('dashboard.licenses.update',$license) }}">
                @csrf @method('PATCH')
                <h2 id="license-heading-{{ $license->id }}">{{ $license->name }}</h2>
                <p>Public license ID {{ $license->public_id }} · {{ $license->listings_count }} referenced products</p>
                @if($definitionLocked)
                  <div class="notice">This definition is already referenced by a product or issued license. Its rights are locked; create a new definition for changed terms.</div>
                  @if($license->status === 'active')<a href="{{ $license->publicUrl() }}" class="btn subtle" target="_blank" rel="noopener noreferrer">Read published license</a>@endif
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
                @else
                  <label for="license-code-{{ $license->id }}">License code (A–Z, 0–9, underscore)</label><input id="license-code-{{ $license->id }}" name="code" value="{{ $license->code }}" required maxlength="32">
                  <label for="license-name-{{ $license->id }}">Display name</label><input id="license-name-{{ $license->id }}" name="name" value="{{ $license->name }}" required maxlength="120">
                  <label for="license-version-{{ $license->id }}">Version</label><input id="license-version-{{ $license->id }}" name="version" value="{{ $license->version }}" required placeholder="1.0.0">
                  <label for="license-permissions-{{ $license->id }}">Permissions granted</label><textarea id="license-permissions-{{ $license->id }}" name="permissions" minlength="30" rows="6" required>{{ $license->permissions }}</textarea>
                  <label for="license-restrictions-{{ $license->id }}">Restrictions and prohibited uses</label><textarea id="license-restrictions-{{ $license->id }}" name="restrictions" minlength="30" rows="6" required>{{ $license->restrictions }}</textarea>
                  <label for="license-status-{{ $license->id }}">Status</label><select id="license-status-{{ $license->id }}" name="status"><option value="draft" @selected($license->status==='draft')>Draft</option><option value="active" @selected($license->status==='active')>Active</option></select>
                  <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save license</button></div>
                @endif
              </form>
            </dialog>
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="7">No license definitions have been created.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$licenses"/>
</section>
<dialog class="config-dialog wide-dialog" id="license-create">
  <form method="post" action="{{ route('dashboard.licenses.store') }}">
    @csrf
    <h2>Create a license definition</h2>
    <p>Define the rights granted to buyers and the uses that are prohibited. Legal review is required before activation.</p>
    <label for="license-new-code">License code</label><input id="license-new-code" name="code" placeholder="REGULAR" maxlength="32" required>
    <label for="license-new-name">License name</label><input id="license-new-name" name="name" maxlength="120" required>
    <label for="license-new-version">Version</label><input id="license-new-version" name="version" value="1.0.0" required>
    <label for="license-new-permissions">Permissions granted</label><textarea id="license-new-permissions" name="permissions" minlength="30" rows="6" required></textarea>
    <label for="license-new-restrictions">Restrictions and prohibited uses</label><textarea id="license-new-restrictions" name="restrictions" minlength="30" rows="6" required></textarea>
    <label for="license-new-status">Publication status</label><select id="license-new-status" name="status"><option value="draft">Draft</option><option value="active">Active</option></select>
    <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Create license</button></div>
  </form>
</dialog>


<section class="panel license-issued-panel" id="issued-product-licenses">
  <div class="panel-head license-issued-heading">
    <div class="license-issued-heading-text">
      <h2>Issued Product Licenses <span class="count">{{ $issued->total() }}</span></h2>
      <p>Licenses for verified downloadable products. License definitions do not create customer keys.</p>
    </div>
    @if(auth()->user()->role === 'super_admin')
      <button class="btn primary license-issue-trigger" type="button" data-modal-open="license-issue-dialog">
        <x-icon name="key-round" size="17"/> Manual License
      </button>
    @endif
  </div>
  <div class="license-issued-controls">
    <form class="license-issued-search" method="get" action="{{ route('dashboard.licenses.index') }}#issued-product-licenses" role="search">
      @foreach(request()->except(['issued_search','issued_sort','issued_page','page','support_page']) as $key=>$value)
        @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
      @endforeach
      <label class="sr-only" for="issued-license-search">Search issued product licenses</label>
      <span class="license-issued-search-field"><x-icon name="search" size="17"/>
        <input id="issued-license-search" name="issued_search" type="search" value="{{ $issuedSearch }}"
          maxlength="120" placeholder="License, product, customer, domain..." autocomplete="off"></span>
      <label class="sr-only" for="issued-license-sort">Sort issued product licenses</label>
      <select id="issued-license-sort" name="issued_sort" aria-label="Sort issued product licenses">
        <option value="recent" @selected($issuedSort==='recent')>Recent (newest first)</option>
        <option value="oldest" @selected($issuedSort==='oldest')>Oldest first</option>
        <option value="updated" @selected($issuedSort==='updated')>Recently updated</option>
        <option value="status" @selected($issuedSort==='status')>Status A–Z</option>
      </select>
      <button type="submit" class="btn subtle"><x-icon name="search" size="16"/> Search</button>
      @if($issuedSearch !== '' || $issuedSort !== 'recent')
        <a class="btn subtle" href="{{ route('dashboard.licenses.index',request()->except(['issued_search','issued_sort','issued_page'])) }}#issued-product-licenses">Reset</a>
      @endif
    </form>
  </div>
  @if(auth()->user()->role === 'super_admin')
    <dialog class="config-dialog wide-dialog license-issue-dialog" id="license-issue-dialog" aria-labelledby="license-issue-heading">
      <div class="license-issue-modal-head">
        <div><span class="eyebrow">Product licensing</span>
          <h2 id="license-issue-heading">Issue Manual License</h2>
          <p>Assign a complimentary license to an eligible product and customer.</p></div>
        <button class="btn subtle compact" type="button" data-modal-close aria-label="Close manual license dialog"><x-icon name="x" size="18"/></button>
      </div>
      <form method="post" action="{{ route('dashboard.licenses.issue') }}" class="license-workflow-form">
        @csrf
        <label for="issued-entitlement">Approved product / release / recipient</label>
        <select id="issued-entitlement" name="product_entitlement_id" required>
          <option value="">Choose an active entitlement with an approved downloadable original</option>
          @foreach($issuable as $grant)
            <option value="{{ $grant->id }}" @selected((string)old('product_entitlement_id')===(string)$grant->id)>
              {{ $grant->version?->product?->title }} · {{ $grant->version?->version }}
              · Customer {{ $grant->recipient?->public_id }} · Entitlement #{{ $grant->id }}
            </option>
          @endforeach
        </select>
        @if($issuable->isEmpty())
          <p class="hint">No eligible entitlements yet. Approve the downloadable product and its file entitlement before issuing a key.</p>
        @endif
        <p class="hint">Product rights, malware screening, license terms and file integrity are checked again on the server.</p>
        <div class="license-issue-fields">
          <div><label for="issued-tier">License tier</label>
            <select id="issued-tier" name="tier" required>
              <option value="regular" @selected(old('tier','regular')==='regular')>Regular License</option>
              <option value="extended" @selected(old('tier')==='extended')>Extended License (when offered)</option>
            </select></div>
          <div><label for="initial-support-term">Product support period</label>
            <select name="support_term" id="initial-support-term" required>
              @foreach(\App\Support\ProductLicenseSupport::TERMS as $term=>$label)
                <option value="{{ $term }}" @selected(old('support_term',\App\Support\ProductLicenseSupport::DEFAULT_TERM)===$term)>{{ $label }}</option>
              @endforeach
            </select></div>
        </div>
        <p class="hint">License validity is lifetime. Standard support starts at 6 months; an authorized administrator can assign longer complimentary support.</p>
        <label for="issued-reason">Reason and authorization</label>
        <textarea id="issued-reason" name="reason" rows="3" minlength="15" maxlength="500" required
          placeholder="Explain this complimentary issuance.">{{ old('reason') }}</textarea>
        <label class="check-label"><input type="checkbox" name="confirm_complimentary" value="1" required>
          I confirm this manually issued license is complimentary and backed by an approved downloadable product and entitlement.</label>
        <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button>
          <button type="submit" class="btn primary" @disabled($issuable->isEmpty())><x-icon name="key-round" size="17"/> Issue License</button>
        </div>
      </form>
    </dialog>
  @endif
  <div class="table-scroll license-issued-scroll" tabindex="0" role="region" aria-label="Issued product license table; scroll horizontally to see all columns">
    <table class="data-table license-issued-table">
    <thead><tr><th scope="col">No</th><th scope="col">License</th><th scope="col">Product</th><th scope="col">Customer</th><th scope="col">Terms</th><th scope="col">Period</th><th scope="col">Support</th><th scope="col">Status</th><th scope="col">Domain</th><th scope="col">Action</th></tr></thead>
    <tbody>
    @forelse($issued as $item)
      <tr>
        <td class="mono">{{ $issued->firstItem() + $loop->index }}</td>
        <td>
          <button class="license-table-link mono" type="button" data-modal-open="issued-{{ $item->id }}"
            aria-label="View license {{ $item->public_id }}">{{ $item->public_id }}</button>
          <div class="text-muted mono">{{ $item->maskedKey() }}</div>
        </td>
        <td>
          @if($item->product)
            <button class="license-table-link" type="button" data-modal-open="issued-product-{{ $item->id }}"
              aria-label="View product {{ $item->product->title }}">{{ $item->product->title }}</button>
            <div class="license-issued-subrow">
              <span class="mono">#{{ $item->product->public_id }}</span>
              <button type="button" class="license-copy-button" data-license-copy-url="{{ $item->product->publicUrl() }}"
                aria-label="Copy full product URL"><x-icon name="copy" size="15"/> Copy URL</button>
            </div>
          @else
            <span class="text-muted">Unavailable product</span>
          @endif
          <div class="text-muted">File v{{ $item->version?->version }}</div>
        </td>
        <td class="mono">{{ $item->customer?->public_id }}</td>
        <td>{{ ucfirst($item->tier) }}<div class="text-muted">{{ $item->definition?->name }} v{{ $item->definition?->version }}</div></td>
        <td><strong>Lifetime</strong></td>
        <td>
          <span class="role-pill {{ $item->supportStatus() === 'Expired' ? 'license-support-expired' : '' }}">{{ $item->supportStatus() }}</span>
          <span class="license-table-secondary">{{ $item->supportName() }}</span>
          @if($item->support_expires_at)<span class="license-table-secondary">Until {{ $item->support_expires_at->format('d M Y') }}</span>@endif
        </td>
        <td><span class="role-pill">{{ ucfirst($item->status) }}</span>
          @unless($item->isActive())<small class="text-muted">Not currently valid</small>@endunless</td>
        <td><span class="license-domain-cell">{{ $item->activations->where('status','active')->pluck('domain')->implode(', ') ?: 'Not activated' }}</span></td>
        <td>
          @if($item->product)
            <dialog class="config-dialog wide-dialog license-product-dialog" id="issued-product-{{ $item->id }}"
              aria-labelledby="issued-product-heading-{{ $item->id }}">
              <h2 id="issued-product-heading-{{ $item->id }}">{{ $item->product->title }}</h2>
              <p>Product #{{ $item->product->public_id }} · {{ ucfirst($item->product->status) }} · Downloadable product</p>
              <div class="license-product-summary">
                <div><small>Product</small><strong>{{ $item->product->title }}</strong></div>
                <div><small>Release</small><strong>v{{ $item->version?->version ?? 'Unavailable' }}</strong></div>
                <div><small>License</small><strong>{{ $item->public_id }}</strong></div>
                <div><small>Customer</small><strong>{{ $item->customer?->public_id }}</strong></div>
              </div>
              <div class="license-product-description">
                <h3>Product description</h3>
                <p>{{ $item->product->description ?: 'No product description available.' }}</p>
              </div>
              <label class="license-product-url-label" for="issued-product-url-{{ $item->id }}">Full product URL</label>
              <div class="license-product-url-row">
                <input id="issued-product-url-{{ $item->id }}" type="text" readonly value="{{ $item->product->publicUrl() }}">
                <button class="btn subtle compact" type="button" data-license-copy-url="{{ $item->product->publicUrl() }}">
                  <x-icon name="copy" size="16"/> Copy URL</button>
              </div>
              <div class="dialog-actions">
                <button class="btn subtle" type="button" data-modal-open="issued-{{ $item->id }}" data-modal-close>License details</button>
                @if($item->product->status === 'published')
                  <a class="btn subtle" href="{{ $item->product->publicUrl() }}" target="_blank" rel="noopener noreferrer">
                    <x-icon name="external-link" size="16"/> Open product</a>
                @endif
                <button type="button" class="btn primary" data-modal-close>Close</button>
              </div>
            </dialog>
          @endif
          <button class="btn subtle compact license-action-trigger" type="button"
            id="issued-action-trigger-{{ $item->id }}" popovertarget="issued-actions-{{ $item->id }}"
            aria-haspopup="true" aria-label="Actions for product license {{ $item->public_id }}">
            <x-icon name="settings" size="15"/> Action <x-icon name="chevron-down" size="14"/>
          </button>
          <div id="issued-actions-{{ $item->id }}" popover="auto"
            class="license-action-popover" data-license-action-menu data-license-anchor="issued-action-trigger-{{ $item->id }}"
            aria-label="Product license actions">
            <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
              data-license-focus="issued-{{ $item->id }}-heading"><x-icon name="eye" size="16"/> License details</button>
            <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
              data-license-focus="issued-{{ $item->id }}-history"><x-icon name="history" size="16"/> Activation history</button>
            @if($item->status !== 'revoked')
              <div class="license-action-separator"></div>
              @if($item->status === 'active')
                <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
                  data-license-focus="issued-{{ $item->id }}-status" data-license-select="suspend">
                  <x-icon name="pause-circle" size="16"/> Suspend license</button>
              @else
                <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
                  data-license-focus="issued-{{ $item->id }}-status" data-license-select="activate">
                  <x-icon name="play-circle" size="16"/> Activate license</button>
              @endif
              @if($item->tier === 'regular' && $item->product?->extended_price !== null)
                <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
                  data-license-focus="issued-{{ $item->id }}-upgrade"><x-icon name="shield" size="16"/> Upgrade to Extended</button>
              @endif
              <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
                data-license-focus="issued-{{ $item->id }}-domain"><x-icon name="globe" size="16"/> Change domain</button>
              <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
                data-license-focus="issued-{{ $item->id }}-rotate"><x-icon name="refresh-cw" size="16"/> Rotate license key</button>
              <div class="license-action-separator"></div>
              <button type="button" data-license-action data-modal-open="issued-{{ $item->id }}"
                data-license-focus="issued-{{ $item->id }}-status" data-license-select="revoke"
                class="is-danger"><x-icon name="ban" size="16"/> Revoke license</button>
            @endif
          </div>
          <dialog class="config-dialog wide-dialog" id="issued-{{ $item->id }}">
            <h2 id="issued-{{ $item->id }}-heading">Manage issued product license</h2>
            <p>License {{ $item->public_id }} · {{ $item->product?->title }} · {{ ucfirst($item->tier) }} · {{ ucfirst($item->status) }}</p>
            <p class="hint">The full secret key is available only to its authenticated customer after password confirmation. Domains and administrative changes are recorded below.</p>
            <div class="license-admin-actions">
              @if($item->status !== 'revoked')
                <form id="issued-{{ $item->id }}-status" method="post" action="{{ route('dashboard.licenses.issued.status',$item) }}">
                  @csrf @method('PATCH')
                  <label for="status-action-{{ $item->id }}">License status</label>
                  <select id="status-action-{{ $item->id }}" name="action" required>
                    @if($item->status==='active')<option value="suspend">Deactivate / suspend</option>@endif
                    @if($item->status==='suspended')<option value="activate">Reactivate</option>@endif
                    <option value="revoke">Revoke permanently (not deletable)</option>
                  </select>
                  <label>Reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required></textarea></label>
                  <label class="check-label"><input type="checkbox" name="confirm_change" value="1" required> Confirm this administrative action</label>
                  <button class="btn primary compact" type="submit">Update status</button>
                </form>
                @if($item->tier==='regular' && $item->product?->extended_price !== null)
                  <form id="issued-{{ $item->id }}-upgrade" method="post" action="{{ route('dashboard.licenses.issued.upgrade',$item) }}">
                    @csrf @method('PATCH')
                    <label>Upgrade reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required placeholder="Administrative entitlement to Extended License"></textarea></label>
                    <label class="check-label"><input type="checkbox" name="confirm_manual_upgrade" value="1" required> Approve manual upgrade to reviewed Extended License (no payment)</label>
                    <button class="btn subtle compact" type="submit">Upgrade to Extended</button>
                  </form>
                @endif
                <form id="issued-{{ $item->id }}-domain" method="post" action="{{ route('dashboard.licenses.issued.domain',$item) }}">
                  @csrf @method('PATCH')
                  <label>Replacement domain (optional; leave blank to deactivate all)</label>
                  <input name="domain" placeholder="new.example.com" maxlength="253">
                  <label>Reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required></textarea></label>
                  <button class="btn subtle compact" type="submit">Release current domain</button>
                  <p class="hint">The replacement domain is not auto-activated. The installed script must authenticate with the license key.</p>
                </form>
                <form id="issued-{{ $item->id }}-rotate" method="post" action="{{ route('dashboard.licenses.issued.rotate',$item) }}">
                  @csrf
                  <label>Key rotation reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required></textarea></label>
                  <label class="check-label"><input type="checkbox" name="confirm_rotate" value="1" required> Invalidate the old license key permanently</label>
                  <button class="btn subtle compact" type="submit">Rotate key</button>
                </form>
              @else
                <p class="hint">This key has been permanently revoked. Its event history is retained for accountability.</p>
              @endif
            </div>
            <h3>Domain activation & action history</h3>
            <div class="license-history" id="issued-{{ $item->id }}-history">
              @forelse($item->events->take(30) as $event)
                <div><strong>{{ ucfirst(str_replace('.',' ',$event->action)) }}</strong>
                <span>{{ $event->previous_domain ? $event->previous_domain.' → ' : '' }}{{ $event->domain }}
                  · {{ $event->created_at->format('d M Y H:i') }}
                  · {{ $event->actor?->name ?? 'Installer API' }}
                  @if($event->reason) · {{ $event->reason }} @endif</span></div>
              @empty <p class="hint">No events recorded.</p> @endforelse
            </div>
            <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>
          </dialog>
        </td>
      </tr>
    @empty<tr><td colspan="10" class="empty-cell">{{ $issuedSearch !== '' ? 'No issued licenses match your search.' : 'No issued product licenses yet. An approved downloadable product and entitlement are required.' }}</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$issued"/>
</section>

<section class="panel" id="support-renewal-requests">
  <div class="panel-head">
    <div>
      <h2>Support renewals <span class="count">{{ $supportRenewals->total() }}</span></h2>
      <p>Support periods renew independently from lifetime product licenses.</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Product / License</th><th>Customer</th><th>Requested support</th><th>Status</th><th>Requested on</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($supportRenewals as $renewal)
          <tr>
            <td>
              <strong>{{ $renewal->license?->product?->title ?? 'Unavailable product' }}</strong>
              <span class="license-table-secondary mono">{{ $renewal->license?->public_id }}</span>
              <span class="license-table-secondary">License period: Lifetime</span>
            </td>
            <td class="mono">{{ $renewal->requester?->public_id }}</td>
            <td>{{ \App\Support\ProductLicenseSupport::TERMS[$renewal->term] ?? 'Unknown' }}</td>
            <td><span class="role-pill">{{ ucfirst($renewal->status) }}</span></td>
            <td>{{ $renewal->created_at?->format('d M Y') }}</td>
            <td>
              @if($renewal->status==='pending' && auth()->user()->role==='super_admin')
                <button type="button" class="btn subtle compact" data-modal-open="support-review-{{ $renewal->id }}"><x-icon name="settings" size="16"/> Review</button>
                <dialog class="config-dialog wide-dialog" id="support-review-{{ $renewal->id }}">
                  <h2>Support renewal review</h2>
                  <p>{{ $renewal->license?->product?->title }} · {{ \App\Support\ProductLicenseSupport::TERMS[$renewal->term] ?? 'Unknown' }}</p>
                  <p class="hint">An approved renewal extends the support period only. It does not charge the customer or alter lifetime licensing.</p>
                  <form method="post" action="{{ route('dashboard.licenses.support.review',$renewal) }}" class="license-workflow-form">
                    @csrf
                    <label for="review-decision-{{ $renewal->id }}">Decision</label>
                    <select id="review-decision-{{ $renewal->id }}" name="decision" required>
                      <option value="approve">Approve complimentary support period</option>
                      <option value="reject">Reject request</option>
                    </select>
                    <label for="review-note-{{ $renewal->id }}">Reason and authorization</label>
                    <textarea id="review-note-{{ $renewal->id }}" name="reason" rows="3" minlength="15" maxlength="500" required></textarea>
                    <label class="check-label">
                      <input type="checkbox" name="confirm_manual" value="1" required>
                      Confirm this manual decision. No verified payment or financial transaction is recorded.
                    </label>
                    <div class="dialog-actions">
                      <button type="button" class="btn subtle" data-modal-close>Cancel</button>
                      <button class="btn primary" type="submit">Save decision</button>
                    </div>
                  </form>
                </dialog>
              @elseif($renewal->reviewed_at)
                <span class="license-table-secondary">{{ $renewal->reviewed_at->format('d M Y') }}</span>
                @if($renewal->review_note)<span class="license-table-secondary">{{ $renewal->review_note }}</span>@endif
              @else
                <span class="text-muted">Pending review</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">No support renewal requests yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$supportRenewals"/>
</section>
@endsection
