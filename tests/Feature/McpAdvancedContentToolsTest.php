<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Mcp\AdvancedContentTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class McpAdvancedContentToolsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role='super_admin'): User
    {
        $n=User::query()->count()+1;
        return User::query()->create(['name'=>'MCP Phase 3 '.$n,'username'=>'mcp3_'.$n,'email'=>'mcp3_'.$n.'@example.test','password'=>'TestPassword123!','role'=>$role,'locale'=>'en']);
    }

    private function post(User $owner,string $status='draft'): ContentEntry
    {
        return ContentEntry::query()->create(['author_id'=>$owner->id,'type'=>'post','title'=>'Original draft title','body'=>'A sufficiently long draft body for testing.','status'=>$status,'published_at'=>$status==='published'?now():null]);
    }

    private function asset(User $owner,string $status='approved'): MediaAsset
    {
        return MediaAsset::query()->create(['user_id'=>$owner->id,'original_name'=>'cover.png','storage_path'=>'private/test-'.uniqid().'.png','mime_type'=>'image/png','bytes'=>100,'width'=>20,'height'=>20,'alt_text'=>'Original alt','rights_basis'=>'owned','rights_holder'=>'Fixture','review_status'=>$status]);
    }

    private function reject(callable $call,string $field): void
    {
        try {
            $call();
            $this->fail('Expected rejection: '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field,$e->errors());
        }
    }

    public function test_registry_and_scope_gate_for_all_eight_tools(): void
    {
        $admin=$this->actor();
        $staff=$this->actor('staff');
        $inactive=$this->actor();
        $inactive->update(['account_status'=>'deactivated']);
        $service=new AdvancedContentTools();
        config(['mcp.production_writes_enabled'=>true]);
        $all=AdvancedContentTools::definitions(true);
        $this->assertCount(8,$all);
        $this->assertCount(5,AdvancedContentTools::definitions(false));
        $this->assertCount(8,array_unique(array_column($all,'name')));
        foreach ($all as $tool) {
            $name=$tool['name'];
            $isWrite=in_array($name,AdvancedContentTools::WRITES,true);
            $this->assertSame(!$isWrite,$tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
            foreach ([[$admin,false,true],[$staff,true,true],[$inactive,true,true]] as [$owner,$read,$write]) {
                $this->reject(fn ()=>$service->call($name,[],$owner,$read,$write),'access');
            }
            if ($isWrite) {
                $this->reject(fn ()=>$service->call($name,[],$admin,true,false),'access');
                config(['mcp.production_writes_enabled'=>false]);
                $this->reject(fn ()=>$service->call($name,[],$admin,true,true),'access');
                config(['mcp.production_writes_enabled'=>true]);
            }
        }
    }

    public function test_read_tools_are_bounded_owner_scoped_and_do_not_mutate(): void
    {
        $owner=$this->actor();
        $other=$this->actor();
        $post=$this->post($owner);
        $foreign=$this->post($other);
        $public=$this->post($owner,'published');
        $ownMedia=$this->asset($owner);
        $this->asset($owner,'pending');
        $this->asset($other);
        MarketplaceCategory::query()->create(['type'=>'product','name'=>'Phase 3 test category','is_active'=>true,'sort_order'=>1,'created_by'=>$owner->id]);
        MarketplaceCategory::query()->create(['type'=>'product','name'=>'Inactive fixture','is_active'=>false,'sort_order'=>2,'created_by'=>$owner->id]);
        $s=new AdvancedContentTools();
        $categories=$s->call('bambodia_categories',['type'=>'product'],$owner,true,false);
        $this->assertStringContainsString('Phase 3 test category',json_encode($categories));
        $this->assertStringNotContainsString('Inactive fixture',json_encode($categories));
        $summary=$s->call('bambodia_content_summary',[],$owner,true,false);
        $this->assertSame(2,$summary['counts_by_entity_and_status']['post']['draft']);
        $this->assertStringNotContainsString($post->title,json_encode($summary));
        $media=$s->call('bambodia_media_search',['mime_type'=>'image/png','search'=>'cover'],$owner,true,false);
        $this->assertSame(1,$media['total']);
        $this->assertSame($ownMedia->id,$media['data'][0]->id);
        $this->assertStringNotContainsString('storage_path',json_encode($media));
        $args=['entity'=>'post','id'=>$post->id];
        $validation=$s->call('bambodia_draft_validate',$args,$owner,true,false);
        $this->assertTrue($validation['passes_basic_checks']);
        $this->assertFalse($validation['publication_authorized']);
        $this->assertSame($post->body,$s->call('bambodia_draft_preview',$args,$owner,true,false)['body_text']);
        foreach (['bambodia_draft_validate','bambodia_draft_preview'] as $name) {
            foreach ([$foreign,$public] as $record) $this->reject(fn ()=>$s->call($name,['entity'=>'post','id'=>$record->id],$owner,true,false),'id');
            $this->reject(fn ()=>$s->call($name,['entity'=>'page','id'=>$post->id],$owner,true,false),'id');
            $this->reject(fn ()=>$s->call($name,['entity'=>'policy','id'=>1],$owner,true,false),'entity');
        }
        $this->reject(fn ()=>$s->call('bambodia_media_search',['user_id'=>$other->id],$owner,true,false),'arguments');
        $this->assertDatabaseCount('content_entries',3);
        $this->assertDatabaseCount('media_assets',3);
    }

    public function test_three_writes_are_audited_and_cannot_change_protected_fields(): void
    {
        config(['mcp.production_writes_enabled'=>true]);
        $owner=$this->actor();
        $other=$this->actor();
        $post=$this->post($owner);
        $foreign=$this->post($other);
        $public=$this->post($owner,'published');
        $asset=$this->asset($owner);
        $foreignAsset=$this->asset($other);
        $pending=$this->asset($owner,'pending');
        $s=new AdvancedContentTools();
        $copy=$s->call('bambodia_draft_duplicate',['entity'=>'post','id'=>$post->id,'title'=>'Copied draft title'],$owner,true,true);
        $this->assertNotSame($post->id,$copy['id']);
        $this->assertDatabaseHas('content_entries',['id'=>$copy['id'],'author_id'=>$owner->id,'status'=>'draft','published_at'=>null,'featured_media_id'=>null]);
        $updated=$s->call('bambodia_content_metadata',['entity'=>'post','id'=>$copy['id'],'fields'=>['seo_title'=>'New SEO title','excerpt'=>'New excerpt']],$owner,true,true);
        $this->assertSame('New SEO title',$updated['metadata']['seo_title']);
        $media=$s->call('bambodia_media_metadata',['id'=>$asset->id,'confirm_metadata_change'=>true,'fields'=>['alt_text'=>'Updated alt text','description'=>'Private descriptive metadata']],$owner,true,true);
        $this->assertSame('Updated alt text',$media['alt_text']);
        $this->assertSame('approved',$asset->fresh()->review_status);
        $this->assertSame($asset->storage_path,$asset->fresh()->storage_path);
        $this->assertSame($asset->rights_basis,$asset->fresh()->rights_basis);
        foreach ([$foreign,$public] as $record) {
            $this->reject(fn ()=>$s->call('bambodia_draft_duplicate',['entity'=>'post','id'=>$record->id,'title'=>'Blocked copy title'],$owner,true,true),'id');
            $this->reject(fn ()=>$s->call('bambodia_content_metadata',['entity'=>'post','id'=>$record->id,'fields'=>['seo_title'=>'Blocked']],$owner,true,true),'id');
        }
        foreach ([$foreignAsset,$pending] as $record) $this->reject(fn ()=>$s->call('bambodia_media_metadata',['id'=>$record->id,'confirm_metadata_change'=>true,'fields'=>['alt_text'=>'Blocked alt']],$owner,true,true),'id');
        foreach (['status','author_id','published_at','body','featured_media_id'] as $field) $this->reject(fn ()=>$s->call('bambodia_content_metadata',['entity'=>'post','id'=>$post->id,'fields'=>[$field=>'blocked']],$owner,true,true),'arguments');
        foreach (['review_status','storage_path','rights_basis','user_id'] as $field) $this->reject(fn ()=>$s->call('bambodia_media_metadata',['id'=>$asset->id,'confirm_metadata_change'=>true,'fields'=>[$field=>'blocked']],$owner,true,true),'arguments');
        $this->reject(fn ()=>$s->call('bambodia_media_metadata',['id'=>$asset->id,'confirm_metadata_change'=>false,'fields'=>['alt_text'=>'Blocked alt']],$owner,true,true),'confirm_metadata_change');
        $this->reject(fn ()=>$s->call('bambodia_content_metadata',['entity'=>'post','id'=>$post->id,'fields'=>['seo_title'=>'<script>alert(1)</script>']],$owner,true,true),'seo_title');
        $this->assertSame(3,AuditEvent::query()->where('event','like','mcp.phase3.%')->count());
        $this->assertSame('published',$public->fresh()->status);
        $this->assertDatabaseCount('content_entries',4);
    }

    public function test_product_validation_reports_missing_license_and_cover(): void
    {
        $owner=$this->actor();
        $draft=MarketplaceListing::query()->create(['user_id'=>$owner->id,'type'=>'product','title'=>'Product fixture','description'=>str_repeat('Draft description. ',4),'status'=>'draft']);
        $result=(new AdvancedContentTools())->call('bambodia_draft_validate',['entity'=>'product','id'=>$draft->id],$owner,true,false);
        $this->assertContains('active_license_required',$result['issues']);
        $this->assertContains('approved_owned_cover_required',$result['issues']);
        $this->assertFalse($result['passes_basic_checks']);
    }

    public function test_product_duplication_does_not_copy_commercial_state_or_media(): void
    {
        config(['mcp.production_writes_enabled'=>true]);
        $owner=$this->actor();
        $other=$this->actor();
        $asset=$this->asset($owner);
        $source=MarketplaceListing::query()->create(['user_id'=>$owner->id,'type'=>'product','title'=>'Original product fixture','description'=>str_repeat('Test description. ',4),'price'=>19,'currency'=>'USD','status'=>'draft','cover_media_id'=>$asset->id,'license_terms'=>'Source license terms']);
        $s=new AdvancedContentTools();
        $args=['entity'=>'product','id'=>$source->id,'title'=>'Copied product fixture'];
        $this->reject(fn ()=>$s->call('bambodia_draft_duplicate',$args,$other,true,true),'id');
        $this->reject(fn ()=>$s->call('bambodia_draft_duplicate',array_replace($args,['entity'=>'service']),$owner,true,true),'id');
        $copy=$s->call('bambodia_draft_duplicate',$args,$owner,true,true);
        $new=MarketplaceListing::query()->findOrFail($copy['id']);
        $this->assertSame('draft',$new->status);
        $this->assertSame($source->description,$new->description);
        $this->assertNull($new->price);
        $this->assertNull($new->cover_media_id);
        $this->assertNull($new->license_terms);
        $this->assertNull($new->license_definition_id);
        $this->assertSame($asset->id,$source->fresh()->cover_media_id);
        $this->assertSame(2,MarketplaceListing::query()->count());
    }

    public function test_staging_script_rejects_drift_and_preserves_original_registry(): void
    {
        $process=new Process(['python3',base_path('support/bambodia-mcp-stage-advanced-content.py'),'--self-test']);
        $process->mustRun();
        $this->assertStringContainsString('PASS:', $process->getOutput());
    }
}
