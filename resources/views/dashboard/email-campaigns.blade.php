@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', 'Email Campaigns')
@push('head')
<link rel="stylesheet" href="{{ asset('css/email-configuration.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page email-configuration">
  <div class="page-intro">
    <div class="eyebrow">System Configuration</div>
    <h1>Email Campaigns</h1>
    <p>Prepare an announcement or promote a published listing to verified subscribers.</p>
  </div>
  <div class="email-transport panel">
    <div><strong>{{ number_format($subscribers) }} verified {{ Str::plural('subscriber', $subscribers) }}</strong><p>Members subscribe from their Notification Center.</p></div>
    <div class="email-actions">
      <a class="btn subtle compact" href="{{ route('dashboard.email-templates.index', ['category'=>'Campaign']) }}">Campaign Templates</a>
      <button class="btn primary compact" type="button" data-modal-open="create-email-campaign" aria-haspopup="dialog">New campaign</button>
    </div>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>Campaigns <span class="count">{{ $campaigns->total() }}</span></h2></div>
    <div class="table-scroll">
      <table class="data-table email-table">
        <thead><tr><th scope="col">Campaign</th><th scope="col">Status</th><th scope="col">Accepted</th><th scope="col">Failed</th><th scope="col">Review</th></tr></thead>
        <tbody>
          @forelse($campaigns as $campaign)
            @php
              $dialog = 'email-campaign-'.$campaign->id;
            @endphp
            <tr>
              <td><strong>{{ $campaign->title }}</strong><p>{{ \App\Support\EmailTemplates::defaults($campaign->event)['label'] }}</p></td>
              <td><span class="role-pill">{{ !$campaign->queued_at ? 'Draft' : ($campaign->expanded_at ? 'Audience queued' : 'Preparing audience') }}</span></td>
              <td>{{ $campaign->sent_count }} / {{ $campaign->deliveries_count }}</td>
              <td>{{ $campaign->failed_count }}</td>
              <td><button class="icon-button" type="button" data-modal-open="{{ $dialog }}" aria-haspopup="dialog" aria-label="Review {{ $campaign->title }}"><x-icon name="settings" size="18"/></button></td>
            </tr>
          @empty
            <tr><td colspan="5" class="empty-cell">Create a draft to prepare your first campaign.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$campaigns"/>
  </section>
  <dialog class="config-dialog email-dialog" id="create-email-campaign" aria-labelledby="create-email-campaign-title">
    <header class="email-dialog-heading">
      <h2 id="create-email-campaign-title">New email campaign</h2>
      <button type="button" class="icon-button" data-modal-close aria-label="Close campaign form"><x-icon name="x" size="18"/></button>
    </header>
    <form method="post" class="email-edit-form" action="{{ route('dashboard.email-campaigns.store') }}">
      @csrf
      @if($errors->any())<ul role="alert">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
      <label>Campaign type<select name="event" required>
        <option value="campaign.announcement" @selected(old('event') === 'campaign.announcement')>Marketplace announcement</option>
        <option value="campaign.listing" @selected(old('event') === 'campaign.listing')>Listing promotion</option>
      </select></label>
      <label>Title<input name="title" maxlength="180" required value="{{ old('title') }}"></label>
      <label>Message<textarea name="message" rows="6" maxlength="2500" required>{{ old('message') }}</textarea></label>
      <div class="email-form-columns">
        <label>Promote a<select name="kind">
          <option value="listing" @selected(old('kind') === 'listing')>Marketplace listing</option>
          <option value="job" @selected(old('kind') === 'job')>Job</option>
        </select></label>
        <label>Public reference<input name="listing" inputmode="numeric" maxlength="9" pattern="[0-9]{9}" value="{{ old('listing') }}"></label>
      </div>
      <p class="email-field-help">The public reference is required for listing promotions.</p>
      <div class="email-actions"><button class="btn primary">Save campaign draft</button></div>
    </form>
  </dialog>
  @if($errors->any())<script>document.getElementById('create-email-campaign').showModal();</script>@endif

  @foreach($campaigns as $campaign)
    @php
      $dialog = 'email-campaign-'.$campaign->id;
    @endphp
    <dialog class="config-dialog email-dialog" id="{{ $dialog }}" aria-labelledby="{{ $dialog }}-title">
      <header class="email-dialog-heading">
        <div><h2 id="{{ $dialog }}-title">{{ $campaign->title }}</h2><p>{{ \App\Support\EmailTemplates::defaults($campaign->event)['label'] }}</p></div>
        <button type="button" class="icon-button" data-modal-close aria-label="Close campaign"><x-icon name="x" size="18"/></button>
      </header>
      <div class="email-edit-form">
        <p class="email-campaign-message">{{ $campaign->message }}</p>
        <a href="{{ $campaign->target_path }}" target="_blank" rel="noopener">Open campaign destination</a>
        @if(!$campaign->queued_at)
          <form method="post" action="{{ route('dashboard.email-campaigns.queue', $campaign) }}" class="email-edit-form email-queue-form">
            @csrf
            <label class="email-opt-in"><input type="checkbox" name="confirm_audience" value="1" required> Queue for the {{ number_format($subscribers) }} currently verified subscribers.</label>
            <button class="btn primary" @disabled(!$deliveryReady || $subscribers === 0 || !in_array($campaign->event, $enabledCampaignTemplates, true))>Queue campaign</button>
            @if(!$deliveryReady)<p>Complete SMTP Setup to queue a campaign.</p>@endif
            @if(!in_array($campaign->event, $enabledCampaignTemplates, true))<p>Turn on this template in Email Templates to queue a campaign.</p>@endif
          </form>
          <form method="post" action="{{ route('dashboard.email-campaigns.destroy', $campaign) }}">@csrf @method('DELETE')<button class="btn subtle compact">Delete draft</button></form>
        @endif
      </div>
    </dialog>
  @endforeach
</div>
@endsection
