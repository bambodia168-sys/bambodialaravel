#!/usr/bin/env bash
# Step 34C.9: private PHP 8.5 Laravel CLI route inventory, no HTTP listener.
# --check checks read-only boundary. --probe requires operator approval.
# Route inspection may write local app log/runtime metadata; database writes,
# OAuth client/admin creation, public hosting and Nginx changes are prohibited.
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
fail() { printf 'STEP34C9_BLOCKED=%s\n' "$1" >&2; exit 34; }

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
    raise SystemExit('STEP34C9_BLOCKED=QA_APP_ENV_SCOPE_MISMATCH')
if env.get('DB_PASSWORD')!=c['client']['password']:
    raise SystemExit('STEP34C9_BLOCKED=ISOLATED_DB_PASSWORD_CHANGED')
if not env['APP_KEY'].startswith('base64:') or \
   len(base64.b64decode(env['APP_KEY'][7:],validate=True))!=32:
    raise SystemExit('STEP34C9_BLOCKED=APP_KEY_MISSING_OR_INVALID')
private=storage/'oauth-private.key';public=storage/'oauth-public.key'
for path in (private,public):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 \
       or stat.S_IMODE(st.st_mode)!=0o600 or st.st_size==0:
        raise SystemExit('STEP34C9_BLOCKED=PASSPORT_KEY_METADATA_INVALID')
def pub_der(path,pub):
    args=['openssl','pkey']
    if pub:args.append('-pubin')
    args+=['-in',str(path),'-pubout','-outform','DER']
    o=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                    timeout=20,check=False)
    if o.returncode or not o.stdout:
        raise SystemExit('STEP34C9_BLOCKED=PASSPORT_KEY_PARSE_INVALID')
    return o.stdout
if pub_der(private,False)!=pub_der(public,True):
    raise SystemExit('STEP34C9_BLOCKED=PASSPORT_KEYPAIR_MISMATCH')
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
  echo 'STEP34C9_READ_ONLY_PREFLIGHT=PASS'
  echo 'HTTP_KERNEL_REQUESTS=NOT_DISPATCHED'
  echo 'DATABASE_AND_NGINX_WRITES=NONE'
  exit 0
fi

snapshot_db() {
python3 - "$MYSQL" "$PUB_CNF" <<'PY'
import hashlib,re,subprocess,sys
mysql,cnf=sys.argv[1:]
base=[mysql,'--defaults-extra-file='+cnf,'--batch','--skip-column-names']
def query(sql):
    r=subprocess.run(base+['-e',sql],stdout=subprocess.PIPE,
                     stderr=subprocess.DEVNULL,check=True,timeout=30)
    return r.stdout.decode('utf-8').strip()
query_table="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
names=query(query_table).splitlines()
if len(names)<20:
    raise SystemExit('STEP34C9_BLOCKED=ISOLATED_TABLE_COUNT_UNEXPECTED')
h=hashlib.sha256()
quote=chr(96)
for name in names:
    if not re.fullmatch('[A-Za-z0-9_]+',name):
        raise SystemExit('STEP34C9_BLOCKED=UNEXPECTED_TABLE_NAME')
    value=query('SELECT COUNT(*) FROM '+quote+name+quote)
    if not value.isdecimal():
        raise SystemExit('STEP34C9_BLOCKED=INVALID_TABLE_COUNT')
    h.update((name+':'+value+'\n').encode('utf-8'))
print(str(len(names))+':'+h.hexdigest())
PY
}

echo '========== SNAPSHOT ISOLATED DATABASE TABLE COUNTS =========='
BEFORE="$(snapshot_db)" || fail DATABASE_SNAPSHOT_FAILED
echo 'ISOLATED_QA_DATABASE_TABLE_COUNT_SNAPSHOT=CAPTURED'

echo '========== APPROVE PRIVATE IN-PROCESS HTTP KERNEL REQUESTS =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo 'No socket or HTTP server will start. No public hostname will be configured.'
echo 'Dispatch OAuth discovery, invalid-target and unauthenticated MCP checks.'
echo 'Framework may create root-private session/cache/log files; no DB writes authorized.'
printf 'Type INSPECT-PRIVATE-OAUTH-KERNEL-NEGATIVE-PATHS: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'INSPECT-PRIVATE-OAUTH-KERNEL-NEGATIVE-PATHS' ||
  fail OPERATOR_DECLINED
echo 'STEP34C9_EXPLICIT_KERNEL_PROBE_APPROVAL=PASS'

echo '========== BOOT ISOLATED LARAVEL HTTP KERNEL WITHOUT LISTENER =========='
python3 - "$PHP" "$APP" <<'PY'
import json,subprocess,sys
php,app=sys.argv[1:]
source=r'''
$case=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$server=["HTTP_ACCEPT"=>"application/json",
         "HTTP_HOST"=>"oauth-public-qa.invalid",
         "HTTPS"=>"on","SERVER_PORT"=>"443"];
$body=null;
if(isset($case["body"])){
  $body=json_encode($case["body"],JSON_THROW_ON_ERROR);
  $server["CONTENT_TYPE"]="application/json";
}
$request=\Illuminate\Http\Request::create(
 "https://oauth-public-qa.invalid".$case["path"],
 $case["method"],$case["params"]??[],[],[],$server,$body
);
$response=$kernel->handle($request);
$raw=$response->getContent();
$data=is_string($raw)?json_decode($raw,true):null;
$data=is_array($data)?$data:[];
$challenge=(string)$response->headers->get("WWW-Authenticate","");
$out=[
 "status"=>$response->getStatusCode(),
 "has_json_fields"=>count($data)>0,
 "invalid_target"=>(($data["error"]??null)==="invalid_target"),
 "bearer_challenge"=>strpos($challenge,"Bearer")!==false,
 "mentions_reserved_origin"=>is_string($raw)
   && strpos($raw,"oauth-public-qa.invalid")!==false
];
$kernel->terminate($request,$response);
echo json_encode($out,JSON_THROW_ON_ERROR);
'''
cases=[
 ('PROTECTED_RESOURCE','GET','/.well-known/oauth-protected-resource/mcp',None,None),
 ('AUTH_SERVER','GET','/.well-known/oauth-authorization-server',None,None),
 ('AUTHORIZE_NO_RESOURCE','GET','/oauth/authorize',None,None),
 ('AUTHORIZE_FOREIGN_RESOURCE','GET','/oauth/authorize',
  {'resource':'https://untrusted.example/mcp'},None),
 ('MCP_WITHOUT_BEARER','POST','/mcp',None,
  {'jsonrpc':'2.0','id':1,'method':'ping'})
]
failure=[]
for name,method,path,params,body in cases:
    case={'method':method,'path':path}
    if params is not None: case['params']=params
    if body is not None: case['body']=body
    try:
        p=subprocess.run(
            [php,'-d','display_errors=0','-d','log_errors=0',
             '-r',source,json.dumps(case,separators=(',',':'))],
            cwd=app,env={'HOME':'/root','PATH':'/usr/bin:/bin','APP_ENV':'testing'},
            stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,timeout=50,check=False)
    except subprocess.TimeoutExpired:
        print('PRIVATE_KERNEL_'+name+'_RESULT=TIMEOUT')
        failure.append(name)
        continue
    try:
        value=json.loads(p.stdout) if p.returncode==0 and len(p.stdout)<65536 else None
    except ValueError:
        value=None
    if not isinstance(value,dict) or not isinstance(value.get('status'),int):
        print('PRIVATE_KERNEL_'+name+'_RESULT=BOOT_OR_JSON_FAILURE_REDACTED')
        failure.append(name)
        continue
    status=value['status']
    if name in ('PROTECTED_RESOURCE','AUTH_SERVER'):
        ok=(status==200 and value.get('has_json_fields')
            and value.get('mentions_reserved_origin'))
    elif name.startswith('AUTHORIZE_'):
        ok=(status==400 and value.get('invalid_target'))
    else:
        ok=(status==401 and value.get('bearer_challenge'))
    print('PRIVATE_KERNEL_'+name+'_HTTP_STATUS='+str(status))
    print('PRIVATE_KERNEL_'+name+'_RESULT='+('PASS' if ok else 'FAIL'))
    if not ok: failure.append(name)
print('PRIVATE_RESPONSE_BODY_AND_KEYS_DISCLOSED=NO')
print('HTTP_LISTENER_STARTED=NO')
if failure:
    raise SystemExit('STEP34C9_BLOCKED=NEGATIVE_OR_METADATA_HTTP_CHECK_FAILED')
print('PRIVATE_KERNEL_METADATA_AND_AUTH_REJECTIONS=PASS')
PY

echo '========== CONFIRM NO DATABASE OR PUBLIC HOST CHANGE =========='
AFTER="$(snapshot_db)" || fail POSTPROBE_DATABASE_SNAPSHOT_FAILED
test "$BEFORE" = "$AFTER" || fail ISOLATED_QA_TABLE_COUNTS_CHANGED
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM migrations')" = 67 ||
  fail ISOLATED_QA_MIGRATIONS_CHANGED
for t in users oauth_clients oauth_access_tokens oauth_refresh_tokens oauth_auth_codes; do
  test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM $t")" = 0 ||
    fail UNEXPECTED_OAUTH_FIXTURE
done
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_CHANGED
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_SITE_OR_VHOST_CHANGED
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail LOCK_CHANGED_AFTER_HTTP_PROBE
echo 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED'
echo 'STEP34C9_PRIVATE_HTTP_KERNEL_ACCEPTANCE=PASS'
echo 'NO_ADMIN_CLIENT_OR_TOKEN_CREATED=YES'
echo 'PUBLIC_OAUTH_ISSUER=NOT_ACTIVATED'
echo 'NGINX_DNS_TLS_AND_PRODUCTION_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PR_MERGE=UNCHANGED'
