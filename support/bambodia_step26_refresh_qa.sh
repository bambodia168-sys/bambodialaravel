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
printf '%s\n' 'This test will issue temporary QA-only tokens, rotate a refresh token,' \
 'reject replay, verify revocation, and preserve the existing QA client.'
printf 'Type TEST-QA-REFRESH to continue: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
test "$CONFIRM" = 'TEST-QA-REFRESH'

printf '%s\n' '========== TEST REFRESH ROTATION AND REVOKED BEARER =========='
if REPORT="$(
 cd "$APP"
 runuser -u www -- "$PHP" <<'PHP'
<?php
$db = null;
$clientId = null;
$stage = 'BOOTSTRAP';
$passed = false;
$clean = false;

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
        'response_type' => 'code', 'scope' => 'mcp:use', 'state' => $state,
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

    $stage = 'REFRESH_ROTATE';
    [, $rotated] = reqQa($app, $kernel, 'POST', $base.'/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $clientId,
        'refresh_token' => $firstRefresh, 'scope' => 'mcp:use',
        'resource' => $base.'/mcp',
    ]);
    qa($rotated->getStatusCode() === 200, 'REFRESH_ROTATION_HTTP_200');
    $second = json_decode($rotated->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $newAccess = $second['access_token'] ?? null;
    $newRefresh = $second['refresh_token'] ?? null;
    qa(is_string($newAccess) && $newAccess !== '' && $newAccess !== $firstAccess
       && is_string($newRefresh) && $newRefresh !== ''
       && $newRefresh !== $firstRefresh, 'REFRESH_NEW_TOKEN_PAIR');
    $claims = bearerClaims($newAccess);
    qa(($claims['iss'] ?? null) === $base
       && ($claims['aud'][0] ?? null) === $clientId
       && ($claims['aud'][1] ?? null) === $base.'/mcp'
       && in_array('mcp:use', $claims['scopes'] ?? [], true)
       && !in_array('mcp.write', $claims['scopes'] ?? [], true),
       'REFRESHED_JWT_READ_ONLY_AUDIENCE');
    [, $mcp] = reqQa($app, $kernel, 'POST', $base.'/mcp', rpc(),
                      bearer: $newAccess, json: true);
    $rpcResult = json_decode($mcp->getContent(), true);
    qa($mcp->getStatusCode() === 200
       && count($rpcResult['result']['tools'] ?? []) === 4,
       'REFRESHED_BEARER_FOUR_READ_TOOLS');

    $stage = 'REPLAY_OLD_REFRESH';
    [, $replay] = reqQa($app, $kernel, 'POST', $base.'/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $clientId,
        'refresh_token' => $firstRefresh, 'scope' => 'mcp:use',
        'resource' => $base.'/mcp',
    ]);
    $err = json_decode($replay->getContent(), true);
    qa($replay->getStatusCode() === 400 && is_array($err)
       && ($err['error'] ?? null) === 'invalid_grant',
       'REPLAY_OLD_REFRESH_REJECTED');

    $stage = 'REVOKE_AND_VERIFY';
    $tokenIds = $db->table('oauth_access_tokens')
        ->where('client_id', $clientId)->pluck('id')->all();
    qa(count($tokenIds) >= 2, 'REFRESH_TOKEN_RECORDS_PRESENT');
    $db->table('oauth_refresh_tokens')
       ->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
    $db->table('oauth_access_tokens')
       ->where('client_id', $clientId)->update(['revoked' => true]);
    $db->table('oauth_auth_codes')
       ->where('client_id', $clientId)->update(['revoked' => true]);
    [, $rejected] = reqQa($app, $kernel, 'POST', $base.'/mcp', rpc(),
                            bearer: $newAccess, json: true);
    qa($rejected->getStatusCode() === 401,
       'REVOKED_BEARER_HTTP_401');
    [, $rejectedRefresh] = reqQa($app, $kernel, 'POST', $base.'/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $clientId,
        'refresh_token' => $newRefresh, 'scope' => 'mcp:use',
        'resource' => $base.'/mcp',
    ]);
    $err = json_decode($rejectedRefresh->getContent(), true);
    qa($rejectedRefresh->getStatusCode() === 400 && is_array($err)
       && ($err['error'] ?? null) === 'invalid_grant',
       'REVOKED_REFRESH_REJECTED');
    $passed = true;
} catch (\Throwable $e) {
    fwrite(STDERR, 'STEP26_FAILURE_STAGE='.$stage."\n".
                   'ERROR_CLASS='.get_class($e)."\n");
} finally {
    if ($db !== null && $clientId !== null) {
        try {
            $ids = $db->table('oauth_access_tokens')
                ->where('client_id', $clientId)->pluck('id')->all();
            if ($ids !== []) {
                $db->table('oauth_refresh_tokens')
                    ->whereIn('access_token_id', $ids)->update(['revoked' => true]);
            }
            $db->table('oauth_access_tokens')
                ->where('client_id', $clientId)->update(['revoked' => true]);
            $db->table('oauth_auth_codes')
                ->where('client_id', $clientId)->update(['revoked' => true]);
            $clean = true;
            foreach (['oauth_auth_codes','oauth_access_tokens','oauth_refresh_tokens'] as $t) {
                $clean = $clean && $db->table($t)->where('revoked', false)->count() === 0;
            }
            echo 'ALL_QA_GRANTS_REVOKED='.($clean ? 'PASS' : 'FAIL')."\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, "STEP26_CLEANUP=REVIEW_REQUIRED\n");
        }
    }
}
if (!$passed || !$clean) {
    echo "STEP26_PHP=FAIL\n";
    exit(26);
}
echo "STEP26_PHP=PASS\n";
exit(0);
PHP
)"; then
  printf '%s\n' "$REPORT"
else
  printf '%s\n' "$REPORT"
  echo 'STEP26_RESULT=BLOCKED; verify QA grants before any retry.'
  exit 1
fi

grep -Fxq 'STEP26_PHP=PASS' <<< "$REPORT"
grep -Fxq 'ALL_QA_GRANTS_REVOKED=PASS' <<< "$REPORT"
! grep -Fq '=FAIL' <<< "$REPORT"

echo '========== VERIFY POST-TEST DATABASE =========='
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL "$DB" -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
 test "$(QA_MYSQL "$DB" -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
printf '%s\n' \
 'STEP26_REFRESH_REPLAY_REVOCATION=PASS' \
 'QA_USERS_AND_CLIENT=UNCHANGED' \
 'ACTIVE_OAUTH_GRANTS=0' \
 'HTTPS_VHOST=NOT_CREATED' \
 'PRODUCTION_DEPLOYMENT=NONE'
