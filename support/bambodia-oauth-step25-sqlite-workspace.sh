#!/usr/bin/env bash
# Bambodia OAuth Step 25: dedicated disposable SQLite PHPUnit workspace.
# --check is read-only. --prepare creates ONLY a previously absent /var/lib path.
# NEVER run RefreshDatabase tests against existing MySQL/aaPanel QA, staging or prod.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in
  --check|--prepare) ;;
  *) echo 'Usage: bash script --check|--prepare' >&2; exit 64 ;;
esac

SRC="/var/lib/bambodia-oauth-browser-qa"
APP="/var/lib/bambodia-oauth-step25-sqlite"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
PHP="/www/server/php/85/bin/php"
fail() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail 'Must be executed by root.'
[[ -d "$SRC/.git" && ! -L "$SRC" && ! -L "$SRC/.git" ]] ||
  fail 'Approved browser-QA source missing or symlinked.'
[[ "$(realpath -e "$SRC")" == "$SRC" ]] ||
  fail 'Approved browser-QA source redirects.'
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] ||
  fail 'Source commit differs from approved QA SHA.'
[[ -z "$(git -C "$SRC" status --porcelain --untracked-files=no)" ]] ||
  fail 'Approved browser-QA source has tracked changes.'
[[ -f "$SRC/phpunit.xml" && -f "$SRC/composer.lock" && -f "$SRC/composer.json" ]] ||
  fail 'Pinned test configuration or dependency lock missing.'
[[ -d /var/lib && ! -L /var/lib && "$(stat -c '%u' /var/lib)" == 0 ]] ||
  fail 'Expected root-owned /var/lib unavailable.'
[[ "$(realpath -m -- "$APP")" == "$APP" ]] ||
  fail 'New target path redirects outside dedicated /var/lib root.'
[[ ! -e "$APP" && ! -L "$APP" ]] ||
  fail 'Step 25 destination already exists; never overwrite or clean it.'
[[ -x "$PHP" ]] || fail 'Expected PHP 8.5 binary unavailable.'
id www >/dev/null || fail 'www account unavailable.'
command -v openssl >/dev/null || fail 'OpenSSL unavailable.'
command -v git >/dev/null || fail 'Git unavailable.'
command -v python3 >/dev/null || fail 'Python 3 unavailable.'
[[ ! -e "$SRC/.env.testing" && ! -L "$SRC/.env.testing" ]] ||
  fail 'Unexpected source .env.testing; review before isolating.'

"$PHP" -r '
foreach (["pdo_sqlite","sqlite3","openssl","mbstring"] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "BLOCKED: Required PHP extension unavailable: ".$ext.PHP_EOL);
        exit(1);
    }
}
echo "PHP_TEST_EXTENSIONS=PASS\n";
'

python3 - "$SRC/phpunit.xml" <<'PY'
import sys
import xml.etree.ElementTree as ET
root=ET.parse(sys.argv[1]).getroot()
found={x.get('name'):x.get('value') for x in root.findall('.//php/env')}
want={
    'APP_ENV':'testing',
    'DB_CONNECTION':'sqlite',
    'DB_DATABASE':':memory:',
    'DB_URL':'',
    'MAIL_MAILER':'array',
    'SESSION_DRIVER':'array',
    'CACHE_STORE':'array',
}
if any(found.get(k)!=v for k,v in want.items()):
    raise SystemExit("BLOCKED: PHPUnit config is not memory-only isolated SQLite.")
print("PHPUNIT_MEMORY_DATABASE=VERIFIED")
PY

# Use only an existing Composer executable. Never download or update Composer.
COMPOSER_KIND=''
COMPOSER_PATH=''
for candidate in "$SRC/.qa-tools/composer.phar" \
                 "/var/lib/bambodia-oauth-qa-acceptance/.qa-tools/composer.phar"; do
  if [[ -f "$candidate" && ! -L "$candidate" ]]; then
    COMPOSER_KIND=phar
    COMPOSER_PATH="$candidate"
    break
  fi
done
if [[ -z "$COMPOSER_KIND" ]] && command -v composer >/dev/null 2>&1; then
  COMPOSER_KIND=binary
  COMPOSER_PATH="$(command -v composer)"
fi
[[ -n "$COMPOSER_KIND" ]] || fail 'No existing Composer executable found. No changes made.'

if [[ "$COMPOSER_KIND" == phar ]]; then
  "$PHP" "$COMPOSER_PATH" --version >/dev/null ||
    fail 'Existing Composer PHAR failed PHP 8.5 startup.'
else
  "$COMPOSER_PATH" --version >/dev/null ||
    fail 'Composer executable failed startup.'
fi
echo "APPROVED_SOURCE_SHA=$SHA"
echo "DISPOSABLE_WORKSPACE=$APP"
echo "COMPOSER_KIND=$COMPOSER_KIND"
echo "COMPOSER_EXECUTABLE=VERIFIED"
echo "STEP25_PREFLIGHT=PASS"

if [[ "$MODE" == --check ]]; then
  echo "READ_ONLY=YES"
  echo "ACCEPTED_BROWSER_QA=UNCHANGED"
  echo "PRODUCTION=UNCHANGED"
  exit 0
fi

[[ -c /dev/tty ]] || fail 'Operator terminal required.'
printf 'Type PREPARE-STEP25 to create ONLY the new disposable test workspace: ' > /dev/tty
IFS= read -r CONFIRM < /dev/tty
[[ "$CONFIRM" == PREPARE-STEP25 ]] || fail 'Operator declined.'
unset CONFIRM

echo '========== CLONE TRACKED SOURCE INTO DEDICATED WORKSPACE =========='
git -c core.hooksPath=/dev/null clone --local --no-hardlinks --no-checkout -- "$SRC" "$APP"
[[ -d "$APP/.git" && ! -L "$APP" ]] || fail 'Disposable checkout not created safely.'
git -c core.hooksPath=/dev/null -C "$APP" checkout --detach "$SHA"
[[ "$(git -C "$APP" rev-parse HEAD)" == "$SHA" ]] || fail 'Disposable source SHA mismatch.'
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] ||
  fail 'Disposable source changed unexpectedly.'
for f in .env .env.testing storage/oauth-private.key storage/oauth-public.key vendor/autoload.php; do
  [[ ! -e "$APP/$f" && ! -L "$APP/$f" ]] ||
    fail "Unexpected private/runtime file in new checkout: $f"
done

echo '========== CREATE ISOLATED SQLITE-ONLY CONFIGURATION =========='
KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
[[ "$KEY" == base64:* && "${#KEY}" -gt 40 ]] || fail 'QA-only application key generation failed.'
(
  set -C
  cat > "$APP/.env" <<EOF
APP_NAME="Bambodia OAuth Step25"
APP_ENV=testing
APP_KEY=$KEY
APP_DEBUG=false
APP_URL=https://oauth-step25.invalid
LOG_CHANNEL=stderr
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
DB_URL=
MAIL_MAILER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
CACHE_STORE=array
FILESYSTEM_DISK=local
PULSE_ENABLED=false
TELESCOPE_ENABLED=false
NIGHTWATCH_ENABLED=false
EOF
)
unset KEY

mkdir -p "$APP/.qa-home" "$APP/.qa-cache" \
  "$APP/storage/framework/cache/data" \
  "$APP/storage/framework/sessions" \
  "$APP/storage/framework/views" \
  "$APP/bootstrap/cache"
chmod 700 "$APP/.qa-home" "$APP/.qa-cache"
chmod 640 "$APP/.env"

echo '========== INSTALL LOCKED DEV DEPENDENCIES IN NEW WORKSPACE ONLY =========='
(
  cd "$APP"
  export COMPOSER_ALLOW_SUPERUSER=1
  export COMPOSER_HOME="$APP/.qa-home"
  export COMPOSER_CACHE_DIR="$APP/.qa-cache"
  export COMPOSER_DISABLE_XDEBUG_WARN=1
  if [[ "$COMPOSER_KIND" == phar ]]; then
    "$PHP" "$COMPOSER_PATH" install \
      --prefer-dist --no-interaction --no-progress --no-plugins --no-scripts
  else
    "$COMPOSER_PATH" install \
      --prefer-dist --no-interaction --no-progress --no-plugins --no-scripts
  fi
)
[[ -f "$APP/vendor/autoload.php" && -f "$APP/vendor/bin/phpunit" ]] ||
  fail 'Dev dependencies missing; preserve new workspace for inspection.'

echo '========== ISOLATE TEST RUNTIME PERMISSIONS =========='
chown -R root:www "$APP"
chmod -R g+rX "$APP"
chmod 750 "$APP"
chmod 640 "$APP/.env"
# Composer home/cache can contain private package credentials; keep root-only.
# Runtime PHP never needs the Git object database either.
chown -R root:root "$APP/.qa-home" "$APP/.qa-cache" "$APP/.git"
chmod -R go-rwx "$APP/.qa-home" "$APP/.qa-cache" "$APP/.git"
chown -R www:www "$APP/storage" "$APP/bootstrap/cache"
chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"

echo '========== PREPARE QA-ONLY PASSPORT TEST KEYS =========='
(
  cd "$APP"
  runuser -u www -- env -i PATH="/www/server/php/85/bin:/usr/bin:/bin" \
    APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' DB_URL='' \
    "$PHP" artisan passport:keys --no-interaction
)
for name in oauth-private.key oauth-public.key; do
  [[ -s "$APP/storage/$name" && ! -L "$APP/storage/$name" ]] ||
    fail "QA-only Passport key missing: $name"
  chmod 600 "$APP/storage/$name"
  [[ ! -e "$SRC/storage/$name" ]] ||
    ! cmp -s "$APP/storage/$name" "$SRC/storage/$name" ||
    fail 'Disposable test keys match accepted browser QA keys.'
done

[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] ||
  fail 'Tracked source changed after preparation.'
[[ ! -e "$APP/bootstrap/cache/config.php" ]] ||
  fail 'Unexpected config cache in dedicated test runtime.'
echo 'STEP25_TEST_WORKSPACE=PREPARED'
echo 'TEST_DATABASE=SQLITE_MEMORY_ONLY'
echo 'PHPUNIT_DEV_DEPENDENCIES=INSTALLED_SEPARATELY'
echo 'PASSPORT_KEYS=NEW_DISPOSABLE_TEST_PAIR'
echo 'TESTS_EXECUTED=NONE'
echo 'ACCEPTED_BROWSER_QA=UNCHANGED'
echo 'QA_MYSQL=UNCHANGED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
