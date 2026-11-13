#!/usr/bin/env bash
# Step 34C.18: private explicit access-token revocation and MCP bearer denial.
# Read-only --check; --exercise creates transient QA admin/client, GETs consent,
# then POSTs approval and validates code redirect, always rolls back.
# Token creation is transient inside the same rollback-only QA transaction.
# Extends accepted private refresh rotation with in-process MCP bearer checks.
# Revoke only transient rotated access token, then force transaction rollback.
# No public browser/issuer or Nginx changes.
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
fail(){ echo "STEP34C18_BLOCKED=$1" >&2; exit 34; }

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
echo 'STEP34C18_READ_ONLY_QA_BOUNDARIES=PASS'

snapshot() {
python3 - "$MYSQL" "$CNF" <<'PY'
import hashlib,re,subprocess,sys
mysql,cnf=sys.argv[1:]
cmd=[mysql,'--defaults-extra-file='+cnf,'--batch','--skip-column-names']
def read(sql):
 p=subprocess.run(cmd+['-e',sql],stdout=subprocess.PIPE,
    stderr=subprocess.DEVNULL,timeout=25,check=False)
 if p.returncode:
  raise SystemExit('STEP34C18_BLOCKED=DATABASE_SNAPSHOT_QUERY_FAILED')
 return p.stdout.decode('utf-8').strip()
tables=read("SELECT TABLE_NAME FROM information_schema.TABLES WHERE "
 "TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_TYPE='BASE TABLE' "
 "ORDER BY TABLE_NAME").splitlines()
if len(tables)<20:
 raise SystemExit('STEP34C18_BLOCKED=UNEXPECTED_TABLE_COUNT')
h=hashlib.sha256()
q=chr(96)
for table in tables:
 if not re.fullmatch(r'[A-Za-z0-9_]+',table):
  raise SystemExit('STEP34C18_BLOCKED=UNEXPECTED_TABLE_NAME')
 count=read('SELECT COUNT(*) FROM '+q+table+q)
 if not count.isdecimal():
  raise SystemExit('STEP34C18_BLOCKED=INVALID_TABLE_ROW_COUNT')
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
PRIOR_REFRESH="/root/bambodia_step34c17_refresh_rotation_replay_rollback.sh"
PRIOR_REFRESH_SHA="a4f67321d8ce9b833711affa682b297806f9ae276b6470dd5b08b0f0dc4a60e5"
test -f "$PRIOR_REFRESH" && test ! -L "$PRIOR_REFRESH" &&
  test "$(stat -c '%u:%a' "$PRIOR_REFRESH")" = '0:700' &&
  test "$(sha256sum "$PRIOR_REFRESH" | awk '{print $1}')" = "$PRIOR_REFRESH_SHA" ||
  fail PRIOR_REFRESH_ROTATION_SCRIPT_CHANGED_OR_MISSING
PRIOR_TOKEN="/root/bambodia_step34c16_pkce_token_exchange_rollback.sh"
PRIOR_TOKEN_SHA="4e2bfbd6024f24f80d41393b23429b7a5ede2678c487ecc357493d86ed48f5e7"
test -f "$PRIOR_TOKEN" && test ! -L "$PRIOR_TOKEN" &&
  test "$(stat -c '%u:%a' "$PRIOR_TOKEN")" = '0:700' &&
  test "$(sha256sum "$PRIOR_TOKEN" | awk '{print $1}')" = "$PRIOR_TOKEN_SHA" ||
  fail PRIOR_PKCE_TOKEN_SCRIPT_CHANGED_OR_MISSING
echo '========== STATIC SYNTAX CHECK EMBEDDED REFRESH PROBE =========='
python3 - "$0" "$PHP" <<'PY'
import pathlib,subprocess,sys
path,php=sys.argv[1:]
source=pathlib.Path(path).read_text(encoding='utf-8')
marker="code=r"+"'"*3
if source.count(marker)!=1:
 raise SystemExit('STEP34C18_BLOCKED=EMBEDDED_PHP_SOURCE_LOCATOR_INVALID')
code=source.split(marker,1)[1].split("\n"+"'"*3,1)[0]
if not code or len(code)>128000:
 raise SystemExit('STEP34C18_BLOCKED=EMBEDDED_PHP_SOURCE_EMPTY_OR_LONG')
proc=subprocess.run([php,'-l'],input=b'<?php\n'+code.encode('utf-8'),
 stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,check=False,timeout=20)
if proc.returncode!=0:
 raise SystemExit('STEP34C18_BLOCKED=EMBEDDED_PHP_SYNTAX_INVALID')
print('STEP34C18_EMBEDDED_PHP_SYNTAX=PASS')
PY
PRIOR_CODE="/root/bambodia_step34c15_pkce_code_approval_rollback.sh"
PRIOR_CODE_SHA="4015de7712f250a05db53d0210761615baf44be2aea631b9501df69394f4f351"
test -f "$PRIOR_CODE" && test ! -L "$PRIOR_CODE" &&
 test "$(stat -c '%u:%a' "$PRIOR_CODE")" = "0:700" &&
 test "$(sha256sum "$PRIOR_CODE" | awk '{print $1}')" = "$PRIOR_CODE_SHA" ||
 fail PRIOR_CODE_ISSUANCE_SCRIPT_CHANGED_OR_MISSING
echo 'STEP34C18_INSTALLED_VENDOR_CONTRACT_AND_PRIOR_SCRIPT=PASS'

if [ "$MODE" = --check ]; then
 echo 'STEP34C18_READ_ONLY_PREFLIGHT=PASS'
 echo 'QA_ADMIN_CLIENT_CODES_AND_TOKENS=NOT_CREATED'
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
echo 'Do not print authorization codes, JWTs, refresh tokens, cookies or credentials.'
echo 'Verify valid scoped bearer passes MCP authentication, revoke rotated access token, then reject its replay.'
echo 'No network HTTP listener or live ChatGPT callback will be contacted.'
printf 'Type REVOKE-PRIVATE-OAUTH-ACCESS-BEARER-ROLLBACK: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'REVOKE-PRIVATE-OAUTH-ACCESS-BEARER-ROLLBACK' ||
 fail OPERATOR_DECLINED
echo 'STEP34C18_EXPLICIT_ACCESS_REVOCATION_APPROVAL=PASS'

echo '========== PRIVATE OAUTH ACCESS TOKEN REVOCATION AND BEARER TEST =========='
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
  // Existing Step34C.15 checks proved one valid code in this transaction.
  // Exercise the code at the private token endpoint with the matching S256
  // verifier, preserving client, callback, RFC8707 resource and no secrets out.
  $tokenRequest=\Illuminate\Http\Request::create(
   "https://oauth-public-qa.invalid/oauth/token","POST",
   ["grant_type"=>"authorization_code","client_id"=>$id,
    "redirect_uri"=>$callback,"code"=>$query["code"],
    "code_verifier"=>$verifier,
    "resource"=>"https://oauth-public-qa.invalid/mcp"],
   [],[],
   array_merge($common,[
    "HTTP_ACCEPT"=>"application/json",
    "CONTENT_TYPE"=>"application/x-www-form-urlencoded"
   ])
  );
  $tokenResponse=$kernel->handle($tokenRequest);
  $tokenStatus=(int)$tokenResponse->getStatusCode();
  $body=$tokenResponse->getContent();
  $kernel->terminate($tokenRequest,$tokenResponse);
  $tokens=is_string($body)?json_decode($body,true):null;
  if($tokenStatus!==200||!is_array($tokens)||
     !is_string($tokens["access_token"]??null)||
     !is_string($tokens["refresh_token"]??null)||
     strlen($tokens["access_token"])<100||
     strlen($tokens["refresh_token"])<30||
     strtolower((string)($tokens["token_type"]??""))!=="bearer"||
     !is_numeric($tokens["expires_in"]??null)||
     (int)$tokens["expires_in"]<=0){
    echo "PKCE_S256_CODE_EXCHANGE_HTTP_STATUS=".$tokenStatus."\n";
    throw new \RuntimeException("QA_OAUTH_CODE_EXCHANGE_FAILED");
  }
  $parts=explode(".",$tokens["access_token"]);
  if(count($parts)!==3)throw new \RuntimeException("JWT_PART_COUNT_INVALID");
  $decode=static function(string $part):string{
   $out=base64_decode(strtr($part,"-_","+/").
     str_repeat("=",(4-strlen($part)%4)%4),true);
   if(!is_string($out))throw new \RuntimeException("JWT_BASE64_INVALID");
   return $out;
  };
  $head=json_decode($decode($parts[0]),true);
  $claims=json_decode($decode($parts[1]),true);
  $sig=$decode($parts[2]);
  if(!is_array($head)||!is_array($claims)||
     ($head["alg"]??null)!=="RS256"||
     openssl_verify($parts[0].".".$parts[1],$sig,
       file_get_contents(storage_path("oauth-public.key")),
       OPENSSL_ALGO_SHA256)!==1){
   throw new \RuntimeException("JWT_RSA_SIGNATURE_NOT_VERIFIED");
  }
  $audiences=$claims["aud"]??[];
  if(is_string($audiences))$audiences=[$audiences];
  if(!is_array($audiences)||
    !in_array($id,$audiences,true)||
    !in_array(\App\Support\Mcp\McpResource::uri(),$audiences,true)||
    ($claims["iss"]??null)!==\App\Support\Mcp\McpResource::issuer()||
    (string)($claims["sub"]??"")!==(string)$user->getKey()||
    !is_numeric($claims["exp"]??null)||
    (int)$claims["exp"]<=time()||
    !is_string($claims["jti"]??null)){
   throw new \RuntimeException("SIGNED_JWT_ISSUER_AUDIENCE_SUBJECT_EXP_INVALID");
  }
  $access=$db->table("oauth_access_tokens")->where("id",$claims["jti"])->first();
  $refresh=$db->table("oauth_refresh_tokens")->first();
  if(!$access||!$refresh||
    $db->table("oauth_access_tokens")->count()!==1||
    $db->table("oauth_refresh_tokens")->count()!==1||
    (string)$access->client_id!==$id||
    (string)$access->user_id!==(string)$user->getKey()||
    (string)$refresh->access_token_id!==(string)$access->id||
    (bool)$access->revoked||(bool)$refresh->revoked){
   throw new \RuntimeException("IN_TRANSACTION_ACCESS_REFRESH_ROW_INVALID");
  }
  $scopes=json_decode((string)$access->scopes,true);
  if(!is_array($scopes)||!in_array("mcp:use",$scopes,true)||
     in_array("mcp.write",$scopes,true)){
   throw new \RuntimeException("TOKEN_SCOPE_RESTRICTED_MCP_VALIDATION_FAILED");
  }
  // Replay the already-consumed code: a valid PKCE verifier MUST NOT
  // allow a second authorization_code grant after first successful exchange.
  $replayRequest=\Illuminate\Http\Request::create(
    "https://oauth-public-qa.invalid/oauth/token","POST",
    ["grant_type"=>"authorization_code","client_id"=>$id,
     "redirect_uri"=>$callback,"code"=>$query["code"],
     "code_verifier"=>$verifier,
     "resource"=>"https://oauth-public-qa.invalid/mcp"],
    [],[],array_merge($common,[
      "HTTP_ACCEPT"=>"application/json",
      "CONTENT_TYPE"=>"application/x-www-form-urlencoded"
    ])
  );
  $replayResponse=$kernel->handle($replayRequest);
  $codeReplayStatus=(int)$replayResponse->getStatusCode();
  $codeReplayRaw=$replayResponse->getContent();
  $kernel->terminate($replayRequest,$replayResponse);
  $codeReplayResult=is_string($codeReplayRaw)?json_decode($codeReplayRaw,true):null;
  if($codeReplayStatus!==400||
     !is_array($codeReplayResult)||
     ($codeReplayResult["error"]??null)!=="invalid_grant"||
     $db->table("oauth_access_tokens")->count()!==1||
     $db->table("oauth_refresh_tokens")->count()!==1){
    throw new \RuntimeException("CONSUMED_AUTH_CODE_REPLAY_ACCEPTED_OR_UNEXPECTED_RESPONSE");
  }
  // Refresh with a precisely scoped, approved, inert resource.
  $refreshRequest=\Illuminate\Http\Request::create(
    "https://oauth-public-qa.invalid/oauth/token","POST",
    ["grant_type"=>"refresh_token",
     "client_id"=>$id,
     "refresh_token"=>$tokens["refresh_token"],
     "resource"=>"https://oauth-public-qa.invalid/mcp"],
    [],[],array_merge($common,[
      "HTTP_ACCEPT"=>"application/json",
      "CONTENT_TYPE"=>"application/x-www-form-urlencoded"
    ])
  );
  $refreshResponse=$kernel->handle($refreshRequest);
  $refreshStatus=(int)$refreshResponse->getStatusCode();
  $refreshRaw=$refreshResponse->getContent();
  $kernel->terminate($refreshRequest,$refreshResponse);
  $rotated=is_string($refreshRaw)?json_decode($refreshRaw,true):null;
  if($refreshStatus!==200||!is_array($rotated)||
     !is_string($rotated["access_token"]??null)||
     !is_string($rotated["refresh_token"]??null)||
     !is_numeric($rotated["expires_in"]??null)||
     (int)$rotated["expires_in"]<=0||
     strtolower((string)($rotated["token_type"]??""))!=="bearer"||
     $rotated["access_token"]===$tokens["access_token"]||
     $rotated["refresh_token"]===$tokens["refresh_token"]){
    echo "OAUTH_REFRESH_ROTATION_HTTP_STATUS=".$refreshStatus."\n";
    throw new \RuntimeException("ROTATED_OAUTH_TOKEN_RESPONSE_INVALID");
  }
  $newParts=explode(".",$rotated["access_token"]);
  if(count($newParts)!==3)throw new \RuntimeException("ROTATED_JWT_SEGMENTS_INVALID");
  $newHeader=json_decode($decode($newParts[0]),true);
  $newClaims=json_decode($decode($newParts[1]),true);
  $newSignature=$decode($newParts[2]);
  if(!is_array($newHeader)||!is_array($newClaims)||
     ($newHeader["alg"]??null)!=="RS256"||
     openssl_verify($newParts[0].".".$newParts[1],
       $newSignature,file_get_contents(storage_path("oauth-public.key")),
       OPENSSL_ALGO_SHA256)!==1){
    throw new \RuntimeException("ROTATED_JWT_RSA_SIGNATURE_FAILED");
  }
  $newAud=$newClaims["aud"]??[];
  if(is_string($newAud))$newAud=[$newAud];
  if(!is_array($newAud)||!in_array($id,$newAud,true)||
     !in_array(\App\Support\Mcp\McpResource::uri(),$newAud,true)||
     ($newClaims["iss"]??null)!==\App\Support\Mcp\McpResource::issuer()||
     (string)($newClaims["sub"]??"")!==(string)$user->getKey()||
     !is_numeric($newClaims["exp"]??null)||
     (int)$newClaims["exp"]<=time()||
     !is_string($newClaims["jti"]??null)||
     ($newClaims["jti"]??null)===$claims["jti"]){
    throw new \RuntimeException("ROTATED_JWT_AUDIENCE_ISSUER_OR_EXPIRY_INVALID");
  }
  $rotatedAccess=$db->table("oauth_access_tokens")->where("id",$newClaims["jti"])->first();
  $refreshRows=$db->table("oauth_refresh_tokens")->get();
  $oldRefreshRows=$refreshRows->where("access_token_id",$claims["jti"]);
  $newRefreshRows=$refreshRows->where("access_token_id",$newClaims["jti"]);
  if(!$rotatedAccess||(bool)$rotatedAccess->revoked||
     $db->table("oauth_access_tokens")->count()!==2||
     $refreshRows->count()!==2||
     $oldRefreshRows->count()!==1||
     $newRefreshRows->count()!==1||
     !(bool)$oldRefreshRows->first()->revoked||
     (bool)$newRefreshRows->first()->revoked||
     (string)$rotatedAccess->client_id!==$id||
     (string)$rotatedAccess->user_id!==(string)$user->getKey()){
    throw new \RuntimeException("ROTATED_REFRESH_STORAGE_REPLAY_MARKING_INVALID");
  }
  $newScopes=json_decode((string)$rotatedAccess->scopes,true);
  if(!is_array($newScopes)||!in_array("mcp:use",$newScopes,true)||
     in_array("mcp.write",$newScopes,true)||
     !in_array("offline_access",$newScopes,true)){
    throw new \RuntimeException("ROTATED_ACCESS_SCOPE_ESCALATED_OR_MISSING");
  }
  $refreshReplayRequest=\Illuminate\Http\Request::create(
    "https://oauth-public-qa.invalid/oauth/token","POST",
    ["grant_type"=>"refresh_token","client_id"=>$id,
     "refresh_token"=>$tokens["refresh_token"],
     "resource"=>"https://oauth-public-qa.invalid/mcp"],
    [],[],array_merge($common,[
      "HTTP_ACCEPT"=>"application/json",
      "CONTENT_TYPE"=>"application/x-www-form-urlencoded"
    ])
  );
  $refreshReplayResponse=$kernel->handle($refreshReplayRequest);
  $refreshReplayStatus=(int)$refreshReplayResponse->getStatusCode();
  $refreshReplayRaw=$refreshReplayResponse->getContent();
  $kernel->terminate($refreshReplayRequest,$refreshReplayResponse);
  $refreshReplayResult=is_string($refreshReplayRaw)?
    json_decode($refreshReplayRaw,true):null;
  if($refreshReplayStatus!==400||
     !is_array($refreshReplayResult)||
     ($refreshReplayResult["error"]??null)!=="invalid_grant"||
     $db->table("oauth_access_tokens")->count()!==2||
     $db->table("oauth_refresh_tokens")->count()!==2){
    throw new \RuntimeException("REVOKED_REFRESH_REPLAY_NOT_REJECTED");
  }
  // Test the protected MCP route without invoking a real MCP tool:
  // unknown method request allows authorization middleware to run while
  // forbidding tools/list or any write capability.
  $protectedProbe=static function(string $bearer) use ($app,$kernel,$common):array {
    $json=json_encode([
      "jsonrpc"=>"2.0",
      "id"=>1,
      "method"=>"qa.invalid.test-no-action"
    ],JSON_THROW_ON_ERROR);
    $request=\Illuminate\Http\Request::create(
      "https://oauth-public-qa.invalid/mcp","POST",
      [],[],[],
      array_merge($common,[
        "HTTP_ACCEPT"=>"application/json, text/event-stream",
        "CONTENT_TYPE"=>"application/json",
        "HTTP_AUTHORIZATION"=>"Bearer ".$bearer
      ]),$json
    );
    // Flush per-request guard user caches; request handling reuses one
    // Laravel kernel only because the entire DB transaction is uncommitted.
    $api=$app->make("auth")->guard("api");
    if(method_exists($api,"forgetUser"))$api->forgetUser();
    if(method_exists($api,"setRequest"))$api->setRequest($request);
    $response=$kernel->handle($request);
    $status=(int)$response->getStatusCode();
    $challenge=(string)$response->headers->get("WWW-Authenticate","");
    $kernel->terminate($request,$response);
    return [$status,strpos($challenge,"Bearer")!==false];
  };
  [$activeStatus,$activeChallenge]=$protectedProbe($rotated["access_token"]);
  // Any non-authentication response 2xx-4xx confirms middleware did not
  // reject valid bearer; 5xx and redirects are not accepted.
  if($activeStatus<200||$activeStatus>499||
     in_array($activeStatus,[401,403],true)){
    echo "ACTIVE_ACCESS_MCP_BEARER_STATUS=".$activeStatus."\n";
    throw new \RuntimeException("VALID_PRIVATE_MCP_BEARER_REJECTED");
  }
  $repository=$app->make(\Laravel\Passport\TokenRepository::class);
  $repository->revokeAccessToken($newClaims["jti"]);
  $storedRevoked=$db->table("oauth_access_tokens")->where(
    "id",$newClaims["jti"])->first();
  if(!$storedRevoked||(bool)$storedRevoked->revoked!==true){
    throw new \RuntimeException("ACCESS_TOKEN_REPOSITORY_REVOCATION_NOT_STORED");
  }
  [$revokedStatus,$revokedChallenge]=$protectedProbe($rotated["access_token"]);
  if($revokedStatus!==401||!$revokedChallenge){
    echo "REVOKED_ACCESS_MCP_BEARER_STATUS=".$revokedStatus."\n";
    throw new \RuntimeException("REVOKED_MCP_BEARER_NOT_REJECTED");
  }
  // Access-token revocation must not silently create/revoke other grants.
  if($db->table("oauth_access_tokens")->count()!==2||
     $db->table("oauth_refresh_tokens")->count()!==2||
     !(bool)$db->table("oauth_access_tokens")->where(
       "id",$newClaims["jti"])->value("revoked")){
    throw new \RuntimeException("OAUTH_TOKEN_ROWS_CHANGED_AFTER_REVOCATION");
  }
  echo "ACTIVE_ACCESS_MCP_BEARER=NOT_REJECTED_BY_AUTH_MIDDLEWARE\n";
  echo "ACTIVE_ACCESS_MCP_BEARER_STATUS=".$activeStatus."\n";
  echo "EXPLICIT_ACCESS_TOKEN_REPOSITORY_REVOCATION=PASS\n";
  echo "REVOKED_ACCESS_TOKEN_DATABASE_STATUS=REVOKED\n";
  echo "REVOKED_ACCESS_MCP_BEARER_HTTP_STATUS=401\n";
  echo "REVOKED_ACCESS_MCP_BEARER=REJECTED_WITH_BEARER_CHALLENGE\n";
  echo "ACCESS_TOKEN_VALUES_AND_MCP_RESPONSES_PRINTED=NO\n";
  echo "CONSUMED_AUTHORIZATION_CODE_REPLAY_HTTP_STATUS=400\n";
  echo "CONSUMED_AUTHORIZATION_CODE_REPLAY=REJECTED_INVALID_GRANT\n";
  echo "OAUTH_REFRESH_ROTATION_HTTP_STATUS=200\n";
  echo "ROTATED_ACCESS_TOKEN_RS256_SIGNATURE=PASS\n";
  echo "ROTATED_ISSUER_CLIENT_RESOURCE_AUDIENCE_EXPIRY=PASS\n";
  echo "ROTATED_ACCESS_TOKEN_SCOPE=READ_ONLY_MCP_USE\n";
  echo "OLD_REFRESH_TOKEN_DB_STATUS=REVOKED\n";
  echo "NEW_REFRESH_TOKEN_DB_STATUS=ACTIVE\n";
  echo "OLD_REFRESH_TOKEN_REPLAY_HTTP_STATUS=400\n";
  echo "OLD_REFRESH_TOKEN_REPLAY=REJECTED_INVALID_GRANT\n";
  echo "REFRESH_TOKEN_VALUES_OR_JWTS_PRINTED=NO\n";
  echo "PKCE_S256_CODE_EXCHANGE_HTTP_STATUS=200\n";
  echo "PASSPORT_SIGNED_ACCESS_TOKEN_RS256=PASS\n";
  echo "PASSPORT_SIGNED_ISSUER_RESOURCE_AUDIENCE_EXPIRY=PASS\n";
  echo "ACCESS_TOKEN_SCOPE=READ_ONLY_MCP_USE\n";
  echo "ISSUED_ACCESS_AND_REFRESH_ROWS=TWO_EACH_AFTER_REFRESH\n";
  echo "RAW_ACCESS_REFRESH_TOKENS_AND_CODE_PRINTED=NO\n";
  echo "AUTHENTICATED_PKCE_S256_CONSENT_GET_HTTP_STATUS=200\n";
  echo "CONSENT_APPROVAL_HTTP_STATUS=".$status."\n";
  echo "AUTHORIZATION_CODE_REDIRECT=EXPECTED_CHATGPT_CALLBACK_STATE_MATCHED\n";
  echo "AUTHORIZATION_CODE_IN_TRANSACTION=ONE\n";
  echo "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=TWO_EACH_AFTER_REFRESH\n";
  echo "REDIRECT_FOLLOWED_OR_CODE_PRINTED=NO\n";
  $verified=true;
  throw new \RuntimeException("QA_FORCE_ROLLBACK_AFTER_ACCESS_REVOCATION");
 });
}catch(\RuntimeException $e){
 if($verified&&$e->getMessage()==="QA_FORCE_ROLLBACK_AFTER_ACCESS_REVOCATION"){
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
echo "DISPOSABLE_QA_ADMIN_CLIENT_CODE_ACCESS_REFRESH=ROLLED_BACK\n";
echo "PRIVATE_OAUTH_ACCESS_REVOCATION_AND_BEARER_REJECTION=PASS\n";
echo "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED\n";
'''
try:
 p=subprocess.run([php,"-d","display_errors=0","-d","log_errors=0",
  "-r",code],cwd=app,
  env={"HOME":"/root","PATH":"/usr/bin:/bin","APP_ENV":"testing"},
  stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
  stderr=subprocess.DEVNULL,timeout=85,check=False)
except subprocess.TimeoutExpired:
 raise SystemExit("STEP34C18_BLOCKED=PKCE_CONSENT_PROBE_TIMEOUT")
try:
 lines=p.stdout.decode("utf-8").splitlines()
except UnicodeDecodeError:
 raise SystemExit("STEP34C18_BLOCKED=NON_UTF8_PROBE_OUTPUT_REDACTED")
prefixes=(
 "AUTHENTICATED_PKCE_S256_CONSENT_GET_HTTP_STATUS=",
 "CONSENT_APPROVAL_HTTP_STATUS=",
 "CONSENT_APPROVAL_REDIRECT=",
 "AUTHORIZATION_CODE_REDIRECT=",
 "AUTHORIZATION_CODE_IN_TRANSACTION=",
 "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=",
 "PKCE_S256_CODE_EXCHANGE_HTTP_STATUS=",
 "PASSPORT_SIGNED_ACCESS_TOKEN_RS256=",
 "PASSPORT_SIGNED_ISSUER_RESOURCE_AUDIENCE_EXPIRY=",
 "ACCESS_TOKEN_SCOPE=",
 "ISSUED_ACCESS_AND_REFRESH_ROWS=",
 "RAW_ACCESS_REFRESH_TOKENS_AND_CODE_PRINTED=",
 "REDIRECT_FOLLOWED_OR_CODE_PRINTED=",
 "DISPOSABLE_QA_ADMIN_CLIENT_CODE_ACCESS_REFRESH=",
 "PRIVATE_OAUTH_REFRESH_ROTATION_REPLAY_PROTECTION=",
 "CONSUMED_AUTHORIZATION_CODE_REPLAY_HTTP_STATUS=",
 "CONSUMED_AUTHORIZATION_CODE_REPLAY=",
 "OAUTH_REFRESH_ROTATION_HTTP_STATUS=",
 "ROTATED_ACCESS_TOKEN_RS256_SIGNATURE=",
 "ROTATED_ISSUER_CLIENT_RESOURCE_AUDIENCE_EXPIRY=",
 "ROTATED_ACCESS_TOKEN_SCOPE=",
 "OLD_REFRESH_TOKEN_DB_STATUS=",
 "NEW_REFRESH_TOKEN_DB_STATUS=",
 "OLD_REFRESH_TOKEN_REPLAY_HTTP_STATUS=",
 "OLD_REFRESH_TOKEN_REPLAY=",
 "REFRESH_TOKEN_VALUES_OR_JWTS_PRINTED=",
 "ACTIVE_ACCESS_MCP_BEARER=",
 "ACTIVE_ACCESS_MCP_BEARER_STATUS=",
 "EXPLICIT_ACCESS_TOKEN_REPOSITORY_REVOCATION=",
 "REVOKED_ACCESS_TOKEN_DATABASE_STATUS=",
 "REVOKED_ACCESS_MCP_BEARER_HTTP_STATUS=",
 "REVOKED_ACCESS_MCP_BEARER=",
 "ACCESS_TOKEN_VALUES_AND_MCP_RESPONSES_PRINTED=",
 "NETWORK_LISTENER_OR_PUBLIC_ISSUER=")
if len(p.stdout)>32768 or any(len(s)>130 or not s.startswith(prefixes)
                                for s in lines):
 raise SystemExit("STEP34C18_BLOCKED=UNEXPECTED_PHP_OUTPUT_REDACTED")
for line in lines:print(line)
needed=[
 "AUTHENTICATED_PKCE_S256_CONSENT_GET_HTTP_STATUS=200",
 "AUTHORIZATION_CODE_REDIRECT=EXPECTED_CHATGPT_CALLBACK_STATE_MATCHED",
 "AUTHORIZATION_CODE_IN_TRANSACTION=ONE",
 "ACCESS_AND_REFRESH_TOKENS_IN_TRANSACTION=TWO_EACH_AFTER_REFRESH",
 "CONSUMED_AUTHORIZATION_CODE_REPLAY_HTTP_STATUS=400",
 "CONSUMED_AUTHORIZATION_CODE_REPLAY=REJECTED_INVALID_GRANT",
 "OAUTH_REFRESH_ROTATION_HTTP_STATUS=200",
 "ROTATED_ACCESS_TOKEN_RS256_SIGNATURE=PASS",
 "ROTATED_ISSUER_CLIENT_RESOURCE_AUDIENCE_EXPIRY=PASS",
 "ROTATED_ACCESS_TOKEN_SCOPE=READ_ONLY_MCP_USE",
 "OLD_REFRESH_TOKEN_DB_STATUS=REVOKED",
 "NEW_REFRESH_TOKEN_DB_STATUS=ACTIVE",
 "OLD_REFRESH_TOKEN_REPLAY_HTTP_STATUS=400",
 "OLD_REFRESH_TOKEN_REPLAY=REJECTED_INVALID_GRANT",
 "REFRESH_TOKEN_VALUES_OR_JWTS_PRINTED=NO",
 "PKCE_S256_CODE_EXCHANGE_HTTP_STATUS=200",
 "PASSPORT_SIGNED_ACCESS_TOKEN_RS256=PASS",
 "PASSPORT_SIGNED_ISSUER_RESOURCE_AUDIENCE_EXPIRY=PASS",
 "ACCESS_TOKEN_SCOPE=READ_ONLY_MCP_USE",
 "ISSUED_ACCESS_AND_REFRESH_ROWS=TWO_EACH_AFTER_REFRESH",
 "RAW_ACCESS_REFRESH_TOKENS_AND_CODE_PRINTED=NO",
 "REDIRECT_FOLLOWED_OR_CODE_PRINTED=NO",
 "DISPOSABLE_QA_ADMIN_CLIENT_CODE_ACCESS_REFRESH=ROLLED_BACK",
 "PRIVATE_OAUTH_ACCESS_REVOCATION_AND_BEARER_REJECTION=PASS",
 "ACTIVE_ACCESS_MCP_BEARER=NOT_REJECTED_BY_AUTH_MIDDLEWARE",
 "EXPLICIT_ACCESS_TOKEN_REPOSITORY_REVOCATION=PASS",
 "REVOKED_ACCESS_TOKEN_DATABASE_STATUS=REVOKED",
 "REVOKED_ACCESS_MCP_BEARER_HTTP_STATUS=401",
 "REVOKED_ACCESS_MCP_BEARER=REJECTED_WITH_BEARER_CHALLENGE",
 "ACCESS_TOKEN_VALUES_AND_MCP_RESPONSES_PRINTED=NO",
 "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED"]
if p.returncode!=0 or not all(x in lines for x in needed):
 raise SystemExit("STEP34C18_BLOCKED=CONSENT_CODE_OR_ROLLBACK_FAILED")
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
echo 'STEP34C18_PRIVATE_ACCESS_REVOCATION_AND_ROLLBACK=PASS'
echo 'REVOKED_ACCESS_TOKEN_REJECTION=TESTED_IN_PRIVATE_KERNEL_ONLY'
echo 'EXTERNAL_REDIRECT_FOLLOWED=NO'
echo 'PUBLIC_OAUTH_ISSUER_OR_HTTP_LISTENER=NOT_ACTIVATED'
echo 'NGINX_DNS_TLS_PRODUCTION_AND_GITHUB_MAIN_WRITES=NONE'
