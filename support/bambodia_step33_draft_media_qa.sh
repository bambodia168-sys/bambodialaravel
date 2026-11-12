#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

APP="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_HASH="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
DB="bambodia_oauthqa_browser"
DB_USER="bambodiaqa_browser"
CNF="/root/bambodia-oauth-browser-qa.cnf"
PHP="/www/server/php/84/bin/php"
MYSQL="/www/server/mysql/bin/mysql"

printf '%s\n' '========== VERIFY FROZEN BROWSER QA =========='
test "$(id -u)" -eq 0
test -d "$APP/.git" && test ! -L "$APP"
test "$(realpath -e "$APP")" = "$APP"
test "$(git -C "$APP" rev-parse HEAD)" = "$SHA"
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
test -f "$APP/vendor/autoload.php" && test -f "$APP/composer.lock"
test "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" = "$LOCK_HASH"
test -f "$APP/.env" && test ! -L "$APP/.env"
test -d "$APP/public/build" && test ! -L "$APP/public/build"
test -s "$APP/public/build/manifest.json" && test ! -L "$APP/public/build/manifest.json"
test ! -e "$APP/public/hot" && test ! -L "$APP/public/hot"
test "$(stat -c '%U:%G:%a' "$APP/.env")" = 'root:www:640'
test ! -e "$APP/bootstrap/cache/config.php"
test -x "$PHP" && test -x "$MYSQL" && test -c /dev/tty
id www >/dev/null
for expected in 'APP_ENV=staging' 'APP_DEBUG=false' \
 'APP_URL=https://oauth-browser-qa.invalid' 'DB_CONNECTION=mysql' \
 'DB_HOST=127.0.0.1' "DB_DATABASE=$DB" "DB_USERNAME=$DB_USER" \
 'SESSION_DRIVER=file' 'MAIL_MAILER=log'; do
  grep -Fxq "$expected" "$APP/.env"
done
for key in oauth-private.key oauth-public.key; do
  test -f "$APP/storage/$key" && test ! -L "$APP/storage/$key"
  test "$(stat -c '%U:%G:%a' "$APP/storage/$key")" = 'www:www:600'
done
printf '%s\n' 'QA_FROZEN_SOURCE=PASS'

printf '%s\n' '========== VERIFY RESTRICTED QA MYSQL =========='
test -f "$CNF" && test ! -L "$CNF"
test "$(stat -c '%u:%a' "$CNF")" = '0:600'
QA_MYSQL() {
 "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP --host=127.0.0.1 \
   --batch --skip-column-names "$@"
}
test "$(QA_MYSQL "$DB" -e 'SELECT DATABASE()')" = "$DB"
IDENTITY="$(QA_MYSQL "$DB" -e 'SELECT CURRENT_USER()')"
QA_MYSQL "$DB" -e 'SHOW GRANTS FOR CURRENT_USER()' | \
 python3 "$APP/support/mcp-oauth-db-grant-guard.py" "$DB" "$DB_USER" "$IDENTITY"
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM migrations')" = 67
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM users WHERE email='oauth-admin@oauth-browser-qa.invalid' AND role='super_admin' AND account_status='active'")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
printf '%s\n' 'QA_BASELINE=PASS' 'ACTIVE_OAUTH_GRANTS=0'

exec 9>/root/bambodia-oauth-browser-pkce.lock
flock -n 9
printf '%s\n' 'QA_PKCE_LOCK=ACQUIRED'
printf '%s\n' 'This test exercises isolated OAuth draft-media ownership and review gates,' \
 'creates synthetic metadata only, then revokes grants and cleans up.'
printf 'Type TEST-QA-DRAFT-MEDIA to continue: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
if [ "$CONFIRM" != 'TEST-QA-DRAFT-MEDIA' ]; then
  echo 'STEP33_CONFIRMATION=DECLINED'
  exit 1
fi
echo 'STEP33_CONFIRMATION=PASS'

printf '%s\n' '========== TEST DRAFT MEDIA WRITE AND OWNERSHIP =========='
if REPORT="$(
 cd "$APP"
 runuser -u www -- "$PHP" <<'PHP'
<?php
$db = null;
$clientId = null;
$actorId = null;
$actorEmail = null;
$foreignId = null;
$foreignEmail = null;
$nonce = null;
$stage = 'BOOTSTRAP';
$passed = false;
$clean = false;
$listingIds = [];
$assetIds = [];
$postIds = [];
$jobIds = [];
$socialBefore = null;

function qa(bool $ok,string $name): void {
    echo $name.'='.($ok ? 'PASS' : 'FAIL')."\n";
    if (!$ok) throw new RuntimeException($name);
}

function reqQa($app,$kernel,string $method,string $uri,
               array $data = [],array $cookies = [],
               ?\App\Models\User $actor = null,
               ?string $bearer = null,bool $json = false): array {
    $server = [
        'HTTP_ACCEPT'=>$json ? 'application/json' : 'text/html',
        'CONTENT_TYPE'=>$json ? 'application/json' : 'application/x-www-form-urlencoded',
        'HTTPS'=>'on',
    ];
    if ($bearer !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    $request = \Illuminate\Http\Request::create(
        $uri,$method,$json ? [] : $data,$cookies,[],$server,
        $json ? json_encode($data,JSON_THROW_ON_ERROR) : null
    );
    $app->instance('request',$request);
    \Illuminate\Support\Facades\Auth::forgetGuards();
    if ($actor !== null) {
        \Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request,$response);
    return [$request,$response];
}

function callMedia($app,$kernel,string $base,string $bearer,
                   string $entity,int $recordId,string $action,
                   string $purpose,int $mediaId,int $rpcId): array {
    $rpc = [
        'jsonrpc'=>'2.0','id'=>$rpcId,'method'=>'tools/call',
        'params'=>['name'=>'bambodia_draft_media','arguments'=>[
            'entity'=>$entity,'id'=>$recordId,'action'=>$action,
            'purpose'=>$purpose,'media_id'=>$mediaId,
        ]],
    ];
    [, $res] = reqQa($app,$kernel,'POST',$base.'/mcp',
                     $rpc,bearer:$bearer,json:true);
    return [
        $res->getStatusCode(),
        json_decode($res->getContent(),true,512,JSON_THROW_ON_ERROR),
    ];
}

function allowed(int $status,array $payload): bool {
    return $status === 200
        && ($payload['result']['isError'] ?? null) === false
        && is_array($payload['result']['structuredContent'] ?? null);
}
function denied(int $status,array $payload): bool {
    return $status === 200
        && ($payload['result']['isError'] ?? null) === true;
}
function verifiedClaims(string $token): array {
    $parts = explode('.',$token);
    if (count($parts) !== 3) throw new RuntimeException('Unexpected JWT');
    return json_decode(
        base64_decode(strtr($parts[1],'-_','+/'),true),
        true,512,JSON_THROW_ON_ERROR
    );
}
function addQaAsset(int $owner,string $nonce,string $slug,string $mime,
                    string $status,?int $reviewer): \App\Models\MediaAsset {
    $suffix = $mime === 'image/gif' ? 'gif'
       : ($mime === 'video/mp4' ? 'mp4' : 'jpg');
    // Metadata only; no file is created or exposed to the public network.
    return \App\Models\MediaAsset::query()->create([
        'user_id'=>$owner,
        'original_name'=>'step33-'.$nonce.'-'.$slug.'.'.$suffix,
        'storage_path'=>'step33-qa/'.$nonce.'/'.$slug.'.'.$suffix,
        'storage_disk'=>'local',
        'mime_type'=>$mime,
        'bytes'=>1024,
        'width'=>400,
        'height'=>400,
        'alt_text'=>'Synthetic Step33 metadata for authorization tests',
        'rights_basis'=>'owned',
        'rights_holder'=>'Step33 Synthetic QA Rights Fixture',
        'rights_note'=>'Synthetic metadata only. No media file, actual approval or rights adjudication.',
        'review_status'=>$status,
        'review_note'=>$status==='approved' ? 'Synthetic QA metadata fixture' : null,
        'reviewed_by'=>$reviewer,
        'reviewed_at'=>$reviewer !== null ? now() : null,
    ]);
}

try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    $base = 'https://oauth-browser-qa.invalid';
    $callback = 'https://chatgpt.com/connector/oauth/qa-callback';
    qa(config('app.env') === 'staging'
       && config('app.debug') === false
       && config('app.url') === $base
       && config('database.default') === 'mysql'
       && config('session.driver') === 'file'
       && config('mcp.redirect_domains') === ['https://chatgpt.com']
       && \Illuminate\Support\Facades\DB::connection()->getDatabaseName()
            === 'bambodia_oauthqa_browser','QA_RUNTIME_ISOLATION');

    $db = \Illuminate\Support\Facades\DB::connection();
    $original = \App\Models\User::query()
       ->where('email','oauth-admin@oauth-browser-qa.invalid')->firstOrFail();
    $originalId = (int)$original->id;
    $originalPasswordHash = (string)$original->getRawOriginal('password');
    $client = \Laravel\Passport\Passport::client()->newQuery()
       ->where('name','Bambodia Browser QA PKCE Client')->firstOrFail();
    $clientId = (string)$client->getKey();
    $redirects = $client->getAttribute('redirect_uris');
    if (is_string($redirects)) $redirects = json_decode($redirects,true);
    qa($db->table('users')->count() === 1
       && $db->table('oauth_clients')->count() === 1
       && $original->role === 'super_admin'
       && $original->account_status === 'active'
       && is_array($redirects) && in_array($callback,$redirects,true),
       'ORIGINAL_QA_USER_AND_CLIENT');

    foreach (['oauth_auth_codes','oauth_access_tokens',
              'oauth_refresh_tokens'] as $table) {
        qa($db->table($table)->where('revoked',false)->count() === 0,
           strtoupper($table).'_INACTIVE_BASELINE');
    }
    foreach ([
        'media_assets','marketplace_listings','marketplace_jobs','content_entries',
    ] as $table) {
        qa($db->table($table)->count() === 0,
           strtoupper($table).'_EMPTY_BASELINE');
    }
    $socialBefore = $db->table('site_social_links')
       ->orderBy('id')->get()->map(static fn ($row)=>(array)$row)->all();
    qa(count($socialBefore) >= 2,'EXISTING_SITE_SOCIAL_BASELINE');

    $stage='CREATE_TEMP_IDENTITIES';
    $nonce=bin2hex(random_bytes(8));
    $actorEmail='step33-media-'.$nonce.'@oauth-browser-qa.invalid';
    $foreignEmail='step33-foreign-'.$nonce.'@oauth-browser-qa.invalid';
    $actor=\App\Models\User::query()->create([
        'name'=>'Step33 Temporary Media Admin',
        'email'=>$actorEmail,
        'password'=>bin2hex(random_bytes(32)),
        'role'=>'super_admin',
        'account_status'=>'active',
    ]);
    $actorId=(int)$actor->id;
    $foreign=\App\Models\User::query()->create([
        'name'=>'Step33 Temporary Foreign Media Admin',
        'email'=>$foreignEmail,
        'password'=>bin2hex(random_bytes(32)),
        'role'=>'super_admin',
        'account_status'=>'active',
    ]);
    $foreignId=(int)$foreign->id;
    qa($actorId!==$foreignId && $actorId!==$originalId
       && $foreignId!==$originalId && $db->table('users')->count()===3,
       'TWO_TEMPORARY_QA_IDENTITIES_CREATED');

    $stage='PKCE_AUTHORIZE';
    $verifier=bin2hex(random_bytes(32));
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $state=bin2hex(random_bytes(24));
    $params=http_build_query([
        'client_id'=>$clientId,'redirect_uri'=>$callback,
        'response_type'=>'code','scope'=>'mcp:use mcp.write',
        'state'=>$state,'code_challenge'=>$challenge,
        'code_challenge_method'=>'S256','resource'=>$base.'/mcp',
        'prompt'=>'consent',
    ]);
    [$request,$consent]=reqQa(
        $app,$kernel,'GET',$base.'/oauth/authorize?'.$params,actor:$actor
    );
    qa($consent->getStatusCode()===200,'WRITE_CONSENT_HTTP_200');
    $csrf=$request->session()->token();
    $authToken=$request->session()->get('authToken');
    $cookie=null;
    foreach ($consent->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            qa($c->isSecure(),'SECURE_OAUTH_CONSENT_SESSION');
            $cookie=$c->getValue();break;
        }
    }
    qa(is_string($csrf) && strlen($csrf)>=20
       && is_string($authToken) && $authToken!==''
       && is_string($cookie) && $cookie!=='',
       'OAUTH_CONSENT_CSRF_AND_SESSION');
    [, $approval]=reqQa($app,$kernel,'POST',
         route('passport.authorizations.approve'),[
             '_token'=>$csrf,'client_id'=>$clientId,'state'=>$state,
             'auth_token'=>$authToken,
         ],[config('session.cookie')=>$cookie],$actor);
    $redirect=$approval->headers->get('Location','');
    parse_str(parse_url($redirect,PHP_URL_QUERY) ?: '',$parsed);
    $code=$parsed['code']??null;
    qa(in_array($approval->getStatusCode(),[302,303],true)
       && str_starts_with($redirect,$callback.'?')
       && ($parsed['state']??null)===$state
       && is_string($code) && $code!=='',
       'PKCE_CONSENT_CODE_AND_CALLBACK');

    $stage='PKCE_TOKEN';
    [, $res]=reqQa($app,$kernel,'POST',$base.'/oauth/token',[
       'grant_type'=>'authorization_code','client_id'=>$clientId,
       'code'=>$code,'redirect_uri'=>$callback,
       'code_verifier'=>$verifier,'resource'=>$base.'/mcp',
    ]);
    $tokenData=json_decode($res->getContent(),true,512,JSON_THROW_ON_ERROR);
    $bearer=$tokenData['access_token']??null;
    qa($res->getStatusCode()===200 && is_string($bearer) && $bearer!=='',
       'SIGNED_WRITE_TOKEN_EXCHANGE');
    $claims=verifiedClaims($bearer);
    qa(($claims['iss']??null)===$base
       && ($claims['aud'][0]??null)===$clientId
       && ($claims['aud'][1]??null)===$base.'/mcp'
       && in_array('mcp:use',$claims['scopes']??[],true)
       && in_array('mcp.write',$claims['scopes']??[],true),
       'SIGNED_OAUTH_WRITE_SCOPE_AND_RESOURCE');

    $stage='CREATE_QA_MEDIA_METADATA';
    $ownJpeg=addQaAsset($actorId,$nonce,'approved-owner','image/jpeg','approved',$actorId);
    $ownGif=addQaAsset($actorId,$nonce,'approved-gif','image/gif','approved',$actorId);
    $pending=addQaAsset($actorId,$nonce,'pending-owner','image/jpeg','pending',null);
    $foreignJpeg=addQaAsset($foreignId,$nonce,'foreign-approved','image/jpeg','approved',$foreignId);
    foreach([$ownJpeg,$ownGif,$pending,$foreignJpeg] as $media) $assetIds[]=(int)$media->id;
    qa($db->table('media_assets')->count()===4
       && $db->table('media_assets')->where('id',$ownJpeg->id)
          ->where('review_status','approved')->where('user_id',$actorId)->exists()
       && $db->table('media_assets')->where('id',$pending->id)
          ->where('review_status','pending')->exists(),
       'SYNTHETIC_MEDIA_METADATA_CREATED');
    // Even the 'approved' test row has no corresponding file on disk.
    $disk=\Illuminate\Support\Facades\Storage::disk('local');
    qa(!$disk->exists($ownJpeg->storage_path)
       && !$disk->exists($ownGif->storage_path)
       && !$disk->exists($foreignJpeg->storage_path),
       'MEDIA_FILES_NOT_CREATED');

    $stage='CREATE_QA_DRAFTS';
    $title='Step33 Media QA '.$nonce.' owned draft';
    $owned=\App\Models\MarketplaceListing::query()->create([
       'user_id'=>$actorId,'type'=>'service','title'=>$title,
       'description'=>str_repeat('Synthetic media approval draft content. ',3),
       'currency'=>'USD','status'=>'draft',
    ]);
    $listingIds[]=(int)$owned->id;
    $foreignListing=\App\Models\MarketplaceListing::query()->create([
       'user_id'=>$foreignId,'type'=>'service',
       'title'=>'Step33 Foreign QA '.$nonce,
       'description'=>str_repeat('Synthetic foreign media owner content. ',3),
       'currency'=>'USD','status'=>'draft',
    ]);
    $listingIds[]=(int)$foreignListing->id;
    $published=\App\Models\MarketplaceListing::query()->create([
       'user_id'=>$actorId,'type'=>'service',
       'title'=>'Step33 Published QA '.$nonce,
       'description'=>str_repeat('Synthetic published but isolated QA record. ',3),
       'currency'=>'USD','status'=>'published',
    ]);
    $listingIds[]=(int)$published->id;
    $post=\App\Models\ContentEntry::query()->create([
       'author_id'=>$actorId,'type'=>'post',
       'title'=>'Step33 Content Post '.$nonce,
       'body'=>str_repeat('Synthetic owned content post for media QA. ',3),
       'body_format'=>'html','status'=>'draft','published_at'=>null,
    ]);
    $postIds[]=(int)$post->id;
    qa($db->table('marketplace_listings')->count()===3
       && $db->table('content_entries')->count()===1,
       'OWN_FOREIGN_PUBLISHED_AND_POST_FIXTURES_CREATED');

    $stage='CHECK_MEDIA_REJECTION';
    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','cover',(int)$pending->id,2);
    qa(denied($status,$result)
       && $owned->fresh()->cover_media_id===null,
       'UNREVIEWED_OWN_MEDIA_REJECTED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','cover',(int)$foreignJpeg->id,3);
    qa(denied($status,$result)
       && $owned->fresh()->cover_media_id===null,
       'FOREIGN_APPROVED_MEDIA_REJECTED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','cover',(int)$ownGif->id,4);
    qa(denied($status,$result)
       && $owned->fresh()->cover_media_id===null,
       'INCOMPATIBLE_GIF_COVER_REJECTED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$foreignListing->id,'attach','cover',(int)$ownJpeg->id,5);
    qa(denied($status,$result)
       && $foreignListing->fresh()->cover_media_id===null,
       'FOREIGN_OWNED_DRAFT_REJECTED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$published->id,'attach','cover',(int)$ownJpeg->id,6);
    qa(denied($status,$result)
       && $published->fresh()->cover_media_id===null
       && $published->fresh()->status==='published',
       'PUBLISHED_RECORD_MEDIA_CHANGE_REJECTED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'post',(int)$post->id,'attach','cover',(int)$ownJpeg->id,7);
    qa(denied($status,$result),'POST_COVER_NOT_SUPPORTED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'post',(int)$post->id,'attach','gallery',(int)$ownJpeg->id,8);
    qa(denied($status,$result),'POST_GALLERY_NOT_SUPPORTED');

    $stage='APPROVED_MEDIA_COVER';
    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','cover',(int)$ownJpeg->id,9);
    qa(allowed($status,$result)
       && (int)$owned->fresh()->cover_media_id===(int)$ownJpeg->id
       && $owned->fresh()->status==='draft',
       'OWN_APPROVED_COVER_ATTACHED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'detach','cover',(int)$ownGif->id,10);
    qa(denied($status,$result)
       && (int)$owned->fresh()->cover_media_id===(int)$ownJpeg->id,
       'INCORRECT_COVER_DETACH_DENIED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'detach','cover',(int)$ownJpeg->id,11);
    qa(allowed($status,$result)
       && $owned->fresh()->cover_media_id===null,
       'OWN_COVER_DETACHED');

    $stage='GALLERY';
    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','gallery',(int)$ownJpeg->id,12);
    qa(allowed($status,$result)
       && $owned->fresh()->gallery_media_ids===[(int)$ownJpeg->id],
       'APPROVED_GALLERY_IMAGE_ATTACHED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','gallery',(int)$ownJpeg->id,13);
    qa(allowed($status,$result)
       && $owned->fresh()->gallery_media_ids===[(int)$ownJpeg->id],
       'GALLERY_DUPLICATE_DEDUPLICATED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','gallery',(int)$ownGif->id,14);
    qa(allowed($status,$result)
       && $owned->fresh()->gallery_media_ids===[(int)$ownJpeg->id,(int)$ownGif->id],
       'APPROVED_GIF_GALLERY_ATTACHED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'detach','gallery',(int)$foreignJpeg->id,15);
    qa(denied($status,$result)
       && count($owned->fresh()->gallery_media_ids ?? [])===2,
       'UNKNOWN_GALLERY_DETACH_DENIED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'detach','gallery',(int)$ownGif->id,16);
    qa(allowed($status,$result)
       && $owned->fresh()->gallery_media_ids===[(int)$ownJpeg->id],
       'APPROVED_GALLERY_IMAGE_DETACHED');

    $stage='FEATURED';
    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'attach','featured',(int)$ownGif->id,17);
    qa(allowed($status,$result)
       && (int)$owned->fresh()->featured_media_id===(int)$ownGif->id
       && $owned->fresh()->featured_kind==='animated',
       'APPROVED_GIF_FEATURED_ATTACHED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'service',(int)$owned->id,'detach','featured',(int)$ownGif->id,18);
    qa(allowed($status,$result)
       && $owned->fresh()->featured_media_id===null
       && $owned->fresh()->featured_kind==='none',
       'FEATURED_MEDIA_DETACHED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'post',(int)$post->id,'attach','featured',(int)$ownJpeg->id,19);
    qa(allowed($status,$result)
       && (int)$post->fresh()->featured_media_id===(int)$ownJpeg->id
       && $post->fresh()->featured_type==='image',
       'POST_FEATURED_APPROVED_IMAGE_ATTACHED');

    [$status,$result]=callMedia($app,$kernel,$base,$bearer,
        'post',(int)$post->id,'detach','featured',(int)$ownJpeg->id,20);
    qa(allowed($status,$result)
       && $post->fresh()->featured_media_id===null
       && $post->fresh()->featured_type==='none',
       'POST_FEATURED_IMAGE_DETACHED');

    $stage='AUDIT_AND_STATUS';
    qa($owned->fresh()->status==='draft'
       && $foreignListing->fresh()->status==='draft'
       && $published->fresh()->status==='published'
       && $post->fresh()->status==='draft',
       'MEDIA_TOOLS_DID_NOT_CHANGE_PUBLICATION_STATUS');
    qa($db->table('media_assets')->where('id',$pending->id)
       ->where('review_status','pending')->exists()
       && $db->table('media_assets')->where('id',$foreignJpeg->id)
       ->where('user_id',$foreignId)->where('review_status','approved')->exists(),
       'MEDIA_RIGHTS_AND_REVIEW_METADATA_UNCHANGED');
    qa($db->table('users')->where('id',$originalId)
       ->where('password',$originalPasswordHash)->where('role','super_admin')
       ->where('account_status','active')->exists(),
       'ORIGINAL_ADMIN_CREDENTIALS_UNCHANGED');

    $stage='REVOKE';
    $ids=$db->table('oauth_access_tokens')
       ->where('client_id',$clientId)->pluck('id')->all();
    qa(count($ids)>0,'QA_WRITE_GRANT_RECORDED');
    $db->table('oauth_refresh_tokens')
       ->whereIn('access_token_id',$ids)->update(['revoked'=>true]);
    $db->table('oauth_access_tokens')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    $db->table('oauth_auth_codes')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    [, $revoked]=reqQa($app,$kernel,'POST',$base.'/mcp',[
       'jsonrpc'=>'2.0','id'=>21,'method'=>'tools/list',
       'params'=>new stdClass(),
    ],bearer:$bearer,json:true);
    qa($revoked->getStatusCode()===401,'REVOKED_MEDIA_BEARER_HTTP_401');
    $passed=true;

} catch (\Throwable $error) {
    fwrite(STDERR,'STEP33_FAILURE_STAGE='.$stage."\n".
                  'ERROR_CLASS='.get_class($error)."\n");
} finally {
    if ($db !== null) {
        try {
            if ($clientId !== null) {
                $ids=$db->table('oauth_access_tokens')
                   ->where('client_id',$clientId)->pluck('id')->all();
                if ($ids!==[]) {
                    $db->table('oauth_refresh_tokens')
                       ->whereIn('access_token_id',$ids)
                       ->update(['revoked'=>true]);
                }
                $db->table('oauth_access_tokens')
                   ->where('client_id',$clientId)->update(['revoked'=>true]);
                $db->table('oauth_auth_codes')
                   ->where('client_id',$clientId)->update(['revoked'=>true]);
            }
            // Remove only identifiers captured from this single random test
            // instance, and only when the synthetic actor/nonce match.
            if ($nonce !== null && $actorId !== null && $foreignId !== null) {
                if ($listingIds!==[]) {
                    $db->table('marketplace_listings')
                      ->whereIn('id',$listingIds)
                      ->whereIn('user_id',[$actorId,$foreignId])
                      ->where('title','like','Step33 %'.$nonce.'%')
                      ->delete();
                }
                if ($postIds!==[]) {
                    $db->table('content_entries')
                      ->whereIn('id',$postIds)
                      ->where('author_id',$actorId)
                      ->where('title','like','Step33 %'.$nonce.'%')
                      ->delete();
                }
                if ($jobIds!==[]) {
                    $db->table('marketplace_jobs')
                      ->whereIn('id',$jobIds)
                      ->where('client_id',$actorId)
                      ->where('title','like','Step33 %'.$nonce.'%')
                      ->delete();
                }
                if ($assetIds!==[]) {
                    $db->table('media_assets')
                      ->whereIn('id',$assetIds)
                      ->whereIn('user_id',[$actorId,$foreignId])
                      ->where('storage_path','like','step33-qa/'.$nonce.'/%')
                      ->delete();
                }
            }
            if ($foreignId!==null && $foreignEmail!==null) {
                $db->table('users')->where('id',$foreignId)
                    ->where('email',$foreignEmail)
                    ->where('name','Step33 Temporary Foreign Media Admin')
                    ->delete();
            }
            if ($actorId!==null && $actorEmail!==null) {
                $db->table('users')->where('id',$actorId)
                    ->where('email',$actorEmail)
                    ->where('name','Step33 Temporary Media Admin')
                    ->delete();
            }
            $active=0;
            foreach ([
                'oauth_auth_codes','oauth_access_tokens','oauth_refresh_tokens',
            ] as $table) {
                $active += $db->table($table)->where('revoked',false)->count();
            }
            $after=$db->table('site_social_links')
                ->orderBy('id')->get()
                ->map(static fn ($row)=>(array)$row)->all();
            $socialIntact=is_array($socialBefore) && $socialBefore===$after;
            echo 'ORIGINAL_SOCIAL_LINKS_UNCHANGED='.
               ($socialIntact?'PASS':'FAIL')."\n";
            echo 'ALL_QA_GRANTS_REVOKED='.
               ($active===0?'PASS':'FAIL')."\n";
            $clean=$socialIntact
                && $active===0
                && $db->table('users')->count()===1
                && $db->table('oauth_clients')->count()===1
                && $db->table('media_assets')->count()===0
                && $db->table('marketplace_listings')->count()===0
                && $db->table('marketplace_jobs')->count()===0
                && $db->table('content_entries')->count()===0
                && $db->table('users')
                   ->where('email','oauth-admin@oauth-browser-qa.invalid')
                   ->where('role','super_admin')
                   ->where('account_status','active')->count()===1;
            echo 'STEP33_FIXTURES_CLEAN='.($clean?'PASS':'FAIL')."\n";
        } catch (\Throwable $error) {
            fwrite(STDERR,'STEP33_CLEANUP=MANUAL_REVIEW_REQUIRED'."\n");
        }
    }
}
if (!$passed || !$clean) {
    echo "STEP33_PHP=FAIL\n";
    exit(33);
}
echo "STEP33_PHP=PASS\n";
exit(0);
PHP
)"; then
  printf '%s\n' "$REPORT"
else
  printf '%s\n' "$REPORT"
  echo 'STEP33_RESULT=BLOCKED; verify QA grants before any retry.'
  exit 1
fi

grep -Fxq 'STEP33_PHP=PASS' <<< "$REPORT"
grep -Fxq 'ALL_QA_GRANTS_REVOKED=PASS' <<< "$REPORT"
grep -Fxq 'STEP33_FIXTURES_CLEAN=PASS' <<< "$REPORT"
grep -Fxq 'ORIGINAL_SOCIAL_LINKS_UNCHANGED=PASS' <<< "$REPORT"
! grep -Fq '=FAIL' <<< "$REPORT"

echo '========== VERIFY POST-TEST DATABASE =========='
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM marketplace_listings')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM content_entries')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM creator_profiles')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM site_seo_pages')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM media_assets')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM marketplace_jobs')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM site_social_links')" -ge 2
test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM site_social_links WHERE platform='website' AND label='Author website' AND url='https://tonsamith.com' AND is_active=1")" = 1
test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM site_social_links WHERE platform='github' AND label='Bambodia GitHub repository' AND url='https://github.com/bambodia168-sys/bambodialaravel' AND is_active=1")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
 test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
printf '%s\n' \
 'STEP33_DRAFT_MEDIA_OWNERSHIP_APPROVAL=PASS' \
 'QA_USERS_AND_CLIENT=UNCHANGED' \
 'ACTIVE_OAUTH_GRANTS=0' \
 'HTTPS_VHOST=NOT_CREATED' \
 'PRODUCTION_DEPLOYMENT=NONE'
