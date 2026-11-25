@extends('layouts.workspace')
@section('workspace', $creatorView ? 'creator' : 'account')
@section('title', 'Project Tracking')
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=5">
<link rel="stylesheet" href="{{ asset('css/project-tracking.css') }}?v=3">
<script defer src="{{ asset('js/support-ticket-editor.js') }}?v=3"></script>
<script defer src="{{ asset('js/project-tracking.js') }}?v=3"></script>
@endpush
@section('content')
@php
  $stageName = \App\Models\ServiceInquiry::DELIVERY_STATUSES[$deliveryStatus] ?? 'In progress';
  $stepIndex = match($deliveryStatus){
    'awaiting_requirements'=>1,'in_progress'=>2,'revision_requested'=>2,
    'delivered'=>3,'accepted'=>4,default=>1,
  };
  $doneTasks = $tasks->where('status','completed')->count();
  $backRoute = $creatorView ? 'studio.tracking.index' : 'account.tracking.index';
  $canWriteTasks = $creatorView && $deliveryStatus !== 'accepted' && $deliveryStatus !== 'delivered';
  $canChangeSchedule = $deliveryStatus !== 'accepted';
  $trackingMode = true;
@endphp
<div class="container inner-page project-tracking project-tracking-detail">
  <div class="tracking-detail-head">
    <a class="tracking-back" href="{{ route($backRoute) }}"><x-icon name="chevron-left" size="16"/> Project tracking</a>
    <div class="tracking-detail-title">
      <div>
        <div class="eyebrow">PRIVATE {{ strtoupper($listingType) }} TRACKING · #{{ $order->reference() }}</div>
        <h1>{{ $order->listing_title }}</h1>
        <p>Customer: {{ $order->buyer_name }} · Creator: {{ $order->seller_name }}</p>
      </div>
      <span class="tracking-state tracking-state-{{ $deliveryStatus }}">{{ $stageName }}</span>
    </div>
    @if(session('status'))<p class="tracking-notice" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())
      <div class="tracking-errors" role="alert"><strong>Please review the details.</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
  </div>
  <section class="tracking-panel tracking-progress-section" aria-label="Overall delivery progress">
    <div class="tracking-progress-head">
      <div><span class="tracking-overline">ORDER DELIVERY WORKFLOW</span>
        <h2>Delivery progress</h2><p>Progress represents delivery stages, not hours or guaranteed completion.</p></div>
      <div class="tracking-progress-percentage"><strong data-tracking-percent>{{ $progress }}%</strong><span>Workflow stage</span></div>
    </div>
    <progress class="tracking-main-progress" value="{{ $progress }}" max="100">{{ $progress }}%</progress>
    <ol class="tracking-stepper">
      @foreach([
        ['Order placed','Payment verified'],['Requirements','Customer brief'],
        ['In progress','Creator working'],['In review','Buyer decision'],['Completed','Delivery accepted']
        ] as $i=>$step)
      <li class="{{ $i < $stepIndex ? 'is-done' : ($i === $stepIndex ? 'is-current' : 'is-future') }}">
        <span class="tracking-step-symbol">{{ $i < $stepIndex ? '✓' : ($i+1) }}</span>
        <span><strong>{{ $step[0] }}</strong><small>{{ $step[1] }}</small></span>
      </li>
      @endforeach
    </ol>
    @if($deliveryStatus === 'revision_requested')
      <p class="tracking-progress-warning"><x-icon name="refresh-cw" size="17"/> Revisions are requested. The creator can submit a corrected delivery for a new customer review.</p>
    @endif
  </section>

  <div class="tracking-workspace-grid">
    <main class="tracking-primary" id="tracking-work">
      <section class="tracking-panel tracking-conversation-panel" id="tracking-conversation" aria-labelledby="tracking-chat-title"
        data-conversation-url="{{ route('tracking.conversation.messages', $order->reference()) }}">
        <div class="tracking-panel-heading">
          <div>
            <span class="tracking-overline">PRIVATE CONVERSATION</span>
            <h2 id="tracking-chat-title">Customer &amp; creator messages</h2>
            <p>Secure, private discussion for this order. New messages appear here without reloading the page.</p>
          </div>
          <span class="tracking-chat-count"><x-icon name="message" size="15"/><span data-chat-count>{{ $messageTotal }}</span> messages</span>
        </div>
        <div class="tracking-chat-presence">
          <span class="tracking-presence-avatars" aria-hidden="true">
            <span>{{ mb_strtoupper(mb_substr($order->buyer_name ?: 'C',0,1)) }}</span>
            <span>{{ mb_strtoupper(mb_substr($order->seller_name ?: 'F',0,1)) }}</span>
          </span>
          <span><strong>{{ $order->buyer_name }}</strong> and <strong>{{ $order->seller_name }}</strong>
            <small>Only verified order participants can access this conversation.</small></span>
        </div>
        <div class="tracking-chat-history">
          <button type="button" class="btn subtle compact" data-chat-older @if(!$hasEarlierMessages) hidden @endif>
            <x-icon name="history" size="15"/> Load earlier messages
          </button>
          <div class="tracking-chat-thread" data-chat-thread role="log" aria-label="Project conversation"
            aria-relevant="additions" aria-live="polite"
            data-first-id="{{ $messages->first()?->id ?? 0 }}"
            data-last-id="{{ $messages->last()?->id ?? 0 }}">
            @forelse($messages as $message)
              @include('tracking.partials.message', ['message' => $message, 'order' => $order])
            @empty
              <div class="tracking-chat-empty" data-chat-empty>
                <x-icon name="message" size="24"/>
                <strong>Start the conversation</strong>
                <p>Ask a question, confirm a requirement, or share your first update.</p>
              </div>
            @endforelse
          </div>
        </div>
        @if($inquiry->status === 'open')
          <form method="post" action="{{ route('tracking.conversation.send', $order->reference()) }}"
            enctype="multipart/form-data" class="tracking-chat-compose" data-tracking-chat-form data-ticket-form>
            @csrf
            <div class="tracking-chat-compose-title">
              <div><x-icon name="pencil" size="17"/><strong>Write a message</strong></div>
              <span>Rich text · private attachments</span>
            </div>
            @include('support.editor', ['editorId' => 'tracking-chat-editor', 'minimum' => 2,
              'editorValue' => '', 'allowCredentialButton' => false])
            <div class="tracking-chat-attach-preview" data-chat-attachment-names hidden></div>
            <div class="tracking-compose-footer">
              <small>Maximum 4 attachments: JPG, PNG, WebP, GIF, PDF or TXT. 10 MB each / 20 MB total.
                Files are privately stored and malware scanned. Never share passwords or payment-card details in chat.</small>
              <div class="tracking-compose-buttons">
                <input id="tracking-chat-attachments" type="file" name="attachments[]" multiple
                  accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain"
                  data-chat-attachments class="tracking-file-input">
                <button type="button" class="btn subtle" data-chat-attach-button>
                  <x-icon name="paperclip" size="17"/> Attachment
                </button>
                <button class="btn primary" type="submit" data-chat-submit>
                  <x-icon name="send" size="16"/> Send message
                </button>
              </div>
            </div>
            <p class="tracking-chat-error" data-chat-error role="alert" hidden></p>
            <p class="tracking-chat-success" data-chat-success role="status" hidden></p>
          </form>
        @else
          <p class="tracking-muted">This conversation is closed.</p>
        @endif
      </section>

      <section class="tracking-panel" aria-labelledby="tracking-tasks-title">
        <div class="tracking-panel-heading">
          <div><span class="tracking-overline">ACTIVE WORK</span>
            <h2 id="tracking-tasks-title">Project checklist</h2>
            <p>Work tasks are creator-reported. Customer approval happens separately through delivery review.</p></div>
          <div class="tracking-count"><strong>{{ $doneTasks }}/{{ $tasks->count() }}</strong><small>Tasks finished</small></div>
        </div>
        @if($tasks->isNotEmpty())
          <div class="tracking-task-progress"><progress value="{{ $doneTasks }}" max="{{ $tasks->count() }}"></progress>
            <span>{{ (int) round(100 * $doneTasks / $tasks->count()) }}% tasks marked complete</span></div>
          <div class="tracking-task-list">
            @foreach($tasks as $task)
              <div class="tracking-task-item tracking-task-{{ $task->status }}">
                <span class="tracking-task-icon"><x-icon name="{{ $task->status === 'completed' ? 'check-circle' : ($task->status === 'in_progress' ? 'clock' : 'circle') }}" size="19"/></span>
                <div class="tracking-task-copy">
                  <strong>{{ $task->title }}</strong>
                  <small>{{ ucfirst(str_replace('_',' ', $task->status)) }}
                    @if($task->due_on) · Target {{ \Carbon\Carbon::parse($task->due_on)->format('d M Y') }}@endif
                  </small>
                </div>
                @if($canWriteTasks)
                  <form method="post" action="{{ route('tracking.tasks.update', [$order->reference(), $task->id]) }}" class="tracking-task-edit">
                    @csrf @method('PATCH')
                    <label for="tracking-task-{{ $task->id }}" class="visually-hidden">Task status</label>
                    <select id="tracking-task-{{ $task->id }}" name="status">
                      <option value="planned" @selected($task->status === 'planned')>Planned</option>
                      <option value="in_progress" @selected($task->status === 'in_progress')>In progress</option>
                      <option value="completed" @selected($task->status === 'completed')>Completed</option>
                    </select>
                    <button class="btn subtle compact" type="submit">Save</button>
                  </form>
                @else
                  <span class="tracking-state tracking-state-{{ $task->status }}">{{ ucfirst(str_replace('_',' ',$task->status)) }}</span>
                @endif
              </div>
            @endforeach
          </div>
        @else
          <div class="tracking-empty-inline"><x-icon name="clipboard-list" size="26"/>
            <p>No tasks have been added yet. The creator can list milestones or project checklist items here.</p></div>
        @endif
        @if($canWriteTasks && $tasks->count() < 20)
          @php
            $taskPresetGroups = \App\Support\TrackingTaskPresets::groupsFor($listingType);
            $recommendedTaskPresets = \App\Support\TrackingTaskPresets::recommendedFor($listingType);
          @endphp
          <details class="tracking-details tracking-task-presets" @if($errors->has('title') || $errors->has('due_on')) open @endif>
            <summary><x-icon name="plus" size="17"/> Add a task or milestone</summary>
            <form method="post" action="{{ route('tracking.tasks.store', $order->reference()) }}" class="tracking-form tracking-preset-form" data-task-preset-form>
              @csrf
              <div class="tracking-presets-intro">
                <span class="tracking-preset-kicker"><x-icon name="clipboard-list" size="17"/> MILESTONE LIBRARY</span>
                <strong>Start with a professional task preset</strong>
                <p>Choose a suggested milestone, browse the library, or write a custom task. Edit any title before saving.</p>
              </div>

              <div class="tracking-preset-suggestions" role="group" aria-label="Recommended milestones">
                @foreach($recommendedTaskPresets as $preset)
                  <button type="button" class="tracking-preset-chip" data-task-preset="{{ $preset }}" aria-pressed="false">
                    <x-icon name="plus" size="14"/><span>{{ $preset }}</span>
                  </button>
                @endforeach
              </div>

              <div class="tracking-preset-select-row">
                <div class="tracking-preset-select-field">
                  <label for="tracking-task-preset-select">Browse milestone presets</label>
                  <select id="tracking-task-preset-select" data-task-preset-select aria-describedby="tracking-task-preset-hint">
                    <option value="">Select a milestone (optional)</option>
                    @foreach($taskPresetGroups as $category => $presetTitles)
                      <optgroup label="{{ $category }}">
                        @foreach($presetTitles as $presetTitle)
                          <option value="{{ $presetTitle }}">{{ $presetTitle }}</option>
                        @endforeach
                      </optgroup>
                    @endforeach
                  </select>
                </div>
                <button type="button" class="btn subtle tracking-custom-task" data-task-custom>
                  <x-icon name="pencil" size="15"/> Custom task
                </button>
              </div>
              <p class="tracking-preset-hint" id="tracking-task-preset-hint" data-task-preset-hint aria-live="polite">
                Presets are suggestions and do not change the delivery status.
              </p>

              <label for="tracking-task-title">Task or milestone title <span class="tracking-required">*</span></label>
              <input id="tracking-task-title" name="title" data-task-title maxlength="180" minlength="5" required
                autocomplete="off" placeholder="Select a preset above or enter your own milestone" value="{{ old('title') }}">
              <label for="tracking-task-date">Target date (optional)</label>
              <input id="tracking-task-date" type="date" name="due_on" min="{{ now()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}" value="{{ old('due_on') }}">
              <div class="tracking-preset-footer">
                <small><x-icon name="shield-check" size="14"/> Saved to this order only. Maximum 20 checklist tasks; customer acceptance remains separate.</small>
                <button class="btn primary" type="submit"><x-icon name="plus" size="16"/> Add task</button>
              </div>
            </form>
          </details>
        @endif
      </section>

      <div id="tracking-delivery">
        @include('inquiries.delivery')
      </div>

    </main>

    <aside class="tracking-aside">
      <section class="tracking-panel tracking-aside-panel" aria-labelledby="tracking-value-title">
        <div class="tracking-panel-heading"><div><span class="tracking-overline">FINANCIAL SUMMARY</span>
          <h2 id="tracking-value-title">Order &amp; payment</h2></div></div>
        <div class="tracking-payment-status"><x-icon name="shield-check" size="19"/>
          <span><strong>Payment verified</strong><small>Recorded by Bambodia's order payment workflow</small></span>
        </div>
        <div class="tracking-amount"><span>Gross order value</span><strong>{{ $order->currency }} {{ number_format((float)$order->total,2) }}</strong></div>
        <div class="tracking-money-row"><span>Order reference</span><strong>{{ $order->reference() }}</strong></div>
        <div class="tracking-money-row"><span>Paid on</span><strong>{{ $order->completed_at?->format('d M Y') ?? 'Recorded' }}</strong></div>
        <p class="tracking-disclaimer">This is not an escrow balance or confirmed freelancer payout. Fees, refunds and settlement eligibility are managed in the existing payment workflow.</p>
        <a class="btn subtle tracking-full" href="{{ route($creatorView ? 'studio.orders.show' : 'account.orders.show', $order->reference()) }}">View order details</a>
      </section>

      <section class="tracking-panel tracking-aside-panel" id="tracking-schedule" aria-labelledby="tracking-schedule-title">
        <div class="tracking-panel-heading"><div><span class="tracking-overline">DELIVERY PLANNING</span>
          <h2 id="tracking-schedule-title">Schedule requests</h2>
          <p>A proposed date affects this tracking page only, not payment or contract terms.</p></div></div>
        <div class="tracking-money-row"><span>Agreed tracker date</span>
          <strong>{{ $targetDate ? \Carbon\Carbon::parse($targetDate)->format('d M Y') : 'Not set' }}</strong></div>
        @if($pendingExtension)
          <div class="tracking-extension-pending"><strong>Waiting for a decision</strong>
            <p>{{ \Carbon\Carbon::parse($pendingExtension->proposed_due_on)->format('d M Y') }} · {{ $pendingExtension->reason }}</p>
            @if((int)$pendingExtension->requested_by !== (int)auth()->id() && $canChangeSchedule)
              <form method="post" action="{{ route('tracking.extensions.decide', [$order->reference(), $pendingExtension->id]) }}">
                @csrf @method('PATCH')
                <button class="btn primary compact" type="submit" name="decision" value="approved">Approve date</button>
                <button class="btn subtle compact" type="submit" name="decision" value="declined">Decline</button>
              </form>
            @else
              <small>Only the other participant can approve or decline this request.</small>
            @endif
          </div>
        @elseif($canChangeSchedule)
          <details class="tracking-details">
            <summary><x-icon name="calendar" size="17"/> Request a schedule change</summary>
            <form method="post" action="{{ route('tracking.extensions.store', $order->reference()) }}" class="tracking-form">
              @csrf
              <label for="tracking-proposed-date">Proposed target date</label>
              <input id="tracking-proposed-date" type="date" name="proposed_due_on"
                min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}" required>
              <label for="tracking-schedule-reason">Why is the change needed?</label>
              <textarea id="tracking-schedule-reason" name="reason" minlength="20" maxlength="2500"
                rows="3" required placeholder="Explain the revised plan to the other participant."></textarea>
              <button class="btn primary" type="submit">Send request</button>
            </form>
          </details>
        @endif
        @if($extensions->isNotEmpty())
          <div class="tracking-extension-history">
            @foreach($extensions->take(4) as $extension)
              <div><span>{{ ucfirst($extension->status) }} · {{ $extension->proposed_due_on }}</span>
                <small>{{ $extension->created_at }}</small></div>
            @endforeach
          </div>
        @endif
      </section>

      <section class="tracking-panel tracking-aside-panel tracking-one-time-panel" id="tracking-one-time">
        <div class="tracking-panel-heading">
          <div><span class="tracking-overline">PRIVATE · SINGLE USE</span>
            <h2><x-icon name="lock-keyhole" size="19"/> One-time handover</h2>
            <p>Share a short private note that the other participant can reveal just once. It expires automatically.</p></div>
        </div>
        <details class="tracking-details tracking-one-time-details">
          <summary><x-icon name="plus" size="16"/> Share a one-time note</summary>
          <form method="post" class="tracking-form tracking-one-time-form"
            data-once-form action="{{ route('tracking.one-time.share', $order->reference()) }}">
            @csrf
            <label for="tracking-once-label">Note label</label>
            <input id="tracking-once-label" name="label" maxlength="100" minlength="3" required
              placeholder="e.g. Temporary access instructions">
            <label for="tracking-once-content">Private content</label>
            <textarea id="tracking-once-content" name="content" rows="4" minlength="3" maxlength="2000" required
              autocomplete="off" spellcheck="false" placeholder="Information intended for a single viewing"></textarea>
            <small>Encrypted at rest · 3-day expiry · One opening only · Sender can revoke before opening.
              Use least-privilege temporary access; never share recovery codes or payment details.</small>
            <button class="btn primary" type="submit"><x-icon name="lock-keyhole" size="15"/> Share once</button>
          </form>
        </details>
        <div class="tracking-one-time-list" data-once-list>
          @foreach($oneTimeNotes as $note)
            @include('tracking.partials.one-time-note', ['note' => $note, 'order' => $order])
          @endforeach
          @if($oneTimeNotes->isEmpty())
            <p class="tracking-muted" data-once-empty>No one-time notes shared for this order.</p>
          @endif
        </div>
        <p class="tracking-chat-error" data-once-error role="alert" hidden></p>
        <div class="tracking-once-accessibility" aria-live="polite" data-once-status></div>
      </section>

      <section class="tracking-panel tracking-aside-panel">
        <div class="tracking-panel-heading"><div><span class="tracking-overline">ACCOUNT ACTIVITY</span>
          <h2>Project timeline</h2><p>Recent actions, deliveries, and decisions</p></div></div>
        <ol class="tracking-activity">
          @foreach($timeline as $item)
            <li>
              <span class="tracking-activity-dot tracking-activity-{{ $item['type'] }}"></span>
              <div><strong>{{ $item['title'] }}</strong><small>{{ $item['description'] }}</small>
                <time>{{ \Carbon\Carbon::parse($item['time'])->format('d M Y · H:i') }}</time></div>
            </li>
          @endforeach
        </ol>
      </section>
      <div class="tracking-aside-links">
        <a class="btn subtle tracking-full" href="{{ route('inquiries.show', $inquiry) }}">Open original delivery workspace</a>
        <a class="btn subtle tracking-full" href="{{ route($creatorView ? 'studio.tracking.index' : 'account.tracking.index') }}">All tracking workspaces</a>
      </div>
    </aside>
  </div>
</div>
@endsection
