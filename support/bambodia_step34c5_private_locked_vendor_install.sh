#!/usr/bin/env bash
# Step 34C.5: install locked PHP Composer packages ONLY in isolated QA root.
# --check is read-only. --install requires typed operator approval.
# No .env, keys, database migrations, public webroot or Nginx changes.
set -Eeuo pipefail
set +x
umask 077
export GIT_NO_LAZY_FETCH=1 GIT_OPTIONAL_LOCKS=0

MODE="$1"
case "$MODE" in --check|--install) ;; *)
  echo 'Usage: script --check|--install' >&2; exit 64 ;;
esac

QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
APP="/var/lib/bambodia-oauth-public-qa"
MANIFEST="$APP/.bambodia-qa-source-manifest"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
PHP="/www/server/php/85/bin/php"
COMPOSER="/usr/bin/composer"

fail() { echo "STEP34C5_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY FROZEN AND STAGED SOURCE =========='
test "$(id -u)" = 0 || fail NOT_ROOT
for bin in git python3 tar stat sha256sum cmp find realpath; do
  command -v "$bin" >/dev/null || fail REQUIRED_TOOL_MISSING
done
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(realpath -e "$QA")" = "$QA" ||
  fail FROZEN_QA_NOT_FOUND
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" ||
  fail FROZEN_SOURCE_COMMIT_CHANGED
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_SOURCE_TRACKED_DIRTY
test -f "$QA/composer.lock" && test ! -L "$QA/composer.lock" ||
  fail FROZEN_LOCKFILE_MISSING
test "$(sha256sum "$QA/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail FROZEN_LOCKFILE_CHANGED
test -d "$APP" && test ! -L "$APP" &&
  test "$(realpath -e "$APP")" = "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
  fail PUBLIC_QA_RELEASE_ROOT_UNSAFE
test ! -e "$APP/.git" && test ! -L "$APP/.git" ||
  fail PUBLIC_QA_MUST_BE_SOURCE_ARCHIVE
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock" &&
  test "$(stat -c '%u:%a' "$APP/composer.lock")" = '0:600' &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" &&
  cmp -s "$APP/composer.lock" "$QA/composer.lock" ||
  fail RECOVERED_LOCAL_LOCK_INVALID
test -f "$MANIFEST" && test ! -L "$MANIFEST" &&
  test "$(stat -c '%u:%a' "$MANIFEST")" = '0:600' ||
  fail PRIVATE_SOURCE_MANIFEST_UNSAFE
EXPECT_MANIFEST="$(printf 'source_sha=%s\ncomposer_lock_sha256=%s\npublic_endpoint=NOT_CONNECTED' "$QA_SHA" "$LOCK_SHA")"
test "$(cat "$MANIFEST")" = "$EXPECT_MANIFEST" ||
  fail SOURCE_MANIFEST_MISMATCH
for f in .env .env.production .env.staging .env.testing \
  storage/oauth-private.key storage/oauth-public.key \
  vendor public/storage public/hot bootstrap/cache/config.php; do
  test ! -e "$APP/$f" && test ! -L "$APP/$f" ||
    fail PRIVATE_RUNTIME_ALREADY_EXISTS
done
for name in .composer-home .composer-cache .composer-tmp; do
  test ! -e "$APP/$name" && test ! -L "$APP/$name" ||
    fail PRIVATE_COMPOSER_DIRECTORY_ALREADY_EXISTS
done
echo 'STAGED_SOURCE_MANIFEST_AND_LOCK=PASS'
echo 'STAGED_ENV_VENDOR_AND_KEYS=ABSENT'

echo '========== VERIFY EXACT 675 COMMITTED SOURCE FILES =========='
git -C "$QA" archive --format=tar "$QA_SHA" |
python3 -c '
import hashlib, pathlib, stat, sys, tarfile
root=pathlib.Path(sys.argv[1])
seen=set()
with tarfile.open(fileobj=sys.stdin.buffer,mode="r|") as tf:
    for m in tf:
        r=pathlib.PurePosixPath(m.name)
        if r.is_absolute() or not r.parts or any(x in ("..",".git") for x in r.parts):
            raise SystemExit("STEP34C5_BLOCKED=UNSAFE_ARCHIVE_PATH")
        path=root.joinpath(*r.parts)
        if m.isdir():
            if not path.is_dir() or path.is_symlink():
                raise SystemExit("STEP34C5_BLOCKED=SOURCE_DIRECTORY_MISMATCH")
            continue
        if not m.isfile() or r.as_posix() in seen:
            raise SystemExit("STEP34C5_BLOCKED=UNEXPECTED_ARCHIVE_MEMBER")
        try:
            st=path.lstat()
        except FileNotFoundError:
            raise SystemExit("STEP34C5_BLOCKED=MISSING_STAGED_SOURCE")
        if not stat.S_ISREG(st.st_mode) or st.st_size!=m.size:
            raise SystemExit("STEP34C5_BLOCKED=STAGED_SOURCE_SIZE_OR_TYPE")
        stream=tf.extractfile(m)
        if stream is None:
            raise SystemExit("STEP34C5_BLOCKED=ARCHIVE_READ_FAILED")
        original=hashlib.sha256()
        staged=hashlib.sha256()
        for chunk in iter(lambda:stream.read(1024*1024),b""): original.update(chunk)
        with path.open("rb") as f:
            for chunk in iter(lambda:f.read(1024*1024),b""): staged.update(chunk)
        if original.digest()!=staged.digest():
            raise SystemExit("STEP34C5_BLOCKED=STAGED_SOURCE_CONTENT_CHANGED")
        seen.add(r.as_posix())
if len(seen)!=675:
    raise SystemExit("STEP34C5_BLOCKED=FROZEN_ARCHIVE_FILE_COUNT_CHANGED")
actual=set()
for item in root.rglob("*"):
    if item.is_symlink():
        raise SystemExit("STEP34C5_BLOCKED=STAGED_SOURCE_SYMLINK")
    if item.is_file():
        actual.add(item.relative_to(root).as_posix())
expected=seen|{"composer.lock",".bambodia-qa-source-manifest"}
if actual!=expected:
    raise SystemExit("STEP34C5_BLOCKED=STAGED_SOURCE_EXTRA_OR_MISSING_FILES")
print("STAGED_COMMITTED_FILE_COUNT=675")
print("STAGED_SOURCE_BYTE_PARITY=PASS")
print("STAGED_EXTRA_FILES=LOCK_AND_MANIFEST_ONLY")
' "$APP" || fail STAGED_SOURCE_AUDIT_FAILED

echo '========== VERIFY EXISTING SITE AND ISOLATED DATABASES =========='
test -f "$SITE" && test ! -L "$SITE" &&
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail SITE_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail NGINX_VHOST_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail ORIGINAL_PRESERVATION_BACKUP_INVALID
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail ORIGINAL_BACKUP_HASH_FAILED
test -x "$MYSQL" || fail MYSQL_CLIENT_MISSING
for cnf in "$PUB_CNF" "$QA_CNF"; do
  test -f "$cnf" && test ! -L "$cnf" &&
  test "$(stat -c '%u:%a' "$cnf")" = '0:600' ||
    fail MYSQL_CREDENTIAL_FILE_UNSAFE
done
PUB_MYSQL() {
  "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"
}
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names bambodia_oauthqa_browser "$@"
}
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' ||
  fail PUBLIC_QA_PRINCIPAL_CHANGED
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail PUBLIC_QA_SCHEMA_CHANGED
test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" = 0 ||
  fail PUBLIC_QA_DB_NOT_EMPTY
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 ||
  fail INTERNAL_QA_USERS_CHANGED
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail INTERNAL_QA_CLIENTS_CHANGED
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0 ||
    fail INTERNAL_OAUTH_GRANTS_ACTIVE
done
echo 'PUBLIC_QA_DATABASE_EMPTY=PASS'
echo 'FROZEN_QA_DATABASE_BASELINE=PASS'
echo 'EXISTING_WEBROOT_VHOST_AND_BACKUP=UNCHANGED'

echo '========== VERIFY PHP85 AND APPROVED COMPOSER SOURCE =========='
test -x "$PHP" || fail PHP85_BINARY_UNAVAILABLE
"$PHP" -r '
if(PHP_MAJOR_VERSION!==8 || PHP_MINOR_VERSION!==5)
 {fwrite(STDERR,"PHP85_REQUIRED\n");exit(1);}
foreach(["pdo","pdo_mysql","openssl","mbstring","fileinfo","tokenizer","ctype","xml","dom"] as $e)
 if(!extension_loaded($e)) {fwrite(STDERR,"MISSING_EXTENSION_".$e."\n");exit(1);}
echo "PHP85_EXTENSION_PREFLIGHT=PASS\n";
' || fail PHP_EXTENSIONS_OR_VERSION_MISMATCH
test -f "$COMPOSER" && test ! -L "$COMPOSER" &&
  test "$(stat -c '%u' "$COMPOSER")" = 0 ||
  fail COMPOSER_PHP_ENTRYPOINT_UNSAFE
python3 - "$COMPOSER" <<'PY'
from pathlib import Path
import sys
with Path(sys.argv[1]).open("rb") as f: content=f.read(1024)
if not (content.startswith(b"<?php") or
    (content.startswith(b"#!") and b"<?php" in content[:512] and
    b"php" in content.split(b"\n",1)[0].lower())):
    raise SystemExit("STEP34C5_BLOCKED=COMPOSER_NOT_PHP_ENTRYPOINT")
print("COMPOSER_PHP_SOURCE_ENTRYPOINT=VERIFIED")
PY
echo 'PHP85_AND_COMPOSER_ENTRYPOINT=PASS'

if [ "$MODE" = '--check' ]; then
  echo 'STEP34C5_READ_ONLY_PREFLIGHT=PASS'
  echo 'COMPOSER_VERSION=NOT_RUN_IN_READ_ONLY_MODE'
  echo 'DEPENDENCIES=NOT_INSTALLED'
  echo 'MYSQL_AND_WEB_CONFIGURATION_WRITES=NONE'
  exit 0
fi

echo '========== EXPLICIT OPERATOR APPROVAL =========='
test -c /dev/tty || fail INTERACTIVE_TERMINAL_REQUIRED
echo "Only private Composer caches and vendor will be created under $APP."
echo 'This may download packages from the lockfile with scripts/plugins disabled.'
echo 'No application secrets, database migrations or public endpoint.'
printf 'Type INSTALL-PRIVATE-OAUTH-QA-VENDOR to approve: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'INSTALL-PRIVATE-OAUTH-QA-VENDOR' || fail OPERATOR_DECLINED
echo 'STEP34C5_EXPLICIT_INSTALL_APPROVAL=PASS'
test ! -e "$APP/vendor" && test ! -L "$APP/vendor" || fail VENDOR_APPEARED
for d in .composer-home .composer-cache .composer-tmp; do
  test ! -e "$APP/$d" && test ! -L "$APP/$d" || fail COMPOSER_DIRECTORY_APPEARED
  mkdir -m 700 -- "$APP/$d" || fail COMPOSER_PRIVATE_DIRECTORY_CREATE_FAILED
  test "$(stat -c '%u:%a' "$APP/$d")" = '0:700' ||
    fail COMPOSER_DIRECTORY_PERMISSIONS
done

echo '========== COMPOSER 2.2.6 WITH EXPLICIT PHP 8.5 =========='
VERSION="$(
  env -i HOME=/root PATH=/usr/bin:/bin \
    COMPOSER_HOME="$APP/.composer-home" \
    COMPOSER_CACHE_DIR="$APP/.composer-cache" \
    TMPDIR="$APP/.composer-tmp" COMPOSER_ALLOW_SUPERUSER=1 \
    "$PHP" -d "include_path=/usr/share/php:." \
    "$COMPOSER" --version --no-ansi --no-plugins 2>/dev/null
)" || fail COMPOSER_VERSION_COMMAND_FAILED
printf '%s\n' "$VERSION" |
python3 -c '
import re,sys
lines=[x.strip() for x in sys.stdin.read().splitlines()
       if x.strip().startswith("Composer")]
if len(lines)!=1 or not re.fullmatch(
 r"Composer(?: version)? 2\.2\.6(?: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})?",
 lines[0]):
 raise SystemExit("STEP34C5_BLOCKED=COMPOSER_VERSION_NOT_APPROVED")
' || fail COMPOSER_226_NOT_VERIFIED
unset VERSION
echo 'COMPOSER_APPROVED_VERSION=2.2.6'
echo 'COMPOSER_RUNTIME=PHP_8_5'

echo '========== PRIVATE LOCKED INSTALL - NO SCRIPTS OR PLUGINS =========='
(
  cd "$APP"
  env -i HOME=/root PATH=/usr/bin:/bin \
    COMPOSER_HOME="$APP/.composer-home" \
    COMPOSER_CACHE_DIR="$APP/.composer-cache" \
    TMPDIR="$APP/.composer-tmp" \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 \
    "$PHP" -d "include_path=/usr/share/php:." "$COMPOSER" install \
    --dry-run --no-dev --no-scripts --no-plugins \
    --prefer-dist --no-interaction --no-progress --no-ansi
  echo 'COMPOSER_LOCK_DRY_RUN=PASS'
  env -i HOME=/root PATH=/usr/bin:/bin \
    COMPOSER_HOME="$APP/.composer-home" \
    COMPOSER_CACHE_DIR="$APP/.composer-cache" \
    TMPDIR="$APP/.composer-tmp" \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 \
    "$PHP" -d "include_path=/usr/share/php:." "$COMPOSER" install \
    --no-dev --no-scripts --no-plugins --prefer-dist \
    --no-interaction --optimize-autoloader --no-progress --no-ansi
) || fail COMPOSER_FAILED_POSSIBLE_PARTIAL_VENDOR_INSPECT_BEFORE_RETRY

test -s "$APP/vendor/autoload.php" &&
  test ! -L "$APP/vendor/autoload.php" || fail AUTOLOAD_MISSING
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail COMPOSER_LOCK_MODIFIED_BY_INSTALL
test ! -e "$APP/.env" && test ! -e "$APP/storage/oauth-private.key" &&
  test ! -e "$APP/storage/oauth-public.key" &&
  test ! -e "$APP/bootstrap/cache/config.php" ||
  fail UNEXPECTED_APP_ENV_OR_KEYS
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail LIVE_HOST_MODIFIED
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" ||
  fail INTERNAL_QA_SOURCE_CHANGED
echo 'STEP34C5_PRIVATE_COMPOSER_DEPENDENCIES=PASS'
echo 'VENDOR_AUTOLOAD=INSTALLED'
echo 'COMPOSER_LOCK_HASH=UNCHANGED'
echo 'PACKAGE_SCRIPTS_AND_PLUGINS=DISABLED'
echo 'DEV_DEPENDENCIES=EXCLUDED'
echo 'APP_ENV_AND_PASSPORT_KEYS=NOT_CREATED'
echo 'PUBLIC_QA_DATABASE_MIGRATIONS=NOT_RUN'
echo 'PUBLIC_QA_ENDPOINT=NOT_CONNECTED'
echo 'ORIGINAL_QA_SITE_VHOST=UNCHANGED'
echo 'DNS_NGINX_TLS_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
