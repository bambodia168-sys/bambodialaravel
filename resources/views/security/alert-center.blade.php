<section class="panel" style="margin-top:20px">
  <div class="panel-head">
    <div>
      <h2>Audit alert rules <span class="count">{{ $alertRules->total() }}</span></h2>
      <p>Monitor real recorded events. New rules are off by default; a scheduled check evaluates enabled rules.</p>
    </div>
    <div class="dialog-actions" style="margin:0">
      <form method="post" action="{{ route('security.alerts.scan') }}">
        @csrf
        <button class="btn subtle compact" type="submit"><x-icon name="refresh-cw" size="16"/> Run check</button>
      </form>
      <button class="btn primary compact" type="button" data-modal-open="security-rule-add">
        <x-icon name="plus" size="16"/> Add
      </button>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Rule</th><th>Audit event</th><th>Trigger</th><th>Observed now</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($alertRules as $rule)
          <tr>
            <td><strong>{{ $rule->name }}</strong><small style="display:block;color:var(--muted)">Cooldown {{ $rule->cooldown_minutes===1440?'24h':$rule->cooldown_minutes.'m' }}</small></td>
            <td>{{ $monitorEvents[$rule->event] ?? $rule->event }}</td>
            <td>{{ $rule->threshold }} events / {{ $rule->window_minutes }} min</td>
            <td>{{ number_format($rule->observed_count) }}</td>
            <td><span class="role-pill">{{ $rule->is_enabled?'Enabled':'Off' }}</span></td>
            <td>
              <x-row-action-menu :id="'security-rule-'.$rule->id" :label="$rule->name">
                <button type="button" data-modal-open="security-rule-edit-{{ $rule->id }}"><x-icon name="pencil" size="16"/> Edit</button>
                <form method="post" action="{{ route('security.rules.destroy',$rule) }}">
                  @csrf @method('DELETE')
                  <button type="submit"><x-icon name="trash-2" size="16"/> Delete rule</button>
                </form>
              </x-row-action-menu>
              <dialog class="config-dialog wide-dialog" id="security-rule-edit-{{ $rule->id }}">
                <form method="post" action="{{ route('security.rules.update',$rule) }}">
                  @csrf @method('PATCH')
                  <h2>Edit security alert rule</h2>
                  @include('security.partials.rule-fields', ['rule'=>$rule])
                  <div class="dialog-actions">
                    <button type="button" class="btn subtle" data-modal-close>Cancel</button>
                    <button type="submit" class="btn primary">Save</button>
                  </div>
                </form>
              </dialog>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">No alert rules yet. Add a rule to monitor a selected audit event.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$alertRules"/>
  <p class="hint" style="padding:0 22px 18px">Last evaluation:
    {{ $lastSecurityScan ? \Carbon\Carbon::parse($lastSecurityScan)->format('d M Y H:i:s').' UTC' : 'Not yet run' }}.
    For automatic checks, aaPanel must run <code>php artisan schedule:run</code> every minute.
  </p>
</section>

<section class="panel" style="margin-top:20px">
  <div class="panel-head">
    <div><h2>Security alerts <span class="count">{{ $alerts->total() }}</span></h2>
      <p>Recorded alert history. Acknowledgment marks an alert as reviewed; it does not delete audit evidence.</p></div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Detected</th><th>Alert</th><th>Observed</th><th>State</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($alerts as $alert)
          <tr @if(request()->query('alert')==(string)$alert->id) style="outline:2px solid var(--line)" @endif>
            <td>{{ $alert->created_at->format('d M Y H:i') }} UTC</td>
            <td><strong>{{ $alert->rule_name }}</strong><small style="display:block;color:var(--muted)">{{ $monitorEvents[$alert->event]??$alert->event }}</small></td>
            <td>{{ number_format($alert->observed_count) }} events</td>
            <td><span class="role-pill">{{ $alert->acknowledged_at ? 'Acknowledged' : 'Needs review' }}</span>
              @if($alert->acknowledged_at)
                <small style="display:block;color:var(--muted)">by {{ $alert->acknowledger?->name??'Former admin' }}</small>
              @endif
            </td>
            <td>
              @unless($alert->acknowledged_at)
                <form method="post" action="{{ route('security.alerts.acknowledge',$alert) }}">
                  @csrf @method('PATCH')
                  <button class="btn subtle compact" type="submit"><x-icon name="check" size="16"/> Acknowledge</button>
                </form>
              @else
                Reviewed
              @endunless
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-cell">No security alerts recorded. Enabled rules will create alerts when their thresholds are reached.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$alerts"/>
</section>
<dialog class="config-dialog wide-dialog" id="security-rule-add">
  <form method="post" action="{{ route('security.rules.store') }}">
    @csrf
    <h2>Add security alert rule</h2>
    @include('security.partials.rule-fields', ['rule'=>null])
    <div class="dialog-actions">
      <button type="button" class="btn subtle" data-modal-close>Cancel</button>
      <button type="submit" class="btn primary">Add</button>
    </div>
  </form>
</dialog>
