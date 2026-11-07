@extends('layouts.workspace')
@section('workspace','account')
@section('title','Privacy Requests')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">Account · Privacy request center</div>
<h1>Privacy Requests</h1>
<p>Submit a request about your personal data and track administrator responses. Submitting a case records your request; it does not automatically change or erase your account data.</p>
</div>
<section class="panel">
<div class="panel-head"><h2>Submitted requests <span class="count">{{ $requests->total() }}</span></h2>
<button class="btn primary" type="button" data-modal-open="new-privacy-request">New privacy request</button>
</div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Reference</th><th>Request type</th><th>Status</th><th>Submitted</th><th>Updated</th><th>Action</th></tr></thead>
<tbody>
@forelse($requests as $record)
<tr><td class="mono">{{ $record->public_id }}</td>
<td><strong>{{ ucfirst($record->category) }}</strong></td>
<td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$record->status)) }}</span></td>
<td>{{ $record->created_at->format('d M Y') }}</td>
<td>{{ $record->updated_at->format('d M Y') }}</td>
<td><x-row-action-menu :id="'account-privacy-'.$record->id" :label="'Privacy request '.$record->public_id"><a href="{{ route('privacy.show',$record) }}"><x-icon name="eye" size="16"/> View request</a></x-row-action-menu></td></tr>
@empty<tr><td colspan="6" class="empty-cell">No privacy requests recorded for your account.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$requests"/>
</section>
<dialog class="config-dialog wide-dialog" id="new-privacy-request">
<form method="post" action="{{ route('privacy.store') }}">@csrf
<h2>Submit a personal-data request</h2>
<p>Select the reason for your request. An authorized operator must assess your request in accordance with applicable law and your account information.</p>
<label for="privacy-category">Request category</label>
<select name="category" id="privacy-category" required>
@foreach(\App\Models\PrivacyRequest::CATEGORIES as $category)
<option value="{{ $category }}">{{ ucfirst($category) }}</option>
@endforeach
</select>
<label for="privacy-details">Information needed to understand your request</label>
<textarea name="details" id="privacy-details" minlength="40" maxlength="6000" rows="6" required></textarea>
<p class="hint">Do not send passwords, payment details, identity documents or other unnecessary sensitive information through this form.</p>
<label class="check-label"><input name="confirm_submission" type="checkbox" value="1" required> I understand that this creates a review request and does not automatically fulfill an erasure, access or portability obligation.</label>
<div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Submit request</button></div>
</form>
</dialog>
</div>
@endsection
