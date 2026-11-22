<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\EmailCampaign;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Support\EmailOutbox;
use App\Support\EmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class EmailCampaignController extends Controller
{
    public function index(): View
    {
        $campaigns = EmailCampaign::query()->withCount([
            'deliveries', 'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
            'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
        ])->latest('id')->paginate(10);

        return view('dashboard.email-campaigns', [
            'campaigns' => $campaigns, 'subscribers' => EmailOutbox::subscribers()->count(),
            'deliveryReady' => EmailOutbox::deliveryReady(),
            'enabledCampaignTemplates' => collect([EmailTemplates::PROMOTION, EmailTemplates::ANNOUNCEMENT])
                ->filter(fn ($event) => EmailOutbox::template($event)['enabled'])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'event' => ['required', Rule::in([EmailTemplates::PROMOTION, EmailTemplates::ANNOUNCEMENT])],
            'title' => ['required', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:2500'],
            'listing' => ['required_if:event,'.EmailTemplates::PROMOTION, 'nullable', 'regex:/^[0-9]{9}$/D'],
            'kind' => ['required_if:event,'.EmailTemplates::PROMOTION, Rule::in(['listing', 'job'])],
        ]);
        $path = route('home', absolute: false);
        if ($values['event'] === EmailTemplates::PROMOTION) {
            $record = ($values['kind'] === 'job' ? MarketplaceJob::query()->where('status', 'published')
                : MarketplaceListing::query()->publiclyAvailable())
                ->where('public_id', $values['listing'])->first();
            if (! $record) {
                return back()->withInput()->withErrors(['listing' => 'Enter the reference of a currently published listing or job.']);
            }
            $path = self::publicPath($record);
        }

        EmailCampaign::query()->create([
            'event' => $values['event'], 'title' => $values['title'], 'message' => $values['message'],
            'target_path' => $path, 'created_by' => $request->user()->id,
        ]);
        AuditEvent::record('mail.campaign.draft_saved', $request->user(), $request);

        return back()->with('status', 'Campaign draft saved.');
    }

    public function queue(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        $request->validate(['confirm_audience' => ['accepted']]);
        $result = DB::transaction(function () use ($request, $campaign): string {
            $locked = EmailCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if ($locked->queued_at) {
                return 'This campaign is already queued.';
            }
            if (! EmailOutbox::deliveryReady()) {
                return 'Activate a mail transport in SMTP Setup first.';
            }
            if (! EmailOutbox::template($locked->event)['enabled']) {
                return 'Turn on this campaign template in Email Templates first.';
            }
            if (! EmailOutbox::subscribers()->exists()) {
                return 'There are no verified subscribers for this campaign.';
            }

            $locked->update(['queued_at' => now(), 'audience_max_id' => User::query()->max('id')]);
            AuditEvent::record('mail.campaign.queued', $request->user(), $request);

            return '';
        }, 3);

        return $result === '' ? back()->with('status', 'Campaign queued for verified subscribers.')
            : back()->with('error', $result);
    }

    public function destroy(Request $request, EmailCampaign $campaign): RedirectResponse
    {
        abort_unless(EmailCampaign::query()->whereKey($campaign->id)->whereNull('queued_at')->delete(), 409);
        AuditEvent::record('mail.campaign.draft_deleted', $request->user(), $request);

        return back()->with('status', 'Campaign draft deleted.');
    }

    private static function publicPath(MarketplaceListing|MarketplaceJob $record): string
    {
        $parts = parse_url($record->publicUrl());

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
