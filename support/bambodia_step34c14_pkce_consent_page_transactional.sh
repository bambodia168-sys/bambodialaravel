#!/usr/bin/env bash
# Step 34C.14: in-process authenticated PKCE consent-page rehearsal.
# Read-only --check; --exercise creates uncommitted QA admin/client in one transaction,
# inspects a valid PKCE S256 GET /oauth/authorize consent response, rolls back.
# No consent approval POST, authorization code, token, network listener or public host.
set -Eeuo pipefail
set +x
umask 077

MODE="$1"
case "$MODE" in --check|--exercise) ;; *)
 echo 'Usage: script --check|--exercise' >&2; exit 64 ;; esac
APP="/var/lib/bambodia-oauth-public-qa"
PHP="/www/server/php/85/bin/php"
MYSQL="/www/server/mysql/bin/mysql"
CNF="/root/bambodia-oauth-public-qa.cnf"
AUDIT="/root/bambodia_step34c11_oauth_fixture_readiness_readonly.sh"
AUDIT_SHA="913fe0cf292c4dc4e05a8bef1740dd3c743e1e0b6b278b0bd7de458e892dcc4e"
fail(){ echo "STEP34C14_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY PINNED ISOLATED READINESS GATE =========='
test "$(id -u)" -eq 0 || fail ROOT_REQUIRED
test -x "$PHP" && test -x "$MYSQL" || fail PHP85_OR_MYSQL_MISSING
for bin in sha256sum stat python3; do
 command -v "$bin" >/dev/null || fail REQUIRED_TOOL_MISSING
done
test -f "$AUDIT" && test ! -L "$AUDIT" &&
 test "$(stat -c '%u:%a' "$AUDIT")" = '0:700' &&
 test "$(sha256sum "$AUDIT" | awk '{print $1}')" = "$AUDIT_SHA" ||
 fail PINNED_READINESS_AUDIT_MISSING_OR_CHANGED
test -d "$APP" && test ! -L "$APP" &&
 test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
 fail ROOT_PRIVATE_APP_UNSAFE
"$PHP" -r 'if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==5)exit(1);' ||
 fail PHP85_RUNTIME_CHANGED
bash "$AUDIT" --audit
echo 'STEP34C14_READ_ONLY_QA_BOUNDARIES=PASS'

snapshot() {
python3 - "$MYSQL" "$CNF" <<'PY'
import hashlib,re,subprocess,sys
mysql,cnf=sys.argv[1:]
cmd=[mysql,'--defaults-extra-file='+cnf,'--batch','--skip-column-names']
def read(sql):
 p=subprocess.run(cmd+['-e',sql],stdout=subprocess.PIPE,
    stderr=subprocess.DEVNULL,timeout=25,check=False)
 if p.returncode:
  raise SystemExit('STEP34C14_BLOCKED=DATABASE_SNAPSHOT_QUERY_FAILED')
 return p.stdout.decode('utf-8').strip()
tables=read("SELECT TABLE_NAME FROM information_schema.TABLES WHERE "
 "TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_TYPE='BASE TABLE' "
 "ORDER BY TABLE_NAME").splitlines()
if len(tables)<20:
 raise SystemExit('STEP34C14_BLOCKED=UNEXPECTED_TABLE_COUNT')
h=hashlib.sha256()
q=chr(96)
for table in tables:
 if not re.fullmatch(r'[A-Za-z0-9_]+',table):
  raise SystemExit('STEP34C14_BLOCKED=UNEXPECTED_TABLE_NAME')
 count=read('SELECT COUNT(*) FROM '+q+table+q)
 if not count.isdecimal():
  raise SystemExit('STEP34C14_BLOCKED=INVALID_TABLE_ROW_COUNT')
 h.update((table+':'+count+'\n').encode('utf-8'))
print(str(len(tables))+':'+h.hexdigest())
PY
}

VENDOR="/root/bambodia_step34c13_passport_vendor_flow_contract_readonly.sh"
VENDOR_SHA="2f139bd8142220d24b58c59ab6bb1b3f46481527b512ff4c710cc4b5714e09b1"
test -f "$VENDOR" && test ! -L "$VENDOR" &&
  test "$(stat -c '%u:%a' "$VENDOR")" = '0:700' &&
  test "$(sha256sum "$VENDOR" | awk '{print $1}')" = "$VENDOR_SHA" ||
  fail PINNED_VENDOR_CONTRACT_AUDIT_CHANGED_OR_MISSING
bash "$VENDOR" --audit
echo 'STEP34C14_INSTALLED_VENDOR_CONTRACT=PASS'

if [ "$MODE" = --check ]; then
 echo 'STEP34C14_READ_ONLY_PREFLIGHT=PASS'
 echo 'QA_ADMIN_OAUTH_CLIENT_AND_HTTP_KERNEL=NOT_STARTED'
 echo 'DATABASE_NGINX_AND_PUBLIC_SITE_WRITES=NONE'
 exit 0
fi

echo '========== SNAPSHOT ALL ISOLATED QA TABLE COUNTS AND KEYS =========='
BEFORE="$(snapshot)" || fail DATABASE_PRESTATE_SNAPSHOT_FAILED
KEYS_BEFORE="$(sha256sum "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key")" ||
 fail KEY_FINGERPRINT_READ_FAILED
echo 'ISOLATED_QA_TABLE_COUNTS_AND_KEY_HASHES=CAPTURED'

echo '========== REQUIRE OPERATOR APPROVAL FOR TRANSIENT CONSENT RENDER =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo 'In a QA-only database transaction, create random disposable super-admin/client.'
echo 'Make one authenticated, in-process GET /oauth/authorize with PKCE S256.'
echo 'Do NOT approve consent, generate tokens, or follow any redirect.'
echo 'All test identity/client rows will be rolled back.'
echo 'Laravel array session and cache avoid shared session files for this test.'
printf 'Type REHEARSE-PRIVATE-PKCE-CONSENT-GET-ROLLBACK: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'REHEARSE-PRIVATE-PKCE-CONSENT-GET-ROLLBACK' ||
 fail OPERATOR_DECLINED
echo 'STEP34C14_EXPLICIT_AUTHORIZATION_PAGE_APPROVAL=PASS'

echo '========== IN-PROCESS PKCE AUTHORIZATION CONSENT PAGE TEST =========='
if python3 - "$PHP" "$APP" <<'PY'
import subprocess,sys
php,app=sys.argv[1:]
code=r'''
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if((string)config("app.url")!=="https://oauth-public-qa.invalid"||
   (string)config("database.default")!=="mysql"){
 throw new \RuntimeException("INVALID_INERT_APP_OR_DB_CONFIGURATION");
}
config(["session.driver"=>"array","cache.default"=>"array"]);
$db=\Illuminate\Support\Facades\DB::connection();
if($db->getDatabaseName()!=="bambodia_oauthqa_public"||
   $db->transactionLevel()!==0){
 throw new \RuntimeException("PRIVATE_DB_TRANSACTION_SCOPE_INVALID");
}
$ok=false;$rolledBack=false;
try {
 $db->transaction(function () use($db,$app,&$ok) {
  $secret=bin2hex(random_bytes(32));
  $user=\App\Models\User::create([
    "name"=>"Disposable PKCE Consent QA",
    "username"=>"pqa".bin2hex(random_bytes(10)),
    "email"=>"pkce-".bin2hex(random_bytes(12))."@example.invalid",
    "password"=>$secret,"role"=>"super_admin",
    "locale"=>"en","account_status"=>"active"
  ]);
  if(!$user->exists||$user->role!=="super_admin"||
     $user->account_status!=="active"||
     !\Illuminate\Support\Facades\Hash::check($secret,$user->password)){
   throw new \RuntimeException("DISPOSABLE_ADMIN_ACCOUNT_INVALID");
  }
  $clientId=(string)\Illuminate\Support\Str::uuid();
  $callback="https://chatgpt.com/connector_platform_oauth_redirect";
  $now=\Illuminate\Support\Carbon::now();
  $db->table("oauth_clients")->insert([
   "id"=>$clientId,
   "owner_type"=>$user->getMorphClass(),"owner_id"=>$user->getKey(),
   "name"=>"Disposable PKCE QA Client",
   "secret"=>null,"provider"=>null,
   "redirect_uris"=>json_encode([$callback]),
   "grant_types"=>json_encode(["authorization_code","refresh_token"]),
   "revoked"=>false,
   "scopes"=>json_encode(["mcp:use","offline_access"]),
   "created_at"=>$now,"updated_at"=>$now
  ]);
  $verifier=rtrim(strtr(base64_encode(random_bytes(48)),"+/","-_"),"=");
  $challenge=rtrim(strtr(base64_encode(hash("sha256",$verifier,true)),"+/","-_"),"=");
  if(strlen($verifier)<43||strlen($challenge)!==43){
   throw new \RuntimeException("INVALID_GENERATED_PKCE_PARAMETERS");
  }
  $params=[
   "client_id"=>$clientId,"redirect_uri"=>$callback,
   "response_type"=>"code","state"=>bin2hex(random_bytes(16)),
   "scope"=>"mcp:use offline_access",
   "code_challenge"=>$challenge,"code_challenge_method"=>"S256",
   "resource"=>"https://oauth-public-qa.invalid/mcp"
  ];
  $request=\Illuminate\Http\Request::create(
    "https://oauth-public-qa.invalid/oauth/authorize",
    "GET",$params,[],[],[
      "HTTP_HOST"=>"oauth-public-qa.invalid",
      "HTTPS"=>"on","SERVER_PORT"=>"443",
      "HTTP_ACCEPT"=>"text/html",
      "REMOTE_ADDR"=>"127.0.0.1"
    ]
  );
  $session=$app->make("session")->driver("array");
  $session->start();
  $request->setLaravelSession($session);
  $guard=$app->make("auth")->guard("web");
  $guard->setRequest($request);
  $guard->login($user);
  $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
  $response=$kernel->handle($request);
  $status=(int)$response->getStatusCode();
  $body=$response->getContent();
  $rendered=$status===200&&is_string($body)&&
     str_contains($body,"Connect Bambodia Manager")&&
     str_contains($body,'name="auth_token"')&&
     str_contains($body,"Authorize access")&&
     !str_contains($body,"mcp.write");
  // Never follow an external callback, even if Passport unexpectedly redirects.
  $kernel->terminate($request,$response);
  echo "PKCE_S256_CHALLENGE=GENERATED_NO_VALUES_DISCLOSED\n";
  echo "AUTHENTICATED_CONSENT_GET_HTTP_STATUS=".$status."\n";
  echo "AUTHENTICATED_CONSENT_VIEW=".($rendered?"PASS":"FAIL")."\n";
  echo "AUTHORIZATION_CODE_IN_TRANSACTION=".
     ($db->table("oauth_auth_codes")->count()===0?"ZERO":"UNEXPECTED")."\n";
  echo "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=".
     ($db->table("oauth_access_tokens")->count()===0&&
      $db->table("oauth_refresh_tokens")->count()===0?"ZERO":"UNEXPECTED")."\n";
  if(!$rendered||$db->table("oauth_auth_codes")->count()!==0||
    $db->table("oauth_access_tokens")->count()!==0||
    $db->table("oauth_refresh_tokens")->count()!==0){
    throw new \RuntimeException("PRIVATE_PKCE_CONSENT_RENDER_NOT_ACCEPTED");
  }
  $ok=true;
  throw new \RuntimeException("QA_ONLY_FORCE_TRANSACTION_ROLLBACK");
 });
}catch(\RuntimeException $e){
 if($ok&&$e->getMessage()==="QA_ONLY_FORCE_TRANSACTION_ROLLBACK"){
   $rolledBack=true;
 }else{
   throw $e;
 }
}
if(!$rolledBack||$db->transactionLevel()!==0||
 $db->table("users")->count()!==0||
 $db->table("oauth_clients")->count()!==0||
 $db->table("oauth_auth_codes")->count()!==0||
 $db->table("oauth_access_tokens")->count()!==0||
 $db->table("oauth_refresh_tokens")->count()!==0){
 throw new \RuntimeException("QA_TRANSACTION_NOT_ROLLED_BACK");
}
echo "DISPOSABLE_ADMIN_AND_CLIENT=ROLLED_BACK\n";
echo "ISOLATED_OAUTH_GRANTS=ZERO\n";
echo "PRIVATE_PKCE_AUTHORIZATION_CONSENT_VIEW=PASS\n";
echo "RAW_CONSENT_HTML_USER_IDS_AND_SECRETS_DISCLOSED=NO\n";
echo "NETWORK_LISTENER_STARTED=NO\n";
'''
try:
 p=subprocess.run([php,'-d','display_errors=0','-d','log_errors=0',
  '-r',code],cwd=app,
  env={'HOME':'/root','PATH':'/usr/bin:/bin','APP_ENV':'testing'},
  stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
  stderr=subprocess.DEVNULL,timeout=85,check=False)
except subprocess.TimeoutExpired:
 raise SystemExit('STEP34C14_BLOCKED=ISOLATED_PHP_KERNEL_TIMEOUT')
lines=p.stdout.decode('utf-8','replace').splitlines()
allowed_prefixes=(
 'PKCE_S256_CHALLENGE=',
 'AUTHENTICATED_CONSENT_GET_HTTP_STATUS=',
 'AUTHENTICATED_CONSENT_VIEW=',
 'AUTHORIZATION_CODE_IN_TRANSACTION=',
 'ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=',
 'DISPOSABLE_ADMIN_AND_CLIENT=',
 'ISOLATED_OAUTH_GRANTS=',
 'PRIVATE_PKCE_AUTHORIZATION_CONSENT_VIEW=',
 'RAW_CONSENT_HTML_USER_IDS_AND_SECRETS_DISCLOSED=',
 'NETWORK_LISTENER_STARTED=')
if len(p.stdout)>32768 or any(
    len(line)>120 or not line.startswith(allowed_prefixes) for line in lines):
 raise SystemExit('STEP34C14_BLOCKED=UNEXPECTED_PHP_OUTPUT_REDACTED')
for line in lines:print(line)
expected=[
 'PKCE_S256_CHALLENGE=GENERATED_NO_VALUES_DISCLOSED',
 'AUTHENTICATED_CONSENT_GET_HTTP_STATUS=200',
 'AUTHENTICATED_CONSENT_VIEW=PASS',
 'AUTHORIZATION_CODE_IN_TRANSACTION=ZERO',
 'ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=ZERO',
 'DISPOSABLE_ADMIN_AND_CLIENT=ROLLED_BACK',
 'ISOLATED_OAUTH_GRANTS=ZERO',
 'PRIVATE_PKCE_AUTHORIZATION_CONSENT_VIEW=PASS',
 'RAW_CONSENT_HTML_USER_IDS_AND_SECRETS_DISCLOSED=NO',
 'NETWORK_LISTENER_STARTED=NO']
if p.returncode!=0 or lines!=expected:
 raise SystemExit('STEP34C14_BLOCKED=PRIVATE_CONSENT_GET_FAILED_OR_ROLLBACK_UNVERIFIED')
PY
then
  EXERCISE_RESULT=PASS
else
  EXERCISE_RESULT=FAILED
fi

echo '========== VERIFY COMPLETE ISOLATION EVEN WHEN TEST FAILS =========='
AFTER="$(snapshot)" || fail DATABASE_POSTSTATE_SNAPSHOT_FAILED
test "$BEFORE" = "$AFTER" || fail ISOLATED_QA_TABLE_COUNTS_CHANGED
KEYS_AFTER="$(sha256sum "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key")" ||
 fail KEY_FINGERPRINT_POST_FAILED
test "$KEYS_BEFORE" = "$KEYS_AFTER" || fail PASSPORT_KEY_CONTENT_CHANGED
echo 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED'
echo 'PASSPORT_KEY_BYTES=UNCHANGED'
bash "$AUDIT" --audit || fail POST_EXERCISE_QA_READINESS_CHANGED
test "$EXERCISE_RESULT" = PASS || fail PKCE_AUTHORIZATION_CONSENT_NOT_ACCEPTED
echo 'STEP34C14_PRIVATE_PKCE_CONSENT_RENDER_AND_ROLLBACK=PASS'
echo 'CONSENT_APPROVAL_OR_TOKEN_ISSUANCE=NOT_PERFORMED'
echo 'PUBLIC_OAUTH_ISSUER_OR_LISTENER=NOT_ACTIVATED'
echo 'NGINX_DNS_TLS_PRODUCTION_AND_GITHUB_MAIN_WRITES=NONE'
