#!/usr/bin/env bash
# Step 25: reconcile only the already prepared disposable SQLite workspace.
# --check performs NO writes. --reconcile needs typed confirmation and updates
# only /var/lib/bambodia-oauth-step25-sqlite; never accepted QA or production.
set -Eeuo pipefail
umask 077
MODE="${1:-}"
case "$MODE" in
  --check|--reconcile) ;;
  *) echo 'Usage: bash script --check|--reconcile' >&2; exit 64 ;;
esac
SOURCE="/var/lib/bambodia-oauth-browser-qa"
TARGET="/var/lib/bambodia-oauth-step25-sqlite"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
PHP="/www/server/php/85/bin/php"
SOURCE_LOCK="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
TARGET_LOCK_BEFORE="9849ea5623687bb9aebfd835a656551370f4a1095da021f4e97ee5f2b3190380"
BACKUP="$TARGET/.qa-home/composer.lock.before-reconcile"
fail() { printf 'STEP25_RECONCILE_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail 'ROOT_REQUIRED'
for cmd in git realpath stat python3 sha256sum cmp mktemp; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d /var/lib && ! -L /var/lib && "$(stat -c %u /var/lib)" == 0 ]] ||
  fail 'UNSAFE_VAR_LIB'
[[ "$SOURCE" != "$TARGET" ]] || fail 'SAME_WORKSPACE'
for dir in "$SOURCE" "$TARGET"; do
  [[ -d "$dir/.git" && ! -L "$dir" && ! -L "$dir/.git" ]] ||
    fail 'QA_TREE_MISSING_OR_SYMLINK'
  [[ "$(realpath -e "$dir")" == "$dir" ]] || fail 'REDIRECTED_QA_TREE'
  [[ "$(git -C "$dir" rev-parse HEAD)" == "$SHA" ]] ||
    fail 'QA_SOURCE_COMMIT_MISMATCH'
  [[ -z "$(git -C "$dir" status --porcelain --untracked-files=no)" ]] ||
    fail 'TRACKED_SOURCE_CHANGED'
  for f in composer.json composer.lock phpunit.xml; do
    [[ -s "$dir/$f" && ! -L "$dir/$f" ]] || fail "MISSING_OR_SYMLINK_$f"
  done
done
[[ -x "$PHP" && -s "$TARGET/vendor/autoload.php" &&
   -f "$TARGET/vendor/bin/phpunit" ]] || fail 'PHPUNIT_RUNTIME_MISSING'
[[ -f "$TARGET/.env" && ! -L "$TARGET/.env" ]] || fail 'TEST_ENV_MISSING'
[[ ! -e "$TARGET/.env.testing" &&
   ! -e "$TARGET/bootstrap/cache/config.php" ]] ||
  fail 'UNREVIEWED_ENV_OR_CONFIG_CACHE'
[[ -d "$TARGET/.qa-home" && ! -L "$TARGET/.qa-home" &&
   "$(stat -c %u "$TARGET/.qa-home")" == 0 ]] ||
  fail 'UNSAFE_COMPOSER_HOME'
[[ ! -e "$BACKUP" && ! -L "$BACKUP" ]] ||
  fail 'PRIOR_RECONCILIATION_BACKUP_EXISTS'
for key in oauth-private.key oauth-public.key; do
  [[ -s "$TARGET/storage/$key" && ! -L "$TARGET/storage/$key" &&
     "$(stat -c %a "$TARGET/storage/$key")" == 600 ]] ||
    fail "UNSAFE_TEST_KEY_$key"
done
cmp -s "$SOURCE/composer.json" "$TARGET/composer.json" ||
  fail 'COMPOSER_REQUIREMENTS_MISMATCH'
cmp -s "$SOURCE/phpunit.xml" "$TARGET/phpunit.xml" ||
  fail 'PHPUNIT_CONFIGURATION_MISMATCH'
[[ "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$SOURCE_LOCK" ]] ||
  fail 'ACCEPTED_SOURCE_LOCK_CHANGED'
[[ "$(sha256sum "$TARGET/composer.lock" | cut -d ' ' -f1)" == "$TARGET_LOCK_BEFORE" ]] ||
  fail 'DISPOSABLE_TARGET_LOCK_CHANGED'

# Read-only: print only package names and versions for the two differences.
# Never print .env, private keys, tokens or repository credentials.
python3 - "$SOURCE" "$TARGET" <<'PY'
import json,pathlib,sys,xml.etree.ElementTree as ET
src,dst=map(pathlib.Path,sys.argv[1:])
env={}
for line in (dst/".env").read_text().splitlines():
    if line and not line.lstrip().startswith("#") and "=" in line:
        k,v=line.split("=",1)
        if k in env: raise SystemExit("BLOCKED: DUPLICATE_TEST_ENV_KEY")
        env[k]=v.strip().strip('"').strip("'")
expected={
    "APP_ENV":"testing","APP_URL":"https://oauth-step25.invalid",
    "DB_CONNECTION":"sqlite","DB_DATABASE":":memory:","DB_URL":"",
    "MAIL_MAILER":"array","CACHE_STORE":"array",
    "SESSION_DRIVER":"array","QUEUE_CONNECTION":"sync"
}
if any(env.get(k)!=v for k,v in expected.items()) or not env.get("APP_KEY","").startswith("base64:"):
    raise SystemExit("BLOCKED: TEST_ENV_NOT_ISOLATED")
xml=ET.parse(dst/"phpunit.xml").getroot()
xe={p.get("name"):p.get("value") for p in xml.findall(".//php/env")}
for k,v in {"APP_ENV":"testing","DB_CONNECTION":"sqlite","DB_DATABASE":":memory:","DB_URL":""}.items():
    if xe.get(k)!=v: raise SystemExit("BLOCKED: PHPUnit_NOT_MEMORY_SQLITE")
a=json.loads((src/"composer.lock").read_text())
b=json.loads((dst/"composer.lock").read_text())
def versions(lock):
    return {p["name"]:p["version"] for group in ("packages","packages-dev") for p in lock.get(group,[])}
av,bv=versions(a),versions(b)
if a.get("content-hash")!=b.get("content-hash") or set(av)!=set(bv) or len(av)!=129:
    raise SystemExit("BLOCKED: DEPENDENCY_SET_MISMATCH")
delta=[(k,av[k],bv[k]) for k in sorted(av) if av[k]!=bv[k]]
if len(delta)!=2: raise SystemExit("BLOCKED: EXPECTED_TWO_VERSION_DIFFERENCES")
print("DISPOSABLE_ENV=VERIFIED")
print("LOCK_PACKAGE_COUNT=129")
print("VERSION_DIFFERENCE_COUNT=2")
for name,accepted,temporary in delta:
    print("PACKAGE_DELTA="+name+" | ACCEPTED="+accepted+" | DISPOSABLE="+temporary)
print("STEP25_LOCK_PREFLIGHT=PASS")
PY
if [[ "$MODE" == --check ]]; then
  echo 'MODE=READ_ONLY'
  echo 'DATABASE_WRITES=NONE'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

[[ -c /dev/tty ]] || fail 'TERMINAL_REQUIRED'
printf 'Type RECONCILE-STEP25 to change dependencies ONLY in disposable workspace: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == RECONCILE-STEP25 ]] || fail 'OPERATOR_DECLINED'
unset APPROVAL
[[ ! -e "$BACKUP" && ! -L "$BACKUP" ]] || fail 'BACKUP_ALREADY_EXISTS'
echo '========== BACKUP DISPOSABLE LOCKFILE =========='
cp -- "$TARGET/composer.lock" "$BACKUP"
chmod 600 "$BACKUP"
[[ "$(sha256sum "$BACKUP" | cut -d ' ' -f1)" == "$TARGET_LOCK_BEFORE" ]] ||
  fail 'BACKUP_HASH_MISMATCH'

echo '========== COPY ACCEPTED LOCK INTO DISPOSABLE WORKSPACE =========='
TEMP="$(mktemp "$TARGET/.composer-lock-reconcile.XXXXXXXX")"
trap 'rm -f -- "$TEMP"' EXIT
cp -- "$SOURCE/composer.lock" "$TEMP"
chmod 640 "$TEMP"
[[ "$(sha256sum "$TEMP" | cut -d ' ' -f1)" == "$SOURCE_LOCK" ]] ||
  fail 'LOCK_COPY_HASH_MISMATCH'
mv -T -- "$TEMP" "$TARGET/composer.lock"
[[ "$(sha256sum "$TARGET/composer.lock" | cut -d ' ' -f1)" == "$SOURCE_LOCK" ]] ||
  fail 'DISPOSABLE_LOCK_MISMATCH'
echo 'LOCKFILE_PARITY=PASS'

echo '========== INSTALL PINNED DEPENDENCIES ONLY IN DISPOSABLE WORKSPACE =========='
COMPOSER=''
for f in "$SOURCE/.qa-tools/composer.phar" \
         "/var/lib/bambodia-oauth-qa-acceptance/.qa-tools/composer.phar"; do
  if [[ -f "$f" && ! -L "$f" ]]; then COMPOSER="$f"; break; fi
done
[[ -n "$COMPOSER" ]] || fail 'COMPOSER_PHAR_UNAVAILABLE'
(
  cd "$TARGET"
  export COMPOSER_ALLOW_SUPERUSER=1
  export COMPOSER_HOME="$TARGET/.qa-home"
  export COMPOSER_CACHE_DIR="$TARGET/.qa-cache"
  "$PHP" "$COMPOSER" install --prefer-dist --no-interaction --no-progress \
    --no-plugins --no-scripts
)
echo '========== VERIFY INSTALLED PACKAGE VERSIONS =========='
python3 - "$SOURCE" "$TARGET" <<'PY'
import json,pathlib,sys
src,dst=map(pathlib.Path,sys.argv[1:])
a=(src/"composer.lock").read_bytes()
b=(dst/"composer.lock").read_bytes()
if a!=b: raise SystemExit("BLOCKED: POST_INSTALL_LOCK_MISMATCH")
lock=json.loads(a)
want={p["name"]:p["version"] for g in ("packages","packages-dev") for p in lock.get(g,[])}
installed=json.loads((dst/"vendor/composer/installed.json").read_text())
items=installed.get("packages",[]) if isinstance(installed,dict) else installed
have={p["name"]:p["version"] for p in items}
different=[k for k in want if have.get(k)!=want[k]]
if different:
    print("INSTALLED_VERSION_MISMATCH_COUNT="+str(len(different)))
    raise SystemExit("BLOCKED: INSTALLED_PACKAGE_VERSIONS_DIFFER")
print("INSTALLED_DEPENDENCY_PARITY=PASS")
print("PACKAGE_VERSION_COUNT="+str(len(want)))
PY
[[ -z "$(git -C "$TARGET" status --porcelain --untracked-files=no)" ]] ||
  fail 'TRACKED_SOURCE_MODIFIED'
echo 'STEP25_DEPENDENCY_RECONCILIATION=PASS'
echo 'PHPUNIT_TESTS_EXECUTED=NONE'
echo 'ACCEPTED_BROWSER_QA=UNCHANGED_BY_SCRIPT'
echo 'QA_MYSQL=UNTOUCHED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
