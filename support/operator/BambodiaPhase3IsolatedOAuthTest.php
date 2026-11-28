<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ContentEntry;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class BambodiaPhase3IsolatedOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('testing', app()->environment());
        config()->set('app.url', 'https://bambodia.com');
        config()->set('mcp.production_enabled', true);
        config()->set('mcp.production_writes_enabled', true);
        // The fake ChatGPT callback must be allowlisted explicitly in QA;
        // production defaults to NO eligible redirect URIs.
        config()->set('mcp.exact_redirect_uris', [
            'https://chatgpt.com/connector/oauth/qa-callback',
        ]);
    }

    private function user(string $role = 'super_admin'): User
    {
        return User::query()->create([
            'name' => 'OAuth Acceptance',
            'username' => 'qa_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)),
            'email' => uniqid('oauth-', true).'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    /**
     * Issue a real Passport JWT via restricted client registration, explicit
     * consent and RFC 7636 S256 exchange. No synthetic Passport::actingAs()
     * bypass is used for actual MCP authentication checks.
     */
    private function authorizedBearer(User $actor, string $scope): string
    {
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $callback = 'https://chatgpt.com/connector/oauth/qa-callback';
        $registered = $this->postJson('/oauth/register', [
            'client_name' => 'Bambodia Security Acceptance',
            'redirect_uris' => [$callback],
        ])->assertCreated();

        $clientId = $registered->json('client_id');
        $verifier = \Illuminate\Support\Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = \Illuminate\Support\Str::random(36);
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url('/mcp'),
        ]);

        $this->actingAs($actor, 'web')
            ->get('/oauth/authorize?'.$query)
            ->assertOk();
        $authToken = session('authToken');
        $this->assertIsString($authToken);

        $approval = $this->post(route('passport.authorizations.approve'), [
            'client_id' => $clientId,
            'state' => $state,
            'auth_token' => $authToken,
        ])->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY) ?: '', $params);
        $this->assertSame($state, $params['state'] ?? null);

        $exchange = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $params['code'],
            'redirect_uri' => $callback,
            'code_verifier' => $verifier,
            'resource' => url('/mcp'),
        ])->assertOk();

        $secret = $exchange->json('access_token');
        $this->assertIsString($secret);
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $secret;
    }


    private const READS = ['bambodia_categories','bambodia_content_summary','bambodia_media_search','bambodia_draft_validate','bambodia_draft_preview'];
    private const WRITES = ['bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata'];

    private function requestRpc(string $bearer, string $method, array $params=[]): \Illuminate\Testing\TestResponse
    {
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        return $this->withToken($bearer)->postJson('/mcp', $this->rpc($method,$params));
    }
    private function tools(string $bearer): array
    {
        $rows=$this->requestRpc($bearer,'tools/list')->assertOk()->json('result.tools');
        $this->assertIsArray($rows);
        $map=array_column($rows,null,'name');
        $this->assertCount(count($rows),$map);
        ksort($map);
        return $map;
    }
    private function callRpc(string $bearer,string $name,array $args=[]): \Illuminate\Testing\TestResponse
    {
        return $this->requestRpc($bearer,'tools/call',['name'=>$name,'arguments'=>$args])->assertOk();
    }
    private function deny(string $bearer): void
    {
        $r=$this->requestRpc($bearer,'tools/list');
        $this->assertContains($r->getStatusCode(),[401,403]);
    }
    private function draft(User $owner, string $status='draft'): ContentEntry
    {
        return ContentEntry::query()->create(['author_id'=>$owner->id,'type'=>'post','title'=>'Phase 3 disposable post','body'=>str_repeat('Synthetic isolated OAuth test text. ',3),'body_format'=>'plain','status'=>$status,'published_at'=>$status==='published'?now():null]);
    }
    private function media(User $owner,string $status='approved'): \App\Models\MediaAsset
    {
        return \App\Models\MediaAsset::query()->create(['user_id'=>$owner->id,'original_name'=>'qa-cover.png','storage_path'=>'qa-only/'.uniqid().'.png','mime_type'=>'image/png','bytes'=>100,'width'=>20,'height'=>20,'alt_text'=>'QA original alt','rights_basis'=>'owned','rights_holder'=>'Synthetic fixture','review_status'=>$status]);
    }
    private function fixtures(User $owner): array
    {
        $post=$this->draft($owner);
        $media=$this->media($owner);
        return [$post,$media];
    }
    private function writeArgs(ContentEntry $post,\App\Models\MediaAsset $media): array
    {
        return [
            'bambodia_draft_duplicate'=>['entity'=>'post','id'=>$post->id,'title'=>'New isolated copy'],
            'bambodia_content_metadata'=>['entity'=>'post','id'=>$post->id,'fields'=>['seo_title'=>'Isolated SEO title']],
            'bambodia_media_metadata'=>['id'=>$media->id,'confirm_metadata_change'=>true,'fields'=>['alt_text'=>'Isolated updated alt']],
        ];
    }

    public function test_registry_snapshot(): void
    {
        $mode=getenv('QA_PHASE3_MODE');
        $this->assertContains($mode,['baseline','candidate','rollback']);
        $actor=$this->user();
        $read=$this->authorizedBearer($actor,'mcp:use');
        $readOn=$this->tools($read);
        config(['mcp.production_writes_enabled'=>false]);
        $readOff=$this->tools($read);
        config(['mcp.production_writes_enabled'=>true]);
        $write=$this->authorizedBearer($actor,'mcp:use mcp.write');
        $writeOn=$this->tools($write);
        $this->assertSame($readOn,$writeOn);
        $current=['enabled'=>$readOn,'disabled'=>$readOff];
        $path=storage_path('app/qa-phase3-registry.json');
        if ($mode==='baseline') {
            $this->assertCount(16,$readOn);
            $this->assertCount(10,$readOff);
            file_put_contents($path,json_encode($current,JSON_THROW_ON_ERROR));
            return;
        }
        $baseline=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if ($mode==='rollback') {
            $this->assertSame($baseline,$current);
            return;
        }
        $this->assertCount(24,$readOn);
        $this->assertCount(15,$readOff);
        foreach ($baseline as $setting=>$defs) {
            foreach ($defs as $name=>$old) $this->assertSame($old,$current[$setting][$name]??null,'Existing definition changed: '.$name);
        }
        $expected=[...self::READS,...self::WRITES]; sort($expected);
        $added=array_keys(array_diff_key($readOn,$baseline['enabled'])); sort($added);
        $this->assertSame($expected,$added);
        $addedOff=array_keys(array_diff_key($readOff,$baseline['disabled'])); sort($addedOff);
        $reads=self::READS; sort($reads);
        $this->assertSame($reads,$addedOff);
        foreach ($expected as $name) {
            $d=$readOn[$name];
            $isWrite=in_array($name,self::WRITES,true);
            $this->assertSame(!$isWrite,$d['annotations']['readOnlyHint']);
            $this->assertSame($isWrite?['mcp:use','mcp.write']:['mcp:use'],$d['securitySchemes'][0]['scopes']);
            $this->assertSame($d['securitySchemes'],$d['_meta']['securitySchemes']);
        }
    }

    public function test_real_read_grant_calls_five_reads_and_rejects_every_write(): void
    {
        $actor=$this->user();
        [$post,$media]=$this->fixtures($actor);
        $this->media($this->user());
        $this->media($actor,'pending');
        \App\Models\MarketplaceCategory::query()->create(['type'=>'product','name'=>'QA category','is_active'=>true,'sort_order'=>1,'created_by'=>$actor->id]);
        $bearer=$this->authorizedBearer($actor,'mcp:use');
        $this->callRpc($bearer,'bambodia_categories',['type'=>'product'])->assertJsonPath('result.isError',false);
        $this->callRpc($bearer,'bambodia_content_summary')->assertJsonPath('result.isError',false);
        $this->callRpc($bearer,'bambodia_media_search',['search'=>'qa-cover'])->assertJsonPath('result.structuredContent.total',1)->assertJsonPath('result.structuredContent.data.0.id',$media->id);
        $args=['entity'=>'post','id'=>$post->id];
        $this->callRpc($bearer,'bambodia_draft_validate',$args)->assertJsonPath('result.structuredContent.publication_authorized',false);
        $this->callRpc($bearer,'bambodia_draft_preview',$args)->assertJsonPath('result.structuredContent.body_text',$post->body);
        foreach ($this->writeArgs($post,$media) as $name=>$values) {
            $result=$this->callRpc($bearer,$name,$values)->assertJsonPath('result.isError',true)->json('result');
            $this->assertStringContainsString('insufficient_scope',json_encode($result['_meta']['mcp/www_authenticate']??[],JSON_THROW_ON_ERROR));
        }
        $this->assertDatabaseCount('content_entries',1);
        $this->assertNull($post->fresh()->seo_title);
        $this->assertSame('QA original alt',$media->fresh()->alt_text);
        $this->assertSame(0,\App\Models\AuditEvent::query()->where('event','like','mcp.phase3.%')->count());
    }

    public function test_real_write_grant_owner_and_status_boundaries_and_audit(): void
    {
        $actor=$this->user(); $other=$this->user();
        [$post,$media]=$this->fixtures($actor);
        [$foreign,$foreignMedia]=$this->fixtures($other);
        $published=$this->draft($actor,'published');
        $pending=$this->media($actor,'pending');
        $bearer=$this->authorizedBearer($actor,'mcp:use mcp.write');
        foreach ($this->writeArgs($post,$media) as $name=>$values) $this->callRpc($bearer,$name,$values)->assertJsonPath('result.isError',false);
        $copy=ContentEntry::query()->where('title','New isolated copy')->sole();
        $this->assertSame($actor->id,$copy->author_id);
        $this->assertSame('draft',$copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->featured_media_id);
        $this->assertSame('Isolated SEO title',$post->fresh()->seo_title);
        $this->assertSame('Isolated updated alt',$media->fresh()->alt_text);
        $this->assertSame($media->storage_path,$media->fresh()->storage_path);
        $this->assertSame('approved',$media->fresh()->review_status);
        foreach ([$foreign,$published] as $target) {
            foreach (['bambodia_draft_validate','bambodia_draft_preview'] as $name) $this->callRpc($bearer,$name,['entity'=>'post','id'=>$target->id])->assertJsonPath('result.isError',true);
            $this->callRpc($bearer,'bambodia_draft_duplicate',['entity'=>'post','id'=>$target->id,'title'=>'Forbidden copy'])->assertJsonPath('result.isError',true);
            $this->callRpc($bearer,'bambodia_content_metadata',['entity'=>'post','id'=>$target->id,'fields'=>['seo_title'=>'Forbidden']])->assertJsonPath('result.isError',true);
        }
        foreach ([$foreignMedia,$pending] as $target) $this->callRpc($bearer,'bambodia_media_metadata',['id'=>$target->id,'confirm_metadata_change'=>true,'fields'=>['alt_text'=>'Forbidden alt']])->assertJsonPath('result.isError',true);
        $this->callRpc($bearer,'bambodia_draft_preview',['entity'=>'page','id'=>$post->id])->assertJsonPath('result.isError',true);
        $this->callRpc($bearer,'bambodia_content_metadata',['entity'=>'post','id'=>$post->id,'fields'=>['status'=>'published']])->assertJsonPath('result.isError',true);
        $this->callRpc($bearer,'bambodia_media_metadata',['id'=>$media->id,'confirm_metadata_change'=>true,'fields'=>['user_id'=>$other->id]])->assertJsonPath('result.isError',true);
        $this->callRpc($bearer,'bambodia_media_metadata',['id'=>$media->id,'confirm_metadata_change'=>false,'fields'=>['alt_text'=>'Forbidden alt']])->assertJsonPath('result.isError',true);
        $this->callRpc($bearer,'bambodia_media_search',['user_id'=>$other->id])->assertJsonPath('result.isError',true);
        $this->assertSame(3,\App\Models\AuditEvent::query()->where('event','like','mcp.phase3.%')->where('actor_id',$actor->id)->count());
        $this->assertDatabaseCount('content_entries',4);
        $this->assertNull($foreign->fresh()->seo_title);
        $this->assertSame('published',$published->fresh()->status);
        $this->assertSame('QA original alt',$foreignMedia->fresh()->alt_text);
    }

    public function test_write_feature_switch_rejects_all_three_real_write_calls(): void
    {
        $actor=$this->user(); [$post,$media]=$this->fixtures($actor);
        $bearer=$this->authorizedBearer($actor,'mcp:use mcp.write');
        config(['mcp.production_writes_enabled'=>false]);
        $this->assertCount(15,$this->tools($bearer));
        foreach ($this->writeArgs($post,$media) as $name=>$args) $this->callRpc($bearer,$name,$args)->assertJsonPath('result.isError',true);
        $this->assertDatabaseCount('content_entries',1);
        $this->assertSame(0,\App\Models\AuditEvent::query()->where('event','like','mcp.phase3.%')->count());
    }

    private function b64(string $bytes): string { return rtrim(strtr(base64_encode($bytes),'+/','-_'),'='); }
    private function claims(string $bearer): array
    {
        return json_decode(base64_decode(strtr(explode('.',$bearer)[1],'-_','+/')),true,512,JSON_THROW_ON_ERROR);
    }
    private function resigned(string $bearer,array $changes): string
    {
        $header=explode('.',$bearer)[0];
        $headerData=json_decode(base64_decode(strtr($header,'-_','+/')),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('RS256',$headerData['alg']);
        $unsigned=$header.'.'.$this->b64(json_encode(array_replace($this->claims($bearer),$changes),JSON_THROW_ON_ERROR));
        $private=openssl_pkey_get_private(file_get_contents(storage_path('oauth-private.key')));
        $this->assertNotFalse($private);
        $this->assertTrue(openssl_sign($unsigned,$sig,$private,OPENSSL_ALGO_SHA256));
        return $unsigned.'.'.$this->b64($sig);
    }
    public function test_missing_invalid_expired_wrong_audience_issuer_and_client_bearers(): void
    {
        $actor=$this->user();
        $this->actingAs($actor,'web')->postJson('/mcp',$this->rpc('tools/list'))->assertUnauthorized();
        $this->deny('invalid-qa-bearer');
        $token=$this->authorizedBearer($actor,'mcp:use');
        $this->assertCount(24,$this->tools($token));
        $aud=$this->claims($token)['aud'];
        $this->assertCount(2,$aud);
        $this->deny($this->resigned($token,['exp'=>time()-120]));
        $this->deny($this->resigned($token,['iss'=>'https://invalid.example']));
        $this->deny($this->resigned($token,['aud'=>[$aud[0],'https://invalid.example/mcp']]));
        $this->deny($this->resigned($token,['aud'=>['invalid-client-id',$aud[1]]]));
        $this->deny($this->resigned($token,['aud'=>[$aud[0]]]));
    }
    public function test_real_grant_rejected_after_account_demotion_or_deactivation(): void
    {
        $actor=$this->user();
        $token=$this->authorizedBearer($actor,'mcp:use');
        $this->tools($token);
        $actor->forceFill(['role'=>'staff'])->save(); $this->deny($token);
        $actor->forceFill(['role'=>'customer'])->save(); $this->deny($token);
        $actor->forceFill(['role'=>'super_admin','account_status'=>'deactivated'])->save(); $this->deny($token);
    }
    public function test_real_grant_rejected_after_access_token_revocation(): void
    {
        $actor=$this->user(); $token=$this->authorizedBearer($actor,'mcp:use'); $this->tools($token);
        $id=$this->claims($token)['jti'];
        $this->assertSame(1,\Illuminate\Support\Facades\DB::table('oauth_access_tokens')->where('id',$id)->update(['revoked'=>true]));
        $this->deny($token);
    }
    public function test_real_connection_revocation_rejects_bearer_and_refresh_rows(): void
    {
        $actor=$this->user(); $token=$this->authorizedBearer($actor,'mcp:use'); $this->tools($token);
        $id=$this->claims($token)['jti'];
        $this->flushHeaders(); \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($actor,'web')->delete('/developer/oauth-connections/'.$id,['password'=>'TestPassword123!'])->assertRedirect();
        $this->assertDatabaseHas('oauth_access_tokens',['id'=>$id,'revoked'=>true]);
        $this->assertFalse(\Illuminate\Support\Facades\DB::table('oauth_refresh_tokens')->where('access_token_id',$id)->where('revoked',false)->exists());
        $this->deny($token);
    }
}
