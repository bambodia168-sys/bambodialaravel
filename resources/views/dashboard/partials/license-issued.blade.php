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
      <input type="hidden" name="tab" value="products">
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
        <a class="btn subtle" href="{{ route('dashboard.licenses.index',['tab' => 'products']) }}#issued-product-licenses">Reset</a>
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
          <button class="row-action-trigger license-action-trigger" type="button"
            id="issued-action-trigger-{{ $item->id }}" popovertarget="issued-actions-{{ $item->id }}"
            aria-haspopup="menu" aria-expanded="false" aria-label="Actions for product license {{ $item->public_id }}">
            <x-icon name="ellipsis-vertical" size="19"/>
          </button>
          <div id="issued-actions-{{ $item->id }}" popover="auto"
            class="row-action-popover license-action-popover" data-row-action-menu data-license-action-menu data-row-action-anchor="issued-action-trigger-{{ $item->id }}"
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
