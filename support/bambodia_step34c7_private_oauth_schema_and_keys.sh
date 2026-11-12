#!/usr/bin/env bash
# Step 34C.7: independently initialize ONLY the private public-QA schema
# and Passport signing keys. No users, clients, seeds, web routing, or mails.
# --check is read-only; --initialize requires explicit operator confirmation.
set -Eeuo pipefail
set +x
umask 077
MODE="$1"
case "$MODE" in --check|--initialize) ;; *)
  echo 'Usage: script --check|--initialize' >&2; exit 64 ;; esac

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
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
fail() { printf 'STEP34C7_BLOCKED=%s\n' "$1" >&2; exit 34; }

echo '========== VERIFY PRIVATE RELEASE AND ENVIRONMENT =========='
test "$(id -u)" -eq 0 || fail ROOT_REQUIRED
for bin in git python3 sha256sum stat realpath openssl; do
  command -v "$bin" >/dev/null || fail REQUIRED_TOOL_MISSING
done
test -x "$PHP" && test -x "$MYSQL" || fail PHP_OR_MYSQL_MISSING
test -d "$APP" && test ! -L "$APP" &&
  test "$(realpath -e "$APP")" = "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
  fail PRIVATE_RELEASE_ROOT_UNSAFE
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail ORIGINAL_QA_SOURCE_CHANGED
test -f "$QA/.env" && test ! -L "$QA/.env" &&
  grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env" ||
  fail ORIGINAL_QA_ISSUER_CHANGED
test -f "$APP/.env" && test ! -L "$APP/.env" &&
  test "$(stat -c '%u:%a' "$APP/.env")" = '0:600' ||
  fail PRIVATE_ENV_MISSING_OR_UNSAFE
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail COMPOSER_LOCK_CHANGED
test -f "$APP/vendor/autoload.php" && test ! -L "$APP/vendor/autoload.php" &&
  test -f "$APP/artisan" &&
  test ! -e "$APP/bootstrap/cache/config.php" ||
  fail VENDOR_OR_CONFIG_CACHE_UNEXPECTED
test ! -e "$APP/storage/oauth-private.key" &&
  test ! -L "$APP/storage/oauth-private.key" &&
  test ! -e "$APP/storage/oauth-public.key" &&
  test ! -L "$APP/storage/oauth-public.key" ||
  fail PASSPORT_KEYS_ALREADY_PRESENT
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail PUBLIC_SITE_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail EXISTING_VHOST_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail PRESERVATION_BACKUP_UNSAFE
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail PRESERVATION_BACKUP_CHANGED
echo 'PRIVATE_RELEASE_AND_ORIGINAL_SITE=PASS'
echo 'PASSPORT_KEYS=ABSENT'
echo 'APP_ENV_AND_COMPOSER_LOCK=VERIFIED'

echo '========== CHECK NONSECRET ENVIRONMENT ISOLATION =========='
for cnf in "$PUB_CNF" "$QA_CNF"; do
  test -f "$cnf" && test ! -L "$cnf" &&
  test "$(stat -c '%u:%a' "$cnf")" = '0:600' ||
    fail DB_CREDENTIAL_UNSAFE
done
python3 - "$APP/.env" "$PUB_CNF" <<'PY'
import base64,configparser,pathlib,stat,sys
env=pathlib.Path(sys.argv[1])
cfg=configparser.ConfigParser(interpolation=None)
with open(sys.argv[2],encoding='utf-8') as f:cfg.read_file(f)
pairs={}
for line in env.read_text(encoding='utf-8').splitlines():
    if not line or line.startswith('#'):continue
    assert '=' in line
    key,val=line.split('=',1)
    assert key not in pairs
    pairs[key]=val
checks={
    'APP_ENV':'testing','APP_DEBUG':'false',
    'APP_URL':'https://oauth-public-qa.invalid',
    'DB_CONNECTION':'mysql',
    'DB_DATABASE':'bambodia_oauthqa_public',
    'DB_USERNAME':'bambodiaqa_public',
    'DB_HOST':'127.0.0.1','DB_PORT':'3306',
    'MAIL_MAILER':'array','SESSION_DRIVER':'file',
    'CACHE_STORE':'file','QUEUE_CONNECTION':'sync',
    'FILESYSTEM_DISK':'local','SESSION_SECURE_COOKIE':'true'
}
assert all(pairs.get(k)==v for k,v in checks.items()),'QA env scope mismatch'
assert pairs.get('DB_PASSWORD')==cfg['client']['password'],'DB password mismatch'
assert pairs['APP_KEY'].startswith('base64:')
assert len(base64.b64decode(pairs['APP_KEY'][7:],validate=True))==32
assert env.stat().st_uid==0 and stat.S_IMODE(env.stat().st_mode)==0o600
print('ISOLATED_ENV_KEY_AND_DB_SCOPE=PASS')
print('APP_KEY_CONTENT=NOT_DISCLOSED')
print('DB_PASSWORD_CONTENT=NOT_DISCLOSED')
PY

echo '========== CHECK ISOLATED SCHEMAS BEFORE MIGRATION =========='
PUB_MYSQL() {
  "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"
}
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP --host=127.0.0.1 \
    --batch --skip-column-names bambodia_oauthqa_browser "$@"
}
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' ||
  fail PUBLIC_QA_DATABASE_WRONG_PRINCIPAL
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail PUBLIC_QA_DATABASE_WRONG_NAME
test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" = 0 ||
  fail PUBLIC_QA_DATABASE_NOT_EMPTY
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_browser' &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_ACCOUNT_BASELINE_CHANGED
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0 ||
    fail FROZEN_QA_ACTIVE_OAUTH_GRANTS_FOUND
done
echo 'ISOLATED_QA_DATABASE=EMPTY'
echo 'FROZEN_QA_ACCOUNTS_CLIENTS_GRANTS=BASELINE_PASS'

if [ "$MODE" = '--check' ]; then
  echo 'STEP34C7_READ_ONLY_PREFLIGHT=PASS'
  echo 'PASSPORT_KEYS_AND_SCHEMA_WRITES=NONE'
  echo 'PUBLIC_SERVER_CHANGES=NONE'
  exit 0
fi

echo '========== EXPLICIT MIGRATION AND PASSPORT KEY AUTHORIZATION =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo 'This will run Laravel migrations ONLY against the new empty QA database.'
echo 'It will then create new private Passport RSA keys inside this QA release.'
echo 'There will be no seed data, OAuth client creation or public routing.'
echo 'Database migrations are not automatically rolled back if interrupted.'
printf 'Type INITIALIZE-ISOLATED-OAUTH-QA-SCHEMA-AND-KEYS: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'INITIALIZE-ISOLATED-OAUTH-QA-SCHEMA-AND-KEYS' ||
  fail OPERATOR_DECLINED
echo 'STEP34C7_EXPLICIT_INITIALIZATION_APPROVAL=PASS'

echo '========== RUN ISOLATED LARAVEL MIGRATIONS =========='
# Artisan will use only APP/.env and the private MySQL principal.
# This is the FIRST intentional write to the independent public QA database.
(
  cd "$APP"
  "$PHP" artisan migrate --force --no-interaction --no-ansi
) || fail MIGRATION_FAILED_POSSIBLE_PARTIAL_SCHEMA_DO_NOT_RERUN

test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" -gt 0 ||
  fail NO_TABLES_CREATED
for table in migrations users oauth_clients oauth_auth_codes \
  oauth_access_tokens oauth_refresh_tokens; do
  test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_NAME='$table'")" = 1 ||
    fail REQUIRED_OAUTH_TABLE_MISSING_REVIEW_MIGRATIONS
done
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM users')" = 0 ||
  fail UNEXPECTED_QA_USER_CREATED
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 0 ||
  fail UNEXPECTED_OAUTH_CLIENT_CREATED
echo 'PUBLIC_QA_LARAVEL_MIGRATIONS=PASS'
echo 'PUBLIC_QA_USERS_AND_CLIENTS=ZERO'

echo '========== GENERATE INDEPENDENT PRIVATE PASSPORT SIGNING KEYS =========='
test ! -e "$APP/storage/oauth-private.key" &&
  test ! -e "$APP/storage/oauth-public.key" ||
  fail PASSPORT_KEY_COLLISION_AFTER_MIGRATION
(
  cd "$APP"
  "$PHP" artisan passport:keys --no-interaction --no-ansi
) || fail PASSPORT_KEYS_FAILED_POSSIBLE_PARTIAL_KEYS_DO_NOT_RERUN

for file in oauth-private.key oauth-public.key; do
  path="$APP/storage/$file"
  test -f "$path" && test ! -L "$path" &&
    test "$(stat -c '%u:%a' "$path")" = '0:600' &&
    test -s "$path" ||
    fail PASSPORT_KEY_OWNER_MODE_OR_FILE_INVALID
done
openssl pkey -in "$APP/storage/oauth-private.key" -noout >/dev/null 2>&1 ||
  fail PASSPORT_PRIVATE_KEY_INVALID
openssl pkey -pubin -in "$APP/storage/oauth-public.key" -noout >/dev/null 2>&1 ||
  fail PASSPORT_PUBLIC_KEY_INVALID

python3 - "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key" <<'PY'
from pathlib import Path
import subprocess,sys
private=Path(sys.argv[1])
public=Path(sys.argv[2])
derived=subprocess.run(['openssl','pkey','-in',str(private),'-pubout'],
                       capture_output=True,check=True).stdout
assert derived==public.read_bytes(), 'Public key does not match private key'
print('PASSPORT_KEYPAIR_MATH_VALIDATION=PASS')
PY

echo '========== POSTINITIALIZATION SAFETY CHECK =========='
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail INTERNAL_QA_DATABASE_CHANGED
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail PUBLIC_SITE_OR_VHOST_CHANGED
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" ||
  fail FROZEN_SOURCE_CHANGED
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail LOCK_CHANGED
echo 'STEP34C7_ISOLATED_QA_SCHEMA_AND_KEYS=PASS'
echo 'PASSPORT_PRIVATE_KEYS=NEW_AND_ROOT_ONLY'
echo 'NO_SEED_DATA_OR_OAUTH_CLIENT=CONFIRMED'
echo 'EXTERNAL_MAIL_DELIVERY=NOT_CONFIGURED'
echo 'PUBLIC_HTTPS_OAUTH_ISSUER=NOT_ACTIVATED'
echo 'PUBLIC_HTTP_SERVER=NOT_STARTED'
echo 'ORIGINAL_QA_DATABASE_WRITES=NONE'
echo 'DNS_NGINX_TLS_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
