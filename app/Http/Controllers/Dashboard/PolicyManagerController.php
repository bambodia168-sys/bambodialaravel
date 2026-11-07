<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverPolicyNotice;
use App\Models\AuditEvent;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\PolicyUpdateNotice;
use App\Support\LegalPolicyDrafts;
use App\Support\PolicyContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PolicyManagerController extends Controller
{
    private const CATEGORIES = ['general', 'privacy', 'cookies', 'gdpr', 'terms', 'commerce', 'security'];

    public function index(): View
    {
        return view('dashboard.policies', [
            'policies' => Policy::query()->latest()->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('dashboard.policy-builder', ['policy' => new Policy([
            'category' => 'general',
            'status' => 'draft',
            'version' => '1.0.0',
            'pic_email' => 'legal@bambodia.com',
            'body_format' => 'html',
        ])]);
    }

    public function edit(Policy $policy): View
    {
        return view('dashboard.policy-builder', compact('policy'));
    }

    public function preview(Policy $policy): View
    {
        return view('dashboard.policy-preview', compact('policy'));
    }

    public function seedLegalDrafts(Request $request): RedirectResponse
    {
        $created = DB::transaction(function (): int {
            $created = 0;
            foreach (LegalPolicyDrafts::all() as $template) {
                if (Policy::query()->where('title', $template['title'])->exists()) {
                    continue;
                }
                Policy::query()->create([...$template, 'status' => 'draft',
                    'body_format' => 'markdown', 'version' => '1.0.0',
                    'pic_email' => 'legal@bambodia.com']);
                $created++;
            }
            return $created;
        });
        AuditEvent::record('policy.legal_drafts_created', $request->user(), $request);
        return back()->with('status', $created.' new legal policy drafts created. Review and publish individually.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedContent($request, null);
        $policy = Policy::query()->create([...$data, 'status' => 'draft']);
        return redirect()->route('dashboard.policies.edit', $policy)
            ->with('status', 'Draft saved. Publication requires administrator legal review.');
    }

    public function update(Request $request, Policy $policy): RedirectResponse
    {
        $data = $this->validatedContent($request, $policy);
        $extra = $request->validate([
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'confirm_legal_review' => ['sometimes', 'accepted'],
            'notify_account' => ['sometimes', 'boolean'],
            'notify_email' => ['sometimes', 'boolean'],
            'show_banner' => ['sometimes', 'boolean'],
            'notification_headline' => ['nullable', 'string', 'max:180'],
            'notification_message' => ['nullable', 'string', 'max:500'],
            'banner_starts_at' => ['nullable', 'date'],
            'banner_ends_at' => ['nullable', 'date', 'after:banner_starts_at'],
        ]);

        $isPublished = $extra['status'] === 'published';
        if ($isPublished && str_contains(PolicyContent::plain(PolicyContent::display($data['body'], $data['body_format'])), 'Draft for legal review')) {
            return back()->withErrors(['body' => 'Finalize this legal-review draft before publication.'])->withInput();
        }
        if ($isPublished && ! $request->boolean('confirm_legal_review')) {
            return back()->withErrors([
                'status' => 'Confirm documented legal review before publishing.',
                'confirm_legal_review' => 'Confirm documented legal review before publishing.',
            ])->withInput();
        }
        $notifyAccount = $request->boolean('notify_account');
        $notifyEmail = $request->boolean('notify_email');
        $showBanner = $request->boolean('show_banner');
        if (! $isPublished && ($notifyAccount || $notifyEmail || $showBanner)) {
            return back()->withErrors(['status' => 'Notifications require publishing a reviewed policy revision.'])->withInput();
        }
        if ($notifyEmail && in_array(config('mail.default'), ['log', 'array', null], true)) {
            return back()->withErrors(['notify_email' => 'Configure and verify a transactional mail provider before queueing legal emails.'])->withInput();
        }
        if (($notifyAccount || $notifyEmail || $showBanner) &&
            (empty($extra['notification_headline']) || empty($extra['notification_message']))) {
            return back()->withErrors(['notification_message' => 'A notification title and message are required when notifying users.'])->withInput();
        }

        DB::transaction(function () use ($policy, $data, $extra, $request, $notifyAccount, $notifyEmail, $showBanner): void {
            $locked = Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $wasPublished = $locked->status === 'published';
            $changed = $locked->title !== $data['title'] ||
                $locked->body !== $data['body'] ||
                $locked->body_format !== $data['body_format'] ||
                $locked->version !== $data['version'] ||
                $locked->category !== $data['category'] ||
                $locked->jurisdiction !== $data['jurisdiction'];
            $publishing = $extra['status'] === 'published';
            $shouldSnapshot = $publishing && (! $wasPublished || $changed ||
                ! PolicyPublication::query()->where('policy_id', $locked->id)->exists());

            $locked->fill([...$data, 'status' => $extra['status']]);
            if ($publishing) {
                $locked->reviewed_at = now();
                $locked->reviewed_by = $request->user()->id;
                $locked->published_at ??= now();
            }
            $locked->save();

            if ($shouldSnapshot) {
                $revision = (int) PolicyPublication::query()->where('policy_id', $locked->id)->max('revision') + 1;
                $publication = PolicyPublication::query()->create([
                    'policy_id' => $locked->id,
                    'revision' => $revision,
                    'title' => $locked->title,
                    'category' => $locked->category,
                    'jurisdiction' => $locked->jurisdiction,
                    'body_snapshot' => $locked->body,
                    'body_format' => $locked->body_format,
                    'version' => $locked->version,
                    'sha256' => PolicyPublication::digestFor($locked),
                    'published_by' => $request->user()->id,
                    'created_at' => now(),
                ]);
                AuditEvent::record('policy.published', $request->user(), $request);

                if ($notifyAccount || $notifyEmail || $showBanner) {
                    $notice = PolicyUpdateNotice::query()->create([
                        'policy_id' => $locked->id,
                        'policy_publication_id' => $publication->id,
                        'headline' => $extra['notification_headline'],
                        'message' => $extra['notification_message'],
                        'notify_account' => $notifyAccount,
                        'notify_email' => $notifyEmail,
                        'show_banner' => $showBanner,
                        'starts_at' => $extra['banner_starts_at'] ?? now(),
                        'ends_at' => $extra['banner_ends_at'] ?? null,
                        'queued_at' => ($notifyAccount || $notifyEmail) ? now() : null,
                        'created_by' => $request->user()->id,
                    ]);
                    AuditEvent::record('policy.notification.queued', $request->user(), $request);
                    if ($notifyAccount || $notifyEmail) {
                        DeliverPolicyNotice::dispatch($notice->id)
                            ->onConnection('database')->onQueue('legal')->afterCommit();
                    }
                }
            } elseif ($notifyAccount || $notifyEmail || $showBanner) {
                // Prevent duplicate campaigns for a publication without new legal content.
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'notification_headline' => 'Update and publish a new policy revision before sending a new notification.',
                ]);
            }
        });

        return redirect()->route('dashboard.policies.edit', $policy)
            ->with('status', 'Policy saved. Any requested notifications are queued; delivery requires a working legal queue worker.');
    }

    private function validatedContent(Request $request, ?Policy $policy): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180',
                Rule::unique('policies', 'title')->ignore($policy?->id)],
            'body' => ['required', 'string', 'min:50', 'max:100000'],
            'body_format' => ['nullable', Rule::in(['html', 'markdown'])],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'jurisdiction' => ['nullable', 'string', 'max:120'],
            'version' => ['nullable', 'regex:/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', 'max:24'],
            'pic_email' => ['nullable', 'email', 'max:180'],
        ]);
        // Omitted optional fields retain their existing values. An explicit empty
        // jurisdiction may still clear the field through Laravel's null conversion.
        if (! array_key_exists('jurisdiction', $data)) {
            $data['jurisdiction'] = $policy?->jurisdiction;
        }
        $data['body_format'] ??= $policy?->body_format ?? 'markdown';
        $data['version'] ??= $policy?->version ?? '1.0.0';
        $data['pic_email'] ??= $policy?->pic_email ?? 'legal@bambodia.com';

        if ($data['body_format'] === 'html') {
            $data['body'] = PolicyContent::sanitize($data['body']);
            if (mb_strlen(PolicyContent::plain($data['body'])) < 50) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'body' => 'Policy content must contain at least 50 characters of readable text.',
                ]);
            }
        }
        return $data;
    }
}
