<section class="license-definition-guide" aria-labelledby="license-definitions-guide">
  <div class="license-definition-guide-header">
    <div><span class="eyebrow">License Definitions</span>
      <h2 id="license-definitions-guide">Regular & Extended License</h2>
      <p>Define the product usage rights before a creator can attach them in Builder Studio. These definitions are not customer keys.</p>
    </div>
  </div>
  <div class="license-definition-guide-grid">
    <article class="license-definition-guide-card">
      <h3><x-icon name="file-check" size="20"/> Regular License</h3>
      <p>{{ \App\Models\LicenseDefinition::tierDescription('regular') }}</p>
      <p class="hint">The customer's product license is lifetime; included standard support is six months, separately renewable.</p>
      <button type="button" class="btn subtle compact" data-license-preset="regular" data-modal-open="license-create"><x-icon name="plus" size="16"/> Draft Regular terms</button>
    </article>
    <article class="license-definition-guide-card">
      <h3><x-icon name="shield" size="20"/> Extended License</h3>
      <p>{{ \App\Models\LicenseDefinition::tierDescription('extended') }}</p>
      <p class="hint">Expanded permissions exist only when expressly written and approved; Extended does not transfer ownership of source code.</p>
      <button type="button" class="btn subtle compact" data-license-preset="extended" data-modal-open="license-create"><x-icon name="plus" size="16"/> Draft Extended terms</button>
    </article>
  </div>
  <p class="license-definition-guide-footer"><x-icon name="shield" size="16"/> Starter language is editable guidance, not an automatically approved legal policy. Review and publish each definition before products use it.</p>
</section>
<section class="panel">
  <div class="panel-head">
    <div><h2>{{ $archived ? 'Archived definitions' : 'License definitions' }} <span class="count">{{ $licenses->total() }}</span></h2>
      <p>These are reusable usage rights for digital products—not customer license keys.</p></div>
    <div class="toolbar-actions">
      <form method="get" class="table-search">
        <input type="hidden" name="tab" value="definitions">
        @if($archived)<input type="hidden" name="archived" value="1">@endif
        <x-icon name="search" size="17"/>
        <input name="search" value="{{ $search }}" aria-label="Search licenses" placeholder="ID, code or license name">
        <button class="btn subtle" type="submit">Search</button>
      </form>
      <a class="btn subtle" href="{{ route('dashboard.licenses.index', ['tab' => 'definitions', 'archived' => $archived ? null : 1]) }}">{{ $archived ? 'Current definitions' : 'Archived definitions' }}</a>
      <button class="btn primary" type="button" data-modal-open="license-create">New license</button>
    </div>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Public ID</th><th>License</th><th>Type</th><th>Code</th><th>Version</th><th>Status</th><th>Products</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($licenses as $license)
        @php
          $definitionLocked = $license->listings_count > 0 || $license->extended_listings_count > 0 || $license->issued_licenses_count > 0;
          $editable = !$definitionLocked && $license->status !== 'archived';
          $archiveFixture = $definitionLocked && $license->canArchiveLayoutTest();
          $removable = !$definitionLocked || $archiveFixture;
        @endphp
        <tr>
          <td class="mono">{{ $license->public_id }}</td><td><strong>{{ $license->name }}</strong></td>
          <td><span class="role-pill">{{ $license->tierLabel() }}</span></td>
          <td class="mono">{{ $license->code }}</td><td>{{ $license->version }}</td>
          <td><span class="role-pill">{{ ucfirst($license->status) }}</span></td><td>{{ $license->listings_count + $license->extended_listings_count }}</td>
          <td>
            <button class="row-action-trigger license-action-trigger" type="button"
              id="license-action-trigger-{{ $license->id }}" popovertarget="license-actions-{{ $license->id }}"
              aria-haspopup="menu" aria-expanded="false" aria-label="Actions for {{ $license->name }}">
              <x-icon name="ellipsis-vertical" size="19"/>
            </button>
            <div id="license-actions-{{ $license->id }}" popover="auto"
              class="row-action-popover license-action-popover" data-row-action-menu data-license-action-menu data-row-action-anchor="license-action-trigger-{{ $license->id }}"
              aria-label="Actions for {{ $license->name }}">
              <button type="button" data-license-action data-modal-open="license-view-{{ $license->id }}"><x-icon name="eye" size="16"/> View definition</button>
              @if($editable)
                <button type="button" data-license-action data-modal-open="license-{{ $license->id }}"
                  data-license-focus="license-code-{{ $license->id }}"><x-icon name="pencil" size="16"/> Edit terms</button>
                <button type="button" data-license-action data-modal-open="license-{{ $license->id }}"
                  data-license-focus="license-status-{{ $license->id }}"
                  data-license-select="{{ $license->status === 'active' ? 'draft' : 'active' }}">
                  <x-icon name="{{ $license->status === 'active' ? 'pause-circle' : 'play-circle' }}" size="16"/>
                  {{ $license->status === 'active' ? 'Deactivate' : 'Activate' }}
                </button>
              @endif
              <button type="button" data-modal-open="license-create"><x-icon name="plus" size="16"/> New definition</button>
              @if($removable)
                <button type="button" class="is-danger" data-modal-open="license-remove-{{ $license->id }}">
                  <x-icon name="trash-2" size="16"/> {{ $archiveFixture ? 'Archive layout-test definition' : 'Delete definition' }}
                </button>
              @endif
              @if($license->status === 'active')
                <a href="{{ $license->publicUrl() }}" target="_blank" rel="noopener noreferrer"
                  data-license-action><x-icon name="external-link" size="16"/> Published terms</a>
              @endif
            </div>
            <dialog class="config-dialog wide-dialog" id="license-view-{{ $license->id }}" aria-labelledby="license-heading-{{ $license->id }}">
              <h2 id="license-heading-{{ $license->id }}">{{ $license->name }}</h2>
              <p>{{ $license->public_id }} · {{ $license->tierLabel() }} · v{{ $license->version }} · {{ ucfirst($license->status) }}</p>
              <h3>Permissions granted</h3>
              <div class="license-terms-copy">{!! $license->permissionsMarkup() !!}</div>
              <h3>Restrictions and prohibited uses</h3>
              <div class="license-terms-copy">{!! $license->restrictionsMarkup() !!}</div>
              <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
            </dialog>
            @if($removable)
              <dialog class="config-dialog wide-dialog" id="license-remove-{{ $license->id }}">
                <h2>{{ $archiveFixture ? 'Archive layout-test definition' : 'Delete definition' }}</h2>
                <p>{{ $license->name }} · {{ $license->public_id }}</p>
                @if($archiveFixture)
                  <p>Archiving removes these terms from the public license page and makes the linked layout-test products unavailable in the public marketplace.</p>
                @else
                  <p>Delete this unused definition permanently.</p>
                @endif
                <form method="post" action="{{ route('dashboard.licenses.destroy', $license) }}">
                  @csrf @method('DELETE')
                  <label class="check-label"><input type="checkbox" name="confirm_remove" value="1" required> Confirm removal</label>
                  <div class="dialog-actions">
                    <button class="btn subtle" type="button" data-modal-close>Cancel</button>
                    <button class="btn danger" type="submit">{{ $archiveFixture ? 'Archive definition' : 'Delete definition' }}</button>
                  </div>
                </form>
              </dialog>
            @endif
            @if($editable)
            @php($restoreEdit = $errors->any() && old('definition_form') === 'edit-'.$license->id)
            <dialog class="config-dialog wide-dialog" id="license-{{ $license->id }}" @if($restoreEdit) data-license-reopen @endif>
              <form method="post" action="{{ route('dashboard.licenses.update',$license) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="definition_form" value="edit-{{ $license->id }}">
                <h2>Edit {{ $license->name }}</h2>
                <p>Public license ID {{ $license->public_id }} · {{ $license->tierLabel() }} · {{ $license->listings_count + $license->extended_listings_count }} referenced products</p>
                  <label for="license-tier-{{ $license->id }}">License definition type</label>
                  <select id="license-tier-{{ $license->id }}" name="tier" required>
                    @foreach(\App\Models\LicenseDefinition::TIERS as $tier=>$label)
                      <option value="{{ $tier }}" @selected(($restoreEdit ? old('tier') : $license->tier) === $tier)>{{ $label }}</option>
                    @endforeach
                  </select>
                  @if(!$license->tier)<p class="hint">This older definition is unclassified. Assign its type before saving; existing issued licenses keep their terms.</p>@endif
                  <label for="license-code-{{ $license->id }}">License code (A–Z, 0–9, underscore)</label><input id="license-code-{{ $license->id }}" name="code" value="{{ $restoreEdit ? old('code') : $license->code }}" required maxlength="32">
                  <label for="license-name-{{ $license->id }}">Display name</label><input id="license-name-{{ $license->id }}" name="name" value="{{ $restoreEdit ? old('name') : $license->name }}" required maxlength="120">
                  <label for="license-version-{{ $license->id }}">Version</label><input id="license-version-{{ $license->id }}" name="version" value="{{ $restoreEdit ? old('version') : $license->version }}" required placeholder="1.0.0">
                  <x-license-terms-editor id="license-permissions-{{ $license->id }}" field="permissions" label="Permissions granted" :value="$restoreEdit ? (string) old('permissions') : $license->permissions" :html="$restoreEdit ? old('permissions_html') : $license->permissions_html"/>
                  <x-license-terms-editor id="license-restrictions-{{ $license->id }}" field="restrictions" label="Restrictions and prohibited uses" :value="$restoreEdit ? (string) old('restrictions') : $license->restrictions" :html="$restoreEdit ? old('restrictions_html') : $license->restrictions_html"/>
                  <label for="license-status-{{ $license->id }}">Status</label><select id="license-status-{{ $license->id }}" name="status"><option value="draft" @selected(($restoreEdit ? old('status') : $license->status) === 'draft')>Draft</option><option value="active" @selected(($restoreEdit ? old('status') : $license->status) === 'active')>Active</option></select>
                  <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save license</button></div>
              </form>
            </dialog>
            @endif
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="8">{{ $archived ? 'No archived definitions match.' : 'No license definitions match.' }}</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$licenses"/>
</section>
@php($restoreCreate = $errors->any() && old('definition_form') === 'create')
<dialog class="config-dialog wide-dialog" id="license-create" @if($restoreCreate) data-license-reopen @endif>
  <form method="post" action="{{ route('dashboard.licenses.store') }}">
    @csrf
    <input type="hidden" name="definition_form" value="create">
    <h2>Create a license definition</h2>
    <p>Define the rights granted to buyers and the prohibited uses for one license type. Review the draft wording before publishing it.</p>
    <label for="license-new-tier">License definition type</label>
    <select id="license-new-tier" name="tier" required>
      <option value="regular" @selected(!$restoreCreate || old('tier') === 'regular')>Regular License</option>
      <option value="extended" @selected($restoreCreate && old('tier') === 'extended')>Extended License</option>
    </select>
    <label for="license-new-code">License code</label><input id="license-new-code" name="code" value="{{ $restoreCreate ? old('code') : '' }}" placeholder="REGULAR" maxlength="32" required>
    <label for="license-new-name">License name</label><input id="license-new-name" name="name" value="{{ $restoreCreate ? old('name') : '' }}" maxlength="120" required>
    <label for="license-new-version">Version</label><input id="license-new-version" name="version" value="{{ $restoreCreate ? old('version') : '1.0.0' }}" required>
    <x-license-terms-editor id="license-new-permissions" field="permissions" label="Permissions granted" :value="$restoreCreate ? (string) old('permissions') : ''" :html="$restoreCreate ? old('permissions_html') : null"/>
    <x-license-terms-editor id="license-new-restrictions" field="restrictions" label="Restrictions and prohibited uses" :value="$restoreCreate ? (string) old('restrictions') : ''" :html="$restoreCreate ? old('restrictions_html') : null"/>
    <label for="license-new-status">Publication status</label><select id="license-new-status" name="status"><option value="draft">Draft</option><option value="active" @selected($restoreCreate && old('status') === 'active')>Active</option></select>
    <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Create license</button></div>
  </form>
</dialog>
