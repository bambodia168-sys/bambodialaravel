@extends('layouts.main')
@section('title','Freelance Contract')
@section('content')
@php
  $client = (int)auth()->id()===(int)$contract->client_id;
  $freelancer = (int)auth()->id()===(int)$contract->freelancer_id;
  $allAccepted = $contract->milestones->isNotEmpty() && $contract->milestones->every(fn($m)=>$m->status==='accepted');
@endphp
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">Private agreement · {{ $contract->public_id }}</div>
    <h1>{{ $contract->title }}</h1>
    <p>{{ $contract->client?->name }} (client) · {{ $contract->freelancer?->name }} (freelancer)</p>
    <div class="toolbar-actions">
      <span class="role-pill">{{ ucfirst($contract->status) }}</span>
      <span class="role-pill">USD {{ number_format((float)$contract->agreed_budget,2) }} proposed value</span>
      <a class="btn subtle compact" href="{{ route('contracts.index') }}">All contracts</a>
    </div>
  </div>
  @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif

  <section class="panel">
    <div class="panel-head"><h2>Agreement scope and terms</h2><span class="role-pill">{{ $contract->offered_at ? 'Frozen offer' : 'Draft' }}</span></div>
    <div class="onboarding-content">
      <h3>Scope of work</h3><div class="review-text">{{ $contract->scope }}</div>
      <h3>Rights, revision and working terms</h3><div class="review-text">{{ $contract->terms }}</div>
      @if($contract->terms_sha256)
        <p><strong>Immutable offer digest (SHA-256):</strong> <span class="mono hash-line">{{ $contract->terms_sha256 }}</span></p>
      @endif
      <p class="hint">These are terms recorded by authenticated accounts. Bambodia has not independently verified the participants' legal identity, legal signatures, payment eligibility or enforceability of this agreement.</p>
      @if($client && in_array($contract->status,['draft','offered'],true))
        <form method="post" action="{{ route('contracts.cancel',$contract) }}" class="contract-inline-form">
          @csrf <button type="submit" class="btn danger">Withdraw unaccepted agreement</button>
        </form>
      @endif
      @if($freelancer && $contract->status==='offered')
        <form method="post" action="{{ route('contracts.respond',$contract) }}" class="contract-decision-form">
          @csrf
          <label class="check-label"><input type="checkbox" name="confirm_terms" value="1"> I reviewed the exact scope, milestone values and terms and acknowledge this agreement using my signed-in account.</label>
          <div class="dialog-actions">
            <button class="btn subtle" type="submit" name="decision" value="declined">Decline</button>
            <button class="btn primary" type="submit" name="decision" value="accepted">Accept agreement</button>
          </div>
        </form>
      @endif
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Milestones <span class="count">{{ $contract->milestones->count() }}</span></h2>
      @if($client && $contract->status==='draft')
        <button type="button" class="btn primary" data-modal-open="new-milestone">Add milestone</button>
      @endif
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>#</th><th>Deliverable</th><th>Target date</th><th>Value (USD)</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($contract->milestones as $milestone)
      <tr>
        <td class="mono">{{ $milestone->sequence }}</td>
        <td><strong>{{ $milestone->title }}</strong></td>
        <td>{{ $milestone->due_on?->format('d M Y') ?? 'Not specified' }}</td>
        <td>{{ number_format((float)$milestone->amount,2) }}</td>
        <td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$milestone->status)) }}</span></td>
        <td>
          <button class="btn subtle compact" type="button" data-modal-open="contract-milestone-{{ $milestone->id }}"><x-icon name="settings" size="15"/> Configure</button>
          <dialog class="config-dialog wide-dialog" id="contract-milestone-{{ $milestone->id }}">
            <h2>Milestone {{ $milestone->sequence }} · {{ $milestone->title }}</h2>
            <p class="review-text">{{ $milestone->description }}</p>
            <p><strong>Value:</strong> USD {{ number_format((float)$milestone->amount,2) }} (informational, not collected)</p>
            <p><strong>State:</strong> {{ ucfirst(str_replace('_',' ',$milestone->status)) }}</p>
            @php $submission=$latestSubmissions[$milestone->id]??null; @endphp
            @if($submission)
              <h3>Latest submitted evidence</h3>
              <p class="review-text">{{ $submission->delivery_notes }}</p>
              @if($submission->evidence_url)
                <a href="{{ $submission->evidence_url }}" class="btn subtle" target="_blank" rel="noopener noreferrer">Open linked evidence</a>
              @endif
              @if($submission->review)
                <p><strong>Client decision:</strong> {{ str_replace('_',' ',$submission->review->decision) }}</p>
                <p class="review-text">{{ $submission->review->feedback }}</p>
              @endif
            @endif
            @if($client && $contract->status==='draft')
              <form method="post" action="{{ route('contracts.milestones.destroy',$milestone) }}">
                @csrf @method('DELETE')
                <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button>
                  <button class="btn danger" type="submit">Remove from draft</button>
                </div>
              </form>
            @elseif($freelancer && $contract->status==='active' && in_array($milestone->status,['planned','revision_requested'],true))
              <form method="post" action="{{ route('contracts.milestones.deliver',$milestone) }}">
                @csrf
                <label for="deliver-{{ $milestone->id }}">Delivery evidence and completion notes</label>
                <textarea id="deliver-{{ $milestone->id }}" name="delivery_notes" minlength="40" maxlength="12000" rows="5" required></textarea>
                <label for="evidence-{{ $milestone->id }}">HTTPS evidence URL (optional)</label>
                <input id="evidence-{{ $milestone->id }}" type="url" name="evidence_url" placeholder="https://" maxlength="500">
                <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Submit deliverable</button></div>
              </form>
            @elseif($client && $contract->status==='active' && $milestone->status==='submitted')
              <form method="post" action="{{ route('contracts.milestones.review',$milestone) }}">
                @csrf
                <label for="feedback-{{ $milestone->id }}">Decision feedback</label>
                <textarea id="feedback-{{ $milestone->id }}" name="feedback" minlength="15" maxlength="4000" rows="4" required></textarea>
                <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button>
                  <button class="btn subtle" type="submit" name="decision" value="revision_requested">Request revisions</button>
                  <button class="btn primary" type="submit" name="decision" value="accepted">Accept deliverable</button>
                </div>
              </form>
            @else
              <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
            @endif
          </dialog>
        </td>
      </tr>
      @empty <tr><td colspan="6" class="empty-cell">No milestones. Add itemized deliverables before offering the agreement.</td></tr> @endforelse
      </tbody>
    </table></div>
    @if($client && $contract->status==='draft' && $contract->milestones->isNotEmpty())
      <div class="onboarding-content">
        <p><strong>Budget reconciliation:</strong> USD {{ number_format($contract->milestones->sum(fn($m)=>(float)$m->amount),2) }} milestone total / USD {{ number_format((float)$contract->agreed_budget,2) }} proposed agreement total.</p>
        <form method="post" action="{{ route('contracts.offer',$contract) }}" class="contract-decision-form">
          @csrf
          <label class="check-label"><input type="checkbox" name="confirm_offer" value="1" required>
            I have reviewed all itemized deliverables, values and terms, and authorize this exact private offer to be presented to the freelancer.</label>
          <button class="btn primary" type="submit">Send complete contract offer</button>
        </form>
      </div>
    @endif
    @if($allAccepted && $contract->status==='active')
      <div class="onboarding-content">
        @if($freelancer && ! $contract->freelancer_completed_at)
          <form method="post" action="{{ route('contracts.acknowledge',$contract) }}">
            @csrf <label class="check-label"><input type="checkbox" name="confirm_completion" value="1" required> I confirm that the agreed milestones have been delivered.</label>
            <button class="btn primary" type="submit">Acknowledge delivery complete</button>
          </form>
        @elseif($client && $contract->freelancer_completed_at)
          <form method="post" action="{{ route('contracts.complete',$contract) }}">
            @csrf <label class="check-label"><input type="checkbox" name="confirm_completion" value="1" required> I accept the finished project and acknowledge completion.</label>
            <button class="btn primary" type="submit">Confirm contract completed</button>
          </form>
        @else
          <p class="hint">Waiting for the other party to acknowledge completion.</p>
        @endif
      </div>
    @endif
  </section>
  @if($client && $contract->status==='draft')
    <dialog class="config-dialog wide-dialog" id="new-milestone">
      <form method="post" action="{{ route('contracts.milestones.store',$contract) }}">
        @csrf
        <h2>Add an itemized milestone</h2>
        <label for="milestone-title">Milestone title</label>
        <input id="milestone-title" name="title" minlength="5" maxlength="180" required>
        <label for="milestone-desc">Deliverable acceptance criteria</label>
        <textarea id="milestone-desc" name="description" minlength="30" maxlength="4000" rows="5" required></textarea>
        <label for="milestone-value">Informational value (USD)</label>
        <input id="milestone-value" name="amount" type="number" min="0" max="9999999999.99" step=".01" required>
        <label for="milestone-date">Target date (optional)</label><input id="milestone-date" name="due_on" type="date" min="{{ now()->toDateString() }}">
        <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save milestone</button></div>
      </form>
    </dialog>
  @endif

  <section class="panel">
    <div class="panel-head"><h2>Account acknowledgements</h2><span class="role-pill">{{ $contract->consents->count() }} recorded</span></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Account</th><th>Action</th><th>Terms digest</th><th>Recorded at</th></tr></thead>
      <tbody>
        @forelse($contract->consents as $consent)
          <tr><td>{{ $consent->actor?->name }}</td>
            <td>{{ str_replace('_',' ',$consent->action) }}</td>
            <td><span class="mono">{{ substr($consent->terms_sha256,0,16) }}…</span></td>
            <td>{{ $consent->created_at->format('d M Y H:i') }}</td>
          </tr>
        @empty <tr><td class="empty-cell" colspan="4">No offer acknowledgement has been recorded.</td></tr> @endforelse
      </tbody>
    </table></div>
    <p class="hint">These events prove that an authenticated application account recorded an action against the displayed digest. They do not independently verify personal identity, create a qualified electronic signature or confirm legal enforceability.</p>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Private contract messages <span class="count">{{ $messages->total() }}</span></h2></div>
    <div class="conversation-thread">
      @forelse($messages as $message)
        <article class="conversation-message {{ (int)$message->sender_id===(int)auth()->id()?'own':'' }}">
          <div class="conversation-byline"><strong>{{ $message->author?->name }}</strong><span>{{ $message->created_at->format('d M Y H:i') }}</span></div>
          <p class="review-text">{{ $message->body }}</p>
        </article>
      @empty <p class="hint">No private contract messages.</p> @endforelse
    </div>
    <x-pagination :paginator="$messages"/>
    @if(in_array($contract->status,['offered','active','disputed'],true))
      <div class="onboarding-content">
        <form method="post" action="{{ route('contracts.messages.store',$contract) }}" class="creator-application-form">
          @csrf <label for="contract-message">Send a private message to the other party</label>
          <textarea id="contract-message" name="body" minlength="2" maxlength="5000" rows="4" required></textarea>
          <button class="btn primary" type="submit">Send message</button>
        </form>
      </div>
    @endif
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Contract review and dispute</h2><span class="role-pill">{{ $contract->dispute?->status ?? 'No case' }}</span></div>
    <div class="onboarding-content">
      @if($contract->dispute)
        <p><strong>Category:</strong> {{ ucfirst($contract->dispute->category) }}</p>
        <div class="review-text">{{ $contract->dispute->details }}</div>
        @if($contract->dispute->resolution_note)
          <h3>Administrative case outcome</h3><p class="review-text">{{ $contract->dispute->resolution_note }}</p>
          <p><strong>Disposition:</strong> {{ ucfirst($contract->dispute->disposition) }}</p>
        @endif
        <p class="hint">An administrator's operational decision is not an external legal judgment, arbitration award or financial refund.</p>
      @elseif($contract->status==='active')
        <button class="btn subtle" type="button" data-modal-open="contract-dispute">Report a contract dispute</button>
        <dialog class="config-dialog wide-dialog" id="contract-dispute">
          <form method="post" action="{{ route('contracts.disputes.store',$contract) }}">
            @csrf
            <h2>Report a disagreement</h2>
            <label for="dispute-type">Category</label><select id="dispute-type" name="category">
              @foreach(['scope','quality','timeline','conduct','other'] as $value)<option value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach
            </select>
            <label for="dispute-details">Factual details</label>
            <textarea id="dispute-details" name="details" minlength="50" maxlength="6000" rows="6" required></textarea>
            <label class="check-label"><input type="checkbox" name="confirm_report" value="1" required>
              I understand the reported case will be visible to administrators responsible for dispute review and will pause milestone activity.</label>
            <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Submit dispute report</button></div>
          </form>
        </dialog>
      @else
        <p class="hint">Dispute reporting is available during an active agreement. Contact support for other concerns.</p>
      @endif
    </div>
  </section>
  <p class="hint">No milestone action transfers funds. Payment gateways, escrow, verified signatures, tax invoices, payouts and external identity checks are not enabled.</p>
</div>
@endsection
