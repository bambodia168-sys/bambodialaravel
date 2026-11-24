@extends('layouts.workspace')
@section('workspace', match(auth()->user()->supportTicketRoutePrefixForRequest()) { 'dashboard.support-tickets' => 'admin', 'studio.support-tickets' => 'creator', default => 'account' })
@section('title', 'Create Support Ticket')
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=5">
<script defer src="{{ asset('js/support-ticket-editor.js') }}?v=3"></script>
@endpush
@section('content')
<div class="container inner-page support-workspace">
  <div class="support-workspace-heading">
    <div>
      <a href="{{ route(auth()->user()->supportTicketRoutePrefixForRequest().'.index') }}" class="support-back-link"><x-icon name="chevron-left" size="16"/> My support tickets</a>
      <div class="eyebrow">Private assistance · Your account</div>
      <h1>Create support ticket</h1>
      <p>Explain what you need help with. Only you and authorized support staff can read the conversation.</p>
    </div>
    <a href="{{ route('support') }}" class="btn subtle"><x-icon name="book-open" size="17"/> Browse public help</a>
  </div>
  <form method="post" action="{{ route(auth()->user()->supportTicketRoutePrefixForRequest().'.store') }}" class="support-ticket-form" enctype="multipart/form-data" data-ticket-form>
    @csrf
    <section class="panel support-ticket-panel">
      <div class="support-form-top"><x-icon name="life-buoy" size="22"/><div><h2>Describe your request</h2><p>Provide a clear subject and enough detail for the support team to respond.</p></div></div>
      <label for="support-subject">Subject <span aria-hidden="true">*</span></label>
      <input id="support-subject" name="subject" value="{{ old('subject') }}" maxlength="180" minlength="5" required
        placeholder="For example: Help with a recent order or product license" autocomplete="off">
      @error('subject')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror

      @include('support.issue-fields')

      <label for="ticket-new-message">Message <span aria-hidden="true">*</span></label>
      @include('support.editor', ['editorId' => 'ticket-new-message', 'minimum' => 10])
      @error('message')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror

      @include('support.attachment-fields', ['attachmentId' => 'ticket-create-attachments'])
      @include('support.credential-fields')

      <div class="support-form-actions">
        <a href="{{ route(auth()->user()->supportTicketRoutePrefixForRequest().'.index') }}" class="btn subtle">Cancel</a>
        <button type="submit" class="btn primary"><x-icon name="send" size="17"/> Submit ticket</button>
      </div>
    </section>
  </form>
</div>
@endsection
