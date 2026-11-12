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
printf '%s\n' 'This test issues a temporary read-only QA OAuth token,' \
 'tests role demotion/account suspension, then revokes grants and cleans up.'
printf 'Type TEST-QA-ROLE-LIFECYCLE to continue: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
if [ "$CONFIRM" != 'TEST-QA-ROLE-LIFECYCLE' ]; then
  echo 'STEP30_CONFIRMATION=DECLINED'
  exit 1
fi
echo 'STEP30_CONFIRMATION=PASS'

printf '%s\n' '========== TEST OAUTH ROLE AND ACCOUNT LIFECYCLE =========='
if REPORT="$(
 cd "$APP"
 runuser -u www -- "$PHP" <<'PHP'
<?php
$db = null;
$clientId = null;
$actorId = null;
$actorEmail = null;
$stage = 'BOOTSTRAP';
$passed = false;
$clean = false;

function qa(bool $ok, string $name): void {
    echo $name.'='.($ok ? 'PASS' : 'FAIL')."\n";
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function reqQa($app, $kernel, string $method, string $uri,
               array $data = [], array $cookies = [],
               ?\App\Models\User $actor = null,
               ?string $bearer = null, bool $json = false): array {
    $server = [
        'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html',
        'CONTENT_TYPE' => $json
            ? 'application/json' : 'application/x-www-form-urlencoded',
        'HTTPS' => 'on',
    ];
    if ($bearer !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    }
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
    return ['jsonrpc'=>'2.0', 'id'=>1, 'method'=>'tools/list',
            'params'=>new stdClass()];
}
function oauthRead($app, $kernel, string $base, string $token): array {
    [, $response] = reqQa($app, $kernel, 'POST', $base.'/mcp', rpc(),
                           bearer:$token, json:true);
    $data = json_decode($response->getContent(), true);
    return [$response->getStatusCode(), $data];
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
       && config('mcp.redirect_domains') === ['https://chatgpt.com']
       && \Illuminate\Support\Facades\DB::connection()
            ->getDatabaseName() === 'bambodia_oauthqa_browser',
       'QA_RUNTIME_ISOLATION');
    $db = \Illuminate\Support\Facades\DB::connection();

    $original = \App\Models\User::query()
        ->where('email','oauth-admin@oauth-browser-qa.invalid')
        ->firstOrFail();
    $client = \Laravel\Passport\Passport::client()->newQuery()
        ->where('name','Bambodia Browser QA PKCE Client')->firstOrFail();
    $clientId = (string) $client->getKey();
    $urls = $client->getAttribute('redirect_uris');
    if (is_string($urls)) { $urls = json_decode($urls, true); }

    qa($db->table('users')->count() === 1
       && $db->table('oauth_clients')->count() === 1
       && $original->role === 'super_admin'
       && $original->account_status === 'active'
       && is_array($urls) && in_array($callback, $urls, true),
       'ORIGINAL_QA_IDENTITY_AND_CLIENT_VERIFIED');

    foreach (['oauth_auth_codes','oauth_access_tokens',
              'oauth_refresh_tokens'] as $table) {
        qa($db->table($table)->where('revoked',false)->count() === 0,
           strtoupper($table).'_INACTIVE_BASELINE');
    }
    qa($db->table('marketplace_listings')->count() === 0,
       'QA_MARKETPLACE_UNMODIFIED_BASELINE');

    $stage = 'CREATE_TEMPORARY_ACTOR';
    $actorEmail = 'step30-'.bin2hex(random_bytes(9))
                .'@oauth-browser-qa.invalid';
    $actor = \App\Models\User::query()->create([
        'name'=>'Step30 Temporary OAuth Admin',
        'email'=>$actorEmail,
        'password'=>bin2hex(random_bytes(32)),
        'role'=>'super_admin',
        'account_status'=>'active',
    ]);
    $actorId = (int) $actor->getKey();
    qa($actorId > 0 && $actorId !== (int) $original->getKey()
       && $db->table('users')->count() === 2,
       'TEMPORARY_ADMIN_ISOLATED');

    // The synthetic actor must use the existing public client.
    // No client registration or new Passport keys are performed.
    $stage = 'PKCE_AUTHORIZE';
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(
        base64_encode(hash('sha256',$verifier,true)), '+/','-_'
    ), '=');
    $state = bin2hex(random_bytes(24));
    $query = http_build_query([
        'client_id'=>$clientId, 'redirect_uri'=>$callback,
        'response_type'=>'code', 'scope'=>'mcp:use', 'state'=>$state,
        'code_challenge'=>$challenge, 'code_challenge_method'=>'S256',
        'resource'=>$base.'/mcp','prompt'=>'consent',
    ]);

    [$request,$consent] = reqQa(
        $app,$kernel,'GET',$base.'/oauth/authorize?'.$query,actor:$actor
    );
    qa($consent->getStatusCode() === 200, 'CONSENT_HTTP_200');
    $csrf = $request->session()->token();
    $authToken = $request->session()->get('authToken');
    $cookie = null;
    foreach ($consent->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            qa($c->isSecure(),'SESSION_COOKIE_SECURE');
            $cookie = $c->getValue();
            break;
        }
    }
    qa(is_string($csrf) && strlen($csrf) >= 20
       && is_string($authToken) && $authToken !== ''
       && is_string($cookie) && $cookie !== '',
       'CONSENT_SESSION_AND_CSRF');

    [$approveReq,$approval] = reqQa(
        $app,$kernel,'POST',route('passport.authorizations.approve'),
        [
            '_token'=>$csrf,'client_id'=>$clientId,
            'state'=>$state,'auth_token'=>$authToken,
        ],[config('session.cookie')=>$cookie],$actor
    );
    $redirect = $approval->headers->get('Location','');
    parse_str(parse_url($redirect,PHP_URL_QUERY) ?: '',$params);
    $code = $params['code'] ?? null;
    qa(in_array($approval->getStatusCode(),[302,303],true)
       && str_starts_with($redirect,$callback.'?')
       && ($params['state'] ?? null) === $state
       && is_string($code) && $code !== '',
       'CONSENT_CODE_AND_REGISTERED_CALLBACK');

    $stage = 'PKCE_TOKEN_EXCHANGE';
    [, $tokenResponse] = reqQa(
        $app,$kernel,'POST',$base.'/oauth/token',[
            'grant_type'=>'authorization_code',
            'client_id'=>$clientId,
            'code'=>$code,
            'redirect_uri'=>$callback,
            'code_verifier'=>$verifier,
            'resource'=>$base.'/mcp',
        ]
    );
    qa($tokenResponse->getStatusCode() === 200,
       'PKCE_TOKEN_EXCHANGE');
    $issued = json_decode($tokenResponse->getContent(),true);
    $access = $issued['access_token'] ?? null;
    qa(is_string($access) && $access !== '',
       'TEMPORARY_BEARER_ISSUED');

    $jwtParts = explode('.',$access);
    qa(count($jwtParts) === 3,'BEARER_IS_JWT');
    $claims = json_decode(
        base64_decode(strtr($jwtParts[1],'-_','+/'),true),
        true,512,JSON_THROW_ON_ERROR
    );
    qa(($claims['iss'] ?? null) === $base
       && ($claims['aud'][0] ?? null) === $clientId
       && ($claims['aud'][1] ?? null) === $base.'/mcp'
       && in_array('mcp:use',$claims['scopes'] ?? [],true)
       && !in_array('mcp.write',$claims['scopes'] ?? [],true),
       'READ_ONLY_JWT_AUDIENCE_AND_SCOPE');

    $stage = 'BASELINE_BEARER_ACCESS';
    [$status,$data] = oauthRead($app,$kernel,$base,$access);
    qa($status === 200 && count($data['result']['tools'] ?? []) === 4,
       'ACTIVE_SUPER_ADMIN_MCP_ALLOWED');

    $stage = 'DEMOTE_ROLE';
    $actor->forceFill(['role'=>'customer'])->save();
    qa($actor->fresh()->role === 'customer',
       'TEMPORARY_ROLE_DEMOTED');
    [$status,$data] = oauthRead($app,$kernel,$base,$access);
    qa($status === 403
       && ($data['message'] ?? '') ===
          'MCP access requires an active super administrator.',
       'DEMOTED_ADMIN_BEARER_FORBIDDEN');

    $stage = 'RESTORE_ROLE';
    $actor->forceFill(['role'=>'super_admin'])->save();
    [$status,$data] = oauthRead($app,$kernel,$base,$access);
    qa($status === 200 && count($data['result']['tools'] ?? []) === 4,
       'ROLE_RESTORED_BEARER_ALLOWED');

    $stage = 'SUSPEND_ACCOUNT';
    $actor->forceFill(['account_status'=>'suspended'])->save();
    qa($actor->fresh()->account_status === 'suspended',
       'TEMPORARY_ACCOUNT_SUSPENDED');
    [$status,$data] = oauthRead($app,$kernel,$base,$access);
    qa($status === 403
       && ($data['message'] ?? '') ===
          'MCP access requires an active super administrator.',
       'SUSPENDED_ADMIN_BEARER_FORBIDDEN');

    $stage = 'RESTORE_ACCOUNT';
    $actor->forceFill(['account_status'=>'active'])->save();
    [$status,$data] = oauthRead($app,$kernel,$base,$access);
    qa($status === 200 && count($data['result']['tools'] ?? []) === 4,
       'ACCOUNT_RESTORED_BEARER_ALLOWED');

    // Demonstrate a role change during consent cannot issue another code.
    $stage = 'DEMOTION_DURING_CONSENT';
    $newState = bin2hex(random_bytes(24));
    $newVerifier = bin2hex(random_bytes(32));
    $newChallenge = rtrim(strtr(
        base64_encode(hash('sha256',$newVerifier,true)), '+/','-_'
    ), '=');
    $secondQuery = http_build_query([
        'client_id'=>$clientId,'redirect_uri'=>$callback,
        'response_type'=>'code','scope'=>'mcp:use','state'=>$newState,
        'code_challenge'=>$newChallenge,'code_challenge_method'=>'S256',
        'resource'=>$base.'/mcp','prompt'=>'consent',
    ]);
    [$r2,$c2] = reqQa(
        $app,$kernel,'GET',$base.'/oauth/authorize?'.$secondQuery,
        actor:$actor
    );
    qa($c2->getStatusCode() === 200,
       'SECOND_CONSENT_PAGE_HTTP_200');
    $token2 = $r2->session()->get('authToken');
    $csrf2 = $r2->session()->token();
    $cookie2 = null;
    foreach ($c2->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie')) {
            $cookie2 = $c->getValue();
        }
    }
    qa(is_string($token2) && $token2 !== ''
       && is_string($csrf2) && strlen($csrf2) >= 20
       && is_string($cookie2) && $cookie2 !== '',
       'SECOND_CONSENT_SESSION_READY');

    $codesBefore = $db->table('oauth_auth_codes')->count();
    $actor->forceFill(['role'=>'customer'])->save();
    [, $denied] = reqQa(
        $app,$kernel,'POST',route('passport.authorizations.approve'),
        [
            '_token'=>$csrf2,'client_id'=>$clientId,
            'state'=>$newState,'auth_token'=>$token2,
        ],[config('session.cookie')=>$cookie2],$actor
    );
    qa($denied->getStatusCode() === 403
       && $db->table('oauth_auth_codes')->count() === $codesBefore,
       'CONSENT_AFTER_DEMOTION_DENIED_NO_CODE');

    $actor->forceFill(['role'=>'super_admin'])->save();

    $stage = 'REVOKE_GRANT';
    $ids = $db->table('oauth_access_tokens')
        ->where('client_id',$clientId)->pluck('id')->all();
    qa(count($ids) > 0,'OAUTH_GRANT_RECORDED');
    $db->table('oauth_refresh_tokens')
       ->whereIn('access_token_id',$ids)->update(['revoked'=>true]);
    $db->table('oauth_access_tokens')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    $db->table('oauth_auth_codes')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    [$status,$data] = oauthRead($app,$kernel,$base,$access);
    qa($status === 401,'REVOKED_BEARER_HTTP_401');

    $passed = true;
} catch (\Throwable $e) {
    fwrite(STDERR,
        'STEP30_FAILURE_STAGE='.$stage."\n".
        'ERROR_CLASS='.get_class($e)."\n"
    );
} finally {
    // Revoke even on failure. Do not delete any unrelated record or token.
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
                   ->where('client_id',$clientId)
                   ->update(['revoked'=>true]);
                $db->table('oauth_auth_codes')
                   ->where('client_id',$clientId)
                   ->update(['revoked'=>true]);
            }
            // Only the one temporary user created by this run is deleted.
            if ($actorId !== null && $actorEmail !== null) {
                $db->table('users')->where('id',$actorId)
                    ->where('email',$actorEmail)
                    ->where('name','Step30 Temporary OAuth Admin')
                    ->delete();
            }
            $active = 0;
            foreach ([
                'oauth_auth_codes','oauth_access_tokens',
                'oauth_refresh_tokens',
            ] as $table) {
                $active += $db->table($table)
                    ->where('revoked',false)->count();
            }
            $originalUnchanged = $db->table('users')
                ->where('email','oauth-admin@oauth-browser-qa.invalid')
                ->where('role','super_admin')
                ->where('account_status','active')->count() === 1;

            $clean = $active === 0 && $originalUnchanged
                && $db->table('users')->count() === 1
                && $db->table('oauth_clients')->count() === 1
                && $db->table('marketplace_listings')->count() === 0;

            echo 'ALL_QA_GRANTS_REVOKED='.
                ($active === 0 ? 'PASS' : 'FAIL')."\n";
            echo 'STEP30_TEMP_ACCOUNT_CLEAN='.
                ($clean ? 'PASS' : 'FAIL')."\n";
        } catch (\Throwable $e) {
            fwrite(STDERR,"STEP30_CLEANUP=MANUAL_REVIEW_REQUIRED\n");
        }
    }
}
if (!$passed || !$clean) {
    echo "STEP30_PHP=FAIL\n";
    exit(30);
}
echo "STEP30_PHP=PASS\n";
exit(0);
PHP
)"; then
  printf '%s\n' "$REPORT"
else
  printf '%s\n' "$REPORT"
  echo 'STEP30_RESULT=BLOCKED; verify QA grants before any retry.'
  exit 1
fi

grep -Fxq 'STEP30_PHP=PASS' <<< "$REPORT"
grep -Fxq 'ALL_QA_GRANTS_REVOKED=PASS' <<< "$REPORT"
grep -Fxq 'STEP30_TEMP_ACCOUNT_CLEAN=PASS' <<< "$REPORT"
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
 'STEP30_ROLE_AND_ACCOUNT_LIFECYCLE=PASS' \
 'QA_USERS_AND_CLIENT=UNCHANGED' \
 'ACTIVE_OAUTH_GRANTS=0' \
 'HTTPS_VHOST=NOT_CREATED' \
 'PRODUCTION_DEPLOYMENT=NONE'
