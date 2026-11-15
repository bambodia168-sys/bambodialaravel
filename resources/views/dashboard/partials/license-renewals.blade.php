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
