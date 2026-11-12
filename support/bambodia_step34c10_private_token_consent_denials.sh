#!/usr/bin/env bash
# Step 34C.10: private PHP85 in-process OAuth token/consent rejection checks.
# --check checks read-only boundary. --probe requires operator approval.
# HTTP kernel may write root-private log/session/cache metadata; database writes,
# OAuth admin/client/grant creation, hosting and Nginx changes prohibited.
set -Eeuo pipefail
set +x
umask 077
MODE="$1"
case "$MODE" in --check|--probe) ;; *)
  echo 'Usage: script --check|--probe' >&2; exit 64 ;;
esac

APP="/var/lib/bambodia-oauth-public-qa"
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
PHP="/www/server/php/85/bin/php"
MYSQL="/www/server/mysql/bin/mysql"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="06806f2913c3150cf430482124a23f760dfb60d3c47dc587700762d32b2a4a6a"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
fail() { printf 'STEP34C10_BLOCKED=%s\n' "$1" >&2; exit 34; }

echo '========== VERIFY PRIVATE APPLICATION AND ORIGINAL BOUNDARIES =========='
test "$(id -u)" = 0 || fail ROOT_REQUIRED
for bin in git python3 sha256sum stat realpath openssl; do
  command -v "$bin" >/dev/null || fail REQUIRED_TOOL_MISSING
done
test -x "$PHP" && test -x "$MYSQL" || fail PHP85_OR_MYSQL_MISSING
"$PHP" -r 'if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==5){exit(13);}echo "PHP85_RUNTIME=VERIFIED\n";' ||
  fail PHP85_VERSION_MISMATCH
test -d "$APP" && test ! -L "$APP" &&
  test "$(realpath -e "$APP")" = "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
  fail ISOLATED_PRIVATE_APP_ROOT_UNSAFE
test -f "$APP/.env" && test ! -L "$APP/.env" &&
  test "$(stat -c '%u:%a' "$APP/.env")" = '0:600' ||
  fail ROOT_PRIVATE_ENV_UNSAFE
test -f "$APP/vendor/autoload.php" && test ! -L "$APP/vendor/autoload.php" &&
  test -f "$APP/artisan" &&
  test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail LOCK_OR_LARAVEL_DEPENDENCIES_CHANGED
test ! -e "$APP/bootstrap/cache/config.php" ||
  fail CONFIG_CACHE_DETECTED
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_INTERNAL_QA_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail OCCUPIED_VHOST_CHANGED
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_PUBLIC_SITE_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail PRESERVATION_BACKUP_UNSAFE
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail PRESERVATION_BACKUP_CHANGED
for cnf in "$PUB_CNF" "$QA_CNF"; do
  test -f "$cnf" && test ! -L "$cnf" &&
  test "$(stat -c '%u:%a' "$cnf")" = '0:600' ||
  fail MYSQL_CREDENTIAL_FILE_CHANGED
done
echo 'FROZEN_AND_PUBLIC_WEBSITE_BASELINES=PASS'
echo 'ROOT_PRIVATE_APP_AND_VENDOR=PASS'
echo 'PUBLIC_OAUTH_HOSTNAME_REUSE=NOT_APPROVED'

echo '========== VERIFY PRIVATE CONFIG AND MATCHED PASSPORT KEYS =========='
python3 - "$APP/.env" "$PUB_CNF" "$APP/storage" <<'PY'
import base64,configparser,pathlib,stat,subprocess,sys
p,cnf,storage=pathlib.Path(sys.argv[1]),sys.argv[2],pathlib.Path(sys.argv[3])
c=configparser.ConfigParser(interpolation=None)
with open(cnf,encoding='utf-8') as f:c.read_file(f)
env={}
for line in p.read_text(encoding='utf-8').splitlines():
    if not line or line.startswith('#'):continue
    assert '=' in line
    k,v=line.split('=',1)
    assert k not in env
    env[k]=v
expect={
  'APP_ENV':'testing','APP_DEBUG':'false',
  'APP_URL':'https://oauth-public-qa.invalid',
  'DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1',
  'DB_DATABASE':'bambodia_oauthqa_public','DB_USERNAME':'bambodiaqa_public',
  'MAIL_MAILER':'array','SESSION_DRIVER':'file','CACHE_STORE':'file',
  'QUEUE_CONNECTION':'sync','SESSION_SECURE_COOKIE':'true'
}
if any(env.get(k)!=v for k,v in expect.items()):
    raise SystemExit('STEP34C10_BLOCKED=QA_APP_ENV_SCOPE_MISMATCH')
if env.get('DB_PASSWORD')!=c['client']['password']:
    raise SystemExit('STEP34C10_BLOCKED=ISOLATED_DB_PASSWORD_CHANGED')
if not env['APP_KEY'].startswith('base64:') or \
   len(base64.b64decode(env['APP_KEY'][7:],validate=True))!=32:
    raise SystemExit('STEP34C10_BLOCKED=APP_KEY_MISSING_OR_INVALID')
private=storage/'oauth-private.key';public=storage/'oauth-public.key'
for path in (private,public):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 \
       or stat.S_IMODE(st.st_mode)!=0o600 or st.st_size==0:
        raise SystemExit('STEP34C10_BLOCKED=PASSPORT_KEY_METADATA_INVALID')
def pub_der(path,pub):
    args=['openssl','pkey']
    if pub:args.append('-pubin')
    args+=['-in',str(path),'-pubout','-outform','DER']
    o=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                    timeout=20,check=False)
    if o.returncode or not o.stdout:
        raise SystemExit('STEP34C10_BLOCKED=PASSPORT_KEY_PARSE_INVALID')
    return o.stdout
if pub_der(private,False)!=pub_der(public,True):
    raise SystemExit('STEP34C10_BLOCKED=PASSPORT_KEYPAIR_MISMATCH')
print('ISOLATED_INVALID_ISSUER_AND_MAIL_ARRAY=PASS')
print('ROOT_PRIVATE_APP_KEY=VALID_NOT_DISCLOSED')
print('PASSPORT_KEYPAIR_ROOT_0600_AND_MATCHED=PASS')
PY

echo '========== VERIFY EXISTING ISOLATED SCHEMA WITHOUT WRITES =========='
PUB_MYSQL(){ "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"; }
QA_MYSQL(){ "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
  --host=127.0.0.1 --batch --skip-column-names bambodia_oauthqa_browser "$@"; }
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' ||
  fail ISOLATED_MYSQL_PRINCIPAL_MISMATCH
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail ISOLATED_DB_NAME_CHANGED
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM migrations')" = 67 ||
  fail ISOLATED_QA_MIGRATIONS_CHANGED
for t in users oauth_clients oauth_access_tokens oauth_refresh_tokens oauth_auth_codes; do
  test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM $t")" = 0 ||
    fail ISOLATED_QA_OAUTH_DATA_UNEXPECTED
done
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_ACCOUNT_BASELINE_CHANGED
for t in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $t WHERE revoked=0")" = 0 ||
    fail FROZEN_QA_ACTIVE_OAUTH_GRANTS
done
echo 'ISOLATED_QA_MIGRATIONS=67'
echo 'ISOLATED_QA_AUTHORIZATION_FIXTURES=NONE'
echo 'FROZEN_QA_ACCOUNT_BASELINE=PASS'

if [ "$MODE" = '--check' ]; then
  echo 'STEP34C10_READ_ONLY_PREFLIGHT=PASS'
  echo 'HTTP_REQUESTS_DISPATCHED=NONE'
  echo 'DATABASE_AND_PUBLIC_SITE_WRITES=NONE'
  exit 0
fi

snapshot_db() {
python3 - "$MYSQL" "$PUB_CNF" <<'PY'
import hashlib,re,subprocess,sys
mysql,cnf=sys.argv[1:]
base=[mysql,'--defaults-extra-file='+cnf,'--batch','--skip-column-names']
def query(sql):
    p=subprocess.run(base+['-e',sql],stdout=subprocess.PIPE,
                     stderr=subprocess.DEVNULL,check=True,timeout=30)
    return p.stdout.decode('utf-8').strip()
names=query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME").splitlines()
if len(names)<20:
    raise SystemExit('STEP34C10_BLOCKED=DATABASE_TABLE_COUNT_UNEXPECTED')
h=hashlib.sha256()
q=chr(96)
for name in names:
    if not re.fullmatch(r'[A-Za-z0-9_]+',name):
        raise SystemExit('STEP34C10_BLOCKED=UNSAFE_TABLE_NAME')
    value=query('SELECT COUNT(*) FROM '+q+name+q)
    if not value.isdecimal():
        raise SystemExit('STEP34C10_BLOCKED=INVALID_ROW_COUNT')
    h.update((name+':'+value+'\n').encode('utf-8'))
print(str(len(names))+':'+h.hexdigest())
PY
}

echo '========== CAPTURE ISOLATED DATABASE COUNTS BEFORE REQUESTS =========='
BEFORE="$(snapshot_db)" || fail PREPROBE_DATABASE_SNAPSHOT_FAILED
echo 'ISOLATED_QA_TABLE_COUNT_SNAPSHOT=CAPTURED'

echo '========== EXPLICIT PRIVATE OAUTH NEGATIVE-CASE APPROVAL =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo 'Dispatch only negative OAuth/token/consent and invalid bearer cases.'
echo 'All requests run inside the private PHP 8.5 Laravel kernel, NO socket.'
echo 'No administrator fixture, client registration, token grant or email.'
echo 'The framework may update root-private session/cache/log files only.'
printf 'Type TEST-PRIVATE-OAUTH-TOKEN-AND-CONSENT-DENIALS: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'TEST-PRIVATE-OAUTH-TOKEN-AND-CONSENT-DENIALS' ||
  fail OPERATOR_DECLINED
echo 'STEP34C10_EXPLICIT_NEGATIVE_PATH_APPROVAL=PASS'

echo '========== RUN PHP85 KERNEL WITH NEGATIVE OAUTH REQUESTS =========='
# Always run the post-probe database census even if an HTTP assertion fails.
if python3 - "$PHP" "$APP" <<'PY'
import json,subprocess,sys
php,app=sys.argv[1:]
# Do not output HTTP bodies or tokens. The reserved app origin is inert.
source=r'''
$case=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$server=["HTTP_ACCEPT"=>"application/json",
         "HTTP_HOST"=>"oauth-public-qa.invalid",
         "HTTPS"=>"on","SERVER_PORT"=>"443"];
if(!empty($case["auth"])){
    $server["HTTP_AUTHORIZATION"]="Bearer invalid-qa-only-do-not-accept";
}
$body=json_encode($case["params"]??[],JSON_THROW_ON_ERROR);
$server["CONTENT_TYPE"]="application/json";
$request=\Illuminate\Http\Request::create(
 "https://oauth-public-qa.invalid".$case["path"],
 $case["method"],[],[],[],$server,$body
);
$response=$kernel->handle($request);
$content=$response->getContent();
$json=is_string($content)?json_decode($content,true):null;
if(!is_array($json)){$json=[];}
$code=$json["error"]??null;
$out=[
 "status"=>(int)$response->getStatusCode(),
 "invalid_target"=>$code==="invalid_target",
 "unsupported_grant"=>$code==="unsupported_grant_type",
 "denied_message"=>($json["message"]??null)==="Administrator authorization is no longer allowed.",
 "has_bearer_challenge"=>strpos((string)$response->headers->get("WWW-Authenticate",""),"Bearer")!==false
];
$kernel->terminate($request,$response);
echo json_encode($out,JSON_THROW_ON_ERROR);
'''
resource="https://oauth-public-qa.invalid/mcp"
cases=[
  ("TOKEN_MISSING_RESOURCE","/oauth/token",{"grant_type":"authorization_code"},False),
  ("TOKEN_FOREIGN_RESOURCE","/oauth/token",{"grant_type":"authorization_code","resource":"https://untrusted.example/mcp"},False),
  ("TOKEN_UNSUPPORTED_GRANT","/oauth/token",{"grant_type":"password","resource":resource},False),
  ("CONSENT_WITHOUT_ADMIN","/oauth/authorize",{"approve":True},False),
  ("MCP_BAD_BEARER","/mcp",{"jsonrpc":"2.0","id":7,"method":"ping"},True)
]
failed=[]
for label,path,params,auth in cases:
    data={"path":path,"method":"POST","params":params,"auth":auth}
    try:
        p=subprocess.run(
            [php,"-d","display_errors=0","-d","log_errors=0",
             "-r",source,json.dumps(data,separators=(",",":"))],
            cwd=app,env={"HOME":"/root","PATH":"/usr/bin:/bin","APP_ENV":"testing"},
            stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,timeout=50,check=False)
    except subprocess.TimeoutExpired:
        print("PRIVATE_OAUTH_"+label+"_RESULT=TIMEOUT")
        failed.append(label);continue
    try:
        v=json.loads(p.stdout) if p.returncode==0 and len(p.stdout)<65536 else None
    except (ValueError,UnicodeDecodeError):
        v=None
    if not isinstance(v,dict) or not isinstance(v.get("status"),int):
        print("PRIVATE_OAUTH_"+label+"_RESULT=BOOT_OR_JSON_FAILURE_REDACTED")
        failed.append(label);continue
    status=v["status"]
    if label in ("TOKEN_MISSING_RESOURCE","TOKEN_FOREIGN_RESOURCE"):
        ok=status==400 and v.get("invalid_target") is True
    elif label=="TOKEN_UNSUPPORTED_GRANT":
        ok=status==400 and v.get("unsupported_grant") is True
    elif label=="CONSENT_WITHOUT_ADMIN":
        # CSRF may reject first (419); the admin gate may reject (403).
        ok=(status==419 or (status==403 and v.get("denied_message") is True))
    else:
        ok=status==401 and v.get("has_bearer_challenge") is True
    print("PRIVATE_OAUTH_"+label+"_HTTP_STATUS="+str(status))
    print("PRIVATE_OAUTH_"+label+"_RESULT="+("PASS" if ok else "FAIL"))
    if not ok:failed.append(label)
print("RAW_HTTP_RESPONSES_TOKENS_AND_SECRETS_DISCLOSED=NO")
print("NETWORK_HTTP_LISTENER_STARTED=NO")
if failed:
    raise SystemExit("STEP34C10_BLOCKED=PRIVATE_NEGATIVE_OAUTH_CASE_FAILED")
print("PRIVATE_OAUTH_TOKEN_CONSENT_AND_BEARER_DENIALS=PASS")
PY
then
  KERNEL_PROBE_RESULT=PASS
else
  KERNEL_PROBE_RESULT=FAILED
fi

echo '========== VERIFY ALL ISOLATED DATABASE COUNTS UNCHANGED =========='
AFTER="$(snapshot_db)" || fail POSTPROBE_DATABASE_SNAPSHOT_FAILED
test "$BEFORE" = "$AFTER" || fail ISOLATED_QA_DATABASE_ROW_COUNT_DRIFT
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM migrations')" = 67 ||
  fail MIGRATION_COUNT_CHANGED
for t in users oauth_clients oauth_access_tokens oauth_refresh_tokens oauth_auth_codes; do
  test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM $t")" = 0 ||
    fail UNEXPECTED_QA_IDENTITY_OR_TOKEN_ROW
done
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_ACCOUNT_CHANGED
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail UNAPPROVED_PUBLIC_SITE_CHANGED
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail LOCKFILE_CHANGED
echo 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED'
test "$KERNEL_PROBE_RESULT" = PASS ||
  fail NEGATIVE_OAUTH_HTTP_CHECK_FAILED_BUT_DB_COUNTS_VERIFIED
echo 'STEP34C10_PRIVATE_OAUTH_DENIAL_ACCEPTANCE=PASS'
echo 'NO_ADMIN_CLIENT_OR_OAUTH_GRANT_CREATED=YES'
echo 'NO_PUBLIC_OAUTH_ISSUER_OR_HTTP_SERVER=YES'
echo 'NGINX_DNS_TLS_AND_PRODUCTION_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PR_MERGE=UNCHANGED'
