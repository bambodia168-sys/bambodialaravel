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
printf '%s\n' 'This test uses a temporary password for real Laravel QA login,' \
 'checks CSRF/session and OAuth login, then revokes grants and cleans up.'
printf 'Type TEST-QA-REAL-LOGIN to continue: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
if [ "$CONFIRM" != 'TEST-QA-REAL-LOGIN' ]; then
  echo 'STEP31_CONFIRMATION=DECLINED'
  exit 1
fi
echo 'STEP31_CONFIRMATION=PASS'

printf '%s\n' '========== TEST REAL QA LOGIN, SESSION AND OAUTH =========='
if REPORT="$(
 cd "$APP"
 runuser -u www -- "$PHP" <<'PHP'
<?php
$db = null;
$clientId = null;
$actorId = null;
$actorEmail = null;
$app = null;
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
    $app->instance('request',$request);
    // Simulate separate HTTP requests; never inject an authenticated user.
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $response = $kernel->handle($request);
    $kernel->terminate($request,$response);
    return [$request,$response];
}
function sessionCookie($response): ?string {
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('session.cookie')) {
            qa($cookie->isSecure(), 'SECURE_SESSION_COOKIE');
            return $cookie->getValue();
        }
    }
    return null;
}
function rpc(): array {
    return ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list',
            'params'=>new stdClass()];
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
       && \Illuminate\Support\Facades\DB::connection()
           ->getDatabaseName() === 'bambodia_oauthqa_browser',
       'QA_RUNTIME_ISOLATION');

    $db = \Illuminate\Support\Facades\DB::connection();
    $original = \App\Models\User::query()
       ->where('email','oauth-admin@oauth-browser-qa.invalid')
       ->firstOrFail();
    $client = \Laravel\Passport\Passport::client()->newQuery()
       ->where('name','Bambodia Browser QA PKCE Client')
       ->firstOrFail();
    $clientId = (string)$client->getKey();
    $urls = $client->getAttribute('redirect_uris');
    if (is_string($urls)) $urls = json_decode($urls,true);
    qa($db->table('users')->count() === 1
       && $db->table('oauth_clients')->count() === 1
       && $original->role === 'super_admin'
       && $original->account_status === 'active'
       && is_array($urls) && in_array($callback,$urls,true),
       'ORIGINAL_QA_USER_AND_CLIENT');

    foreach (['oauth_auth_codes','oauth_access_tokens',
              'oauth_refresh_tokens'] as $table) {
        qa($db->table($table)->where('revoked',false)->count() === 0,
           strtoupper($table).'_INACTIVE_BASELINE');
    }
    qa($db->table('marketplace_listings')->count() === 0,
       'QA_LISTINGS_EMPTY');

    // Never silently bypass an enabled real Cloudflare Turnstile challenge.
    qa(\App\Models\TurnstileBotProtection::forAction('login') === null,
       'QA_LOGIN_TURNSTILE_DISABLED');

    $stage = 'TEMPORARY_REAL_LOGIN_ACCOUNT';
    $actorEmail = 'step31-'.bin2hex(random_bytes(8))
                .'@oauth-browser-qa.invalid';
    $password = bin2hex(random_bytes(32));
    $actor = \App\Models\User::query()->create([
        'name'=>'Step31 Temporary Login QA Admin',
        'email'=>$actorEmail,
        'password'=>$password,
        'role'=>'super_admin',
        'account_status'=>'active',
    ]);
    $actorId = (int)$actor->getKey();
    qa($actorId !== (int)$original->getKey()
       && $db->table('users')->count() === 2
       && \Illuminate\Support\Facades\Hash::check($password,$actor->password)
       && !$db->table('user_two_factors')
           ->where('user_id',$actorId)->exists(),
       'TEMP_PASSWORD_IDENTITY_CREATED');

    $stage = 'LOGIN_FORM';
    [$loginRequest,$loginPage] = reqQa(
        $app,$kernel,'GET',$base.'/login'
    );
    $csrf = $loginRequest->session()->token();
    $cookie = sessionCookie($loginPage);
    qa($loginPage->getStatusCode() === 200
       && is_string($csrf) && strlen($csrf) >= 20
       && is_string($cookie) && $cookie !== '',
       'LOGIN_FORM_AND_CSRF_READY');

    // Post as an unauthenticated client with session but without _token.
    // Laravel's request-forgery middleware must reject before AuthController.
    $stage = 'LOGIN_CSRF';
    [, $noCsrf] = reqQa(
        $app,$kernel,'POST',$base.'/login',[
            'email'=>$actorEmail,
            'password'=>$password,
        ],[config('session.cookie')=>$cookie]
    );
    qa($noCsrf->getStatusCode() === 419,
       'LOGIN_MISSING_CSRF_HTTP_419');

    // A separate valid login must authenticate the password, not setUser().
    $stage = 'REAL_PASSWORD_LOGIN';
    [, $loggedIn] = reqQa(
        $app,$kernel,'POST',$base.'/login',[
            '_token'=>$csrf,
            'email'=>$actorEmail,
            'password'=>$password,
        ],[config('session.cookie')=>$cookie]
    );
    $location = $loggedIn->headers->get('Location','');
    qa(in_array($loggedIn->getStatusCode(),[302,303],true)
       && str_contains($location,'/dashboard'),
       'PASSWORD_LOGIN_REDIRECTED_TO_DASHBOARD');

    // Laravel rotates session ID at login; use the new cookie.
    $newCookie = sessionCookie($loggedIn);
    qa(is_string($newCookie) && $newCookie !== ''
       && !hash_equals($cookie,$newCookie),
       'LOGIN_SESSION_COOKIE_ROTATED');

    $sessionCookies = [config('session.cookie')=>$newCookie];
    $password = null; // No need to keep QA password after login.

    // Cookie alone must never authenticate MCP.
    $stage = 'COOKIE_NOT_MCP_CREDENTIAL';
    [, $noBearer] = reqQa(
        $app,$kernel,'POST',$base.'/mcp',rpc(),$sessionCookies,
        json:true
    );
    qa($noBearer->getStatusCode() === 401,
       'WEB_SESSION_CANNOT_ACCESS_MCP');

    $stage = 'SESSION_CONSENT';
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(
        base64_encode(hash('sha256',$verifier,true)),'+/','-_'
    ),'=');
    $state = bin2hex(random_bytes(24));
    $query = http_build_query([
        'client_id'=>$clientId,
        'redirect_uri'=>$callback,
        'response_type'=>'code',
        'scope'=>'mcp:use',
        'state'=>$state,
        'code_challenge'=>$challenge,
        'code_challenge_method'=>'S256',
        'resource'=>$base.'/mcp',
        'prompt'=>'consent',
    ]);
    [$consentRequest,$consent] = reqQa(
        $app,$kernel,'GET',
        $base.'/oauth/authorize?'.$query,$sessionCookies
    );
    $consentBody = $consent->getContent();
    qa($consent->getStatusCode() === 200
       && is_string($consentBody)
       && str_contains($consentBody,'Connect Bambodia Manager'),
       'REAL_LOGIN_SESSION_CAN_VIEW_CONSENT');

    $csrfConsent = $consentRequest->session()->token();
    $authToken = $consentRequest->session()->get('authToken');
    $consentCookie = sessionCookie($consent);
    qa(is_string($csrfConsent) && strlen($csrfConsent) >= 20
       && is_string($authToken) && $authToken !== ''
       && is_string($consentCookie) && $consentCookie !== '',
       'REAL_SESSION_CONSENT_CSRF_AND_COOKIE');

    $stage = 'REAL_SESSION_APPROVE';
    [, $approval] = reqQa(
        $app,$kernel,'POST',route('passport.authorizations.approve'),[
            '_token'=>$csrfConsent,
            'client_id'=>$clientId,
            'state'=>$state,
            'auth_token'=>$authToken,
        ],[config('session.cookie')=>$consentCookie]
    );
    $redirect = $approval->headers->get('Location','');
    parse_str(parse_url($redirect,PHP_URL_QUERY) ?: '',$params);
    $code = $params['code'] ?? null;
    qa(in_array($approval->getStatusCode(),[302,303],true)
       && str_starts_with($redirect,$callback.'?')
       && ($params['state'] ?? null) === $state
       && is_string($code) && $code !== '',
       'REAL_SESSION_CONSENT_CODE_AND_CALLBACK');

    $stage = 'PKCE_EXCHANGE';
    [, $exchange] = reqQa(
        $app,$kernel,'POST',$base.'/oauth/token',[
            'grant_type'=>'authorization_code',
            'client_id'=>$clientId,
            'redirect_uri'=>$callback,
            'code'=>$code,
            'code_verifier'=>$verifier,
            'resource'=>$base.'/mcp',
        ]
    );
    $issued = json_decode($exchange->getContent(),true);
    $bearer = $issued['access_token'] ?? null;
    qa($exchange->getStatusCode() === 200
       && is_string($bearer) && $bearer !== '',
       'REAL_LOGIN_PKCE_EXCHANGE_HTTP_200');

    $stage = 'MCP_BEARER';
    [, $mcp] = reqQa(
        $app,$kernel,'POST',$base.'/mcp',rpc(),
        bearer:$bearer,json:true
    );
    $result = json_decode($mcp->getContent(),true);
    $names = array_column($result['result']['tools'] ?? [],'name');
    sort($names);
    qa($mcp->getStatusCode() === 200 && $names === [
        'bambodia_analytics','bambodia_get',
        'bambodia_list','bambodia_overview',
    ],'SIGNED_BEARER_FOUR_READ_TOOLS');

    $stage = 'REAL_SESSION_LOGOUT';
    // Logout rotates/destroys the browser login session, but an OAuth
    // access token remains independent until revoked.
    [, $logout] = reqQa(
        $app,$kernel,'POST',$base.'/logout',[
            '_token'=>$csrfConsent,
        ],[config('session.cookie')=>$consentCookie]
    );
    qa(in_array($logout->getStatusCode(),[302,303],true),
       'REAL_SESSION_LOGOUT_REDIRECT');

    $stage = 'REVOKE_BEARER';
    $ids = $db->table('oauth_access_tokens')
        ->where('client_id',$clientId)->pluck('id')->all();
    qa(count($ids) > 0,'TEST_OAUTH_GRANT_RECORDED');
    $db->table('oauth_refresh_tokens')
       ->whereIn('access_token_id',$ids)->update(['revoked'=>true]);
    $db->table('oauth_access_tokens')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    $db->table('oauth_auth_codes')
       ->where('client_id',$clientId)->update(['revoked'=>true]);
    [, $revoked] = reqQa(
        $app,$kernel,'POST',$base.'/mcp',rpc(),
        bearer:$bearer,json:true
    );
    qa($revoked->getStatusCode() === 401,
       'REVOKED_LOGIN_FLOW_BEARER_HTTP_401');

    $passed = true;
} catch (\Throwable $error) {
    fwrite(STDERR,'STEP31_FAILURE_STAGE='.$stage."\n".
                   'ERROR_CLASS='.get_class($error)."\n");
} finally {
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
            // Do not delete any unrelated account.
            if ($actorId !== null && $actorEmail !== null) {
                $db->table('users')->where('id',$actorId)
                   ->where('email',$actorEmail)
                   ->where('name','Step31 Temporary Login QA Admin')
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
            $clean = $active === 0
                && $db->table('users')->count() === 1
                && $db->table('oauth_clients')->count() === 1
                && $db->table('marketplace_listings')->count() === 0
                && $db->table('users')
                  ->where('email','oauth-admin@oauth-browser-qa.invalid')
                  ->where('role','super_admin')
                  ->where('account_status','active')
                  ->count() === 1;
            echo 'ALL_QA_GRANTS_REVOKED='.
                ($active === 0 ? 'PASS':'FAIL')."\n";
            echo 'STEP31_TEMP_ACCOUNT_CLEAN='.
                ($clean ? 'PASS':'FAIL')."\n";
        } catch (\Throwable $error) {
            fwrite(STDERR,"STEP31_CLEANUP=MANUAL_REVIEW_REQUIRED\n");
        }
    }
}
if (!$passed || !$clean) {
    echo "STEP31_PHP=FAIL\n";
    exit(31);
}
echo "STEP31_PHP=PASS\n";
exit(0);
PHP
)"; then
  printf '%s\n' "$REPORT"
else
  printf '%s\n' "$REPORT"
  echo 'STEP31_RESULT=BLOCKED; verify QA grants before any retry.'
  exit 1
fi

grep -Fxq 'STEP31_PHP=PASS' <<< "$REPORT"
grep -Fxq 'ALL_QA_GRANTS_REVOKED=PASS' <<< "$REPORT"
grep -Fxq 'STEP31_TEMP_ACCOUNT_CLEAN=PASS' <<< "$REPORT"
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
 'STEP31_REAL_LOGIN_SESSION_PKCE=PASS' \
 'QA_USERS_AND_CLIENT=UNCHANGED' \
 'ACTIVE_OAUTH_GRANTS=0' \
 'HTTPS_VHOST=NOT_CREATED' \
 'PRODUCTION_DEPLOYMENT=NONE'
