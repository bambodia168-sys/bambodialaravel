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
printf '%s\n' 'This test will issue a QA read-and-write OAuth token,' \
 'test owned/foreign draft permissions, clean fixtures, revoke grants.'
printf 'Type TEST-QA-WRITE-OWNERSHIP to continue: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
if [ "$CONFIRM" != 'TEST-QA-WRITE-OWNERSHIP' ]; then
  echo 'STEP29_CONFIRMATION=DECLINED'
  exit 1
fi
echo 'STEP29_CONFIRMATION=PASS'

printf '%s\n' '========== TEST POSITIVE OAUTH WRITE AND OWNERSHIP =========='
if REPORT="$(
 cd "$APP"
 runuser -u www -- "$PHP" <<'PHP'
<?php
$db = null;
$clientId = null;
$stage = 'BOOTSTRAP';
$passed = false;
$clean = false;
$nonce = null;
$adminId = null;
$foreignId = null;
$foreignEmail = null;
$foreignListingId = null;
$ownListingId = null;
$ownTitle = null;
$updatedTitle = null;
$foreignTitle = null;

function qa(bool $ok, string $name): void {
    echo $name.'='.($ok ? 'PASS' : 'FAIL')."\n";
    if (!$ok) { throw new RuntimeException($name); }
}
function reqQa($app, $kernel, string $method, string $uri,
               array $data = [], array $cookies = [],
               ?\App\Models\User $actor = null,
               ?string $bearer = null, bool $json = false): array {
    $server = [
        'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html',
        'CONTENT_TYPE' => $json ? 'application/json' : 'application/x-www-form-urlencoded',
        'HTTPS' => 'on',
    ];
    if ($bearer !== null) { $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer; }
    $request = \Illuminate\Http\Request::create(
        $uri, $method, $json ? [] : $data, $cookies, [], $server,
        $json ? json_encode($data, JSON_THROW_ON_ERROR) : null
    );
    $app->instance('request', $request);
    \Illuminate\Support\Facades\Auth::forgetGuards();
    if ($actor !== null) {
        \Illuminate\Support\Facades\Auth::guard('web')->setUser($actor);
    }
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return [$request, $response];
}
function rpc(): array {
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            'params' => new stdClass()];
}
function bearerClaims(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) { throw new RuntimeException('Invalid JWT format'); }
    return json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true),
                       true, 512, JSON_THROW_ON_ERROR);
}
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    $base = 'https://oauth-browser-qa.invalid';
    $callback = 'https://chatgpt.com/connector/oauth/qa-callback';
    qa(config('app.env') === 'staging' && config('app.debug') === false
       && config('app.url') === $base
       && config('database.default') === 'mysql'
       && config('mcp.redirect_domains') === ['https://chatgpt.com']
       && \Illuminate\Support\Facades\DB::connection()->getDatabaseName()
          === 'bambodia_oauthqa_browser', 'QA_RUNTIME_ISOLATION');
    $db = \Illuminate\Support\Facades\DB::connection();
    $admin = \App\Models\User::query()
        ->where('email', 'oauth-admin@oauth-browser-qa.invalid')->firstOrFail();
    $client = \Laravel\Passport\Passport::client()->newQuery()
        ->where('name', 'Bambodia Browser QA PKCE Client')->firstOrFail();
    $clientId = (string) $client->getKey();
    $adminId = (int) $admin->getKey();
    $urls = $client->getAttribute('redirect_uris');
    if (is_string($urls)) { $urls = json_decode($urls, true); }
    qa($db->table('users')->count() === 1 && $admin->role === 'super_admin'
       && $admin->account_status === 'active'
       && $db->table('oauth_clients')->count() === 1
       && is_array($urls) && in_array($callback, $urls, true),
       'EXISTING_QA_IDENTITY_AND_CLIENT');
    foreach (['oauth_auth_codes','oauth_access_tokens','oauth_refresh_tokens'] as $t) {
        qa($db->table($t)->where('revoked', false)->count() === 0,
           strtoupper($t).'_INACTIVE_BASELINE');
    }

    $stage = 'AUTHORIZE';
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $state = bin2hex(random_bytes(24));
    $query = http_build_query([
        'client_id' => $clientId, 'redirect_uri' => $callback,
        'response_type' => 'code', 'scope' => 'mcp:use mcp.write', 'state' => $state,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        'resource' => $base.'/mcp', 'prompt' => 'consent',
    ]);
    [$request, $consent] = reqQa($app, $kernel, 'GET',
                                $base.'/oauth/authorize?'.$query, actor: $admin);
    qa($consent->getStatusCode() === 200, 'CONSENT_HTTP_200');
    $csrf = $request->session()->token();
    $authToken = $request->session()->get('authToken');
    $cookie = null;
    foreach ($consent->headers->getCookies() as $item) {
        if ($item->getName() === config('session.cookie')) {
            qa($item->isSecure(), 'SECURE_SESSION_COOKIE');
            $cookie = $item->getValue();
            break;
        }
    }
    qa(is_string($csrf) && strlen($csrf) >= 20
       && is_string($authToken) && $authToken !== ''
       && is_string($cookie) && $cookie !== '', 'CONSENT_STATE_AND_CSRF');
    [, $approve] = reqQa($app, $kernel, 'POST',
        route('passport.authorizations.approve'), [
          '_token' => $csrf, 'client_id' => $clientId,
          'state' => $state, 'auth_token' => $authToken,
        ], [config('session.cookie') => $cookie], $admin);
    $location = $approve->headers->get('Location', '');
    parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $parameters);
    $code = $parameters['code'] ?? null;
    qa(in_array($approve->getStatusCode(), [302,303], true)
       && str_starts_with($location, $callback.'?')
       && ($parameters['state'] ?? null) === $state
       && is_string($code) && $code !== '', 'CONSENT_CODE_AND_CALLBACK');

    $stage = 'TOKEN_EXCHANGE';
    [, $first] = reqQa($app, $kernel, 'POST', $base.'/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId,
        'code' => $code, 'redirect_uri' => $callback,
        'code_verifier' => $verifier, 'resource' => $base.'/mcp',
    ]);
    qa($first->getStatusCode() === 200, 'INITIAL_PKCE_EXCHANGE');
    $initial = json_decode($first->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $firstAccess = $initial['access_token'] ?? null;
    $firstRefresh = $initial['refresh_token'] ?? null;
    qa(is_string($firstAccess) && $firstAccess !== ''
       && is_string($firstRefresh) && $firstRefresh !== '', 'FIRST_TOKEN_PAIR');

    $stage = 'WRITE_OWNERSHIP_BOUNDARY';
    $claims = bearerClaims($firstAccess);
    qa(($claims['iss'] ?? null) === $base
       && ($claims['aud'][0] ?? null) === $clientId
       && ($claims['aud'][1] ?? null) === $base.'/mcp'
       && in_array('mcp:use', $claims['scopes'] ?? [], true)
       && in_array('mcp.write', $claims['scopes'] ?? [], true),
       'SIGNED_JWT_WRITE_SCOPE_AND_RESOURCE');

    [, $list] = reqQa($app,$kernel,'POST',$base.'/mcp',rpc(),
                      bearer:$firstAccess,json:true);
    $json = json_decode($list->getContent(), true);
    $names = array_column($json['result']['tools'] ?? [], 'name');
    sort($names);
    $expected = [
        'bambodia_analytics','bambodia_create_draft','bambodia_delete_draft',
        'bambodia_draft_media','bambodia_get','bambodia_list',
        'bambodia_manage_site','bambodia_overview','bambodia_update_draft',
        'bambodia_update_own_profile',
    ];
    qa($list->getStatusCode() === 200 && $names === $expected,
       'WRITE_BEARER_EXPOSES_EXACT_TEN_TOOLS');
    foreach ($json['result']['tools'] as $tool) {
        if (!in_array($tool['name'], [
            'bambodia_create_draft','bambodia_update_draft',
            'bambodia_delete_draft','bambodia_draft_media',
            'bambodia_manage_site','bambodia_update_own_profile'
        ], true)) continue;
        qa(($tool['_meta']['securitySchemes'][0]['scopes'] ?? [])
            === ['mcp:use','mcp.write'],
            strtoupper($tool['name']).'_WRITE_SCOPE_ADVERTISED');
    }
    qa($db->table('users')->count() === 1
       && $db->table('marketplace_listings')->count() === 0,
       'QA_USERS_AND_LISTINGS_BASELINE');

    // A disposable second super-admin is needed to verify cross-owner access.
    // Its password is randomly generated and never displayed or persisted in shell.
    $foreignEmail = 'step29-'.bin2hex(random_bytes(8))
                  .'@oauth-browser-qa.invalid';
    $foreign = \App\Models\User::query()->create([
        'name'=>'Step29 Temporary Foreign Admin',
        'email'=>$foreignEmail,
        'password'=>bin2hex(random_bytes(32)),
        'role'=>'super_admin','account_status'=>'active',
    ]);
    $foreignId = (int)$foreign->getKey();
    qa($foreignId > 0 && $foreignId !== $adminId
       && $db->table('users')->count() === 2,
       'FOREIGN_QA_OWNER_CREATED');

    $nonce = bin2hex(random_bytes(6));
    $ownTitle = 'Step29 OAuth QA '.$nonce.' own';
    $updatedTitle = 'Step29 OAuth QA '.$nonce.' updated';
    $foreignTitle = 'Step29 OAuth QA '.$nonce.' foreign';
    $foreignDraft = \App\Models\MarketplaceListing::query()->create([
        'user_id'=>$foreignId,'type'=>'service','title'=>$foreignTitle,
        'description'=>str_repeat('Synthetic foreign QA service. ', 3),
        'currency'=>'USD','status'=>'draft',
    ]);
    $foreignListingId = (int)$foreignDraft->getKey();
    qa($foreignListingId > 0
       && (int)$foreignDraft->user_id === $foreignId,
       'FOREIGN_QA_DRAFT_CREATED');

    function mcpWrite($app,$kernel,string $base,string $token,
                      string $name,array $args,int $id): array {
        $rpc = [
            'jsonrpc'=>'2.0','id'=>$id,'method'=>'tools/call',
            'params'=>['name'=>$name,'arguments'=>$args],
        ];
        [, $response] = reqQa(
            $app,$kernel,'POST',$base.'/mcp',$rpc,
            bearer:$token,json:true
        );
        return [$response->getStatusCode(),
                json_decode($response->getContent(),true)];
    }

    $stage = 'CREATE_OWN_DRAFT';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_create_draft',[
            'entity'=>'service','fields'=>[
                'title'=>$ownTitle,
                'description'=>str_repeat('Owned synthetic QA service. ',3),
            ],
        ],2);
    $created = $r['result']['structuredContent'] ?? [];
    $ownListingId = (int)($created['id'] ?? 0);
    qa($status === 200 && ($r['result']['isError'] ?? null) === false
       && $ownListingId > 0
       && (int)($created['user_id'] ?? 0) === $adminId
       && ($created['status'] ?? null) === 'draft',
       'AUTHORIZED_OWN_DRAFT_CREATED');
    qa(\App\Models\MarketplaceListing::query()
       ->whereKey($ownListingId)->where('user_id',$adminId)
       ->where('title',$ownTitle)->where('status','draft')->exists(),
       'OWN_DRAFT_DATABASE_VERIFIED');

    $stage = 'CROSS_OWNER_UPDATE';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_update_draft',[
            'entity'=>'service','id'=>$foreignListingId,
            'fields'=>['title'=>'Unauthorized cross owner change'],
        ],3);
    qa($status === 200 && ($r['result']['isError'] ?? null) === true
       && \App\Models\MarketplaceListing::query()
           ->whereKey($foreignListingId)->where('title',$foreignTitle)
           ->where('user_id',$foreignId)->exists(),
       'CROSS_OWNER_UPDATE_DENIED');

    $stage = 'CROSS_OWNER_DELETE';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_delete_draft',[
            'entity'=>'service','id'=>$foreignListingId,
            'confirm_title'=>$foreignTitle,
        ],4);
    qa($status === 200 && ($r['result']['isError'] ?? null) === true
       && \App\Models\MarketplaceListing::query()
           ->whereKey($foreignListingId)->exists(),
       'CROSS_OWNER_DELETE_DENIED');

    $stage = 'OWN_UPDATE';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_update_draft',[
            'entity'=>'service','id'=>$ownListingId,
            'fields'=>['title'=>$updatedTitle],
        ],5);
    qa($status === 200 && ($r['result']['isError'] ?? null) === false
       && \App\Models\MarketplaceListing::query()
           ->whereKey($ownListingId)->where('user_id',$adminId)
           ->where('title',$updatedTitle)->where('status','draft')->exists(),
       'AUTHORIZED_OWN_DRAFT_UPDATED');

    $stage = 'BLOCK_PUBLICATION';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_update_draft',[
            'entity'=>'service','id'=>$ownListingId,
            'fields'=>['status'=>'published'],
        ],6);
    qa($status === 200 && ($r['result']['isError'] ?? null) === true
       && \App\Models\MarketplaceListing::query()
           ->whereKey($ownListingId)->where('status','draft')->exists(),
       'MCP_PUBLICATION_BYPASS_DENIED');

    $stage = 'BLOCK_WRONG_DELETE_TITLE';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_delete_draft',[
            'entity'=>'service','id'=>$ownListingId,
            'confirm_title'=>$ownTitle,
        ],7);
    qa($status === 200 && ($r['result']['isError'] ?? null) === true
       && \App\Models\MarketplaceListing::query()
           ->whereKey($ownListingId)->exists(),
       'WRONG_DELETE_TITLE_DENIED');

    $stage = 'OWN_DELETE';
    [$status,$r] = mcpWrite($app,$kernel,$base,$firstAccess,
        'bambodia_delete_draft',[
            'entity'=>'service','id'=>$ownListingId,
            'confirm_title'=>$updatedTitle,
        ],8);
    qa($status === 200
       && ($r['result']['structuredContent']['deleted'] ?? null) === true
       && !\App\Models\MarketplaceListing::query()
           ->whereKey($ownListingId)->exists(),
       'AUTHORIZED_OWN_DRAFT_DELETED');
    qa(\App\Models\MarketplaceListing::query()
       ->whereKey($foreignListingId)->where('title',$foreignTitle)->exists(),
       'FOREIGN_DRAFT_UNCHANGED');

    $stage = 'REVOKE_WRITE_GRANT';
    $tokenIds = $db->table('oauth_access_tokens')
        ->where('client_id',$clientId)->pluck('id')->all();
    qa(count($tokenIds) >= 1, 'TEST_WRITE_GRANT_RECORDED');
    $db->table('oauth_refresh_tokens')
       ->whereIn('access_token_id',$tokenIds)->update(['revoked'=>true]);
    $db->table('oauth_access_tokens')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    $db->table('oauth_auth_codes')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    [, $revoked] = reqQa($app,$kernel,'POST',$base.'/mcp',rpc(),
                         bearer:$firstAccess,json:true);
    qa($revoked->getStatusCode() === 401,'REVOKED_WRITE_BEARER_401');
    $passed = true;
} catch (\Throwable $e) {
    fwrite(STDERR, 'STEP29_FAILURE_STAGE='.$stage."\n".
                   'ERROR_CLASS='.get_class($e)."\n");
} finally {
    // Leave any unexpectedly published record for manual investigation.
    // Remove only synthetic records whose owner/title match this QA run.
    if ($db !== null) {
        try {
            if ($clientId !== null) {
                $ids = $db->table('oauth_access_tokens')
                    ->where('client_id',$clientId)->pluck('id')->all();
                if ($ids !== []) {
                    $db->table('oauth_refresh_tokens')
                       ->whereIn('access_token_id',$ids)
                       ->update(['revoked'=>true]);
                }
                $db->table('oauth_access_tokens')
                   ->where('client_id',$clientId)->update(['revoked'=>true]);
                $db->table('oauth_auth_codes')
                   ->where('client_id',$clientId)->update(['revoked'=>true]);
            }
            if ($nonce !== null && $adminId !== null) {
                $db->table('marketplace_listings')
                   ->where('user_id',$adminId)->where('type','service')
                   ->where('status','draft')
                   ->whereIn('title',[$ownTitle,$updatedTitle])->delete();
            }
            if ($foreignId !== null && $foreignTitle !== null
                && $foreignListingId !== null) {
                $db->table('marketplace_listings')
                   ->where('id',$foreignListingId)
                   ->where('user_id',$foreignId)->where('type','service')
                   ->where('status','draft')->where('title',$foreignTitle)
                   ->delete();
            }
            if ($foreignId !== null && $foreignEmail !== null
                && !$db->table('marketplace_listings')
                    ->where('user_id',$foreignId)->exists()) {
                $db->table('users')->where('id',$foreignId)
                   ->where('email',$foreignEmail)
                   ->where('name','Step29 Temporary Foreign Admin')
                   ->delete();
            }
            $active = 0;
            foreach (['oauth_auth_codes','oauth_access_tokens',
                      'oauth_refresh_tokens'] as $table) {
                $active += $db->table($table)
                    ->where('revoked',false)->count();
            }
            $clean = $active === 0
                && $db->table('users')->count() === 1
                && $db->table('marketplace_listings')->count() === 0
                && $db->table('oauth_clients')->count() === 1;
            echo 'ALL_QA_GRANTS_REVOKED='.($active === 0 ? 'PASS' : 'FAIL')."\n";
            echo 'STEP29_FIXTURES_CLEAN='.($clean ? 'PASS' : 'FAIL')."\n";
        } catch (\Throwable $error) {
            fwrite(STDERR,"STEP29_CLEANUP=MANUAL_REVIEW_REQUIRED\n");
        }
    }
}

if (!$passed || !$clean) {
    echo "STEP29_PHP=FAIL\n";
    exit(27);
}
echo "STEP29_PHP=PASS\n";
exit(0);
PHP
)"; then
  printf '%s\n' "$REPORT"
else
  printf '%s\n' "$REPORT"
  echo 'STEP29_RESULT=BLOCKED; verify QA grants before any retry.'
  exit 1
fi

grep -Fxq 'STEP29_PHP=PASS' <<< "$REPORT"
grep -Fxq 'ALL_QA_GRANTS_REVOKED=PASS' <<< "$REPORT"
grep -Fxq 'STEP29_FIXTURES_CLEAN=PASS' <<< "$REPORT"
! grep -Fq '=FAIL' <<< "$REPORT"

echo '========== VERIFY POST-TEST DATABASE =========='
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM marketplace_listings')" = 0
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM content_entries')" = 0
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
 test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
printf '%s\n' \
 'STEP29_OWNED_WRITE_AND_CROSS_OWNER=PASS' \
 'QA_USERS_AND_CLIENT=UNCHANGED' \
 'ACTIVE_OAUTH_GRANTS=0' \
 'HTTPS_VHOST=NOT_CREATED' \
 'PRODUCTION_DEPLOYMENT=NONE'
