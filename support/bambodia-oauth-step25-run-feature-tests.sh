#!/usr/bin/env bash
# Step 25: fail-closed OAuth PHPUnit acceptance on a disposable in-memory
# SQLite workspace ONLY. Never run RefreshDatabase against accepted MySQL QA.
# --check = read-only preflight. --run = typed-confirmed tests as www.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in --check|--run) ;; *)
  echo 'Usage: bash script --check|--run' >&2; exit 64 ;;
esac

SRC="/var/lib/bambodia-oauth-browser-qa"
APP="/var/lib/bambodia-oauth-step25-sqlite"
SOURCE_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
PHP="/www/server/php/85/bin/php"
fail() { printf 'STEP25_TEST_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail 'ROOT_OPERATOR_REQUIRED'
[[ -d /var/lib && ! -L /var/lib && "$(stat -c %u /var/lib)" == 0 ]] ||
  fail 'UNSAFE_VAR_LIB'
[[ "$SRC" != "$APP" && "$APP" == /var/lib/bambodia-oauth-step25-sqlite ]] ||
  fail 'UNSAFE_WORKSPACE_PATH'
[[ -x "$PHP" ]] || fail 'PHP85_MISSING'
id www >/dev/null || fail 'WWW_ACCOUNT_MISSING'
for dir in "$SRC" "$APP"; do
  [[ -d "$dir/.git" && ! -L "$dir" && ! -L "$dir/.git" ]] ||
    fail 'QA_CHECKOUT_MISSING_OR_SYMLINKED'
  [[ "$(realpath -e "$dir")" == "$dir" ]] ||
    fail 'QA_CHECKOUT_REDIRECTED'
  [[ "$(git -C "$dir" rev-parse HEAD)" == "$SOURCE_SHA" ]] ||
    fail 'UNEXPECTED_QA_COMMIT'
  [[ -z "$(git -C "$dir" status --porcelain --untracked-files=no)" ]] ||
    fail 'TRACKED_QA_FILES_MODIFIED'
  [[ -s "$dir/composer.lock" && ! -L "$dir/composer.lock" ]] ||
    fail 'QA_DEPENDENCY_LOCK_MISSING_OR_SYMLINKED'
done
[[ "$(sha256sum "$SRC/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail 'SOURCE_LOCK_CHANGED'
[[ "$(sha256sum "$APP/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail 'TEST_LOCK_CHANGED'
cmp -s "$SRC/composer.lock" "$APP/composer.lock" ||
  fail 'DEP_LOCK_BYTES_DIFFER'
cmp -s "$SRC/phpunit.xml" "$APP/phpunit.xml" ||
  fail 'PHPUNIT_CONFIG_DIFFERS'
[[ -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail 'ISOLATED_APP_ENV_MISSING'
[[ ! -e "$APP/.env.testing" && ! -L "$APP/.env.testing" &&
   ! -e "$APP/bootstrap/cache/config.php" &&
   ! -L "$APP/bootstrap/cache/config.php" ]] ||
  fail 'TEST_OVERRIDE_OR_CONFIG_CACHE_FOUND'
[[ -s "$APP/vendor/autoload.php" &&
   -f "$APP/vendor/bin/phpunit" &&
   -f "$APP/vendor/composer/installed.json" ]] ||
  fail 'PHPUNIT_DEPENDENCIES_MISSING'
for name in oauth-private.key oauth-public.key; do
  [[ -s "$APP/storage/$name" && ! -L "$APP/storage/$name" &&
     "$(stat -c '%a' "$APP/storage/$name")" == 600 ]] ||
    fail "UNSAFE_DISPOSABLE_PASSPORT_KEY_$name"
  cmp -s "$SRC/storage/$name" "$APP/storage/$name" &&
    fail "PASSPORT_KEY_NOT_INDEPENDENT_$name"
done
for file in BambodiaMcpOAuthTest.php BambodiaMcpOAuthConnectionsTest.php; do
  [[ -s "$APP/tests/Feature/$file" && ! -L "$APP/tests/Feature/$file" ]] ||
    fail "TEST_CLASS_MISSING_$file"
done

python3 - "$APP" "$SRC" <<'PY'
import json, pathlib, sys, xml.etree.ElementTree as ET
dst,src=map(pathlib.Path,sys.argv[1:])
env={}
for line in (dst/'.env').read_text().splitlines():
    if line and not line.lstrip().startswith('#') and '=' in line:
        k,v=line.split('=',1)
        if k in env: raise SystemExit('BLOCKED: DUPLICATE_TEST_ENV_KEY')
        env[k]=v.strip().strip('"').strip("'")
want={
    'APP_ENV':'testing','APP_DEBUG':'false',
    'APP_URL':'https://oauth-step25.invalid',
    'DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','DB_URL':'',
    'MAIL_MAILER':'array','QUEUE_CONNECTION':'sync',
    'CACHE_STORE':'array','SESSION_DRIVER':'array',
    'FILESYSTEM_DISK':'local',
}
if any(env.get(k)!=v for k,v in want.items()) or not env.get('APP_KEY','').startswith('base64:'):
    raise SystemExit('BLOCKED: DISPOSABLE_ENV_NOT_SQLITE_MEMORY_ONLY')
phpunit=ET.parse(dst/'phpunit.xml').getroot()
values={p.get('name'):p.get('value') for p in phpunit.findall('.//php/env')}
for k,v in {
    'APP_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:',
    'DB_URL':'','MAIL_MAILER':'array','SESSION_DRIVER':'array',
    'CACHE_STORE':'array','QUEUE_CONNECTION':'sync'
}.items():
    if values.get(k)!=v: raise SystemExit('BLOCKED: PHPUNIT_DB_OR_SERVICES_UNSAFE')
locked=json.loads((src/'composer.lock').read_text())
wantpk={p['name']:p['version'] for g in ('packages','packages-dev')
        for p in locked.get(g,[])}
installed=json.loads((dst/'vendor/composer/installed.json').read_text())
items=installed.get('packages',[]) if isinstance(installed,dict) else installed
have={p['name']:p['version'] for p in items}
if len(wantpk)!=129 or any(have.get(k)!=v for k,v in wantpk.items()):
    raise SystemExit('BLOCKED: INSTALLED_DEPENDENCIES_DO_NOT_MATCH_LOCK')
if 'phpunit/phpunit' not in have:
    raise SystemExit('BLOCKED: PHPUNIT_NOT_INSTALLED')
print('ISOLATED_SQLITE_ENV=VERIFIED')
print('COMPOSER_LOCK_AND_VENDOR_PARITY=129_PACKAGES')
print('PHPUNIT_TEST_FILE_COUNT=2')
PY

# Sanitize the entire child environment. Never inherit MySQL credentials,
# production service tokens, shell startup files, or connection URLs.
run_php_as_www() {
  (
    cd "$APP"
    runuser -u www -- env -i \
      HOME="$APP" USER=www LOGNAME=www \
      PATH="/www/server/php/85/bin:/usr/bin:/bin" \
      APP_ENV=testing APP_DEBUG=false \
      APP_URL=https://oauth-step25.invalid \
      DB_CONNECTION=sqlite DB_DATABASE=':memory:' DB_URL='' \
      DB_HOST=127.0.0.1 DB_PORT=9 DB_USERNAME=step25_disabled \
      DB_PASSWORD=step25_disabled DB_SOCKET='' \
      PASSPORT_CONNECTION=sqlite \
      CACHE_STORE=array SESSION_DRIVER=array \
      MAIL_MAILER=array QUEUE_CONNECTION=sync \
      FILESYSTEM_DISK=local \
      "$PHP" "$@"
  )
}

echo '========== VERIFY EFFECTIVE LARAVEL DATABASE CONFIGURATION =========='
run_php_as_www -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = [
    config("app.env")==="testing",
    config("app.debug")===false,
    config("app.url")==="https://oauth-step25.invalid",
    config("database.default")==="sqlite",
    config("database.connections.sqlite.database")===":memory:",
    !config("database.connections.sqlite.url"),
    in_array(config("passport.connection"), [null,"sqlite"], true),
    config("cache.default")==="array",
    config("session.driver")==="array",
    config("mail.default")==="array",
];
if(in_array(false,$checks,true)) {fwrite(STDERR,"EFFECTIVE_CONFIG=UNSAFE\n");exit(1);}
$pdo=\Illuminate\Support\Facades\DB::connection("sqlite")->getPdo();
if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=="sqlite") {
    fwrite(STDERR,"EFFECTIVE_PDO=NOT_SQLITE\n");exit(1);
}
echo "EFFECTIVE_LARAVEL_DB=SQLITE_MEMORY_ONLY\n";
echo "EFFECTIVE_EXTERNAL_SERVICES=DISABLED\n";
'
echo 'STEP25_FEATURE_TEST_PREFLIGHT=PASS'

if [[ "$MODE" == --check ]]; then
  echo 'RUN_MODE=READ_ONLY_PREFLIGHT'
  echo 'OAUTH_FEATURE_TESTS_EXECUTED=NONE'
  echo 'ACCEPTED_BROWSER_QA=UNCHANGED'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

[[ -c /dev/tty ]] || fail 'INTERACTIVE_OPERATOR_REQUIRED'
printf 'Type RUN-STEP25-TESTS to run PHPUnit ONLY in disposable SQLite memory: ' > /dev/tty
IFS= read -r ANSWER < /dev/tty
[[ "$ANSWER" == RUN-STEP25-TESTS ]] || fail 'OPERATOR_DECLINED'
unset ANSWER

echo '========== RUN OAUTH AUTHORIZATION AND TOKEN SECURITY TESTS =========='
run_php_as_www vendor/bin/phpunit \
  --configuration phpunit.xml --no-progress --colors=never \
  tests/Feature/BambodiaMcpOAuthTest.php
echo 'STEP25_OAUTH_SECURITY_TEST_CLASS=PASS'

echo '========== RUN OAUTH CONNECTION OWNERSHIP TESTS =========='
run_php_as_www vendor/bin/phpunit \
  --configuration phpunit.xml --no-progress --colors=never \
  tests/Feature/BambodiaMcpOAuthConnectionsTest.php
echo 'STEP25_OAUTH_CONNECTION_TEST_CLASS=PASS'

echo '========== FINAL TEST ACCEPTANCE =========='
echo 'STEP25_OAUTH_FEATURE_TESTS=PASS'
echo 'TEST_DATABASE=SQLITE_IN_MEMORY'
echo 'ACCEPTED_BROWSER_QA=UNCHANGED_BY_SCRIPT'
echo 'QA_MYSQL=NOT_TARGETED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
