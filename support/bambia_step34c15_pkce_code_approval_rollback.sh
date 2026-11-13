#!/usr/bin/env bash
# Step 34C.15: in-process PKCE consent approval and authorization code rehearsal.
# Read-only --check; --exercise creates transient QA admin/client, GETs consent,
# then POSTs approval and validates code redirect, always rolls back.
# No token exchange/refresh, public HTTP listener, real callback or public host.
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
fail(){ echo "STEP34C15_BLOCKED=$1" >&2; exit 34; }

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
echo 'STEP34C15_READ_ONLY_QA_BOUNDARIES=PASS'

snapshot() {
python3 - "$MYSQL" "$CNF" <<'PY'
import hashlib,re,subprocess,sys
mysql,cnf=sys.argv[1:]
cmd=[mysql,'--defaults-extra-file='+cnf,'--batch','--skip-column-names']
def read(sql):
 p=subprocess.run(cmd+['-e',sql],stdout=subprocess.PIPE,
    stderr=subprocess.DEVNULL,timeout=25,check=False)
 if p.returncode:
  raise SystemExit('STEP34C15_BLOCKED=DATABASE_SNAPSHOT_QUERY_FAILED')
 return p.stdout.decode('utf-8').strip()
tables=read("SELECT TABLE_NAME FROM information_schema.TABLES WHERE "
 "TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_TYPE='BASE TABLE' "
 "ORDER BY TABLE_NAME").splitlines()
if len(tables)<20:
 raise SystemExit('STEP34C15_BLOCKED=UNEXPECTED_TABLE_COUNT')
h=hashlib.sha256()
q=chr(96)
for table in tables:
 if not re.fullmatch(r'[A-Za-z0-9_]+',table):
  raise SystemExit('STEP34C15_BLOCKED=UNEXPECTED_TABLE_NAME')
 count=read('SELECT COUNT(*) FROM '+q+table+q)
 if not count.isdecimal():
  raise SystemExit('STEP34C15_BLOCKED=INVALID_TABLE_ROW_COUNT')
 h.update((table+':'+count+'\n').encode('utf-8'))
print(str(len(tables))+':'+h.hexdigest())
PY
}

VENDOR="/root/bambodia_step34c13_passport_vendor_flow_contract_readonly.sh"
VENDOR_SHA="2f139bd8142220d24b58c59ab6bb1b3f46481527b512ff4c710cc4b5714e09b1"
PRIOR="/root/bambodia_step34c14_pkce_consent_page_transactional.sh"
PRIOR_SHA="c9d2399a24b339e632f9a6748eeafd154da3d5215e6aa8479ae1faed9ac4a10b"
for guard in "$VENDOR" "$PRIOR"; do
 test -f "$guard" && test ! -L "$guard" &&
 test "$(stat -c '%u:%a' "$guard")" = '0:700' ||
 fail PINNED_PRIOR_AUDIT_MISSING_OR_UNSAFE
done
test "$(sha256sum "$VENDOR" | awk '{print $1}')" = "$VENDOR_SHA" ||
 fail PINNED_VENDOR_CONTRACT_CHANGED
test "$(sha256sum "$PRIOR" | awk '{print $1}')" = "$PRIOR_SHA" ||
 fail PINNED_PRIOR_CONSENT_TEST_CHANGED
bash "$VENDOR" --audit
echo 'STEP34C15_INSTALLED_VENDOR_CONTRACT_AND_PRIOR_SCRIPT=PASS'

if [ "$MODE" = --check ]; then
 echo 'STEP34C15_READ_ONLY_PREFLIGHT=PASS'
 echo 'QA_ADMIN_OAUTH_CLIENT_AND_CONSENT_POST=NOT_STARTED'
 echo 'DATABASE_NGINX_AND_PUBLIC_SITE_WRITES=NONE'
 exit 0
fi

echo '========== SNAPSHOT ISOLATED DATABASE ROW COUNTS AND SIGNING KEYS =========='
BEFORE="$(snapshot)" || fail DATABASE_PRESTATE_SNAPSHOT_FAILED
KEYS_BEFORE="$(sha256sum "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key")" ||
 fail KEY_FINGERPRINT_READ_FAILED
echo 'ISOLATED_QA_TABLE_COUNTS_AND_KEYS=CAPTURED'

echo '========== REQUIRE NEW OPERATOR APPROVAL =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo 'Create a disposable QA admin and OAuth client in a DB transaction.'
echo 'Send in-process authenticated GET consent and POST approval with CSRF.'
echo 'Inspect code redirect to the *expected* ChatGPT callback without following it.'
echo 'Never print authorization code, cookies, credentials or HTML.'
echo 'Verify zero access/refresh tokens, and ALWAYS roll back the transaction.'
echo 'No network HTTP listener or live ChatGPT callback will be contacted.'
printf 'Type VERIFY-PRIVATE-PKCE-CODE-CONSENT-ROLLBACK: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'VERIFY-PRIVATE-PKCE-CODE-CONSENT-ROLLBACK' ||
 fail OPERATOR_DECLINED
echo 'STEP34C15_EXPLICIT_CONSENT_CODE_REHEARSAL_APPROVAL=PASS'

echo '========== PRIVATE PKCE CONSENT APPROVAL VIA HTTP KERNEL =========='
if python3 - "$PHP" "$APP" <<'PY'
import subprocess,sys
php,app=sys.argv[1:]
code=r'''
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if((string)config("app.url")!=="https://oauth-public-qa.invalid"||
   (string)config("database.default")!=="mysql"){
 throw new \RuntimeException("INERT_APP_ENV_CHANGED");
}
config(["session.driver"=>"array","cache.default"=>"array"]);
$db=\Illuminate\Support\Facades\DB::connection();
if($db->getDatabaseName()!=="bambodia_oauthqa_public"||
   $db->transactionLevel()!==0){
 throw new \RuntimeException("PRIVATE_QA_DATABASE_INVALID");
}
$verified=false;$rolledBack=false;
try {
 $db->transaction(function() use($app,$db,&$verified){
  $password=bin2hex(random_bytes(32));
  $user=\App\Models\User::create([
   "name"=>"Disposable PKCE Approval QA",
   "username"=>"pqa".bin2hex(random_bytes(10)),
   "email"=>"pkce-".bin2hex(random_bytes(12))."@example.invalid",
   "password"=>$password,
   "role"=>"super_admin","locale"=>"en","account_status"=>"active"
  ]);
  if(!$user->exists||$user->role!=="super_admin"||
    $user->account_status!=="active"||
    !\Illuminate\Support\Facades\Hash::check($password,$user->password)){
   throw new \RuntimeException("QA_SUPER_ADMIN_INVALID");
  }
  $id=(string)\Illuminate\Support\Str::uuid();
  $callback="https://chatgpt.com/connector_platform_oauth_redirect";
  $now=\Illuminate\Support\Carbon::now();
  $db->table("oauth_clients")->insert([
   "id"=>$id,"owner_type"=>$user->getMorphClass(),
   "owner_id"=>$user->getKey(),
   "name"=>"Disposable PKCE Approval Client",
   "secret"=>null,"provider"=>null,
   "redirect_uris"=>json_encode([$callback]),
   "grant_types"=>json_encode(["authorization_code","refresh_token"]),
   "revoked"=>false,
   "scopes"=>json_encode(["mcp:use","offline_access"]),
   "created_at"=>$now,"updated_at"=>$now
  ]);
  if($db->table("users")->count()!==1||
     $db->table("oauth_clients")->count()!==1){
   throw new \RuntimeException("QA_FIXTURE_COUNT_INVALID");
  }
  $verifier=rtrim(strtr(base64_encode(random_bytes(48)),"+/","-_"),"=");
  $challenge=rtrim(strtr(base64_encode(hash("sha256",$verifier,true)),"+/","-_"),"=");
  if(strlen($verifier)<43||strlen($challenge)!==43){
   throw new \RuntimeException("PKCE_S256_GENERATION_INVALID");
  }
  $state=bin2hex(random_bytes(20));
  $session=$app->make("session")->driver("array");
  $session->start();
  $guard=$app->make("auth")->guard("web");
  $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
  $common=[
   "HTTP_HOST"=>"oauth-public-qa.invalid",
   "HTTPS"=>"on","SERVER_PORT"=>"443",
   "REMOTE_ADDR"=>"127.0.0.1"
  ];
  $params=[
   "client_id"=>$id,"redirect_uri"=>$callback,
   "response_type"=>"code","state"=>$state,
   "scope"=>"mcp:use offline_access",
   "code_challenge"=>$challenge,"code_challenge_method"=>"S256",
   "resource"=>"https://oauth-public-qa.invalid/mcp"
  ];
  $get=\Illuminate\Http\Request::create(
   "https://oauth-public-qa.invalid/oauth/authorize",
   "GET",$params,[],[],
   array_merge($common,["HTTP_ACCEPT"=>"text/html"])
  );
  $get->setLaravelSession($session);
  $guard->setRequest($get);
  $guard->login($user);
  $getResponse=$kernel->handle($get);
  $getStatus=(int)$getResponse->getStatusCode();
  $html=$getResponse->getContent();
  $kernel->terminate($get,$getResponse);
  if($getStatus!==200||!is_string($html)||
     !str_contains($html,"Connect Bambodia Manager")||
     !str_contains($html,"Authorize access")||
     !str_contains($html,'name="auth_token"')||
     str_contains($html,"mcp.write")){
   throw new \RuntimeException("AUTHORIZATION_CONSENT_GET_FAILED");
  }
  $form=[];
  foreach(["auth_token","_token"] as $field){
   $pattern='/name="'.preg_quote($field,'/').'"\s+value="([^"]+)"/';
   if(preg_match($pattern,$html,$m)!==1){
    throw new \RuntimeException("CONSENT_HIDDEN_FIELDS_MISSING");
   }
   $form[$field]=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,"UTF-8");
   if($form[$field]===""||strlen($form[$field])>4096){
    throw new \RuntimeException("CONSENT_HIDDEN_FIELD_INVALID");
   }
  }
  // Never log the form token or the redirect's code.
  $post=\Illuminate\Http\Request::create(
   "https://oauth-public-qa.invalid/oauth/authorize",
   "POST",
   ["client_id"=>$id,"state"=>$state,
    "auth_token"=>$form["auth_token"],
    "_token"=>$form["_token"]],
   [],[],
   array_merge($common,["HTTP_ACCEPT"=>"text/html"])
  );
  $post->setLaravelSession($session);
  $guard->setRequest($post);
  $postResponse=$kernel->handle($post);
  $status=(int)$postResponse->getStatusCode();
  $location=(string)$postResponse->headers->get("Location","");
  $kernel->terminate($post,$postResponse);
  $url=parse_url($location);
  if(!is_array($url)||!in_array($status,[302,303],true)||
     strtolower((string)($url["scheme"]??""))!=="https"||
     strtolower((string)($url["host"]??""))!=="chatgpt.com"||
     ($url["path"]??"")!=="/connector_platform_oauth_redirect"||
     isset($url["user"])||isset($url["pass"])||isset($url["fragment"])){
   echo "CONSENT_APPROVAL_HTTP_STATUS=".$status."\n";
   echo "CONSENT_APPROVAL_REDIRECT=NOT_ACCEPTED\n";
   throw new \RuntimeException("CONSENT_APPROVAL_REDIRECT_UNEXPECTED");
  }
  $query=[];
  parse_str((string)($url["query"]??""),$query);
  if(($query["state"]??null)!==$state||
     !is_string($query["code"]??null)||
     strlen($query["code"])<10||
     isset($query["error"])){
   throw new \RuntimeException("AUTHORIZATION_CODE_OR_STATE_INVALID");
  }
  $code=$db->table("oauth_auth_codes")->first();
  if(!$code||$db->table("oauth_auth_codes")->count()!==1||
     (string)$code->client_id!==$id||
     (string)$code->user_id!==(string)$user->getKey()||
     (bool)$code->revoked){
   throw new \RuntimeException("QA_AUTHORIZATION_CODE_NOT_PERSISTED_IN_TRANSACTION");
  }
  if($db->table("oauth_access_tokens")->count()!==0||
     $db->table("oauth_refresh_tokens")->count()!==0){
   throw new \RuntimeException("QA_TOKENS_UNEXPECTED_BEFORE_EXCHANGE");
  }
  echo "AUTHENTICATED_PKCE_S256_CONSENT_GET_HTTP_STATUS=200\n";
  echo "CONSENT_APPROVAL_HTTP_STATUS=".$status."\n";
  echo "AUTHORIZATION_CODE_REDIRECT=EXPECTED_CHATGPT_CALLBACK_STATE_MATCHED\n";
  echo "AUTHORIZATION_CODE_IN_TRANSACTION=ONE\n";
  echo "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=ZERO\n";
  echo "REDIRECT_FOLLOWED_OR_CODE_PRINTED=NO\n";
  $verified=true;
  throw new \RuntimeException("QA_FORCE_ROLLBACK_AFTER_CODE_VERIFICATION");
 });
}catch(\RuntimeException $e){
 if($verified&&$e->getMessage()==="QA_FORCE_ROLLBACK_AFTER_CODE_VERIFICATION"){
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
 throw new \RuntimeException("QA_ROLLBACK_INCOMPLETE");
}
echo "DISPOSABLE_QA_ADMIN_CLIENT_AND_AUTH_CODE=ROLLED_BACK\n";
echo "PRIVATE_PKCE_CONSENT_APPROVAL_AND_CODE_ISSUANCE=PASS\n";
echo "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED\n";
'''
try:
 p=subprocess.run([php,"-d","display_errors=0","-d","log_errors=0",
  "-r",code],cwd=app,
  env={"HOME":"/root","PATH":"/usr/bin:/bin","APP_ENV":"testing"},
  stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
  stderr=subprocess.DEVNULL,timeout=85,check=False)
except subprocess.TimeoutExpired:
 raise SystemExit("STEP34C15_BLOCKED=PKCE_CONSENT_PROBE_TIMEOUT")
try:
 lines=p.stdout.decode("utf-8").splitlines()
except UnicodeDecodeError:
 raise SystemExit("STEP34C15_BLOCKED=NON_UTF8_PROBE_OUTPUT_REDACTED")
prefixes=(
 "AUTHENTICATED_PKCE_S256_CONSENT_GET_HTTP_STATUS=",
 "CONSENT_APPROVAL_HTTP_STATUS=",
 "CONSENT_APPROVAL_REDIRECT=",
 "AUTHORIZATION_CODE_REDIRECT=",
 "AUTHORIZATION_CODE_IN_TRANSACTION=",
 "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=",
 "REDIRECT_FOLLOWED_OR_CODE_PRINTED=",
 "DISPOSABLE_QA_ADMIN_CLIENT_AND_AUTH_CODE=",
 "PRIVATE_PKCE_CONSENT_APPROVAL_AND_CODE_ISSUANCE=",
 "NETWORK_LISTENER_OR_PUBLIC_ISSUER=")
if len(p.stdout)>32768 or any(len(s)>130 or not s.startswith(prefixes)
                                for s in lines):
 raise SystemExit("STEP34C15_BLOCKED=UNEXPECTED_PHP_OUTPUT_REDACTED")
for line in lines:print(line)
needed=[
 "AUTHENTICATED_PKCE_S256_CONSENT_GET_HTTP_STATUS=200",
 "AUTHORIZATION_CODE_REDIRECT=EXPECTED_CHATGPT_CALLBACK_STATE_MATCHED",
 "AUTHORIZATION_CODE_IN_TRANSACTION=ONE",
 "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=ZERO",
 "REDIRECT_FOLLOWED_OR_CODE_PRINTED=NO",
 "DISPOSABLE_QA_ADMIN_CLIENT_AND_AUTH_CODE=ROLLED_BACK",
 "PRIVATE_PKCE_CONSENT_APPROVAL_AND_CODE_ISSUANCE=PASS",
 "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED"]
if p.returncode!=0 or not all(x in lines for x in needed):
 raise SystemExit("STEP34C15_BLOCKED=CONSENT_CODE_OR_ROLLBACK_FAILED")
PY
then
  EXERCISE_RESULT=PASS
else
  EXERCISE_RESULT=FAILED
fi

echo '========== VERIFY ISOLATION EVEN AFTER EXERCISE FAILURE =========='
AFTER="$(snapshot)" || fail DATABASE_POSTSTATE_SNAPSHOT_FAILED
test "$BEFORE" = "$AFTER" || fail ISOLATED_QA_TABLE_COUNT_DRIFT
KEYS_AFTER="$(sha256sum "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key")" ||
 fail KEY_FINGERPRINT_POST_FAILED
test "$KEYS_BEFORE" = "$KEYS_AFTER" || fail PASSPORT_KEY_CONTENT_CHANGED
echo 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED'
echo 'PASSPORT_RSA_KEY_BYTES=UNCHANGED'
bash "$AUDIT" --audit || fail POST_EXERCISE_QA_READINESS_CHANGED
test "$EXERCISE_RESULT" = PASS || fail CONSENT_CODE_REHEARSAL_FAILED_BUT_ISOLATION_VERIFIED
echo 'STEP34C15_PKCE_CONSENT_APPROVAL_CODE_AND_ROLLBACK=PASS'
echo 'TOKEN_EXCHANGE_REFRESH_AND_REVOCATION=NOT_TESTED'
echo 'EXTERNAL_REDIRECT_FOLLOWED=NO'
echo 'PUBLIC_OAUTH_ISSUER_OR_HTTP_LISTENER=NOT_ACTIVATED'
echo 'NGINX_DNS_TLS_PRODUCTION_AND_GITHUB_MAIN_WRITES=NONE'
