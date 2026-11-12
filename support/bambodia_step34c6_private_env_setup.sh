#!/usr/bin/env bash
# Step 34C.6: root-private Laravel .env + fresh APP_KEY only.
# --check is read-only; --configure requires approval.
# No public issuer, Passport keys, migrations, or public web routing.
set -Eeuo pipefail
set +x
umask 077
MODE="$1"
case "$MODE" in --check|--configure) ;; *)
  echo 'Usage: script --check|--configure' >&2; exit 64 ;; esac

APP="/var/lib/bambodia-oauth-public-qa"
QA="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
INDEX_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
fail(){ printf 'STEP34C6_BLOCKED=%s\n' "$1" >&2; exit 34; }

echo '========== VERIFY PRIVATE APP AND DEPENDENCIES =========='
test "$(id -u)" -eq 0 || fail NOT_ROOT
for cmd in python3 git stat sha256sum realpath; do
  command -v "$cmd" >/dev/null || fail TOOL_UNAVAILABLE
done
test -x "$MYSQL" || fail MYSQL_UNAVAILABLE
test -d "$APP" && test ! -L "$APP" &&
  test "$(realpath -e "$APP")" = "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = "0:700" ||
  fail PRIVATE_RELEASE_ROOT_UNSAFE
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_SOURCE_CHANGED
test -f "$QA/.env" && test ! -L "$QA/.env" &&
  grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env" ||
  fail INTERNAL_QA_ISSUER_CHANGED
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail COMPOSER_LOCK_CHANGED
test -f "$APP/vendor/autoload.php" && test ! -L "$APP/vendor/autoload.php" &&
  test -f "$APP/artisan" && test -f "$APP/bootstrap/app.php" ||
  fail VENDOR_OR_LARAVEL_SOURCE_MISSING
test ! -e "$APP/.env" && test ! -L "$APP/.env" ||
  fail ENV_ALREADY_EXISTS
for f in storage/oauth-private.key storage/oauth-public.key \
  bootstrap/cache/config.php; do
  test ! -e "$APP/$f" && test ! -L "$APP/$f" ||
    fail UNEXPECTED_RUNTIME_FILE
done
echo 'STAGED_SOURCE_VENDOR_LOCK=PASS'
echo 'INDEPENDENT_ENV=ABSENT'
echo 'PASSPORT_KEYS=ABSENT'

echo '========== VERIFY ORIGINAL SITE AND ISOLATED DATABASES =========='
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$INDEX_SHA" ||
  fail SITE_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail VHOST_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail PRESERVATION_BACKUP_UNSAFE
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail PRESERVATION_BACKUP_CHANGED
for cnf in "$PUB_CNF" "$QA_CNF"; do
  test -f "$cnf" && test ! -L "$cnf" &&
    test "$(stat -c '%u:%a' "$cnf")" = "0:600" ||
    fail DATABASE_CREDENTIAL_UNSAFE
done
python3 - "$PUB_CNF" <<'PY'
import configparser,re,sys
p=configparser.ConfigParser(interpolation=None)
with open(sys.argv[1],encoding="utf-8") as f:p.read_file(f)
assert 'client' in p
c=p['client']
assert all(c.get(k)==v for k,v in {
'user':'bambodiaqa_public','database':'bambodia_oauthqa_public',
'host':'127.0.0.1','port':'3306','protocol':'tcp'}.items())
assert re.fullmatch('[0-9a-f]{64}',c.get('password',''))
print('ISOLATED_DB_CREDENTIAL_SCOPE=PASS')
PY
PUB_MYSQL(){ "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"; }
QA_MYSQL(){ "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
  --host=127.0.0.1 --batch --skip-column-names bambodia_oauthqa_browser "$@"; }
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' ||
  fail PUBLIC_DB_PRINCIPAL_CHANGED
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail PUBLIC_DB_CHANGED
test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" = 0 ||
  fail PUBLIC_DB_NOT_EMPTY
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_ACCOUNT_BASELINE_CHANGED
for t in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $t WHERE revoked=0")" = 0 ||
    fail INTERNAL_ACTIVE_OAUTH_GRANTS
done
echo 'PUBLIC_QA_SCHEMA=EMPTY'
echo 'FROZEN_QA_DB_AND_ACTIVE_GRANTS=BASELINE_PASS'
echo 'ORIGINAL_SITE_VHOST_BACKUP=UNCHANGED'

if [ "$MODE" = --check ]; then
  echo 'STEP34C6_READ_ONLY_PREFLIGHT=PASS'
  echo 'ENV_AND_DATABASE_WRITES=NONE'
  exit 0
fi

echo '========== APPROVE PRIVATE APP CONFIGURATION =========='
test -c /dev/tty || fail NO_TERMINAL
echo 'Create root-private .env, new APP_KEY, and local-only runtime directories.'
echo 'APP_URL will remain reserved .invalid; external mail is disabled.'
echo 'No migrations, Passport keys, services, public endpoints or config changes.'
printf 'Type CONFIGURE-PRIVATE-OAUTH-QA-ENV to approve: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = CONFIGURE-PRIVATE-OAUTH-QA-ENV || fail OPERATOR_DECLINED
echo 'STEP34C6_EXPLICIT_APPROVAL=PASS'

echo '========== CREATE RESTRICTED ENV WITH NEW RANDOM APP KEY =========='
python3 - "$APP" "$PUB_CNF" <<'PY'
import base64,configparser,os,pathlib,re,secrets,stat,sys
app=pathlib.Path(sys.argv[1])
cfg=configparser.ConfigParser(interpolation=None)
with open(sys.argv[2],encoding="utf-8") as f:cfg.read_file(f)
c=cfg['client']
assert (c['database'],c['user'],c['host'],c['port'],c['protocol'])==(
'bambodia_oauthqa_public','bambodiaqa_public','127.0.0.1','3306','tcp')
pw=c.get('password','')
assert re.fullmatch('[0-9a-f]{64}',pw)
key=base64.b64encode(secrets.token_bytes(32)).decode('ascii')
data=[
'APP_NAME="Bambodia OAuth Public QA"',
'APP_ENV=testing','APP_KEY=base64:'+key,'APP_DEBUG=false',
'APP_URL=https://oauth-public-qa.invalid',
'APP_LOCALE=en','APP_FALLBACK_LOCALE=en','BCRYPT_ROUNDS=12',
'LOG_CHANNEL=single','LOG_LEVEL=warning',
'DB_CONNECTION=mysql','DB_URL=','DB_HOST=127.0.0.1','DB_PORT=3306',
'DB_DATABASE=bambodia_oauthqa_public','DB_USERNAME=bambodiaqa_public',
'DB_PASSWORD='+pw,'DB_SOCKET=',
'SESSION_DRIVER=file','SESSION_LIFETIME=120',
'SESSION_COOKIE=bambodia_oauth_public_qa_session',
'SESSION_SECURE_COOKIE=true','SESSION_HTTP_ONLY=true',
'SESSION_SAME_SITE=lax',
'CACHE_STORE=file','CACHE_PREFIX=bambodia_oauth_public_qa_cache_',
'QUEUE_CONNECTION=sync','FILESYSTEM_DISK=local','MEDIA_LIBRARY_DISK=local',
'MAIL_MAILER=array','MAIL_FROM_ADDRESS=noreply@oauth-public-qa.invalid',
'MAIL_FROM_NAME="Bambodia OAuth QA"','BROADCAST_CONNECTION=log',
'VITE_APP_NAME="Bambodia OAuth QA"'
]
path=app/'.env'
fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
with os.fdopen(fd,'w',encoding='utf-8') as f:
    f.write('\n'.join(data)+'\n')
    f.flush()
    os.fsync(f.fileno())
assert path.stat().st_uid==0 and stat.S_IMODE(path.stat().st_mode)==0o600
print('NEW_APP_KEY=GENERATED_32_BYTES_PRIVATE')
print('PRIVATE_APP_ENV_MODE=ROOT_600')
print('APP_URL=RESERVED_INVALID_ORIGIN')
print('MAIL_TRANSPORT=ARRAY_NO_EXTERNAL_DELIVERY')
PY

echo '========== INITIALIZE PRIVATE LOCAL DIRECTORIES =========='
for dir in storage/app/private storage/app/public \
  storage/framework/cache/data storage/framework/cache/locks \
  storage/framework/sessions storage/framework/views storage/logs \
  bootstrap/cache; do
  test ! -L "$APP/$dir" || fail RUNTIME_DIRECTORY_SYMLINK
  install -d -m 700 "$APP/$dir" || fail RUNTIME_DIRECTORY_FAILED
done
echo 'LARAVEL_LOCAL_RUNTIME_DIRS=PRIVATE'

echo '========== VERIFY SECURITY WITHOUT EXPOSING SECRETS =========='
python3 - "$APP/.env" "$PUB_CNF" <<'PY'
import base64,configparser,pathlib,stat,sys
p=pathlib.Path(sys.argv[1])
c=configparser.ConfigParser(interpolation=None)
with open(sys.argv[2],encoding="utf-8") as f:c.read_file(f)
data={}
for line in p.read_text(encoding='utf-8').splitlines():
  if not line or line.startswith('#'):continue
  k,v=line.split('=',1)
  assert k not in data
  data[k]=v
assert data['DB_PASSWORD']==c['client']['password']
assert data['DB_DATABASE']=='bambodia_oauthqa_public'
assert data['DB_USERNAME']=='bambodiaqa_public'
assert data['APP_URL']=='https://oauth-public-qa.invalid'
assert data['APP_DEBUG']=='false'
assert data['MAIL_MAILER']=='array'
assert data['SESSION_DRIVER']=='file'
assert data['CACHE_STORE']=='file'
assert data['SESSION_SECURE_COOKIE']=='true'
assert data['SESSION_COOKIE']=='bambodia_oauth_public_qa_session'
assert len(base64.b64decode(data['APP_KEY'][7:],validate=True))==32
assert p.stat().st_uid==0 and stat.S_IMODE(p.stat().st_mode)==0o600
print('PRIVATE_LARAVEL_ENV_SECURITY=PASS')
PY
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$INDEX_SHA" ||
  fail SITE_CHANGED_AFTER_CONFIG
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail VHOST_CHANGED_AFTER_CONFIG
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail COMPOSER_LOCK_CHANGED_AFTER_CONFIG
echo 'STEP34C6_PRIVATE_LARAVEL_ENV=PASS'
echo 'PUBLIC_OAUTH_ISSUER=NOT_ACTIVATED'
echo 'PASSPORT_KEYS_AND_CLIENTS=NOT_CREATED'
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'PUBLIC_SERVER_AND_SERVICES=NOT_STARTED'
echo 'QA_DATABASE_WRITES=NONE'
echo 'DNS_NGINX_TLS_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
