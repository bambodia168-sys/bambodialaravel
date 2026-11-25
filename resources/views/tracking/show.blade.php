@extends('layouts.workspace')
@section('workspace', $creatorView ? 'creator' : 'account')
@section('title', 'Project Tracking')
@push('head')
<link rel="stylesheet" href="{{ asset('css/project-tracking.css') }}?v=1">
<script defer src="{{ asset('js/project-tracking.js') }}?v=1"></script>
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
          <details class="tracking-details">
            <summary><x-icon name="plus" size="17"/> Add a work task</summary>
            <form method="post" action="{{ route('tracking.tasks.store', $order->reference()) }}" class="tracking-form">
              @csrf
              <label for="tracking-task-title">Task or milestone title</label>
              <input id="tracking-task-title" name="title" maxlength="180" minlength="5" required placeholder="e.g. Design and confirm responsive screens" value="{{ old('title') }}">
              <label for="tracking-task-date">Target date (optional)</label>
              <input id="tracking-task-date" type="date" name="due_on" min="{{ now()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}" value="{{ old('due_on') }}">
              <button class="btn primary" type="submit">Add task</button>
            </form>
          </details>
        @endif
      </section>

      <div id="tracking-delivery">
        @include('inquiries.delivery')
      </div>

      <section class="tracking-panel" id="tracking-conversation" aria-labelledby="tracking-chat-title">
        <div class="tracking-panel-heading"><div><span class="tracking-overline">PRIVATE CONVERSATION</span>
          <h2 id="tracking-chat-title">Customer &amp; creator messages</h2>
          <p>Messages are visible only to both participants. They do not alter order payments or formal agreements.</p></div>
          <span class="tracking-count">{{ $messages->total() }} messages</span>
        </div>
        <div class="tracking-chat-thread">
          @forelse($messages as $message)
            <article class="tracking-chat-item {{ (int)$message->sender_id === (int)auth()->id() ? 'is-own' : '' }}">
              <div class="tracking-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($message->sender?->name ?? 'U',0,1)) }}</div>
              <div class="tracking-chat-body">
                <div class="tracking-chat-meta">
                  <strong>{{ $message->sender?->name ?? 'Participant' }}</strong>
                  <time datetime="{{ $message->created_at->toAtomString() }}">{{ $message->created_at->format('d M Y · H:i') }}</time>
                </div>
                <p>{{ $message->body }}</p>
              </div>
            </article>
          @empty
            <p class="tracking-empty-inline">No messages yet. Start by sharing project requirements.</p>
          @endforelse
        </div>
        <x-pagination :paginator="$messages"/>
        @if($inquiry->status === 'open')
          <form method="post" action="{{ route('inquiries.reply', $inquiry) }}" class="tracking-form tracking-chat-compose">
            @csrf
            <label for="tracking-chat-reply">Write a message</label>
            <textarea id="tracking-chat-reply" name="body" minlength="2" maxlength="5000" rows="4" required
              placeholder="Share an update, clarify a requirement or provide feedback.">{{ old('body') }}</textarea>
            <div class="tracking-compose-footer"><small>Do not include passwords, private keys or payment-card details.</small>
              <button class="btn primary" type="submit"><x-icon name="send" size="16"/> Send message</button></div>
          </form>
        @else
          <p class="tracking-muted">This conversation is closed.</p>
        @endif
      </section>
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
