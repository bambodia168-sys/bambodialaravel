@extends($isStaff ? 'layouts.dashboard' : 'layouts.workspace')
@section('workspace', $isStaff ? 'admin' : match(auth()->user()->supportTicketRoutePrefixForRequest()) { 'dashboard.support-tickets' => 'admin', 'studio.support-tickets' => 'creator', default => 'account' })
@section('title', 'Edit Support Ticket '.$ticket->public_id)
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=5">
@if(! $isStaff)<script defer src="{{ asset('js/support-ticket-editor.js') }}?v=3"></script>@endif
@endpush
@section('content')
@php
  $backUrl = $isStaff
    ? route('dashboard.support.show', $ticket->public_id)
    : route(auth()->user()->supportTicketRoutePrefixForRequest().'.show', $ticket->public_id);
  $updateUrl = $isStaff
    ? route('dashboard.support.update', $ticket->public_id)
    : route(auth()->user()->supportTicketRoutePrefixForRequest().'.update', $ticket->public_id);
@endphp
<div class="container inner-page support-workspace">
  <div class="support-workspace-heading">
    <div>
      <a class="support-back-link" href="{{ $backUrl }}"><x-icon name="chevron-left" size="16"/> Back to conversation</a>
      <div class="eyebrow">Private assistance · Ticket #{{ $ticket->public_id }}</div>
      <h1>Edit support ticket</h1>
      <p>{{ $isStaff ? 'Adjust the ticket subject without changing the customer conversation.' : 'Revise your request before the support team responds.' }}</p>
    </div>
  </div>
  <form method="post" action="{{ $updateUrl }}" enctype="multipart/form-data" class="support-ticket-form" @unless($isStaff) data-ticket-form @endunless>
    @csrf @method('PATCH')
    <section class="panel support-ticket-panel">
      <div class="support-form-top"><x-icon name="pencil" size="22"/><div><h2>Ticket details</h2><p>Changes are recorded in the support audit log.</p></div></div>
      <label for="support-subject">Subject</label>
      <input id="support-subject" type="text" name="subject" required minlength="5" maxlength="180"
        value="{{ old('subject', $ticket->subject) }}">
      @error('subject')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
      @unless($isStaff)
        @include('support.issue-fields')
        <label for="ticket-edit-message">Original message</label>
        @include('support.editor', ['editorId'=>'ticket-edit-message', 'minimum'=>10, 'editorValue'=>$firstMessage->body, 'allowCredentialButton'=>false])
        @error('message')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
        @include('support.attachment-fields', ['attachmentId'=>'ticket-edit-attachments'])
      @else
        <p class="text-muted">Original messages cannot be rewritten by support staff or administrators.</p>
      @endunless
      <div class="support-form-actions">
        <a class="btn subtle" href="{{ $backUrl }}">Cancel</a>
        <button class="btn primary" type="submit"><x-icon name="save" size="16"/> Save changes</button>
      </div>
    </section>
  </form>
</div>
@endsection
