#!/usr/bin/env bash
# Step34C.11: STRICTLY READ-ONLY positive OAuth fixture readiness.
# No accounts, OAuth clients, tokens, keys, migrations, listeners or uploads.
set -Eeuo pipefail
set +x
umask 077
MODE="$1"
test "$MODE" = --audit || { echo "Usage: script --audit"; exit 64; }
APP="/var/lib/bambodia-oauth-public-qa"
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="06806f2913c3150cf430482124a23f760dfb60d3c47dc587700762d32b2a4a6a"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
fail(){ echo "STEP34C11_BLOCKED=$1" >&2; exit 34; }

echo "========== VERIFY PRIVATE ROOT AND PRESERVED WEBSITE =========="
test "$(id -u)" -eq 0 || fail ROOT_REQUIRED
for t in python3 git sha256sum stat openssl; do
  command -v "$t" >/dev/null || fail TOOL_MISSING
done
test -x "$MYSQL" || fail MYSQL_MISSING
test -d "$APP" && test ! -L "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = "0:700" ||
  fail PRIVATE_ROOT_UNSAFE
test -f "$APP/.env" && test ! -L "$APP/.env" &&
  test "$(stat -c '%u:%a' "$APP/.env")" = "0:600" ||
  fail PRIVATE_ENV_UNSAFE
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" &&
  test -f "$APP/vendor/autoload.php" ||
  fail LOCK_OR_VENDOR_CHANGED
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_QA_SOURCE_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail OCCUPIED_VHOST_CHANGED
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_SITE_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = "0:700" ||
  fail BACKUP_CHANGED
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail BACKUP_CHECKSUM_CHANGED
for f in "$CNF" "$QA_CNF"; do
  test -f "$f" && test ! -L "$f" &&
    test "$(stat -c '%u:%a' "$f")" = "0:600" ||
    fail DB_CREDENTIAL_FILE_UNSAFE
done
echo "ROOT_PRIVATE_RELEASE_AND_FROZEN_QA=PASS"
echo "OCCUPIED_VHOST_SITE_AND_BACKUP=UNCHANGED"

echo "========== VERIFY INERT ENV, MATCHED SIGNING KEYS AND SCHEMA =========="
python3 - "$APP" "$MYSQL" "$CNF" "$QA_CNF" <<'PY'
import base64,configparser,pathlib,stat,subprocess,sys
app=pathlib.Path(sys.argv[1])
mysql,cnf,qa_cnf=sys.argv[2:]
cfg=configparser.ConfigParser(interpolation=None)
with open(cnf,encoding='utf-8') as f:cfg.read_file(f)
env={}
for line in (app/'.env').read_text('utf-8').splitlines():
    if not line or line.startswith('#'):continue
    if '=' not in line:raise SystemExit('STEP34C11_BLOCKED=ENV_MALFORMED')
    k,v=line.split('=',1)
    if k in env:raise SystemExit('STEP34C11_BLOCKED=ENV_DUPLICATE')
    env[k]=v
expected={
 'APP_URL':'https://oauth-public-qa.invalid',
 'APP_ENV':'testing','APP_DEBUG':'false',
 'DB_DATABASE':'bambodia_oauthqa_public',
 'DB_USERNAME':'bambodiaqa_public','DB_CONNECTION':'mysql',
 'DB_HOST':'127.0.0.1','MAIL_MAILER':'array',
 'SESSION_DRIVER':'file','CACHE_STORE':'file','QUEUE_CONNECTION':'sync'
}
if any(env.get(k)!=v for k,v in expected.items()) or \
   env.get('DB_PASSWORD')!=cfg['client'].get('password'):
    raise SystemExit('STEP34C11_BLOCKED=ENV_SCOPE_CHANGED')
if not env.get('APP_KEY','').startswith('base64:') or \
   len(base64.b64decode(env['APP_KEY'][7:],validate=True))!=32:
    raise SystemExit('STEP34C11_BLOCKED=APP_KEY_INVALID')
pvt=app/'storage/oauth-private.key';pub=app/'storage/oauth-public.key'
for p in (pvt,pub):
    s=p.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or \
       stat.S_IMODE(s.st_mode)!=0o600 or s.st_size==0:
        raise SystemExit('STEP34C11_BLOCKED=PASSPORT_KEY_METADATA')
def derive(path,is_public):
    a=['openssl','pkey']
    if is_public:a.append('-pubin')
    a+=['-in',str(path),'-pubout','-outform','DER']
    r=subprocess.run(a,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
                     timeout=15,check=False)
    if r.returncode or not r.stdout:
        raise SystemExit('STEP34C11_BLOCKED=PASSPORT_KEY_PARSE')
    return r.stdout
if derive(pvt,False)!=derive(pub,True):
    raise SystemExit('STEP34C11_BLOCKED=PASSPORT_KEYPAIR_MISMATCH')
print('INERT_ISSUER_AND_NO_EXTERNAL_MAIL=PASS')
print('PASSPORT_KEYPAIR_ROOT_0600_MATCHED=PASS')

def query(path,sql,db=None):
    cmd=[mysql,'--defaults-extra-file='+path,'--batch','--skip-column-names']
    if db:cmd.append(db)
    r=subprocess.run(cmd+['-e',sql],stdout=subprocess.PIPE,
                     stderr=subprocess.DEVNULL,timeout=25,check=False)
    if r.returncode:
        raise SystemExit('STEP34C11_BLOCKED=SQL_READ_ONLY_QUERY_FAILED')
    return r.stdout.decode('utf-8').rstrip('\n')
if query(cnf,'SELECT CURRENT_USER()')!='bambodiaqa_public@127.0.0.1' or \
   query(cnf,'SELECT DATABASE()')!='bambodia_oauthqa_public':
    raise SystemExit('STEP34C11_BLOCKED=DB_PRINCIPAL_CHANGED')
if query(cnf,'SELECT COUNT(*) FROM migrations')!='67':
    raise SystemExit('STEP34C11_BLOCKED=MIGRATION_COUNT_CHANGED')
tables=('users','oauth_clients','oauth_auth_codes',
        'oauth_access_tokens','oauth_refresh_tokens','oauth_device_codes')
for table in tables:
    if query(cnf,'SELECT COUNT(*) FROM '+table)!='0':
        raise SystemExit('STEP34C11_BLOCKED=NONEMPTY_QA_IDENTITY_TABLE')
if query(qa_cnf,'SELECT COUNT(*) FROM users',
         'bambodia_oauthqa_browser')!='1' or \
   query(qa_cnf,'SELECT COUNT(*) FROM oauth_clients',
         'bambodia_oauthqa_browser')!='1':
    raise SystemExit('STEP34C11_BLOCKED=FROZEN_QA_COUNT_CHANGED')
for t in ('oauth_auth_codes','oauth_access_tokens','oauth_refresh_tokens'):
    if query(qa_cnf,'SELECT COUNT(*) FROM '+t+' WHERE revoked=0',
             'bambodia_oauthqa_browser')!='0':
        raise SystemExit('STEP34C11_BLOCKED=FROZEN_ACTIVE_OAUTH_GRANTS')

need={
 'users':{'id','name','email','password','public_id','role','account_status'},
 'oauth_clients':{'id','owner_type','owner_id','name','secret','provider',
                  'redirect_uris','grant_types','revoked','scopes'},
 'oauth_auth_codes':{'id','user_id','client_id','scopes','revoked','expires_at'},
 'oauth_access_tokens':{'id','user_id','client_id','scopes','revoked','expires_at'},
 'oauth_refresh_tokens':{'id','access_token_id','revoked','expires_at'}
}
schema={}
for table,required in need.items():
    sql=("SELECT COLUMN_NAME,DATA_TYPE,COALESCE(CHARACTER_MAXIMUM_LENGTH,0) "
         "FROM information_schema.COLUMNS WHERE "
         "TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_NAME='"+table+"'")
    lines=[x.split('\t') for x in query(cnf,sql).splitlines() if x]
    if any(len(x)!=3 for x in lines):
        raise SystemExit('STEP34C11_BLOCKED=COLUMN_METADATA_INVALID')
    d={x[0]:x[1:] for x in lines}
    if not required.issubset(set(d)):
        print('OAUTH_COLUMNS_'+table.upper()+'=MISSING')
        raise SystemExit('STEP34C11_BLOCKED=OAUTH_SCHEMA_COLUMN_MISSING')
    schema[table]=d
    print('OAUTH_COLUMNS_'+table.upper()+'=PASS')
if schema['oauth_clients']['scopes'][0]!='json':
    raise SystemExit('STEP34C11_BLOCKED=CLIENT_SCOPES_NOT_JSON')
if schema['oauth_clients']['id']!=['char','36']:
    raise SystemExit('STEP34C11_BLOCKED=CLIENT_UUID_SCHEMA_CHANGED')
for k in ('role','account_status'):
    if schema['users'][k][0] not in ('char','varchar'):
        raise SystemExit('STEP34C11_BLOCKED=USER_ROLE_STATUS_SCHEMA_CHANGED')
print('CLIENT_UUID_JSON_SCOPES=PASS')
print('SUPER_ADMIN_ROLE_AND_ACCOUNT_STATUS_FIELDS=PASS')
print('ISOLATED_QA_MIGRATIONS=67')
print('ISOLATED_QA_USERS_CLIENTS_CODES_TOKENS=ZERO')
print('FROZEN_QA_ACCOUNT_AND_GRANT_BASELINE=PASS')
print('DB_ROWS_CREDENTIALS_AND_KEY_MATERIAL_DISCLOSED=NO')
PY

echo '========== REVERIFY ORIGINAL SITE AFTER READ-ONLY INSPECTION =========='
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" ||
  fail ORIGINAL_SITE_OR_QA_CHANGED_DURING_AUDIT
echo 'STEP34C11_READ_ONLY_OAUTH_FIXTURE_READINESS=PASS'
echo 'QA_ADMIN_AND_CLIENT_FIXTURES=NOT_CREATED'
echo 'PKCE_EXCHANGE_REFRESH_AND_REVOCATION=NOT_TESTED'
echo 'OAUTH_PUBLIC_ISSUER=NOT_DEPLOYED'
echo 'DATABASE_KEYS_NGINX_DNS_TLS_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
