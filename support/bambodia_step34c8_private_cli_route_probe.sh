#!/usr/bin/env bash
# Step 34C.8: private PHP 8.5 Laravel CLI route inventory, no HTTP listener.
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
fail() { printf 'STEP34C8_BLOCKED=%s\n' "$1" >&2; exit 34; }

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
    raise SystemExit('STEP34C8_BLOCKED=QA_APP_ENV_SCOPE_MISMATCH')
if env.get('DB_PASSWORD')!=c['client']['password']:
    raise SystemExit('STEP34C8_BLOCKED=ISOLATED_DB_PASSWORD_CHANGED')
if not env['APP_KEY'].startswith('base64:') or \
   len(base64.b64decode(env['APP_KEY'][7:],validate=True))!=32:
    raise SystemExit('STEP34C8_BLOCKED=APP_KEY_MISSING_OR_INVALID')
private=storage/'oauth-private.key';public=storage/'oauth-public.key'
for path in (private,public):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 \
       or stat.S_IMODE(st.st_mode)!=0o600 or st.st_size==0:
        raise SystemExit('STEP34C8_BLOCKED=PASSPORT_KEY_METADATA_INVALID')
def pub_der(path,pub):
    args=['openssl','pkey']
    if pub:args.append('-pubin')
    args+=['-in',str(path),'-pubout','-outform','DER']
    o=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                    timeout=20,check=False)
    if o.returncode or not o.stdout:
        raise SystemExit('STEP34C8_BLOCKED=PASSPORT_KEY_PARSE_INVALID')
    return o.stdout
if pub_der(private,False)!=pub_der(public,True):
    raise SystemExit('STEP34C8_BLOCKED=PASSPORT_KEYPAIR_MISMATCH')
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
  echo 'STEP34C8_READ_ONLY_PREFLIGHT=PASS'
  echo 'LARAVEL_CLI_NOT_STARTED=YES'
  echo 'DB_NGINX_AND_PUBLIC_SITE_WRITES=NONE'
  exit 0
fi

echo '========== APPROVE PRIVATE CLI ROUTE INVENTORY ONLY =========='
test -c /dev/tty || fail TERMINAL_REQUIRED
echo 'Run PHP 8.5 Artisan route:list --json only inside the isolated private QA.'
echo 'The framework may write its private local log, but must not write DB records.'
echo 'No PHP-FPM listener, OAuth fixture, browser session, or public endpoint.'
printf 'Type INSPECT-PRIVATE-OAUTH-QA-ROUTES-ONLY: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'INSPECT-PRIVATE-OAUTH-QA-ROUTES-ONLY' ||
  fail OPERATOR_DECLINED
echo 'STEP34C8_EXPLICIT_PRIVATE_ROUTE_PROBE_APPROVAL=PASS'

echo '========== RUN ISOLATED LARAVEL ROUTE DISCOVERY =========='
python3 - "$APP" "$PHP" <<'PY'
import json,os,subprocess,sys
root,php=sys.argv[1:]
env={
 'HOME':'/root',
 'PATH':'/usr/bin:/bin',
 'APP_ENV':'testing'
}
try:
    result=subprocess.run(
        [php,'-d','display_errors=0','-d','log_errors=0',
         'artisan','route:list','--json','--no-ansi'],
        cwd=root,env=env,stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,timeout=70,check=False)
except subprocess.TimeoutExpired:
    raise SystemExit('STEP34C8_BLOCKED=ARTISAN_ROUTE_LIST_TIMEOUT')
if result.returncode:
    print('ARTISAN_ROUTE_LIST_EXIT=FAILED')
    print('ARTISAN_STDERR_AND_PRIVATE_VALUES=REDACTED')
    raise SystemExit('STEP34C8_BLOCKED=ARTISAN_ROUTE_LIST_FAILED')
if len(result.stdout)>16*1024*1024:
    raise SystemExit('STEP34C8_BLOCKED=ROUTE_INVENTORY_TOO_LARGE')
try:
    records=json.loads(result.stdout)
except ValueError:
    raise SystemExit('STEP34C8_BLOCKED=ARTISAN_ROUTE_OUTPUT_NOT_JSON')
if not isinstance(records,list) or not records:
    raise SystemExit('STEP34C8_BLOCKED=EMPTY_OR_INVALID_ROUTE_INVENTORY')
items=[]
for item in records:
    if not isinstance(item,dict):continue
    uri=str(item.get('uri','')).strip('/')
    method=str(item.get('method','')).upper()
    if not uri:continue
    items.append((uri,set(method.replace(',','|').split('|'))))
checks=[
    ('MCP_POST',('mcp','POST')),
    ('OAUTH_DYNAMIC_REGISTRATION',('oauth/register','POST')),
    ('OAUTH_AUTHORIZE',('oauth/authorize','GET')),
    ('OAUTH_TOKEN',('oauth/token','POST')),
]
failed=[]
for label,(uri,method) in checks:
    ok=any(u==uri and method in methods for u,methods in items)
    print('ROUTE_'+label+'='+('PRESENT' if ok else 'MISSING'))
    if not ok:failed.append(label)
metadata=[u for u,methods in items if '.well-known' in u and 'GET' in methods]
print('OAUTH_WELL_KNOWN_GET_ROUTE_COUNT='+str(len(set(metadata))))
print('TOTAL_LARAVEL_ROUTE_ENTRIES='+str(len(items)))
print('RAW_ROUTE_LIST_AND_CREDENTIALS_PRINTED=NO')
if failed:
    raise SystemExit('STEP34C8_BLOCKED=REQUIRED_OAUTH_ROUTE_MISSING')
print('PRIVATE_LARAVEL_ROUTE_REGISTRY=PASS')
PY

echo '========== POSTPROBE DATABASE AND PUBLIC BOUNDARIES =========='
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM migrations')" = 67 ||
  fail ISOLATED_MIGRATIONS_CHANGED_AFTER_CLI_PROBE
for t in users oauth_clients oauth_access_tokens oauth_refresh_tokens oauth_auth_codes; do
  test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM $t")" = 0 ||
    fail UNEXPECTED_OAUTH_RECORD_AFTER_PROBE
done
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_CHANGED_AFTER_PROBE
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail PUBLIC_HOST_CHANGED_AFTER_PROBE
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail LOCK_CHANGED_AFTER_PROBE
echo 'STEP34C8_PRIVATE_ROUTE_REGISTRY_ACCEPTANCE=PASS'
echo 'ISOLATED_QA_DB_FIXTURE_AND_MIGRATION_COUNTS=UNCHANGED'
echo 'PUBLIC_HTTP_OAUTH_ISSUER=NOT_DEPLOYED'
echo 'ORIGINAL_VHOST_SITE_AND_PRODUCTION=UNCHANGED'
echo 'NO_OAUTH_CLIENT_OR_ADMIN_CREATED=YES'
echo 'GITHUB_MAIN_AND_PR_MERGE=UNCHANGED'
