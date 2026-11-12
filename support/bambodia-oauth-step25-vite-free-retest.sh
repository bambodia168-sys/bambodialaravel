#!/usr/bin/env bash
# Step 25: re-run OAuth PHPUnit on ephemeral, SQLite-only QA.
# The approved HEAD stays at 34e86f.. in the existing disposable workspace.
# A separately blob-verified, test-only Vite-disabled PR test class is loaded
# from /tmp, not written into the accepted source or tracked QA checkout.
set -Eeuo pipefail
umask 077
MODE="${1:-}"
case "$MODE" in --check|--run) ;; *)
  echo 'Usage: bash script --check|--run' >&2; exit 64 ;;
esac
SRC="/var/lib/bambodia-oauth-browser-qa"
APP="/var/lib/bambodia-oauth-step25-sqlite"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
OLD_BLOB="9df410cb268b3d6c950bf76239d82636e84ba074"
NEW_BLOB="d76b1830c6c2129e1c4e367214b6a3f84423f31b"
PATCH_SHA="f08e378391a5a3c1b2c574d876f77ca02673bf50"
REPO="bambodia168-sys/bambodialaravel"
PHP="/www/server/php/85/bin/php"
fail(){ printf 'STEP25_RETEST_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
[[ -d /var/lib && ! -L /var/lib && "$(stat -c %u /var/lib)" == 0 ]] ||
  fail UNSAFE_VAR_LIB
[[ -x "$PHP" ]] || fail PHP85_UNAVAILABLE
id www >/dev/null || fail WWW_UNAVAILABLE
for tool in gh git sha256sum cmp python3 realpath; do
  command -v "$tool" >/dev/null || fail "COMMAND_MISSING_$tool"
done
for dir in "$SRC" "$APP"; do
  [[ -d "$dir/.git" && ! -L "$dir" && ! -L "$dir/.git" &&
     "$(realpath -e "$dir")" == "$dir" ]] ||
    fail WORKSPACE_PATH_UNSAFE
  [[ "$(git -C "$dir" rev-parse HEAD)" == "$SHA" ]] ||
    fail WORKSPACE_SHA_MISMATCH
  [[ -z "$(git -C "$dir" status --porcelain --untracked-files=no)" ]] ||
    fail TRACKED_SOURCE_CHANGED
  [[ -s "$dir/composer.lock" && ! -L "$dir/composer.lock" &&
     "$(sha256sum "$dir/composer.lock" | cut -d ' ' -f1)" == "$LOCK" ]] ||
    fail DEPENDENCY_LOCK_CHANGED
done
cmp -s "$SRC/composer.lock" "$APP/composer.lock" ||
  fail SOURCE_TARGET_LOCK_DIFFER
cmp -s "$SRC/phpunit.xml" "$APP/phpunit.xml" ||
  fail PHPUNIT_XML_DIFFER
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
   ! -e "$APP/.env.testing" && ! -L "$APP/.env.testing" &&
   ! -e "$APP/bootstrap/cache/config.php" &&
   ! -L "$APP/bootstrap/cache/config.php" ]] ||
  fail TEST_ENV_OR_CACHE_UNSAFE
[[ -f "$APP/vendor/bin/phpunit" &&
   -s "$APP/vendor/autoload.php" ]] ||
  fail PHPUNIT_NOT_INSTALLED
[[ "$(git hash-object "$APP/tests/Feature/BambodiaMcpOAuthConnectionsTest.php")" == "$OLD_BLOB" ]] ||
  fail ORIGINAL_CONNECTION_TEST_MODIFIED
for f in oauth-private.key oauth-public.key; do
  [[ -s "$APP/storage/$f" && ! -L "$APP/storage/$f" &&
     "$(stat -c %a "$APP/storage/$f")" == 600 ]] ||
    fail "UNSAFE_DISPOSABLE_KEY_$f"
  cmp -s "$SRC/storage/$f" "$APP/storage/$f" &&
    fail "TEST_KEY_SHARED_$f"
done

python3 - "$APP" <<'PY'
import json,pathlib,sys,xml.etree.ElementTree as ET
app=pathlib.Path(sys.argv[1])
env={}
for line in (app/'.env').read_text().splitlines():
    if line and not line.startswith('#') and '=' in line:
        k,v=line.split('=',1)
        if k in env: raise SystemExit('BLOCKED: DUPLICATE_TEST_ENV')
        env[k]=v.strip().strip('"').strip("'")
expected={'APP_ENV':'testing','APP_DEBUG':'false','APP_URL':'https://oauth-step25.invalid',
          'DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','DB_URL':'',
          'QUEUE_CONNECTION':'sync','MAIL_MAILER':'array',
          'CACHE_STORE':'array','SESSION_DRIVER':'array','FILESYSTEM_DISK':'local'}
if any(env.get(k)!=v for k,v in expected.items()) or not env.get('APP_KEY','').startswith('base64:'):
    raise SystemExit('BLOCKED: TEST_ENV_NOT_ISOLATED')
xml=ET.parse(app/'phpunit.xml').getroot()
x={v.get('name'):v.get('value') for v in xml.findall('.//php/env')}
if any(x.get(k)!=v for k,v in {'APP_ENV':'testing','DB_CONNECTION':'sqlite',
                                'DB_DATABASE':':memory:','DB_URL':''}.items()):
    raise SystemExit('BLOCKED: PHPUnit_MUST_USE_MEMORY_SQLITE')
lock=json.loads((app/'composer.lock').read_text())
expected={p['name']:p['version'] for g in ('packages','packages-dev')
          for p in lock.get(g,[])}
installed=json.loads((app/'vendor/composer/installed.json').read_text())
packages=installed.get('packages',[]) if isinstance(installed,dict) else installed
actual={p['name']:p['version'] for p in packages}
if len(expected)!=129 or any(actual.get(k)!=v for k,v in expected.items()):
    raise SystemExit('BLOCKED: DEPENDENCY_PARITY_MISSING')
print('SQLITE_TEST_ENV=VERIFIED')
print('PACKAGE_LOCK_PARITY=129_PACKAGES')
PY

WORK="$(mktemp -d /tmp/bambodia-oauth-step25-retest.XXXXXXXX)"
OVERLAY="$WORK/BambodiaMcpOAuthConnectionsTest.php"
cleanup(){ rm -f -- "$OVERLAY"; rmdir -- "$WORK" 2>/dev/null || true; }
trap cleanup EXIT
[[ "$(gh api "repos/$REPO/commits/$PATCH_SHA" --jq '.sha')" == "$PATCH_SHA" ]] ||
  fail TEST_PATCH_COMMIT_NOT_FOUND
gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/tests/Feature/BambodiaMcpOAuthConnectionsTest.php?ref=$PATCH_SHA" \
  > "$OVERLAY"
[[ -s "$OVERLAY" && "$(git hash-object "$OVERLAY")" == "$NEW_BLOB" ]] ||
  fail TEST_OVERLAY_INTEGRITY_MISMATCH
"$PHP" -l "$OVERLAY"
grep -Fq '$this->withoutVite();' "$OVERLAY" ||
  fail WITHOUT_VITE_NOT_CONFIGURED
chown root:www "$WORK" "$OVERLAY"
chmod 750 "$WORK"
chmod 640 "$OVERLAY"
echo 'PATCHED_TEST_BLOB=VERIFIED'
echo 'TRACKED_QA_SOURCE=UNCHANGED'

run_as_www(){
  (
    cd "$APP"
    runuser -u www -- env -i \
      HOME="$APP" USER=www LOGNAME=www \
      PATH="/www/server/php/85/bin:/usr/bin:/bin" \
      APP_ENV=testing APP_DEBUG=false APP_URL=https://oauth-step25.invalid \
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
run_as_www -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=[
config("app.env")==="testing", config("app.debug")===false,
config("app.url")==="https://oauth-step25.invalid",
config("database.default")==="sqlite",
config("database.connections.sqlite.database")===":memory:",
!config("database.connections.sqlite.url"),
in_array(config("passport.connection"),[null,"sqlite"],true),
config("cache.default")==="array",config("session.driver")==="array",
config("mail.default")==="array",
];
if(in_array(false,$c,true))exit(1);
$pdo=\Illuminate\Support\Facades\DB::connection("sqlite")->getPdo();
if($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=="sqlite")exit(1);
echo "EFFECTIVE_TEST_DATABASE=SQLITE_MEMORY_ONLY\n";
'
echo 'STEP25_RETEST_PREFLIGHT=PASS'
if [[ "$MODE" == --check ]]; then
  echo 'TESTS_EXECUTED=NONE'
  echo 'QA_SOURCE_UNCHANGED=YES'
  echo 'PRODUCTION_UNCHANGED=YES'
  exit 0
fi

[[ -c /dev/tty ]] || fail INTERACTIVE_TERMINAL_REQUIRED
printf 'Type RUN-STEP25-RETEST to execute OAuth tests on disposable SQLite: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
[[ "$CONFIRM" == RUN-STEP25-RETEST ]] || fail OPERATOR_DECLINED
unset CONFIRM

echo '========== FULL OAUTH SECURITY TEST CLASS =========='
run_as_www vendor/bin/phpunit \
  --configuration phpunit.xml --no-progress --colors=never --do-not-cache-result \
  tests/Feature/BambodiaMcpOAuthTest.php
echo 'STEP25_SECURITY_CLASS=PASS'

echo '========== OAUTH CONNECTIONS / VITE-DISABLED TEST CLASS =========='
run_as_www vendor/bin/phpunit \
  --configuration phpunit.xml --no-progress --colors=never --do-not-cache-result \
  "$OVERLAY"
echo 'STEP25_CONNECTIONS_CLASS=PASS'
echo 'STEP25_RETEST=PASS'
echo 'TEST_DATABASE=SQLITE_MEMORY_ONLY'
echo 'TRACKED_QA_SOURCE=UNCHANGED'
echo 'ACCEPTED_BROWSER_QA=UNCHANGED_BY_SCRIPT'
echo 'QA_MYSQL=NOT_TARGETED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
