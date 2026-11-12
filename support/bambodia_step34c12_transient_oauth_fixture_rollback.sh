#!/usr/bin/env bash
# Step 34C.12: isolated transactional OAuth fixture rehearsal.
# No persisted fixture; one admin and client are rolled back.
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
fail(){ echo "STEP34C12_BLOCKED=$1" >&2; exit 34; }

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
echo 'STEP34C12_READ_ONLY_QA_BOUNDARIES=PASS'

snapshot() {
python3 - "$MYSQL" "$CNF" <<'PY'
import hashlib,re,subprocess,sys
mysql,cnf=sys.argv[1:]
cmd=[mysql,'--defaults-extra-file='+cnf,'--batch','--skip-column-names']
def read(sql):
 p=subprocess.run(cmd+['-e',sql],stdout=subprocess.PIPE,
    stderr=subprocess.DEVNULL,timeout=25,check=False)
 if p.returncode:
  raise SystemExit('STEP34C12_BLOCKED=DATABASE_SNAPSHOT_QUERY_FAILED')
 return p.stdout.decode('utf-8').strip()
tables=read("SELECT TABLE_NAME FROM information_schema.TABLES WHERE "
 "TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_TYPE='BASE TABLE' "
 "ORDER BY TABLE_NAME").splitlines()
if len(tables)<20:
 raise SystemExit('STEP34C12_BLOCKED=UNEXPECTED_TABLE_COUNT')
h=hashlib.sha256()
q=chr(96)
for table in tables:
 if not re.fullmatch(r'[A-Za-z0-9_]+',table):
  raise SystemExit('STEP34C12_BLOCKED=UNEXPECTED_TABLE_NAME')
 count=read('SELECT COUNT(*) FROM '+q+table+q)
 if not count.isdecimal():
  raise SystemExit('STEP34C12_BLOCKED=INVALID_TABLE_ROW_COUNT')
 h.update((table+':'+count+'\n').encode('utf-8'))
print(str(len(tables))+':'+h.hexdigest())
PY
}

if [ "$MODE" = --check ]; then
 echo 'STEP34C12_READ_ONLY_PREFLIGHT=PASS'
 echo 'DISPOSABLE_ACCOUNT_OR_CLIENT=NOT_CREATED'
 echo 'DATABASE_AND_PUBLIC_HOST_WRITES=NONE'
 exit 0
fi

echo '========== SNAPSHOT ENTIRE ISOLATED QA SCHEMA =========='
BEFORE="$(snapshot)" || fail DATABASE_PRESTATE_SNAPSHOT_FAILED
echo 'ISOLATED_QA_ALL_TABLE_COUNTS_SNAPSHOT=CAPTURED'

echo '========== APPROVE TRANSIENT AND ROLLBACK-ONLY DB WRITES =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo 'An isolated disposable admin and OAuth client will be inserted temporarily.'
echo 'Both will be rolled back in the same MySQL transaction.'
echo 'No real credentials, public hosting, OAuth token issuance or clients persist.'
echo 'MySQL auto-increment counters can advance despite rollback.'
printf 'Type EXERCISE-TRANSIENT-OAUTH-ADMIN-CLIENT-ROLLBACK: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'EXERCISE-TRANSIENT-OAUTH-ADMIN-CLIENT-ROLLBACK' ||
 fail OPERATOR_DECLINED
echo 'STEP34C12_EXPLICIT_ISOLATED_TRANSACTION_APPROVAL=PASS'

echo '========== RUN DISPOSABLE SUPER-ADMIN AND CLIENT DB TRANSACTION =========='
if python3 - "$PHP" "$APP" <<'PY'
import subprocess,sys
php,app=sys.argv[1:]
source=r'''
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::connection();
if($db->getDatabaseName()!=="bambodia_oauthqa_public"
 || $db->transactionLevel()!==0){
  throw new \RuntimeException("INVALID_PRIVATE_DATABASE");
}
$uuid=(string)\Illuminate\Support\Str::uuid();
$email="qa-".bin2hex(random_bytes(12))."@example.invalid";
$username="qa".bin2hex(random_bytes(10));
$password=bin2hex(random_bytes(32));
$confirmed=false;
$rolledBack=false;
try {
 $db->transaction(function() use ($db,$uuid,$email,$username,$password,&$confirmed) {
  $user=\App\Models\User::create([
   "name"=>"Disposable OAuth QA Fixture",
   "username"=>$username,"email"=>$email,"password"=>$password,
   "role"=>"super_admin","locale"=>"en","account_status"=>"active"
  ]);
  if(!$user->exists||$user->role!=="super_admin"||
     $user->account_status!=="active"||
     !\Illuminate\Support\Facades\Hash::check($password,$user->password)||
     !is_string($user->public_id)||strlen($user->public_id)!==8) {
    throw new \RuntimeException("TEMPORARY_SUPER_ADMIN_INVALID");
  }
  $now=\Illuminate\Support\Carbon::now();
  $db->table("oauth_clients")->insert([
   "id"=>$uuid,"owner_type"=>$user->getMorphClass(),
   "owner_id"=>$user->getKey(),
   "name"=>"Disposable OAuth QA Client",
   "secret"=>null,"provider"=>null,
   "redirect_uris"=>json_encode(["https://chatgpt.com/connector_platform_oauth_redirect"]),
   "grant_types"=>json_encode(["authorization_code","refresh_token"]),
   "revoked"=>false,
   "scopes"=>json_encode(["mcp:use","offline_access"]),
   "created_at"=>$now,"updated_at"=>$now
  ]);
  $client=$db->table("oauth_clients")->where("id",$uuid)->first();
  if(!$client||$client->owner_type!==$user->getMorphClass()||
     (string)$client->owner_id!==(string)$user->getKey()||
     json_decode($client->scopes,true)!==["mcp:use","offline_access"]||
     json_decode($client->grant_types,true)!==["authorization_code","refresh_token"]||
     $client->secret!==null ||
     $db->table("users")->count()!==1||
     $db->table("oauth_clients")->count()!==1) {
    throw new \RuntimeException("TEMPORARY_OAUTH_CLIENT_INVALID");
  }
  $confirmed=true;
  throw new \RuntimeException("ROLLBACK_DISPOSABLE_QA_FIXTURE");
 });
} catch (\RuntimeException $exception) {
 if($confirmed&&$exception->getMessage()==="ROLLBACK_DISPOSABLE_QA_FIXTURE"){
  $rolledBack=true;
 }else{
  throw $exception;
 }
}
if(!$rolledBack||$db->transactionLevel()!==0||
   $db->table("users")->count()!==0||
   $db->table("oauth_clients")->count()!==0||
   $db->table("oauth_auth_codes")->count()!==0||
   $db->table("oauth_access_tokens")->count()!==0||
   $db->table("oauth_refresh_tokens")->count()!==0) {
 throw new \RuntimeException("ROLLBACK_VERIFICATION_FAILED");
}
echo "DISPOSABLE_SUPER_ADMIN=CREATED_THEN_ROLLED_BACK\n";
echo "DISPOSABLE_OAUTH_CLIENT=CREATED_THEN_ROLLED_BACK\n";
echo "CLIENT_SCOPES=READ_ONLY_AND_REFRESH_ONLY\n";
echo "MCP_WRITE_SCOPE=NOT_GRANTED\n";
echo "MYSQL_ISOLATED_TRANSACTION=ROLLED_BACK\n";
echo "CREDENTIALS_IDS_AND_SIGNING_KEYS_PRINTED=NO\n";
'''
try:
 p=subprocess.run([php,'-d','display_errors=0','-d','log_errors=0',
   '-r',source],cwd=app,
   env={'HOME':'/root','PATH':'/usr/bin:/bin','APP_ENV':'testing'},
   stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
   stderr=subprocess.DEVNULL,timeout=65,check=False)
except subprocess.TimeoutExpired:
 raise SystemExit('STEP34C12_BLOCKED=PHP_DISPOSABLE_TEST_TIMEOUT')
expected=[
 'DISPOSABLE_SUPER_ADMIN=CREATED_THEN_ROLLED_BACK',
 'DISPOSABLE_OAUTH_CLIENT=CREATED_THEN_ROLLED_BACK',
 'CLIENT_SCOPES=READ_ONLY_AND_REFRESH_ONLY',
 'MCP_WRITE_SCOPE=NOT_GRANTED',
 'MYSQL_ISOLATED_TRANSACTION=ROLLED_BACK',
 'CREDENTIALS_IDS_AND_SIGNING_KEYS_PRINTED=NO'
]
if p.returncode!=0 or len(p.stdout)>16384:
 raise SystemExit('STEP34C12_BLOCKED=PHP_TRANSACTION_PROBE_FAILED_REDACTED')
try:
 got=p.stdout.decode('utf-8').splitlines()
except UnicodeDecodeError:
 raise SystemExit('STEP34C12_BLOCKED=PHP_OUTPUT_INVALID')
if got!=expected:
 raise SystemExit('STEP34C12_BLOCKED=PHP_TRANSACTION_OUTPUT_MISMATCH')
for line in got:print(line)
PY
then
  TRANSACTION_RESULT=PASS
else
  TRANSACTION_RESULT=FAILED
fi

echo '========== VERIFY ALL DATABASE COUNTS AND INDEPENDENT ACCEPTANCE =========='
AFTER="$(snapshot)" || fail DATABASE_POSTSTATE_SNAPSHOT_FAILED
test "$BEFORE" = "$AFTER" || fail ISOLATED_QA_DATABASE_ROW_COUNT_DRIFT
echo 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED'
bash "$AUDIT" --audit || fail POST_EXERCISE_QA_BOUNDARIES_CHANGED
test "$TRANSACTION_RESULT" = PASS || fail TRANSACTION_PROBE_FAILED_BUT_COUNTS_VERIFIED
echo 'STEP34C12_DISPOSABLE_OAUTH_FIXTURE_ROLLBACK=PASS'
echo 'ISOLATED_ADMIN_CLIENT_AND_TOKENS=ZERO'
echo 'PUBLIC_OAUTH_ISSUER_OR_LISTENER=NOT_ACTIVATED'
echo 'PKCE_CODE_TOKEN_REFRESH=NOT_TESTED'
echo 'NGINX_DNS_TLS_AND_PRODUCTION_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PR_MERGE=UNCHANGED'
