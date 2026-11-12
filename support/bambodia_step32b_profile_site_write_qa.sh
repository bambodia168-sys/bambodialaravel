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
printf '%s\n' 'This test exercises authorized QA profile and site-metadata writes,' \
 'preserves seeded social links, tests confirmation gates, then cleans up.'
printf 'Type TEST-QA-PROFILE-SITE-SEEDED to continue: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
if [ "$CONFIRM" != 'TEST-QA-PROFILE-SITE-SEEDED' ]; then
  echo 'STEP32_CONFIRMATION=DECLINED'
  exit 1
fi
echo 'STEP32_CONFIRMATION=PASS'

printf '%s\n' '========== TEST PROFILE, SEO AND SOCIAL WRITE BOUNDARIES =========='
if REPORT="$(
 cd "$APP"
 runuser -u www -- "$PHP" <<'PHP'
<?php
$db = null;
$clientId = null;
$actorId = null;
$actorEmail = null;
$nonce = null;
$seoPath = null;
$socialUrl = null;
$stage = 'BOOTSTRAP';
$passed = false;
$clean = false;
$socialBaselineRows = null;

function qa(bool $ok, string $name): void {
    echo $name.'='.($ok ? 'PASS' : 'FAIL')."\n";
    if (!$ok) { throw new RuntimeException($name); }
}

function reqQa($app, $kernel, string $method, string $uri,
               array $data = [], array $cookies = [],
               ?\App\Models\User $actor = null,
               ?string $bearer = null, bool $json = false): array {
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

function claims(string $token): array {
    $parts = explode('.',$token);
    if (count($parts) !== 3) throw new RuntimeException('JWT shape');
    return json_decode(
        base64_decode(strtr($parts[1],'-_','+/'),true),
        true,512,JSON_THROW_ON_ERROR
    );
}

function mcpWrite($app,$kernel,string $base,string $token,
                  string $name,array $args,int $id): array {
    $rpc = [
        'jsonrpc'=>'2.0','id'=>$id,'method'=>'tools/call',
        'params'=>['name'=>$name,'arguments'=>$args],
    ];
    [, $response] = reqQa($app,$kernel,'POST',$base.'/mcp',$rpc,
                          bearer:$token,json:true);
    return [$response->getStatusCode(),
            json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR)];
}

function approved(int $status,array $response): bool {
    return $status === 200
        && ($response['result']['isError'] ?? null) === false
        && is_array($response['result']['structuredContent'] ?? null);
}
function denied(int $status,array $response): bool {
    return $status === 200 && ($response['result']['isError'] ?? null) === true;
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
       'QA_ORIGINAL_IDENTITY_AND_CLIENT');
    foreach (['oauth_auth_codes','oauth_access_tokens',
              'oauth_refresh_tokens'] as $table) {
        qa($db->table($table)->where('revoked',false)->count() === 0,
           strtoupper($table).'_INACTIVE_BASELINE');
    }
    // The frozen 2026_11_10_000003 migration intentionally inserts two
    // site_social_links rows. Do NOT truncate, delete, or re-seed them.
    // Snapshot all pre-existing rows, including timestamps and ownership,
    // without printing any row values. This must match exactly on cleanup.
    $socialBaselineRows = $db->table('site_social_links')
        ->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    $expectedSeeded = [
        ['platform'=>'website', 'label'=>'Author website',
         'url'=>'https://tonsamith.com', 'sort_order'=>10],
        ['platform'=>'github', 'label'=>'Bambodia GitHub repository',
         'url'=>'https://github.com/bambodia168-sys/bambodialaravel',
         'sort_order'=>20],
    ];
    $seedMatches = 0;
    foreach ($expectedSeeded as $seed) {
        foreach ($socialBaselineRows as $row) {
            if (($row['platform'] ?? null) === $seed['platform']
                && ($row['label'] ?? null) === $seed['label']
                && ($row['url'] ?? null) === $seed['url']
                && (int) ($row['sort_order'] ?? -1) === $seed['sort_order']
                && (int) ($row['is_active'] ?? 0) === 1) {
                $seedMatches++;
                break;
            }
        }
    }
    qa($seedMatches === 2 && count($socialBaselineRows) >= 2,
       'SEEDED_SOCIAL_LINKS_BASELINE_PRESERVED');
    // The other entity tables must be empty before this isolated QA test.
    foreach ([
        'creator_profiles','site_seo_pages',
        'marketplace_listings','content_entries',
    ] as $table) {
        qa($db->table($table)->count() === 0,
           strtoupper($table).'_EMPTY_BASELINE');
    }

    $stage = 'TEMPORARY_QA_ADMIN';
    $nonce = bin2hex(random_bytes(8));
    $actorEmail = 'step32-'.$nonce.'@oauth-browser-qa.invalid';
    $seoPath = '/step32-'.$nonce;
    $socialUrl = 'https://example.invalid/step32/'.$nonce;
    $actor = \App\Models\User::query()->create([
        'name'=>'Step32 Temporary Profile and Site Admin',
        'email'=>$actorEmail,
        'password'=>bin2hex(random_bytes(32)),
        'role'=>'super_admin',
        'account_status'=>'active',
    ]);
    $actorId = (int)$actor->getKey();
    $originalId = (int)$original->getKey();
    $originalPasswordHash = (string)$original->getRawOriginal('password');
    $actorPasswordHash = (string)$actor->getRawOriginal('password');
    qa($actorId > 0 && $actorId !== $originalId
       && $db->table('users')->count() === 2,
       'TEMPORARY_QA_ADMIN_CREATED');

    $stage = 'OAUTH_AUTHORIZE';
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(
        hash('sha256',$verifier,true)),'+/','-_'),'=');
    $state = bin2hex(random_bytes(24));
    $query = http_build_query([
        'client_id'=>$clientId,'redirect_uri'=>$callback,
        'response_type'=>'code','scope'=>'mcp:use mcp.write',
        'state'=>$state,'code_challenge'=>$challenge,
        'code_challenge_method'=>'S256',
        'resource'=>$base.'/mcp','prompt'=>'consent',
    ]);
    [$request,$consent] = reqQa(
        $app,$kernel,'GET',$base.'/oauth/authorize?'.$query,actor:$actor
    );
    qa($consent->getStatusCode() === 200,'CONSENT_HTTP_200');
    $csrf = $request->session()->token();
    $authToken = $request->session()->get('authToken');
    $cookie = null;
    foreach ($consent->headers->getCookies() as $item) {
        if ($item->getName() === config('session.cookie')) {
            qa($item->isSecure(),'SECURE_SESSION_COOKIE');
            $cookie = $item->getValue();
            break;
        }
    }
    qa(is_string($csrf) && strlen($csrf) >= 20
        && is_string($authToken) && $authToken !== ''
        && is_string($cookie) && $cookie !== '',
        'CONSENT_CSRF_AND_COOKIE');
    [, $approval] = reqQa($app,$kernel,'POST',
        route('passport.authorizations.approve'), [
            '_token'=>$csrf,'client_id'=>$clientId,
            'state'=>$state,'auth_token'=>$authToken,
        ],[config('session.cookie')=>$cookie],$actor);
    $redirect = $approval->headers->get('Location','');
    parse_str(parse_url($redirect,PHP_URL_QUERY) ?: '',$parameters);
    $code = $parameters['code'] ?? null;
    qa(in_array($approval->getStatusCode(),[302,303],true)
        && str_starts_with($redirect,$callback.'?')
        && ($parameters['state'] ?? null) === $state
        && is_string($code) && $code !== '',
        'CONSENT_CODE_AND_CALLBACK');

    $stage = 'OAUTH_TOKEN_EXCHANGE';
    [, $tokenResponse] = reqQa($app,$kernel,'POST',$base.'/oauth/token',[
        'grant_type'=>'authorization_code','client_id'=>$clientId,
        'code'=>$code,'redirect_uri'=>$callback,
        'code_verifier'=>$verifier,'resource'=>$base.'/mcp',
    ]);
    $issued = json_decode($tokenResponse->getContent(),true,512,JSON_THROW_ON_ERROR);
    $bearer = $issued['access_token'] ?? null;
    qa($tokenResponse->getStatusCode() === 200
       && is_string($bearer) && $bearer !== '',
       'WRITE_TOKEN_EXCHANGE_HTTP_200');
    $jwt = claims($bearer);
    qa(($jwt['iss'] ?? null) === $base
       && ($jwt['aud'][0] ?? null) === $clientId
       && ($jwt['aud'][1] ?? null) === $base.'/mcp'
       && in_array('mcp:use',$jwt['scopes'] ?? [],true)
       && in_array('mcp.write',$jwt['scopes'] ?? [],true)
       && $db->table('oauth_access_tokens')
         ->where('client_id',$clientId)->where('user_id',$actorId)
         ->where('revoked',false)->exists(),
       'TEMP_ADMIN_SIGNED_WRITE_BEARER');

    $stage = 'PROFILE_CREATE';
    $headline = 'Step32 private QA creator profile';
    $bio = str_repeat('This private synthetic QA profile validates allowlisted creator profile fields. ',2);
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_update_own_profile',[
            'fields'=>[
                'headline'=>$headline,
                'biography'=>$bio,
                'website_url'=>null,
                'is_public'=>false,
            ],
        ],2);
    qa(approved($status,$response)
       && (int)($response['result']['structuredContent']['user_id'] ?? 0) === $actorId
       && ($response['result']['structuredContent']['is_public'] ?? null) === false
       && $db->table('creator_profiles')
          ->where('user_id',$actorId)->where('headline',$headline)
          ->where('is_public',false)->exists(),
       'AUTHORIZED_PRIVATE_PROFILE_CREATED');

    $stage = 'PROFILE_UPDATE';
    $updatedHeadline = 'Step32 updated private QA creator profile';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_update_own_profile',[
            'fields'=>['headline'=>$updatedHeadline],
        ],3);
    qa(approved($status,$response)
       && $db->table('creator_profiles')
          ->where('user_id',$actorId)->where('headline',$updatedHeadline)
          ->where('is_public',false)->exists(),
       'AUTHORIZED_OWN_PROFILE_UPDATED');

    $stage = 'PROFILE_FORBIDDEN_FIELDS';
    foreach ([
        'email'=>'forbidden@oauth-browser-qa.invalid',
        'role'=>'customer',
        'password'=>'not-a-valid-permitted-field',
        'user_id'=>$originalId,
        'account_status'=>'suspended',
    ] as $field=>$value) {
        [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
            'bambodia_update_own_profile',[
                'fields'=>[$field=>$value],
            ],4);
        qa(denied($status,$response)
            && $db->table('creator_profiles')
               ->where('user_id',$actorId)->where('headline',$updatedHeadline)
               ->where('is_public',false)->count() === 1
            && $db->table('creator_profiles')
               ->where('user_id',$originalId)->count() === 0
            && $db->table('users')
               ->where('id',$actorId)->where('role','super_admin')
               ->where('account_status','active')
               ->where('email',$actorEmail)
               ->where('password',$actorPasswordHash)->exists()
            && $db->table('users')
               ->where('id',$originalId)
               ->where('password',$originalPasswordHash)->exists(),
           'PROFILE_FORBIDDEN_'.strtoupper($field).'_REJECTED');
    }

    $stage = 'SEO_CREATE';
    $seoTitle = 'Step32 isolated SEO entry';
    $seoDescription = 'Step32 synthetic inactive SEO entry used only for isolated OAuth QA.';
    $seoFields = [
        'path'=>$seoPath,'locale'=>'en','title'=>$seoTitle,
        'description'=>$seoDescription,
        'keywords'=>null,'canonical_url'=>null,
        'og_image_url'=>null,'is_indexable'=>false,'is_active'=>false,
    ];
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'create','fields'=>$seoFields,
        ],5);
    $seoId = (int)($response['result']['structuredContent']['id'] ?? 0);
    qa(approved($status,$response) && $seoId > 0
        && $db->table('site_seo_pages')->where('id',$seoId)
          ->where('path',$seoPath)->where('locale','en')
          ->where('is_active',false)->where('is_indexable',false)
          ->where('updated_by',$actorId)->exists(),
        'AUTHORIZED_INACTIVE_SEO_CREATED');

    $stage = 'SEO_UPDATE';
    $seoUpdatedTitle = 'Step32 revised isolated SEO entry';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'update','id'=>$seoId,
            'fields'=>['title'=>$seoUpdatedTitle],
        ],6);
    qa(approved($status,$response)
        && $db->table('site_seo_pages')->where('id',$seoId)
           ->where('path',$seoPath)->where('title',$seoUpdatedTitle)
           ->where('is_active',false)->exists(),
       'AUTHORIZED_INACTIVE_SEO_UPDATED');

    $stage = 'SEO_PUBLIC_CHANGE_GATES';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'update','id'=>$seoId,
            'fields'=>['is_active'=>true,'is_indexable'=>true],
        ],7);
    qa(denied($status,$response)
       && $db->table('site_seo_pages')->where('id',$seoId)
          ->where('is_active',false)->where('is_indexable',false)->exists(),
       'SEO_ACTIVATION_WITHOUT_CONFIRMATION_DENIED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'update','id'=>$seoId,
            'fields'=>['updated_by'=>$originalId],
        ],8);
    qa(denied($status,$response)
       && $db->table('site_seo_pages')->where('id',$seoId)
          ->where('updated_by',$actorId)->exists(),
       'SEO_OWNER_OVERRIDE_REJECTED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'delete','id'=>$seoId,
            'confirm_title'=>'Incorrect QA title',
        ],9);
    qa(denied($status,$response)
        && $db->table('site_seo_pages')->where('id',$seoId)->exists(),
        'SEO_WRONG_DELETE_TITLE_DENIED');

    // Verify explicitly confirmed active changes *only* on a synthetic,
    // nonroutable QA path in an isolated database. Immediately delete it.
    $stage = 'SEO_CONFIRMED_ACTIVE_CHANGE';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'update','id'=>$seoId,
            'fields'=>['is_active'=>true],
            'confirm_live_change'=>true,
        ],10);
    qa(approved($status,$response)
        && $db->table('site_seo_pages')->where('id',$seoId)
          ->where('is_active',true)->where('is_indexable',false)->exists(),
        'SEO_CONFIRMED_QA_ACTIVATION_ALLOWED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'delete','id'=>$seoId,
            'confirm_title'=>$seoUpdatedTitle,
        ],11);
    qa(denied($status,$response)
        && $db->table('site_seo_pages')->where('id',$seoId)->exists(),
       'ACTIVE_SEO_DELETE_WITHOUT_CONFIRMATION_DENIED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'seo','action'=>'delete','id'=>$seoId,
            'confirm_title'=>$seoUpdatedTitle,
            'confirm_live_change'=>true,
        ],12);
    qa(approved($status,$response)
        && ($response['result']['structuredContent']['deleted'] ?? null) === true
        && !$db->table('site_seo_pages')->where('id',$seoId)->exists(),
       'CONFIRMED_QA_SEO_DELETED');

    $stage = 'SOCIAL_CREATE';
    $socialLabel = 'Step32 OAuth social QA';
    $socialFields = [
        'platform'=>'github','label'=>$socialLabel,
        'url'=>$socialUrl,'sort_order'=>977,
        'is_active'=>false,
    ];
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'create','fields'=>$socialFields,
        ],13);
    $socialId = (int)($response['result']['structuredContent']['id'] ?? 0);
    qa(approved($status,$response) && $socialId > 0
       && $db->table('site_social_links')->where('id',$socialId)
          ->where('url',$socialUrl)->where('is_active',false)
          ->where('updated_by',$actorId)->exists(),
       'AUTHORIZED_INACTIVE_SOCIAL_CREATED');

    $stage = 'SOCIAL_UPDATE';
    $socialUpdatedLabel = 'Step32 revised OAuth social QA';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'update','id'=>$socialId,
            'fields'=>['label'=>$socialUpdatedLabel],
        ],14);
    qa(approved($status,$response)
        && $db->table('site_social_links')->where('id',$socialId)
           ->where('url',$socialUrl)->where('label',$socialUpdatedLabel)
           ->where('is_active',false)->exists(),
        'AUTHORIZED_INACTIVE_SOCIAL_UPDATED');

    $stage = 'SOCIAL_PUBLIC_CHANGE_GATES';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'update','id'=>$socialId,
            'fields'=>['is_active'=>true],
        ],15);
    qa(denied($status,$response)
       && $db->table('site_social_links')->where('id',$socialId)
          ->where('is_active',false)->exists(),
       'SOCIAL_ACTIVATION_WITHOUT_CONFIRMATION_DENIED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'update','id'=>$socialId,
            'fields'=>['updated_by'=>$originalId],
        ],16);
    qa(denied($status,$response)
       && $db->table('site_social_links')->where('id',$socialId)
          ->where('updated_by',$actorId)->exists(),
       'SOCIAL_OWNER_OVERRIDE_REJECTED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'delete','id'=>$socialId,
            'confirm_title'=>'Wrong social label',
        ],17);
    qa(denied($status,$response)
       && $db->table('site_social_links')->where('id',$socialId)->exists(),
       'SOCIAL_WRONG_DELETE_LABEL_DENIED');

    $stage = 'SOCIAL_CONFIRMED_ACTIVE_CHANGE';
    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'update','id'=>$socialId,
            'fields'=>['is_active'=>true],
            'confirm_live_change'=>true,
        ],18);
    qa(approved($status,$response)
       && $db->table('site_social_links')->where('id',$socialId)
          ->where('is_active',true)->exists(),
       'SOCIAL_CONFIRMED_QA_ACTIVATION_ALLOWED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'delete','id'=>$socialId,
            'confirm_title'=>$socialUpdatedLabel,
        ],19);
    qa(denied($status,$response)
       && $db->table('site_social_links')->where('id',$socialId)->exists(),
       'ACTIVE_SOCIAL_DELETE_WITHOUT_CONFIRMATION_DENIED');

    [$status,$response] = mcpWrite($app,$kernel,$base,$bearer,
        'bambodia_manage_site',[
            'entity'=>'social','action'=>'delete','id'=>$socialId,
            'confirm_title'=>$socialUpdatedLabel,
            'confirm_live_change'=>true,
        ],20);
    qa(approved($status,$response)
       && ($response['result']['structuredContent']['deleted'] ?? null) === true
       && !$db->table('site_social_links')->where('id',$socialId)->exists(),
       'CONFIRMED_QA_SOCIAL_DELETED');

    $stage = 'FINAL_IDENTITY_PROTECTION';
    qa($db->table('users')
          ->where('id',$actorId)->where('email',$actorEmail)
          ->where('role','super_admin')->where('account_status','active')
          ->where('password',$actorPasswordHash)->exists()
       && $db->table('users')->where('id',$originalId)
          ->where('email','oauth-admin@oauth-browser-qa.invalid')
          ->where('role','super_admin')->where('account_status','active')
          ->where('password',$originalPasswordHash)->exists()
       && $db->table('creator_profiles')
          ->where('user_id',$originalId)->count() === 0,
       'QA_ORIGINAL_AND_ACTOR_CREDENTIALS_UNCHANGED');

    $stage = 'REVOKE_OAUTH';
    $ids = $db->table('oauth_access_tokens')
        ->where('client_id',$clientId)->pluck('id')->all();
    qa(count($ids) > 0,'STEP32_TEST_GRANT_RECORDED');
    $db->table('oauth_refresh_tokens')->whereIn('access_token_id',$ids)
       ->update(['revoked'=>true]);
    $db->table('oauth_access_tokens')->where('client_id',$clientId)
       ->update(['revoked'=>true]);
    $db->table('oauth_auth_codes')->where('client_id',$clientId)
       ->update(['revoked'=>true]);
    [, $revoked] = reqQa($app,$kernel,'POST',$base.'/mcp',[
        'jsonrpc'=>'2.0','id'=>21,'method'=>'tools/list',
        'params'=>new stdClass(),
    ],bearer:$bearer,json:true);
    qa($revoked->getStatusCode() === 401,'REVOKED_WRITE_BEARER_HTTP_401');
    $passed = true;

} catch (\Throwable $error) {
    fwrite(STDERR,'STEP32_FAILURE_STAGE='.$stage."\n".
                  'ERROR_CLASS='.get_class($error)."\n");
} finally {
    if ($db !== null) {
        try {
            // Revoke grants before deleting the synthetic account, even if
            // an assertion above failed. Never act on another OAuth client.
            if ($clientId !== null) {
                $ids = $db->table('oauth_access_tokens')
                    ->where('client_id',$clientId)->pluck('id')->all();
                if ($ids !== []) {
                    $db->table('oauth_refresh_tokens')
                        ->whereIn('access_token_id',$ids)
                        ->update(['revoked'=>true]);
                }
                $db->table('oauth_access_tokens')
                   ->where('client_id',$clientId)
                   ->update(['revoked'=>true]);
                $db->table('oauth_auth_codes')
                   ->where('client_id',$clientId)
                   ->update(['revoked'=>true]);
            }
            // Only this run's unique, isolated synthetic identifiers.
            if ($nonce !== null) {
                $db->table('site_seo_pages')
                   ->where('path',$seoPath)
                   ->where('locale','en')->where('updated_by',$actorId)
                   ->delete();
                $db->table('site_social_links')
                   ->where('url',$socialUrl)->where('updated_by',$actorId)
                   ->delete();
            }
            if ($actorId !== null && $actorEmail !== null) {
                $db->table('creator_profiles')
                   ->where('user_id',$actorId)->delete();
                $db->table('users')->where('id',$actorId)
                   ->where('email',$actorEmail)
                   ->where('name','Step32 Temporary Profile and Site Admin')
                   ->delete();
            }

            $socialAfter = $db->table('site_social_links')
                ->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
            $socialPreserved = is_array($socialBaselineRows)
                && $socialAfter === $socialBaselineRows;
            echo 'ORIGINAL_QA_SOCIAL_LINKS_UNCHANGED='.
                ($socialPreserved ? 'PASS' : 'FAIL')."\n";
            $active = 0;
            foreach ([
                'oauth_auth_codes','oauth_access_tokens',
                'oauth_refresh_tokens',
            ] as $table) {
                $active += $db->table($table)
                    ->where('revoked',false)->count();
            }
            $clean = $socialPreserved && $active === 0
               && $db->table('users')->count() === 1
               && $db->table('oauth_clients')->count() === 1
               && $db->table('creator_profiles')->count() === 0
               && $db->table('site_seo_pages')->count() === 0
               && $socialPreserved
               && $db->table('marketplace_listings')->count() === 0
               && $db->table('content_entries')->count() === 0
               && $db->table('users')
                  ->where('email','oauth-admin@oauth-browser-qa.invalid')
                  ->where('role','super_admin')
                  ->where('account_status','active')->count() === 1;
            echo 'ALL_QA_GRANTS_REVOKED='.
                ($active === 0 ? 'PASS':'FAIL')."\n";
            echo 'STEP32_FIXTURES_CLEAN='.
                ($clean ? 'PASS':'FAIL')."\n";
        } catch (\Throwable $error) {
            fwrite(STDERR,"STEP32_CLEANUP=MANUAL_REVIEW_REQUIRED\n");
        }
    }
}
if (!$passed || !$clean) {
    echo "STEP32_PHP=FAIL\n";
    exit(32);
}
echo "STEP32_PHP=PASS\n";
exit(0);
PHP
)"; then
  printf '%s\n' "$REPORT"
else
  printf '%s\n' "$REPORT"
  echo 'STEP32_RESULT=BLOCKED; verify QA grants before any retry.'
  exit 1
fi

grep -Fxq 'STEP32_PHP=PASS' <<< "$REPORT"
grep -Fxq 'ALL_QA_GRANTS_REVOKED=PASS' <<< "$REPORT"
grep -Fxq 'STEP32_FIXTURES_CLEAN=PASS' <<< "$REPORT"
grep -Fxq 'ORIGINAL_QA_SOCIAL_LINKS_UNCHANGED=PASS' <<< "$REPORT"
! grep -Fq '=FAIL' <<< "$REPORT"

echo '========== VERIFY POST-TEST DATABASE =========='
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM marketplace_listings')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM content_entries')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM creator_profiles')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM site_seo_pages')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM site_social_links')" -ge 2
test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM site_social_links WHERE platform='website' AND label='Author website' AND url='https://tonsamith.com' AND is_active=1")" = 1
test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM site_social_links WHERE platform='github' AND label='Bambodia GitHub repository' AND url='https://github.com/bambodia168-sys/bambodialaravel' AND is_active=1")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
 test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
printf '%s\n' \
 'STEP32B_PROFILE_SEO_SOCIAL_BOUNDARIES=PASS' \
 'QA_USERS_AND_CLIENT=UNCHANGED' \
 'ACTIVE_OAUTH_GRANTS=0' \
 'HTTPS_VHOST=NOT_CREATED' \
 'PRODUCTION_DEPLOYMENT=NONE'
