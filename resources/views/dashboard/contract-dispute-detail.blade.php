@extends('layouts.dashboard')
@section('title','Dispute Review')
@section('content')
<div class="page-intro">
<div class="eyebrow">Restricted case file · {{ $contract->public_id }}</div>
<h1>Contract Dispute Review</h1>
<p>{{ $contract->title }} · {{ $contract->client?->name }} and {{ $contract->freelancer?->name }}</p>
<div class="toolbar-actions"><span class="role-pill">{{ ucfirst(str_replace('_',' ',$dispute->status)) }}</span>
<a class="btn subtle" href="{{ route('dashboard.contracts.disputes') }}">Back to cases</a></div>
</div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<section class="panel"><div class="panel-head"><h2>Reported concern</h2><span class="role-pill">{{ ucfirst($dispute->category) }}</span></div>
<div class="onboarding-content">
<p><strong>Reporter:</strong> {{ $dispute->reporter?->name }} · {{ $dispute->created_at->format('d M Y H:i') }}</p>
<p class="review-text">{{ $dispute->details }}</p>
@if($dispute->resolution_note)
  <h3>Recorded case resolution</h3>
  <p class="review-text">{{ $dispute->resolution_note }}</p>
  <p><strong>Disposition:</strong> {{ ucfirst($dispute->disposition) }}</p>
@endif
</div></section>
<section class="panel"><div class="panel-head"><h2>Agreement offered terms</h2></div><div class="onboarding-content">
<h3>Scope</h3><p class="review-text">{{ $contract->scope }}</p>
<h3>Terms</h3><p class="review-text">{{ $contract->terms }}</p>
<p><strong>SHA-256 offer digest:</strong> <span class="mono hash-line">{{ $contract->terms_sha256 }}</span></p>
</div></section>
<section class="panel"><div class="panel-head"><h2>Milestones <span class="count">{{ $milestones->count() }}</span></h2></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>#</th><th>Title</th><th>Status</th><th>Delivery history</th><th>Recorded reviews</th></tr></thead><tbody>
@forelse($milestones as $milestone)
<tr><td>{{ $milestone->sequence }}</td><td><strong>{{ $milestone->title }}</strong><p class="review-text">{{ $milestone->description }}</p></td>
<td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$milestone->status)) }}</span></td>
<td>@foreach($milestone->submissions as $submission)<p class="review-text">{{ $submission->delivery_notes }}</p>@endforeach</td>
<td>@foreach($milestone->submissions as $submission)
@if($submission->review)<p><strong>{{ ucfirst(str_replace('_',' ',$submission->review->decision)) }}</strong>: {{ $submission->review->feedback }}</p>@endif
@endforeach</td></tr>
@empty<tr><td colspan="5" class="empty-cell">No milestones in this agreement.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-head"><h2>Contract communications <span class="count">{{ $messages->total() }}</span></h2></div>
<p class="hint">These private messages are accessible in this case file only because a participant reported a contract dispute. The read operation has been audited.</p>
<div class="conversation-thread">
@forelse($messages as $message)
<article class="conversation-message"><div class="conversation-byline"><strong>{{ $message->author?->name }}</strong><span>{{ $message->created_at->format('d M Y H:i') }}</span></div>
<p class="review-text">{{ $message->body }}</p></article>
@empty<p class="hint">No messages recorded.</p>@endforelse
</div><x-pagination :paginator="$messages"/></section>
@if(in_array($dispute->status,['open','under_review'],true))
<section class="panel"><div class="panel-head"><h2>Administrative decision</h2></div><div class="onboarding-content">
@if($dispute->status==='open')
<form method="post" action="{{ route('dashboard.contracts.decide',$dispute) }}">
@csrf @method('PATCH')<input type="hidden" name="decision" value="under_review">
<button type="submit" class="btn subtle">Mark under review</button>
</form>
@endif
<form method="post" action="{{ route('dashboard.contracts.decide',$dispute) }}" class="creator-application-form">
@csrf @method('PATCH')<input type="hidden" name="decision" value="resolved">
<label for="case-disposition">Operational case disposition</label>
<select id="case-disposition" name="disposition"><option value="resume">Resume milestone work</option><option value="cancel">Close agreement without payment action</option></select>
<label for="case-resolution">Document findings, policy and reasons</label>
<textarea id="case-resolution" name="resolution_note" minlength="30" maxlength="5000" rows="7" required></textarea>
<p class="hint">This records platform operational handling only. It does not issue a court ruling, reverse a transaction, refund a customer, or determine enforceability.</p>
<button type="submit" class="btn primary">Record and notify both parties</button>
</form>
</div></section>
@endif
@endsection
