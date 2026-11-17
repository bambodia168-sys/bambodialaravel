@extends('layouts.dashboard')
@section('title', 'SMTP Setup')
@section('content')
<div class="page-intro">
  <div class="eyebrow">System Settings</div>
  <h1>SMTP Setup</h1>
  <p>Configure email delivery for checkout verification and other Bambodia notifications.</p>
</div>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2><x-icon name="mail" size="19"/> Active mail delivery</h2>
      @if($activeSender)
        <p>Dashboard SMTP sends from <strong>{{ $activeSender }}</strong>.</p>
      @else
        <p>Server environment mailer <strong>{{ $environmentMailer ?? 'Unconfigured' }}</strong></p>
      @endif
    </div>
    @if($activeSender)
      <form class="smtp-settings-actions" method="post" action="{{ route('dashboard.smtp.disable') }}">
        @csrf @method('DELETE')
        <button class="btn subtle compact" type="submit">Use server settings</button>
      </form>
    @endif
  </div>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <h2>SMTP draft</h2>
      <p>Save your provider details, then send a test email before enabling this draft.</p>
    </div>
    <div class="smtp-settings-actions">
      <button class="btn primary compact" type="submit" form="smtp-draft">Save draft</button>
    </div>
  </div>
  <form id="smtp-draft" class="settings-form smtp-settings-grid" method="post" action="{{ route('dashboard.smtp.save') }}">
    @csrf @method('PUT')
    <div>
      <label for="smtp-host">SMTP server</label>
      <input id="smtp-host" name="host" value="{{ old('host', $draft['host'] ?? '') }}" maxlength="253"
        placeholder="smtp.example.com" autocomplete="off" required>
    </div>
    <div>
      <label for="smtp-port">Port</label>
      <input id="smtp-port" name="port" type="number" min="1" max="65535"
        value="{{ old('port', $draft['port'] ?? 587) }}" required>
    </div>
    <div>
      <label for="smtp-encryption">Encryption</label>
      <select id="smtp-encryption" name="encryption" required>
        <option value="starttls" @selected(old('encryption', $draft['encryption'] ?? 'starttls') === 'starttls')>STARTTLS</option>
        <option value="tls" @selected(old('encryption', $draft['encryption'] ?? 'starttls') === 'tls')>Implicit TLS (SMTPS)</option>
      </select>
    </div>
    <div>
      <label for="smtp-username">Username</label>
      <input id="smtp-username" name="username" value="{{ old('username', $draft['username'] ?? '') }}"
        autocomplete="off" maxlength="255">
    </div>
    <div>
      <label for="smtp-password">Password or app password</label>
      <input id="smtp-password" name="password" type="password" autocomplete="new-password" maxlength="4096"
        placeholder="{{ $hasPassword ? 'Password stored' : 'Enter SMTP password' }}" aria-describedby="smtp-password-help">
      <p id="smtp-password-help" class="hint">{{ $hasPassword ? 'Leave blank to keep the stored password for this connection.' : 'Use the credential supplied by your email provider.' }}</p>
    </div>
    <div>
      <label for="smtp-from-address">Sender email</label>
      <input id="smtp-from-address" name="from_address" type="email" value="{{ old('from_address', $draft['from_address'] ?? '') }}"
        maxlength="254" required>
    </div>
    <div>
      <label for="smtp-from-name">Sender name</label>
      <input id="smtp-from-name" name="from_name" value="{{ old('from_name', $draft['from_name'] ?? config('app.name')) }}"
        maxlength="120" required>
    </div>
  </form>
</section>

@if($draft)
  <section class="panel">
    <div class="panel-head">
      <div>
        <h2>Test and enable</h2>
        <p>{{ $testedAt ? 'SMTP accepted a test on '.$testedAt->format('d M Y, H:i T').'.' : 'Send a test to an inbox you can check.' }}</p>
      </div>
      <div class="smtp-settings-actions">
        <button class="btn subtle compact" type="submit" form="smtp-test">Send test email</button>
        @if($testedAt && $hasUnappliedDraft)
          <form method="post" action="{{ route('dashboard.smtp.activate') }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $revision }}">
            <button class="btn primary compact" type="submit">Enable SMTP</button>
          </form>
        @endif
      </div>
    </div>
    <form id="smtp-test" class="settings-form" method="post" action="{{ route('dashboard.smtp.test') }}">
      @csrf
      <input type="hidden" name="revision" value="{{ $revision }}">
      <label for="smtp-recipient">Test recipient</label>
      <input id="smtp-recipient" name="recipient" type="email" value="{{ old('recipient', $defaultRecipient) }}"
        maxlength="254" required>
      <p class="hint">Check the inbox after sending. SMTP acceptance confirms submission to your mail server.</p>
    </form>
  </section>
@endif
@endsection
