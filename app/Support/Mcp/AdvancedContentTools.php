<?php

namespace App\Support\Mcp;

use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\Policy;
use App\Models\User;
use App\Support\BambodiaTaxonomy;
use App\Support\BuilderAccess;
use App\Support\BuilderContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Additive MCP content tools. All write operations are draft-only or owner-scoped metadata. */
final class AdvancedContentTools
{
    public const ENTITIES = ['product', 'project', 'service', 'freelance', 'job', 'post', 'page'];
    public const WRITES = ['bambodia_draft_duplicate', 'bambodia_content_metadata', 'bambodia_media_metadata'];

    private const DESCRIPTIONS = [
        'bambodia_categories' => 'List active marketplace categories, including parent IDs.',
        'bambodia_content_summary' => 'Return aggregate content publication counts without content or owner identities.',
        'bambodia_media_search' => 'Search your own approved media by file format, name or alt text; no storage paths.',
        'bambodia_draft_validate' => 'Check basic readiness of an owned unpublished draft; not publication approval.',
        'bambodia_draft_preview' => 'Return a bounded plain-text preview of your own draft.',
        'bambodia_draft_duplicate' => 'Copy basic text and classification into a new owned unpublished draft; no media, files, licenses, terms or commercial state.',
        'bambodia_content_metadata' => 'Update only excerpt, tags and SEO text of your own unpublished post or page.',
        'bambodia_media_metadata' => 'Update only alt text and description of your own approved media; explicit confirmation required.',
    ];

    public static function definitions(bool $includeWrites): array
    {
        $out = [];
        $id = ['type'=>'integer','minimum'=>1];
        $string = fn (int $max, int $min = 0) => ['type'=>'string','minLength'=>$min,'maxLength'=>$max];
        $draft = ['entity'=>['type'=>'string','enum'=>self::ENTITIES], 'id'=>$id];
        $page = ['type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>1];
        $contentFields = [];
        foreach (['excerpt'=>320,'tags'=>320,'seo_title'=>180,'seo_description'=>320] as $key=>$max) {
            $contentFields[$key] = ['type'=>['string','null'],'maxLength'=>$max];
        }
        $schema = fn (array $properties, array $required = []) => [
            'type'=>'object','properties'=>$properties ?: new \stdClass(),
            'required'=>$required,'additionalProperties'=>false,
        ];
        foreach (self::DESCRIPTIONS as $name=>$description) {
            $write = in_array($name, self::WRITES, true);
            if ($write && ! $includeWrites) {
                continue;
            }
            [$properties,$required] = match ($name) {
                'bambodia_categories' => [['type'=>['type'=>'string','enum'=>MarketplaceCategory::TYPES],'page'=>$page],[]],
                'bambodia_content_summary' => [[],[]],
                'bambodia_media_search' => [['mime_type'=>['type'=>'string','enum'=>MediaAsset::ALLOWED_MIME],'search'=>$string(100,1),'page'=>$page],[]],
                'bambodia_draft_validate','bambodia_draft_preview' => [$draft,['entity','id']],
                'bambodia_draft_duplicate' => [array_merge($draft,['title'=>$string(180,8)]),['entity','id','title']],
                'bambodia_content_metadata' => [['entity'=>['type'=>'string','enum'=>['post','page']],'id'=>$id,'fields'=>$schema($contentFields)],['entity','id','fields']],
                'bambodia_media_metadata' => [['id'=>$id,'confirm_metadata_change'=>['type'=>'boolean','const'=>true],'fields'=>$schema(['alt_text'=>$string(180,4),'description'=>['type'=>['string','null'],'maxLength'=>1000]])],['id','fields','confirm_metadata_change']],
            };
            $out[] = [
                'name'=>$name,'description'=>$description,'inputSchema'=>$schema($properties,$required),
                'annotations'=>['readOnlyHint'=>!$write,'destructiveHint'=>false,'idempotentHint'=>!$write,'openWorldHint'=>false],
            ];
        }

        return $out;
    }

    public function call(string $name, array $args, User $owner, bool $mayRead, bool $mayWrite): array
    {
        if (! isset(self::DESCRIPTIONS[$name])) {
            $this->reject('name', 'Unknown advanced content tool.');
        }
        $write = in_array($name,self::WRITES,true);
        $actor = User::query()->find($owner->getKey());
        if (! $mayRead || ! $actor || $actor->role !== 'super_admin' || $actor->account_status !== 'active'
            || ($write && (! $mayWrite || ! config('mcp.production_writes_enabled', false)))) {
            $this->reject('access','Active super administrator and required MCP authorization are mandatory.');
        }
        $draft = ['entity'=>['required','in:'.implode(',',self::ENTITIES)],'id'=>['required','integer','min:1']];
        $rules = match ($name) {
            'bambodia_categories' => ['type'=>['sometimes','in:'.implode(',',MarketplaceCategory::TYPES)],'page'=>['sometimes','integer','min:1','max:1000']],
            'bambodia_content_summary' => [],
            'bambodia_media_search' => ['mime_type'=>['sometimes','in:'.implode(',',MediaAsset::ALLOWED_MIME)],'search'=>['sometimes','string','min:1','max:100'],'page'=>['sometimes','integer','min:1','max:1000']],
            'bambodia_draft_validate','bambodia_draft_preview' => $draft,
            'bambodia_draft_duplicate' => array_merge($draft,['title'=>['required','string','min:8','max:180']]),
            'bambodia_content_metadata' => ['entity'=>['required','in:post,page'],'id'=>['required','integer','min:1'],'fields'=>['required','array','min:1']],
            'bambodia_media_metadata' => ['id'=>['required','integer','min:1'],'fields'=>['required','array','min:1'],'confirm_metadata_change'=>['required','boolean']],
        };
        $v = $this->validated($args,$rules);
        if ($write) {
            return DB::transaction(function () use ($name,$v,$actor): array {
                // Recheck account state inside the mutation transaction, not a cached grant owner.
                $current = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if (! $current || $current->role !== 'super_admin' || $current->account_status !== 'active'
                    || ! config('mcp.production_writes_enabled',false)) {
                    $this->reject('access','Write authorization is unavailable.');
                }
                return match ($name) {
                    'bambodia_draft_duplicate' => $this->duplicate($v,$current),
                    'bambodia_content_metadata' => $this->contentMetadata($v,$current),
                    'bambodia_media_metadata' => $this->mediaMetadata($v,$current),
                };
            }, 3);
        }
        return match ($name) {
            'bambodia_categories' => $this->categories($v),
            'bambodia_content_summary' => $this->summary(),
            'bambodia_media_search' => $this->mediaSearch($v,$actor),
            'bambodia_draft_validate' => $this->readiness($v['entity'],$this->draft($v,$actor),$actor),
            'bambodia_draft_preview' => $this->preview($v['entity'],$this->draft($v,$actor)),
        };
    }

    private function reject(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }

    private function validated(array $values,array $rules): array
    {
        if (array_diff(array_keys($values),array_keys($rules))) {
            $this->reject('arguments','Unsupported arguments or fields.');
        }
        // Enforce JSON scalar types as advertised, not Laravel's coercible forms.
        foreach ($values as $field=>$value) {
            $rule=$rules[$field];
            if ((in_array('integer',$rule,true) && ! is_int($value))
                || (in_array('boolean',$rule,true) && ! is_bool($value))) {
                $this->reject($field,'Invalid JSON scalar type.');
            }
        }
        return Validator::make($values,$rules)->validate();
    }

    private function categories(array $v): array
    {
        $query = MarketplaceCategory::query()->where('is_active',true)
            ->when(isset($v['type']),fn (Builder $q)=>$q->where('type',$v['type']));
        return $this->page($query->orderBy('sort_order')->orderBy('id'),$v,['id','type','name','slug','parent_id','sort_order']);
    }

    private function page(Builder $query,array $v,array $columns): array
    {
        $page = $query->paginate(20,$columns,'page',(int)($v['page']??1));
        return ['data'=>array_map(fn (Model $row)=>$row->only($columns),$page->items()),'page'=>$page->currentPage(),'pages'=>$page->lastPage(),'per_page'=>20,'total'=>$page->total()];
    }

    private function summary(): array
    {
        $out=[];
        foreach ([...self::ENTITIES,'policy'] as $entity) {
            $query = match ($entity) {
                'job'=>MarketplaceJob::query(),
                'post','page'=>ContentEntry::query()->where('type',$entity),
                'policy'=>Policy::query(),
                default=>MarketplaceListing::query()->where('type',$entity),
            };
            $out[$entity] = $query->selectRaw('status, COUNT(*) AS total')->groupBy('status')
                ->pluck('total','status')->map(fn ($n)=>(int)$n)->all();
        }
        return ['counts_by_entity_and_status'=>$out,'basis'=>'current aggregate status counts'];
    }

    private function mediaQuery(User $owner): Builder
    {
        return MediaAsset::query()->where('user_id',$owner->id)->where('review_status','approved')
            ->whereIn('mime_type',MediaAsset::ALLOWED_MIME);
    }

    private function mediaSearch(array $v,User $owner): array
    {
        $q=$this->mediaQuery($owner)->when(isset($v['mime_type']),fn (Builder $q)=>$q->where('mime_type',$v['mime_type']));
        if (isset($v['search'])) {
            // Escape SQL LIKE wildcards using an explicit portable escape character.
            $term='%'.strtr($v['search'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $q->where(fn (Builder $q)=>$q->whereRaw("original_name LIKE ? ESCAPE '!'",[$term])->orWhereRaw("alt_text LIKE ? ESCAPE '!'",[$term]));
        }
        return $this->page($q->orderByDesc('id'),$v,['id','public_id','original_name','mime_type','bytes','width','height','alt_text','description','review_status']);
    }

    private function draft(array $v,User $owner,bool $lock=false): Model
    {
        $entity=$v['entity'];
        $q=match ($entity) {
            'job'=>MarketplaceJob::query()->where('client_id',$owner->id),
            'post','page'=>ContentEntry::query()->where('author_id',$owner->id)->where('type',$entity)->whereNull('published_at'),
            default=>MarketplaceListing::query()->where('user_id',$owner->id)->where('type',$entity),
        };
        $q->whereKey($v['id'])->where('status','draft');
        $record=($lock?$q->lockForUpdate():$q)->first();
        if (! $record) {
            $this->reject('id','Owned unpublished draft not found.');
        }
        return $record;
    }

    private function plain(Model $record): string
    {
        if ($record instanceof ContentEntry) {
            return $record->body_format==='html' ? BuilderContent::plainText(BuilderContent::sanitize($record->body)) : (string)$record->body;
        }
        return $record->description_html ? BuilderContent::plainText(BuilderContent::sanitize($record->description_html)) : (string)$record->description;
    }

    private function readiness(string $entity,Model $record,User $owner): array
    {
        $issues=[];
        $titleMin=$entity==='job'?8:4;
        $bodyMin=$entity==='job'?70:($record instanceof ContentEntry?20:40);
        $bodyMax=$record instanceof ContentEntry?100000:10000;
        $titleLength=mb_strlen(trim($record->title));
        $bodyLength=mb_strlen(trim($this->plain($record)));
        if ($titleLength<$titleMin || $titleLength>180) $issues[]='title_length';
        if ($bodyLength<$bodyMin || $bodyLength>$bodyMax) $issues[]='body_length';
        if ($record instanceof MarketplaceJob && ! isset(BambodiaTaxonomy::jobChoices()[$record->category])) $issues[]='active_job_category_required';
        if ($record instanceof MarketplaceListing && $record->category_id && ! MarketplaceCategory::query()->whereKey($record->category_id)->where('type',$entity)->where('is_active',true)->exists()) $issues[]='invalid_listing_category';
        if (($record instanceof MarketplaceListing || $record instanceof MarketplaceJob) && ! in_array($entity,BuilderAccess::allowedTypes($owner),true)) $issues[]='builder_access_unavailable';
        if ($entity==='product') {
            if (! LicenseDefinition::query()->whereKey($record->license_definition_id)->where('status','active')->exists()) $issues[]='active_license_required';
            if (! $this->mediaQuery($owner)->whereKey($record->cover_media_id)->whereIn('mime_type',['image/jpeg','image/png','image/webp','image/gif'])->exists()) $issues[]='approved_owned_cover_required';
        }
        $ids=array_filter(array_merge([$record->featured_media_id ?? null],(array)($record->gallery_media_ids ?? [])));
        foreach ($ids as $id) {
            if (! $this->mediaQuery($owner)->whereKey($id)->exists()) {
                $issues[]='unapproved_or_unowned_media';
                break;
            }
        }
        return ['entity'=>$entity,'id'=>$record->id,'status'=>'draft','passes_basic_checks'=>!$issues,'issues'=>$issues,
            'publication_authorized'=>false,'note'=>'Advisory only. The native dashboard must verify all media, files, pricing, legal terms, eligibility and review requirements before publication.'];
    }

    private function preview(string $entity,Model $record): array
    {
        $body=$this->plain($record);
        return ['entity'=>$entity,'id'=>$record->id,'status'=>'draft','title'=>$record->title,
            'body_text'=>mb_substr($body,0,5000),'truncated'=>mb_strlen($body)>5000,'format'=>'plain_text','public_url'=>null];
    }

    private function duplicate(array $v,User $owner): array
    {
        $source=$this->draft($v,$owner,true);
        $title=trim($v['title']);
        if (mb_strlen($title)<8 || $title===$source->title || strip_tags($title)!==$title) $this->reject('title','Supply a different plain-text title of at least eight characters.');
        $body=$this->plain($source);
        if ($source instanceof MarketplaceListing || $source instanceof MarketplaceJob) {
            if (! in_array($v['entity'],BuilderAccess::allowedTypes($owner),true)) $this->reject('access','Builder access is unavailable.');
            if (mb_strlen($body)>10000) $this->reject('body','Source body exceeds the supported draft limit.');
        }
        if ($source instanceof MarketplaceListing) {
            $category=$source->category_id;
            if ($category && ! MarketplaceCategory::query()->whereKey($category)->where('type',$source->type)->where('is_active',true)->exists()) $category=null;
            $copy=MarketplaceListing::query()->create(['user_id'=>$owner->id,'type'=>$source->type,'title'=>$title,'description'=>$body,'category_id'=>$category,'currency'=>'USD','status'=>'draft']);
        } elseif ($source instanceof MarketplaceJob) {
            if (! isset(BambodiaTaxonomy::jobChoices()[$source->category])) $this->reject('category','Choose an active category in the native job editor before copying.');
            $copy=MarketplaceJob::query()->create(['client_id'=>$owner->id,'title'=>$title,'description'=>$body,'category'=>$source->category,'currency'=>'USD','status'=>'draft']);
        } else {
            if (mb_strlen($body)>100000) $this->reject('body','Source body exceeds the supported draft limit.');
            $copy=ContentEntry::query()->create(['author_id'=>$owner->id,'type'=>$source->type,'title'=>$title,'body'=>$body,'body_format'=>'plain','status'=>'draft','published_at'=>null]);
        }
        $this->audit('mcp.phase3.draft.duplicated',$owner);
        return ['entity'=>$v['entity'],'id'=>$copy->id,'title'=>$copy->title,'status'=>'draft','source_id'=>$source->id,'media_and_commercial_state_copied'=>false];
    }

    private function textFields(array $fields,array $rules): array
    {
        $fields=$this->validated($fields,$rules);
        foreach ($fields as $key=>$value) {
            if ($value!==null && (strip_tags($value)!==$value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value))) $this->reject($key,'Only plain text is accepted.');
        }
        return $fields;
    }

    private function contentMetadata(array $v,User $owner): array
    {
        $record=$this->draft($v,$owner,true);
        $fields=$this->textFields($v['fields'],[
            'excerpt'=>['sometimes','nullable','string','max:320'],'tags'=>['sometimes','nullable','string','max:320'],
            'seo_title'=>['sometimes','nullable','string','max:180'],'seo_description'=>['sometimes','nullable','string','max:320'],
        ]);
        $record->fill($fields);
        if ($record->isDirty()) {
            $record->save();
            $this->audit('mcp.phase3.content.metadata.updated',$owner);
        }
        return ['entity'=>$v['entity'],'id'=>$record->id,'status'=>'draft','metadata'=>$record->only(['excerpt','tags','seo_title','seo_description'])];
    }

    private function mediaMetadata(array $v,User $owner): array
    {
        if ($v['confirm_metadata_change']!==true) $this->reject('confirm_metadata_change','Explicit confirmation is required; approved media may be publicly displayed.');
        $fields=$this->textFields($v['fields'],[
            'alt_text'=>['sometimes','required','string','min:4','max:180'],'description'=>['sometimes','nullable','string','max:1000'],
        ]);
        if (isset($fields['alt_text']) && mb_strlen(trim($fields['alt_text']))<4) $this->reject('alt_text','Enter descriptive alternative text.');
        $record=$this->mediaQuery($owner)->whereKey($v['id'])->lockForUpdate()->first();
        if (! $record) $this->reject('id','Owned approved media not found.');
        // Only explicitly validated keys are assigned. No rights, storage or review fields.
        $record->forceFill($fields);
        if ($record->isDirty()) {
            $record->save();
            $this->audit('mcp.phase3.media.metadata.updated',$owner);
        }
        return ['id'=>$record->id,'alt_text'=>$record->alt_text,'description'=>$record->description,'review_status'=>$record->review_status];
    }

    private function audit(string $event,User $owner): void
    {
        AuditEvent::query()->create(['event'=>$event,'actor_id'=>$owner->id,'created_at'=>now()]);
    }
}
