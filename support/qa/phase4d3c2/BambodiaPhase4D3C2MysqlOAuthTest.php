<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CommercePaymentMethod;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceOrder;
use App\Models\LicenseDefinition;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\ProductLicense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\ContentEntry;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class BambodiaPhase4D3C2MysqlOAuthTest extends TestCase
{
    // Schema lifecycle is exclusively controlled by the isolated operator.

    private array $issuedIdentities = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__,2).'/qa-phase4d3c2/guard.php';
        phase4d3c2Environment();
        parent::setUp();
        phase4d3c2Database();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['commerce.enabled'=>true,'mail.default'=>'array']);
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('bambodia_acceptance', config('database.connections.mysql.database'));
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
        // Feature tests reuse one application/router across simulated HTTP requests.
        // A cached Passport consent controller may hold a previous SessionGuard.
        // Reset the test browser session and cached controller objects before a
        // new authorization flow; no middleware is disabled or replaced.
        $this->app['session']->driver()->flush();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        foreach ($this->app['router']->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'oauth/')) $route->flushController();
        }
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

        // Model a separate browser login, including the session identity, not
        // just an in-memory actingAs user on a potentially cached guard.
        $guard=\Illuminate\Support\Facades\Auth::guard('web');
        $this->withSession([$guard->getName()=>$actor->getAuthIdentifier()]);
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
        $claims=$this->claims($secret);
        $this->assertSame((string)$actor->id,(string)($claims['sub']??''),'OAUTH_ISSUANCE_SUBJECT_MISMATCH');
        $row=DB::table('oauth_access_tokens')->where('id',$claims['jti'])->first();
        $this->assertNotNull($row,'OAUTH_ISSUANCE_TOKEN_ROW_MISSING');
        $this->assertSame((string)$actor->id,(string)$row->user_id,'OAUTH_ISSUANCE_DATABASE_OWNER_MISMATCH');
        $this->assertSame((string)$clientId,(string)$row->client_id,'OAUTH_ISSUANCE_CLIENT_MISMATCH');
        $scopes=json_decode($row->scopes,true,512,JSON_THROW_ON_ERROR);
        $this->assertContains(explode(' ',$scope)[0],$scopes,'OAUTH_ISSUANCE_SCOPE_MISSING');
        $mayWrite=in_array('mcp.write',explode(' ',$scope),true);
        $this->assertSame($mayWrite,in_array('mcp.write',$scopes,true),'OAUTH_ISSUANCE_WRITE_SCOPE_MISMATCH');
        $this->issuedIdentities[hash('sha256',$secret)]=['id'=>(string)$actor->id,'write'=>$mayWrite];
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $secret;
    }


    private const READS = ['bambodia_categories','bambodia_content_summary','bambodia_media_search','bambodia_draft_validate','bambodia_draft_preview'];
    private const WRITES = ['bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata'];

    private function requestRpc(string $bearer, string $method, array $params=[]): \Illuminate\Testing\TestResponse
    {
        $this->flushHeaders();
        // MCP requests must be bearer-only. Never carry a simulated consent
        // browser login into the API request, even for a different actor.
        $this->app['session']->driver()->flush();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $response=$this->withToken($bearer)->postJson('/mcp', $this->rpc($method,$params));
        $expected=$this->issuedIdentities[hash('sha256',$bearer)]??null;
        if ($expected!==null && $response->getStatusCode()===200) {
            $request=$this->app['request'];
            $resolved=$request->attributes->get('bambodia_api_owner');
            $grant=$request->attributes->get('bambodia_api_token');
            $this->assertInstanceOf(User::class,$resolved,'MCP_RESOLVED_OWNER_MISSING');
            $this->assertSame($expected['id'],(string)$resolved->id,'MCP_RESOLVED_OWNER_MISMATCH');
            $this->assertInstanceOf(\App\Support\Mcp\McpAccessGrant::class,$grant,'MCP_RESOLVED_GRANT_MISSING');
            $this->assertTrue($grant->allows('mcp.read'),'MCP_RESOLVED_READ_SCOPE_MISSING');
            $this->assertSame($expected['write'],$grant->allows('mcp.write'),'MCP_RESOLVED_WRITE_SCOPE_MISMATCH');
        }
        return $response;
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

    private const ALL_READS=['bambodia_overview','bambodia_list','bambodia_get','bambodia_analytics',
        'bambodia_accounts_summary','bambodia_orders_summary','bambodia_review_queue','bambodia_support_summary',
        'bambodia_notifications_summary','bambodia_email_delivery_summary','bambodia_categories','bambodia_content_summary',
        'bambodia_media_search','bambodia_draft_validate','bambodia_draft_preview','bambodia_orders_list','bambodia_order_get',
        'bambodia_transactions_summary','bambodia_receipt_get','bambodia_licenses_list','bambodia_license_get',
        'bambodia_tracking_list','bambodia_tracking_get','bambodia_contracts_list','bambodia_milestones_list'];
    private const ALL_WRITES=['bambodia_draft_media','bambodia_update_own_profile','bambodia_manage_site','bambodia_create_draft',
        'bambodia_update_draft','bambodia_delete_draft','bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata',
        'bambodia_tracking_task_create','bambodia_tracking_task_update','bambodia_contract_milestone_create','bambodia_tracking_comment_create'];

    public function test_registry_snapshot(): void
    {
        $mode=getenv('QA_PHASE4_MODE');
        $this->assertContains($mode,['baseline','candidate','rollback']);
        $actor=$this->user();
        $read=$this->authorizedBearer($actor,'mcp:use');
        $readOn=$this->tools($read);
        config(['mcp.production_writes_enabled'=>false]);
        $readOff=$this->tools($read);
        config(['mcp.production_writes_enabled'=>true]);
        $write=$this->authorizedBearer($actor,'mcp:use mcp.write');
        $this->assertSame($readOn,$this->tools($write));
        $current=['enabled'=>$readOn,'disabled'=>$readOff];
        $path=storage_path('app/qa-phase4-registry.json');
        $reads=$mode==='candidate'?self::ALL_READS:array_slice(self::ALL_READS,0,15);
        $writes=$mode==='candidate'?self::ALL_WRITES:array_slice(self::ALL_WRITES,0,9);
        $all=[...$reads,...$writes]; sort($all); sort($reads);
        $this->assertSame($all,array_keys($readOn));
        $this->assertSame($reads,array_keys($readOff));
        foreach ($readOn as $name=>$d) {
            $writeTool=in_array($name,$writes,true);
            $scopes=$d['securitySchemes'][0]['scopes']; sort($scopes);
            $expected=$writeTool?['mcp.write','mcp:use']:['mcp:use']; sort($expected);
            $this->assertSame($expected,$scopes,$name);
            $this->assertSame($d['securitySchemes'],$d['_meta']['securitySchemes'],$name);
        }
        if ($mode==='baseline') {
            $this->assertCount(24,$readOn);
            $this->assertCount(15,$readOff);
            $this->assertNotFalse(file_put_contents($path,json_encode($current,JSON_THROW_ON_ERROR)));
            return;
        }
        $baseline=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if ($mode==='rollback') {
            $this->assertSame($baseline,$current);
            return;
        }
        $this->assertCount(38,$readOn);
        $this->assertCount(25,$readOff);
        foreach ($baseline as $setting=>$defs)
            foreach ($defs as $name=>$old) $this->assertSame($old,$current[$setting][$name]??null,'Existing definition changed: '.$name);
        foreach (array_diff_key($readOn,$baseline['enabled']) as $name=>$d)
            $this->assertSame(!in_array($name,self::ALL_WRITES,true),$d['annotations']['readOnlyHint']);
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
        $this->assertCount(25,$this->tools($bearer));
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
        $this->assertCount(38,$this->tools($token));
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

    public function test_oauth_resource_indicator_is_required_on_authorization_and_token_requests(): void
    {
        $this->get('/oauth/authorize')->assertStatus(400)
            ->assertJsonPath('error', 'invalid_target');

        $this->get('/oauth/authorize?'.http_build_query([
            'resource' => 'https://other.example/mcp',
        ]))->assertStatus(400)->assertJsonPath('error', 'invalid_target');

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_target');

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'resource' => 'https://other.example/mcp',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_target');

        $this->postJson('/oauth/token', [
            'grant_type' => 'password',
            'resource' => url('/mcp'),
        ])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

        $this->assertTrue(\App\Support\Mcp\McpResource::matches(url('/mcp')));
        $this->assertFalse(\App\Support\Mcp\McpResource::matches(url('/')));
        $this->assertFalse(\App\Support\Mcp\McpResource::matches(url('/mcp').'?evil=1'));
    }


    public function test_pkce_s256_does_not_exchange_code_with_wrong_verifier(): void
    {
        $actor = $this->user();
        $redirect = 'https://chatgpt.com/connector/oauth/qa-callback';
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Bambodia Negative PKCE QA',
            'redirect_uris' => [$redirect],
        ])->assertCreated()->json('client_id');

        $verifier = \Illuminate\Support\Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = \Illuminate\Support\Str::random(36);

        $this->actingAs($actor, 'web')
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client,
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => 'mcp:use',
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'resource' => url('/mcp'),
            ]))->assertOk();
        $authToken = session('authToken');

        $approval = $this->post(route('passport.authorizations.approve'), [
            'client_id' => $client,
            'state' => $state,
            'auth_token' => $authToken,
        ])->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY) ?: '', $callback);
        $this->assertSame($state, $callback['state'] ?? null);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client,
            'code' => $callback['code'],
            'redirect_uri' => $redirect,
            'code_verifier' => \Illuminate\Support\Str::random(64),
            'resource' => url('/mcp'),
        ])->assertStatus(400);

        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }


    public function test_dynamic_client_registration_accepts_chatgpt_only(): void
    {
        $registration = $this->postJson('/oauth/register', [
            'client_name' => 'Bambodia Manager QA',
            'redirect_uris' => ['https://chatgpt.com/connector/oauth/qa-callback'],
        ])->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonPath('scope', 'mcp:use');
        $this->assertDatabaseCount('oauth_clients', 1);

        $client = Passport::client()->newQuery()->findOrFail($registration->json('client_id'));
        $this->assertContains('mcp:use', $client->scopes);
        $this->assertContains('mcp.write', $client->scopes);
        // The default advertised scope remains read-only; writes need extra consent.
        $this->assertNotSame('mcp:use mcp.write', $registration->json('scope'));

        $this->postJson('/oauth/register', [
            'client_name' => 'Untrusted Integration',
            'redirect_uris' => ['https://untrusted.example/steal'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->assertDatabaseCount('oauth_clients', 1);
    }


    public function test_registration_fails_closed_without_exact_callback_approval(): void
    {
        $endpoint = '/oauth/register';
        $valid = ['client_name' => 'Exact URI QA',
            'redirect_uris' => ['https://chatgpt.com/connector/oauth/qa-callback']];
        config()->set('mcp.exact_redirect_uris', []);
        $this->postJson($endpoint, $valid)->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
        config()->set('mcp.exact_redirect_uris', [
            'https://chatgpt.com/connector/oauth/qa-callback',
        ]);
        foreach ([
            'https://chatgpt.com/connector/oauth/another-callback',
            'https://chatgpt.com/connector/oauth/qa-callback?next=/redirect',
            'https://chatgpt.com/connector_platform_oauth_redirect',
            'https://chatgpt.com.evil.example/connector/oauth/qa-callback',
        ] as $unapproved) {
            $this->postJson($endpoint, [
                'client_name' => 'Rejected Redirect',
                'redirect_uris' => [$unapproved],
            ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
        $this->assertDatabaseCount('oauth_clients', 0);
    }


    public function test_read_only_release_does_not_register_write_scopes(): void
    {
        config()->set('mcp.production_writes_enabled', false);
        $registration = $this->postJson('/oauth/register', [
            'client_name' => 'Read Only QA',
            'redirect_uris' => ['https://chatgpt.com/connector/oauth/qa-callback'],
        ])->assertCreated();
        $client = Passport::client()->newQuery()
            ->findOrFail($registration->json('client_id'));
        $this->assertContains('mcp:use', $client->scopes);
        $this->assertNotContains('mcp.write', $client->scopes);
        $this->assertContains('offline_access', $client->scopes);
    }


    public function test_demotion_between_oauth_consent_page_and_approval_is_denied(): void
    {
        $actor = $this->user();
        $callback = 'https://chatgpt.com/connector/oauth/qa-callback';
        $client = $this->postJson('/oauth/register', [
            'client_name' => 'Consent Role Change QA',
            'redirect_uris' => [$callback],
        ])->assertCreated()->json('client_id');
        $state = \Illuminate\Support\Str::random(32);
        $verifier = \Illuminate\Support\Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs($actor, 'web')
            ->get('/oauth/authorize?'.http_build_query([
                'client_id' => $client,
                'redirect_uri' => $callback,
                'response_type' => 'code',
                'scope' => 'mcp:use',
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'resource' => url('/mcp'),
            ]))->assertOk();

        $authToken = session('authToken');
        $actor->forceFill(['role' => 'customer'])->save();

        $approvalRoutes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'oauth/authorize'
                && in_array('POST', $r->methods(), true))->values();
        $this->assertCount(1, $approvalRoutes, 'Unexpected Passport POST consent route count.');
        $this->assertContains('web', $approvalRoutes->first()->gatherMiddleware(),
            'Passport approval must use the authenticated session web middleware.');
        $this->assertContains(
            \App\Http\Middleware\EnsureMcpOAuthConsentAdmin::class,
            app('router')->getMiddlewareGroups()['web'] ?? [],
            'The web group must check the administrator role after StartSession.'
        );

        $approvalResponse = $this->post(route('passport.authorizations.approve'), [
            'client_id' => $client,
            'state' => $state,
            'auth_token' => $authToken,
        ]);
        $destination = (string) (parse_url((string) $approvalResponse->headers->get('Location'),
            PHP_URL_PATH) ?? '');
        $this->assertSame(403, $approvalResponse->status(),
            'Demoted admin was redirected to path '.$destination.' instead of being forbidden.');
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }


    public function test_write_only_grant_without_mcp_read_scope_is_denied(): void
    {
        $token=$this->authorizedBearer($this->user(),'mcp.write');
        $this->deny($token);
    }

    public function test_disabled_mcp_rejects_valid_real_grant(): void
    {
        $token=$this->authorizedBearer($this->user(),'mcp:use');
        config(['mcp.production_enabled'=>false]);
        $this->requestRpc($token,'tools/list')->assertStatus(503);
    }
    private function listing(User $seller): MarketplaceListing
    {
        return MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>'product',
            'title'=>'Phase 4 product','description'=>'Disposable commerce fixture','currency'=>'USD','status'=>'draft']);
    }

    private function orderFixture(User $buyer,User $seller,string $status='completed',string $total='12.34',string $currency='USD'): MarketplaceOrder
    {
        return MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'request_key'=>(string)Str::uuid(),
            'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,'marketplace_listing_id'=>$this->listing($seller)->id,
            'listing_title'=>'Order fixture','buyer_name'=>'PRIVATE_BUYER','seller_name'=>'PRIVATE_SELLER',
            'status'=>$status,'total'=>$total,'currency'=>$currency,
            'receipt_number'=>(string)Str::uuid(),'completed_at'=>now()]);
    }

    private function paid(MarketplaceOrder $order,bool $live=true,bool $settled=true): void
    {
        $method=CommercePaymentMethod::query()->firstOrFail();
        $order->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'provider'=>'stripe','method_name'=>'Stripe','live_mode'=>$live,'status'=>'paid',
            'settlement_reference'=>$settled?'SECRET-'.Str::uuid():null,'remote_id'=>'REMOTE-'.Str::uuid(),
            'proof_path'=>'private/secret.pdf','qr_payload'=>'PRIVATE_QR']);
    }

    private function licenseFixture(User $customer,User $creator): ProductLicense
    {
        $product=$this->listing($creator);
        $def=LicenseDefinition::query()->create(['code'=>'PH4_'.Str::random(6),'name'=>'Fixture license','version'=>'1.0.0',
            'permissions'=>'Fixture permissions','restrictions'=>'Fixture restrictions','status'=>'active','created_by'=>$creator->id]);
        $release=ProductFileVersion::query()->create(['marketplace_listing_id'=>$product->id,'uploaded_by'=>$creator->id,
            'version'=>'1.0.0','release_notes'=>'Fixture release','original_filename'=>'secret.zip','storage_path'=>'private/'.Str::uuid().'.zip',
            'file_type'=>'zip','byte_size'=>100,'sha256'=>hash('sha256',(string)Str::uuid()),'status'=>'approved','rights_attested_at'=>now()]);
        $entitlement=ProductEntitlement::query()->create(['user_id'=>$customer->id,'product_file_version_id'=>$release->id,
            'granted_by'=>$creator->id,'grant_reason'=>'Fixture grant','status'=>'active','expires_at'=>now()->subDay()]);
        $license=ProductLicense::query()->create(['product_entitlement_id'=>$entitlement->id,'marketplace_listing_id'=>$product->id,
            'product_file_version_id'=>$release->id,'user_id'=>$customer->id,'license_definition_id'=>$def->id,'issued_by'=>$creator->id,
            'tier'=>'regular','source'=>'admin_complimentary','key_hash'=>hash('sha256',(string)Str::uuid()),
            'key_prefix'=>'SECRET-PREFIX','key_ciphertext'=>'SECRET-KEY','status'=>'active','max_domains'=>1,'pending_domain'=>'private.example.test']);
        $license->activations()->create(['domain'=>'private.example.test','status'=>'active','activated_at'=>now()]);
        return $license;
    }

    private function order(User $buyer,User $seller,string $type='project',string $status='completed',bool $live=true,bool $settled=true): MarketplaceOrder
    {
        $listing=MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>$type,'title'=>'Project fixture',
            'description'=>'PRIVATE_LISTING_DESCRIPTION','status'=>'published','price'=>'85.00','currency'=>'USD']);
        $method=CommercePaymentMethod::query()->firstOrCreate(['name'=>'Project fixture transfer'],['available'=>true]);
        $order=MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'request_key'=>(string)Str::uuid(),
            'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,'marketplace_listing_id'=>$listing->id,'listing_title'=>$listing->title,
            'buyer_name'=>'PRIVATE_BUYER','seller_name'=>'PRIVATE_SELLER','total'=>'85.00','currency'=>'USD',
            'status'=>$status,'completed_at'=>now()]);
        $order->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'method_name'=>$method->name,'provider'=>'bank_transfer','status'=>'paid','live_mode'=>$live,
            'settlement_reference'=>$settled?'SECRET-'.Str::uuid():null]);
        $order->serviceInquiry()->create(['marketplace_listing_id'=>$listing->id,'client_id'=>$buyer->id,
            'creator_id'=>$seller->id,'subject'=>'PRIVATE_SUBJECT','status'=>'open','currency'=>'USD']);
        return $order;
    }

    private function contract(User $client,User $freelancer,string $status='draft'): FreelanceContract
    {
        $job=MarketplaceJob::query()->create(['client_id'=>$client->id,'title'=>'Job fixture','description'=>'PRIVATE_JOB',
            'category'=>'development','budget'=>'100.00','status'=>'published']);
        $proposal=JobProposal::query()->create(['marketplace_job_id'=>$job->id,'freelancer_id'=>$freelancer->id,
            'cover_message'=>'PRIVATE_PROPOSAL','proposed_budget'=>'100.00','status'=>'shortlisted']);
        $contract=FreelanceContract::query()->create(['job_proposal_id'=>$proposal->id,'client_id'=>$client->id,
            'freelancer_id'=>$freelancer->id,'title'=>'Contract fixture','scope'=>'PRIVATE_SCOPE','terms'=>'PRIVATE_TERMS',
            'agreed_budget'=>'100.00','currency'=>'USD','status'=>$status,'terms_sha256'=>str_repeat('a',64)]);
        $contract->milestones()->create(['sequence'=>1,'title'=>'Milestone fixture','description'=>'PRIVATE_MILESTONE_DESCRIPTION',
            'amount'=>'100.00','status'=>$status==='active'?'accepted':'planned','due_on'=>now()->addDay(),
            'accepted_at'=>$status==='active'?now():null]);
        return $contract;
    }


    private function resultData(string $token,string $tool,array $args=[]): array
    {
        $r=$this->callRpc($token,$tool,$args)->assertJsonPath('result.isError',false)->json('result.structuredContent');
        $this->assertIsArray($r);
        return $r;
    }

    private function operation(string $reference,string $version,array $args): array
    {
        return ['reference'=>$reference,'expected_version'=>$version,'request_id'=>(string)Str::uuid(),'confirm'=>true,...$args];
    }

    // Advance only the isolated Laravel test clock; never alter or bypass
    // production rate limits, middleware, cache keys or OAuth verification.
    private function nextMcpRateWindow(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('array', config('cache.default'));
        $this->travelTo(now()->addSeconds(65));
    }

    public function test_actual_mcp_throttle_rejects_burst_and_recovers_next_window(): void
    {
        $token=$this->authorizedBearer($this->user(),'mcp:use');
        $this->nextMcpRateWindow();
        try {
            $accepted=0;
            $blocked=false;
            for ($i=0;$i<60;$i++) {
                $response=$this->requestRpc($token,'tools/list');
                if ($response->getStatusCode()===429) {
                    $blocked=true;
                    break;
                }
                $response->assertOk();
                $accepted++;
            }
            $this->assertGreaterThan(0,$accepted);
            $this->assertTrue($blocked,'Expected real MCP middleware to throttle the burst');
            $this->nextMcpRateWindow();
            $this->assertCount(38,$this->tools($token));
        } finally {
            $this->travelBack();
        }
    }

    public function test_all_fourteen_new_tools_through_real_oauth_and_all_write_scope_gates(): void
    {
        $a=$this->user(); $b=$this->user(); $c=$this->user();
        $order=$this->order($b,$a);
        $order->update(['receipt_number'=>(string)Str::uuid()]);
        $other=$this->order($b,$c); $other->update(['receipt_number'=>(string)Str::uuid()]);
        $test=$this->order($b,$a,'project','completed',false);
        $license=$this->licenseFixture($a,$b); $foreignLicense=$this->licenseFixture($b,$c);
        $draft=$this->contract($a,$b); $incoming=$this->contract($b,$a);
        $draft->update(['agreed_budget'=>'150.00']);
        $read=$this->authorizedBearer($a,'mcp:use');
        $write=$this->authorizedBearer($a,'mcp:use mcp.write');
        $foreign=$this->authorizedBearer($c,'mcp:use mcp.write');
        $buyer=$this->authorizedBearer($b,'mcp:use mcp.write');
        $this->nextMcpRateWindow();
        $this->assertSame(2,$this->resultData($read,'bambodia_orders_list')['total']);
        $this->assertTrue($this->resultData($read,'bambodia_order_get',['reference'=>$order->reference()])['verified_live_payment']);
        $this->assertSame('Paid',$this->resultData($read,'bambodia_receipt_get',['reference'=>$order->reference()])['status']);
        $this->assertCount(1,$this->resultData($read,'bambodia_transactions_summary')['by_currency_and_status']);
        $this->assertSame(1,$this->resultData($read,'bambodia_licenses_list')['total']);
        $licenseData=$this->resultData($read,'bambodia_license_get',['license_id'=>$license->public_id]);
        $this->assertStringNotContainsString('SECRET-KEY',json_encode($licenseData));
        $this->assertStringNotContainsString('private.example.test',json_encode($licenseData));
        $this->assertSame(1,$this->resultData($read,'bambodia_tracking_list')['total']);
        $tracking=$this->resultData($read,'bambodia_tracking_get',['reference'=>$order->reference()]);
        $this->assertSame(1,$this->resultData($read,'bambodia_contracts_list')['total']);
        $milestones=$this->resultData($read,'bambodia_milestones_list',['contract_id'=>$draft->public_id]);
        foreach ([
            ['bambodia_order_get',['reference'=>$other->reference()]],
            ['bambodia_receipt_get',['reference'=>$other->reference()]],
            ['bambodia_tracking_get',['reference'=>$other->reference()]],
            ['bambodia_tracking_get',['reference'=>$test->reference()]],
            ['bambodia_license_get',['license_id'=>$foreignLicense->public_id]],
            ['bambodia_milestones_list',['contract_id'=>$incoming->public_id]],
        ] as [$tool,$args]) $this->callRpc($read,$tool,$args)->assertJsonPath('result.isError',true);

        $create=$this->operation($order->reference(),$tracking['version'],['title'=>'Isolated OAuth task']);
        $milestone=['contract_id'=>$draft->public_id,'expected_version'=>$milestones['version'],'request_id'=>(string)Str::uuid(),
            'confirm'=>true,'title'=>'Isolated milestone','description'=>'A disposable OAuth acceptance milestone with clear deliverables.','amount'=>'50.00'];
        $comment=$this->operation($order->reference(),$tracking['version'],['message'=>'PRIVATE_OAUTH_COMMENT fixture.']);
        $this->nextMcpRateWindow();
        foreach (self::ALL_WRITES as $tool) {
            $args=match($tool) {
                'bambodia_tracking_task_create'=>$create,
                'bambodia_contract_milestone_create'=>$milestone,
                'bambodia_tracking_comment_create'=>$comment,
                'bambodia_tracking_task_update'=>[...$create,'task_id'=>1,'status'=>'completed'],
                default=>[],
            };
            $r=$this->callRpc($read,$tool,$args)->assertJsonPath('result.isError',true)->json('result');
            $this->assertStringContainsString('insufficient_scope',json_encode($r['_meta']['mcp/www_authenticate']??[],JSON_THROW_ON_ERROR),$tool);
        }
        $this->assertDatabaseCount('mcp_project_operations',0);
        $this->nextMcpRateWindow();
        $this->assertSame((string)$a->id,(string)$order->fresh()->seller_id,'FIXTURE_SELLER_MISMATCH');
        $this->assertNotSame((string)$c->id,(string)$order->fresh()->seller_id,'FIXTURE_FOREIGN_ACTOR_IS_SELLER');
        $this->assertSame((string)$b->id,(string)$order->fresh()->buyer_id,'FIXTURE_BUYER_MISMATCH');
        foreach ([
            [$foreign,'bambodia_tracking_task_create',$create],
            [$buyer,'bambodia_tracking_task_create',$create],
            [$buyer,'bambodia_contract_milestone_create',$milestone],
            [$write,'bambodia_tracking_comment_create',[...$comment,'confirm'=>false]],
        ] as [$token,$tool,$args]) {
            $response=$this->callRpc($token,$tool,$args);
            $this->assertDatabaseCount('mcp_project_operations',0);
            $this->assertDatabaseCount('order_tracking_tasks',0);
            $this->assertDatabaseCount('service_inquiry_messages',0);
            $this->assertSame(1,$draft->milestones()->count());
            $this->assertSame(0,DB::table('account_notifications')->whereIn('event',
                ['order.tracking.task_added','order.tracking.task_updated','inquiry.replied'])->count());
            $this->assertSame(0,DB::table('audit_events')->where('event','like','mcp.phase4.%')->count());
            $response->assertJsonPath('result.isError',true);
        }
        $r=$this->resultData($write,'bambodia_tracking_task_create',$create);
        $this->assertFalse($r['replayed']);
        $replay=$this->resultData($write,'bambodia_tracking_task_create',$create);
        $this->assertSame([...$r,'replayed'=>true],$replay);
        $this->callRpc($write,'bambodia_tracking_task_create',[...$create,'title'=>'Changed payload'])->assertJsonPath('result.isError',true);
        $this->callRpc($write,'bambodia_tracking_task_create',[...$create,'request_id'=>(string)Str::uuid()])->assertJsonPath('result.isError',true);
        $this->callRpc($write,'bambodia_tracking_comment_create',$comment)->assertJsonPath('result.isError',true);
        $update=$this->operation($order->reference(),$r['version'],['task_id'=>$r['task_id'],'status'=>'completed']);
        $u=$this->resultData($write,'bambodia_tracking_task_update',$update);
        $this->assertSame('completed',$u['status']);
        $this->assertSame([...$u,'replayed'=>true],$this->resultData($write,'bambodia_tracking_task_update',$update));
        $m=$this->resultData($write,'bambodia_contract_milestone_create',$milestone);
        $this->assertSame(2,$m['sequence']);
        $this->assertSame([...$m,'replayed'=>true],$this->resultData($write,'bambodia_contract_milestone_create',$milestone));
        $comment['expected_version']=$u['version'];
        $sent=$this->resultData($write,'bambodia_tracking_comment_create',$comment);
        $this->assertSame([...$sent,'replayed'=>true],$this->resultData($write,'bambodia_tracking_comment_create',$comment));
        $this->assertStringNotContainsString('PRIVATE_OAUTH_COMMENT',json_encode($sent));
        $this->assertDatabaseCount('mcp_project_operations',4);
        $this->assertDatabaseCount('order_tracking_tasks',1);
        $this->assertSame(2,$draft->milestones()->count());
        $this->assertDatabaseCount('service_inquiry_messages',1);
        $this->assertSame(3,DB::table('account_notifications')->where('user_id',$b->id)->whereIn('event',
            ['order.tracking.task_added','order.tracking.task_updated','inquiry.replied'])->count());
        $this->assertSame(1,DB::table('email_deliveries')->where('user_id',$b->id)->where('event','inquiry.replied')->count());
        foreach ([$r,$u,$m,$sent] as $receipt) {
            $this->assertDatabaseHas('mcp_project_operations',['actor_id'=>$a->id,'request_id'=>$receipt['request_id'],'audit_event_id'=>$receipt['audit_id']]);
            $this->assertDatabaseHas('audit_events',['id'=>$receipt['audit_id'],'actor_id'=>$a->id]);
        }
        $this->nextMcpRateWindow();
        config(['mcp.production_writes_enabled'=>false]);
        $this->assertCount(25,$this->tools($write));
        foreach (self::ALL_WRITES as $tool) $this->callRpc($write,$tool,[])->assertJsonPath('result.isError',true);
        $this->assertDatabaseCount('mcp_project_operations',4);
        $this->travelBack();
    }

    // A separate fresh schema is provisioned by the namespace operator for
    // this method. No outer PHPUnit transaction: worker sessions see fixtures.
    public function test_mysql_oauth_concurrent_and_strict_confirmation(): void
    {
        $this->assertDatabaseCount('mcp_project_operations',0);
        $seller=$this->user(); $buyer=$this->user();
        $order=$this->order($buyer,$seller);
        $draft=$this->contract($seller,$buyer); $draft->update(['agreed_budget'=>'150.00']);
        $bearer=$this->authorizedBearer($seller,'mcp:use mcp.write');
        $this->nextMcpRateWindow();
        $defs=$this->tools($bearer);
        $names=[...self::ALL_READS,...self::ALL_WRITES]; sort($names);
        $this->assertSame($names,array_keys($defs));
        foreach ($defs as $name=>$d) {
            $scopes=$d['securitySchemes'][0]['scopes']; sort($scopes);
            $expected=in_array($name,self::ALL_WRITES,true)?['mcp.write','mcp:use']:['mcp:use']; sort($expected);
            $this->assertSame($expected,$scopes);
        }
        $state=$this->resultData($bearer,'bambodia_tracking_get',['reference'=>$order->reference()]);
        $m=$this->resultData($bearer,'bambodia_milestones_list',['contract_id'=>$draft->public_id]);
        $create=$this->operation($order->reference(),$state['version'],['title'=>'Isolated concurrent task']);
        $comment=$this->operation($order->reference(),$state['version'],['message'=>'Isolated comment']);
        $milestone=['contract_id'=>$draft->public_id,'expected_version'=>$m['version'],'request_id'=>(string)Str::uuid(),
          'confirm'=>true,'title'=>'Isolated milestone','description'=>'A complete disposable acceptance milestone for testing.','amount'=>'50.00'];
        $update=$this->operation($order->reference(),$state['version'],['task_id'=>1,'status'=>'completed']);
        foreach (['bambodia_tracking_task_create'=>$create,'bambodia_tracking_task_update'=>$update,
          'bambodia_contract_milestone_create'=>$milestone,'bambodia_tracking_comment_create'=>$comment] as $tool=>$args) {
            $this->nextMcpRateWindow();
            foreach ([array_diff_key($args,['confirm'=>true]),[...$args,'confirm'=>false],[...$args,'confirm'=>'true'],
                [...$args,'request_id'=>'not-a-uuid'],[...$args,'expected_version'=>'bad'],[...$args,'unexpected'=>1]] as $bad)
                $this->callRpc($bearer,$tool,$bad)->assertJsonPath('result.isError',true);
            $this->assertDatabaseCount('mcp_project_operations',0);
            $this->assertDatabaseCount('order_tracking_tasks',0);
            $this->assertDatabaseCount('service_inquiry_messages',0);
            $this->assertSame(1,$draft->milestones()->count());
            $this->assertSame(0,DB::table('audit_events')->where('event','like','mcp.phase4.%')->count());
            $this->assertSame(0,DB::table('account_notifications')->whereIn('event',['order.tracking.task_added','order.tracking.task_updated','inquiry.replied'])->count());
        }
        // Restore wall-clock time before starting independent PHP processes.
        $this->travelBack();
        $race=function(string $table,int $id,string $tool,array $left,array $right) use($seller,$bearer): array {
            $workers=[];
            $wait=function(callable $test): void {
                $end=microtime(true)+12;
                do { if ($test()) return; usleep(25000); } while(microtime(true)<$end);
                throw new \RuntimeException('OAuth contention barrier timed out');
            };
            try {
                DB::beginTransaction();
                try {
                    $this->assertNotNull(DB::table($table)->where('id',$id)->lockForUpdate()->first());
                    foreach ([$left,$right] as $args) {
                        $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('qa-phase4d3c2/oauth-worker.php')],base_path(),null,
                          json_encode(['actor'=>$seller->id,'bearer'=>$bearer,'tool'=>$tool,'args'=>$args],JSON_THROW_ON_ERROR),55);
                        $workers[]=$p; $p->start();
                    }
                    $ids=[];
                    $wait(function()use(&$ids,$workers) {
                        $ids=[];
                        foreach ($workers as $p) {
                            if (!$p->isRunning()) throw new \RuntimeException('OAuth worker exited before contention');
                            if (!preg_match('/^READY ([0-9]+)$/m',$p->getOutput(),$m)) return false;
                            $ids[]=(int)$m[1];
                        }
                        return count(array_unique($ids))===2;
                    });
                    $wait(function()use($ids) {
                        $seen=[];
                        foreach(DB::select('SHOW FULL PROCESSLIST') as $row)
                            if(in_array((int)$row->Id,$ids,true)&&stripos((string)$row->Info,'for update')!==false) $seen[]=(int)$row->Id;
                        return count(array_unique($seen))===2;
                    });
                } finally { DB::rollBack(); }
                $out=[];
                foreach ($workers as $p) {
                    $p->wait(); $this->assertTrue($p->isSuccessful(),'OAuth worker failed (private log withheld)');
                    $this->assertSame(1,preg_match('/^RESULT (.+)$/m',$p->getOutput(),$match));
                    $out[]=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
                }
                return $out;
            } finally { foreach($workers as $p) if($p->isRunning()) $p->stop(1); }
        };
        $r=$race('users',(int)$seller->id,'bambodia_tracking_task_create',$create,$create);
        $this->assertSame(['ok','ok'],array_column($r,'kind'));
        $a=$r[0]['receipt']; $b=$r[1]['receipt'];
        $this->assertNotSame($a['replayed'],$b['replayed']);
        unset($a['replayed'],$b['replayed']); $this->assertSame($a,$b);
        $this->assertDatabaseCount('order_tracking_tasks',1); $this->assertDatabaseCount('mcp_project_operations',1);
        $this->assertSame(1,DB::table('account_notifications')->where('event','order.tracking.task_added')->count());
        $args=$this->operation($order->reference(),$a['version'],['task_id'=>$a['task_id'],'status'=>'completed']);
        $r=$race('marketplace_orders',(int)$order->id,'bambodia_tracking_task_update',$args,[...$args,'request_id'=>(string)Str::uuid(),'status'=>'in_progress']);
        $ok=array_values(array_filter($r,fn($v)=>$v['kind']==='ok'));
        $bad=array_values(array_filter($r,fn($v)=>$v['kind']==='rejected'));
        $this->assertCount(1,$ok); $this->assertCount(1,$bad); $this->assertTrue($bad[0]['stale']);
        $this->assertDatabaseCount('mcp_project_operations',2);
        $this->assertSame(1,DB::table('account_notifications')->where('event','order.tracking.task_updated')->count());
        foreach ([$a,$ok[0]['receipt']] as $receipt)
            $this->assertDatabaseHas('mcp_project_operations',['actor_id'=>$seller->id,'request_id'=>$receipt['request_id'],'audit_event_id'=>$receipt['audit_id']]);
    }
}
