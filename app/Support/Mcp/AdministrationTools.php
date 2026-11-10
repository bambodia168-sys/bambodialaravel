<?php

namespace App\Support\Mcp;

use App\Http\Middleware\SetLocale;
use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\CreatorProfile;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\Policy;
use App\Models\SiteSeoPage;
use App\Models\SiteSocialLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdministrationTools
{
    public function __construct(private readonly Request $request) {}

    /**
     * Aggregated, read-only operational snapshot. No account emails, IPs,
     * passwords, tokens, license keys or personal data are returned.
     */
    public function analytics(array $args): array
    {
        $values = Validator::make($args, [
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ])->validate();
        $days = (int) ($values['days'] ?? 30);

        $statusCounts = static function (string $model): array {
            return $model::query()
                ->selectRaw('status, COUNT(*) AS total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total) => (int) $total)
                ->all();
        };

        return [
            'period_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'marketplace_listings' => $statusCounts(MarketplaceListing::class),
            'jobs' => $statusCounts(MarketplaceJob::class),
            'posts' => $statusCounts(ContentEntry::class),
            'policies' => $statusCounts(Policy::class),
            'media_review' => MediaAsset::query()->selectRaw('review_status, COUNT(*) AS total')
                ->groupBy('review_status')->pluck('total', 'review_status')
                ->map(fn ($total) => (int) $total)->all(),
            'recent_audit_events' => AuditEvent::query()
                ->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('event, COUNT(*) AS total')
                ->groupBy('event')->orderByDesc('total')->limit(20)
                ->pluck('total', 'event')->map(fn ($total) => (int) $total)->all(),
        ];
    }

    /**
     * Safely edits the authenticated administrator's OWN optional creator
     * profile. Changes to user names, account approval and avatars remain
     * in the existing Profile Editor workflow.
     */
    public function updateOwnProfile(array $args, User $actor): array
    {
        $input = Validator::make($args, ['fields' => ['required', 'array', 'min:1']])->validate()['fields'];
        $allowed = ['headline','biography','website_url','is_public'];
        $this->rejectUnknown($input, $allowed);
        $profile = DB::transaction(function () use ($input, $actor): CreatorProfile {
            $existing = CreatorProfile::query()->where('user_id', $actor->id)->lockForUpdate()->first();
            $payload = array_merge($existing?->only($allowed) ?? [], $input);
            $data = Validator::make($payload, [
                'headline' => ['required','string','min:8','max:160'],
                'biography' => ['required','string','min:80','max:5000'],
                'website_url' => ['nullable','url','starts_with:https://','max:400'],
                'is_public' => ['required','boolean'],
            ])->validate();
            $updated = CreatorProfile::query()->updateOrCreate(['user_id' => $actor->id], $data);
            AuditEvent::record('creator.profile.updated', $actor, $this->request);
            return $updated;
        });

        return $profile->only(['id','user_id','headline','biography','website_url','is_public','updated_at']);
    }

    /**
     * Reuses the same allowlisted fields and validation rules as Bambodia's
     * existing SEO/social dashboards. Public changes require an extra explicit
     * confirmation; removals also require the record's exact title/label.
     */
    public function manageSite(array $args, User $actor): array
    {
        $values = Validator::make($args, [
            'entity' => ['required', Rule::in(['seo','social'])],
            'action' => ['required', Rule::in(['create','update','delete'])],
            'id' => ['required_unless:action,create','nullable','integer','min:1'],
            'fields' => ['required_unless:action,delete','nullable','array','min:1'],
            'confirm_title' => ['required_if:action,delete','nullable','string','max:180'],
            'confirm_live_change' => ['sometimes','boolean'],
        ])->validate();

        $entity = $values['entity'];
        $action = $values['action'];
        $model = $entity === 'seo' ? SiteSeoPage::class : SiteSocialLink::class;
        $allowed = $entity === 'seo'
            ? ['path','locale','title','description','keywords','canonical_url','og_image_url','is_indexable','is_active']
            : ['platform','label','url','sort_order','is_active'];
        $fields = $values['fields'] ?? [];
        $this->rejectUnknown($fields, $allowed);

        return DB::transaction(function () use ($values, $entity, $action, $model, $fields, $actor): array {
            $record = $action === 'create' ? null : $model::query()->lockForUpdate()->findOrFail($values['id']);
            $confirmed = (bool) ($values['confirm_live_change'] ?? false);

            if ($action === 'delete') {
                $label = $entity === 'seo' ? $record->title : $record->label;
                if (! hash_equals((string) $label, (string) $values['confirm_title'])) {
                    throw ValidationException::withMessages(['confirm_title' => 'Type the exact title or label to confirm deletion.']);
                }
                if ($record->is_active && ! $confirmed) {
                    throw ValidationException::withMessages(['confirm_live_change' => 'Confirm removal of the active site setting.']);
                }
                $id = $record->id;
                $record->delete();
                AuditEvent::record($entity === 'seo' ? 'site.seo.deleted' : 'site.social.deleted', $actor, $this->request);
                return ['deleted'=>true,'entity'=>$entity,'id'=>$id];
            }

            $payload = array_merge($record?->only(array_keys($fields)) ?? [], $fields);
            // For updates, use all existing values as defaults but never permit
            // caller-supplied IDs, ownership or other unlisted model attributes.
            if ($record) {
                $payload = array_merge($record->only($entity === 'seo'
                    ? ['path','locale','title','description','keywords','canonical_url','og_image_url','is_indexable','is_active']
                    : ['platform','label','url','sort_order','is_active']), $fields);
            }

            $data = $entity === 'seo'
                ? $this->validateSeo($payload, $record)
                : $this->validateSocial($payload);

            if (($record?->is_active || $data['is_active']) && ! $confirmed) {
                throw ValidationException::withMessages(['confirm_live_change' => 'Confirm changing active public website metadata.']);
            }
            $data['updated_by'] = $actor->id;
            if ($record) {
                $record->update($data);
            } else {
                $record = $model::query()->create($data);
            }
            AuditEvent::record('site.'.$entity.'.'.($action === 'create' ? 'created' : 'updated'), $actor, $this->request);

            return $record->only($entity === 'seo'
                ? ['id','path','locale','title','description','canonical_url','og_image_url','is_indexable','is_active','updated_at']
                : ['id','platform','label','url','sort_order','is_active','updated_at']);
        });
    }

    private function validateSeo(array $input, ?SiteSeoPage $current): array
    {
        $data = Validator::make($input, [
            'path' => ['required','string','max:180','regex:~^/(?:[a-zA-Z0-9_-]+/?)*$~'],
            'locale' => ['required', Rule::in(SetLocale::SUPPORTED)],
            'title' => ['required','string','min:4','max:180'],
            'description' => ['required','string','min:12','max:320'],
            'keywords' => ['nullable','string','max:400'],
            'canonical_url' => ['nullable','url','starts_with:https://','max:400'],
            'og_image_url' => ['nullable','url','starts_with:https://','max:400'],
            'is_indexable' => ['required','boolean'],
            'is_active' => ['required','boolean'],
        ])->validate();

        $data['path'] = rtrim($data['path'], '/') ?: '/';
        $first = explode('/', ltrim($data['path'], '/'))[0] ?? '';
        if (in_array($first, [
            'dashboard','account','studio','developer','api','mcp','login','register',
            'forgot-password','reset-password','logout','storage','build','installer',
        ], true) || in_array($first, SetLocale::SUPPORTED, true)) {
            throw ValidationException::withMessages(['path' => 'SEO records must target a public, unprefixed route.']);
        }

        $exists = SiteSeoPage::query()->where('path', $data['path'])->where('locale', $data['locale'])
            ->when($current, fn ($q) => $q->where('id','!=',$current->id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['path' => 'This path and language already have an SEO entry.']);
        }

        if ($data['canonical_url'] ?? null) {
            $siteHost = parse_url(config('app.url'), PHP_URL_HOST);
            $canonicalHost = parse_url($data['canonical_url'], PHP_URL_HOST);
            if (! $siteHost || strcasecmp((string) $siteHost, (string) $canonicalHost) !== 0) {
                throw ValidationException::withMessages(['canonical_url' => 'Canonical URLs must use the Bambodia website host.']);
            }
        }
        return $data;
    }

    private function validateSocial(array $input): array
    {
        return Validator::make($input, [
            'platform' => ['required', Rule::in(array_keys(SiteSocialLink::PLATFORMS))],
            'label' => ['required','string','min:2','max:100'],
            'url' => ['required','url','starts_with:https://','max:400'],
            'sort_order' => ['required','integer','between:0,1000'],
            'is_active' => ['required','boolean'],
        ])->validate();
    }

    private function rejectUnknown(array $fields, array $allow): void
    {
        $unknown = array_diff(array_keys($fields), $allow);
        if ($unknown) {
            throw ValidationException::withMessages(['fields' => 'Unsupported fields: '.implode(', ', $unknown)]);
        }
    }
}
