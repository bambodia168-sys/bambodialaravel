<?php

namespace App\Support\Mcp;

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
use App\Support\PolicyContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BambodiaTools
{
    public const ENTITIES = [
        'product', 'project', 'service', 'freelance', 'job',
        'post', 'page', 'policy', 'profile', 'media', 'seo', 'social', 'audit',
    ];

    private const DRAFT_TYPES = [
        'product', 'project', 'service', 'freelance', 'job', 'post', 'page', 'policy',
    ];

    public function __construct(private readonly Request $request) {}

    public function overview(): array
    {
        return [
            'site' => config('app.url'),
            'entities' => self::ENTITIES,
            'read' => 'List and inspect records via bambodia_list and bambodia_get.',
            'drafts' => 'Create, edit and delete unpublished drafts only. Marketplace approvals, published content and legal publication remain in the existing dashboards.',
            'media_upload' => 'Use the authenticated Media Library. MCP never accepts remote URLs or arbitrary filesystem paths as uploads.',
            'github' => 'Source code changes must use GitHub pull requests and CI; no command execution is exposed through MCP.',
        ];
    }

    public function listing(array $args): array
    {
        $args = Validator::make($args, [
            'entity' => ['required', Rule::in(self::ENTITIES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ])->validate();
        $entity = $args['entity'];
        $query = $this->query($entity)->orderByDesc('id');
        if ($term = trim((string) ($args['search'] ?? ''))) {
            $field = match ($entity) {
                'profile' => 'headline', 'seo' => 'path', 'social' => 'label',
                'media' => 'original_name', 'audit' => 'event', default => 'title',
            };
            $query->where($field, 'like', '%'.addcslashes($term, '%_\\').'%');
        }
        $page = (int) ($args['page'] ?? 1);
        $records = $query->paginate(10, ['*'], 'page', $page);

        return [
            'entity' => $entity,
            'data' => $records->getCollection()->map(fn (Model $r) => $this->present($entity, $r))->values()->all(),
            'page' => $records->currentPage(),
            'pages' => $records->lastPage(),
            'per_page' => 10,
            'total' => $records->total(),
        ];
    }

    public function get(array $args): array
    {
        $values = Validator::make($args, [
            'entity' => ['required', Rule::in(self::ENTITIES)],
            'id' => ['required', 'integer', 'min:1'],
        ])->validate();

        return $this->present($values['entity'], $this->query($values['entity'])->findOrFail($values['id']));
    }

    public function createDraft(array $args, User $actor): array
    {
        $entity = Validator::make($args, [
            'entity' => ['required', Rule::in(self::DRAFT_TYPES)],
            'fields' => ['required', 'array'],
        ])->validate()['entity'];
        $fields = $this->validateDraft($entity, $args['fields'], false);

        $record = DB::transaction(function () use ($entity, $fields, $actor): Model {
            return match ($entity) {
                'product', 'project', 'service', 'freelance' => MarketplaceListing::query()->create([
                    ...$fields, 'user_id' => $actor->id, 'type' => $entity, 'currency' => 'USD', 'status' => 'draft',
                ]),
                'job' => MarketplaceJob::query()->create([
                    ...$fields, 'client_id' => $actor->id, 'currency' => 'USD', 'status' => 'draft',
                ]),
                'post', 'page' => ContentEntry::query()->create([
                    ...$fields, 'type' => $entity, 'author_id' => $actor->id, 'status' => 'draft', 'published_at' => null,
                ]),
                'policy' => Policy::query()->create([
                    ...$fields, 'status' => 'draft',
                ]),
            };
        });
        AuditEvent::record('mcp.draft.created', $actor, $this->request);
        return $this->present($entity, $record);
    }

    public function updateDraft(array $args, User $actor): array
    {
        $values = Validator::make($args, [
            'entity' => ['required', Rule::in(self::DRAFT_TYPES)],
            'id' => ['required', 'integer', 'min:1'],
            'fields' => ['required', 'array', 'min:1'],
        ])->validate();
        $entity = $values['entity'];
        $fields = $this->validateDraft($entity, $values['fields'], true);
        $record = DB::transaction(function () use ($entity, $fields, $values, $actor): Model {
            $record = $this->ownedDraftQuery($entity, $actor)->lockForUpdate()->findOrFail($values['id']);
            if ($record->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only drafts can be edited through MCP. Use the existing review or publication workflow.']);
            }
            $record->update($fields);
            return $record->refresh();
        });
        AuditEvent::record('mcp.draft.updated', $actor, $this->request);
        return $this->present($entity, $record);
    }

    public function deleteDraft(array $args, User $actor): array
    {
        $values = Validator::make($args, [
            'entity' => ['required', Rule::in(self::DRAFT_TYPES)],
            'id' => ['required', 'integer', 'min:1'],
            'confirm_title' => ['required', 'string', 'max:180'],
        ])->validate();
        $entity = $values['entity'];
        DB::transaction(function () use ($values, $entity, $actor): void {
            $record = $this->ownedDraftQuery($entity, $actor)->lockForUpdate()->findOrFail($values['id']);
            if ($record->status !== 'draft' || ! hash_equals((string) $record->title, $values['confirm_title'])) {
                throw ValidationException::withMessages(['confirm_title' => 'Deletion requires draft status and an exact title match.']);
            }
            $record->delete();
        });
        AuditEvent::record('mcp.draft.deleted', $actor, $this->request);
        return ['deleted' => true, 'entity' => $entity, 'id' => $values['id']];
    }

    /** This intentionally excludes approval, publication, media and arbitrary admin settings. */
    private function validateDraft(string $entity, array $fields, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $rules = match ($entity) {
            'product', 'project', 'service', 'freelance' => [
                'title' => [$required, 'string', 'min:4', 'max:180'],
                'description' => [$required, 'string', 'min:40', 'max:10000'],
                'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
                'license_terms' => ['sometimes', 'nullable', 'string', 'max:20000'],
            ],
            'job' => [
                'title' => [$required, 'string', 'min:8', 'max:180'],
                'description' => [$required, 'string', 'min:70', 'max:10000'],
                'category' => [$required, Rule::in(['development', 'design', 'writing', 'marketing', 'business', 'other'])],
                'budget' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            ],
            'post', 'page' => [
                'title' => [$required, 'string', 'min:4', 'max:180'],
                'body' => [$required, 'string', 'min:20', 'max:100000'],
                'excerpt' => ['sometimes', 'nullable', 'string', 'max:320'],
                'seo_title' => ['sometimes', 'nullable', 'string', 'max:180'],
                'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            ],
            'policy' => [
                'title' => [$required, 'string', 'min:4', 'max:180'],
                'body' => [$required, 'string', 'min:50', 'max:100000'],
                'category' => [$required, Rule::in(['general', 'privacy', 'cookies', 'gdpr', 'terms', 'commerce', 'security'])],
                'jurisdiction' => ['sometimes', 'nullable', 'string', 'max:120'],
                'version' => ['sometimes', 'regex:/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', 'max:24'],
            ],
        };
        // Reject unknown fields, including owner IDs, statuses and review bypass flags.
        $unknown = array_diff(array_keys($fields), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(['fields' => 'Unsupported fields: '.implode(', ', $unknown)]);
        }
        $data = Validator::make($fields, $rules)->validate();
        if (isset($data['body']) && in_array($entity, ['post','page','policy'], true)) {
            $data['body'] = PolicyContent::sanitize($data['body']);
            if (mb_strlen(PolicyContent::plain($data['body'])) < ($entity === 'policy' ? 50 : 20)) {
                throw ValidationException::withMessages(['body' => 'Content is too short after HTML sanitization.']);
            }
            if ($entity !== 'policy') {
                $data['body_format'] = 'html';
            }
        }
        if ($entity === 'policy' && ! $partial) {
            $data['version'] ??= '1.0.0';
            $data['body_format'] = 'html';
        }
        return $data;
    }

    /**
     * Marketplace drafts are private to their authors, even when an MCP
     * administrator can read global catalog records. Policies are site-wide
     * legal artifacts with no owner column and remain super-admin-only
     * operations under the existing audit/approval constraints.
     */
    private function ownedDraftQuery(string $entity, User $actor): Builder
    {
        $query = $this->query($entity);

        return match ($entity) {
            'product', 'project', 'service', 'freelance' => $query->where('user_id', $actor->id),
            'job' => $query->where('client_id', $actor->id),
            'post', 'page' => $query->where('author_id', $actor->id),
            'policy' => $query,
            default => throw ValidationException::withMessages(['entity' => 'Unknown draft entity.']),
        };
    }

    private function query(string $entity): Builder
    {
        return match ($entity) {
            'product', 'project', 'service', 'freelance' => MarketplaceListing::query()->where('type', $entity),
            'job' => MarketplaceJob::query(),
            'post', 'page' => ContentEntry::query()->where('type', $entity),
            'policy' => Policy::query(),
            'profile' => CreatorProfile::query(),
            'media' => MediaAsset::query(),
            'seo' => SiteSeoPage::query(),
            'social' => SiteSocialLink::query(),
            'audit' => AuditEvent::query(),
            default => throw ValidationException::withMessages(['entity' => 'Unknown entity.']),
        };
    }

    /** Return explicit safe fields, never mass-serialized models or credentials. */
    private function present(string $entity, Model $record): array
    {
        $fields = match ($entity) {
            'product', 'project', 'service', 'freelance' => ['id','public_id','type','title','description','price','currency','status','user_id','updated_at'],
            'job' => ['id','public_id','title','description','category','budget','currency','status','client_id','updated_at'],
            'post', 'page' => ['id','public_id','type','title','body','excerpt','status','seo_title','seo_description','updated_at'],
            'policy' => ['id','public_id','title','body','category','version','status','jurisdiction','updated_at'],
            'profile' => ['id','user_id','headline','biography','website_url','is_public','updated_at'],
            'media' => ['id','public_id','original_name','mime_type','bytes','alt_text','review_status','updated_at'],
            'seo' => ['id','path','locale','title','description','keywords','canonical_url','is_active','is_indexable','updated_at'],
            'social' => ['id','platform','label','url','sort_order','is_active','updated_at'],
            'audit' => ['id','event','created_at'],
        };
        return $record->only($fields);
    }
}
