#!/usr/bin/env bash
# Step 34C.4R: recover one local accepted Composer lockfile into a staged
# frozen-commit Git archive. --check makes NO writes; --repair needs approval.
# NEVER removes the staged source, modifies the frozen QA, DB, webroot, vhost,
# credentials, TLS, DNS or production.
set -Eeuo pipefail
set +x
umask 077
export GIT_OPTIONAL_LOCKS=0 GIT_NO_LAZY_FETCH=1

MODE="$1"
case "$MODE" in --check|--repair) ;; *)
  echo 'Usage: script --check|--repair' >&2; exit 64 ;;
esac
QA="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
APP="/var/lib/bambodia-oauth-public-qa"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
INDEX_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
fail() { printf 'STEP34C4_RECOVERY_BLOCKED=%s\n' "$1" >&2; exit 34; }

echo '========== VERIFY PRIVATE SOURCE BOUNDARY =========='
test "$(id -u)" -eq 0 || fail NOT_ROOT
for bin in git python3 tar sha256sum realpath stat find cmp ln mktemp; do
  command -v "$bin" >/dev/null || fail MISSING_REQUIRED_TOOL
done
test -d "$QA/.git" && test ! -L "$QA" || fail FROZEN_QA_NOT_FOUND
test "$(realpath -e "$QA")" = "$QA" || fail QA_PATH_SYMLINK
test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" || fail FROZEN_SHA_MISMATCH
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_TRACKED_SOURCE_CHANGED
test -f "$QA/composer.lock" && test ! -L "$QA/composer.lock" ||
  fail SOURCE_LOCK_MISSING
test "$(sha256sum "$QA/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail SOURCE_LOCK_HASH_MISMATCH
if git -C "$QA" cat-file -e "$SHA:composer.lock" 2>/dev/null; then
  fail COMPOSER_LOCK_UNEXPECTEDLY_TRACKED_IN_SOURCE
fi
test -d "$APP" && test ! -L "$APP" || fail PARTIAL_STAGE_NOT_FOUND
test "$(realpath -e "$APP")" = "$APP" || fail PARTIAL_STAGE_SYMLINK
test "$(stat -c '%u:%a' "$APP")" = '0:700' || fail STAGE_OWNER_OR_MODE
test ! -e "$APP/.git" && test ! -L "$APP/.git" || fail STAGED_GIT_DIRECTORY
for f in .env .env.production .env.staging .env.testing \
  vendor/autoload.php storage/oauth-private.key storage/oauth-public.key \
  public/storage public/hot .bambodia-qa-source-manifest; do
  test ! -e "$APP/$f" && test ! -L "$APP/$f" ||
    fail UNEXPECTED_STAGED_RUNTIME_OR_MANIFEST
done
echo 'FROZEN_ACCEPTED_LOCK=VERIFIED_LOCAL_UNTRACKED'
echo 'PARTIAL_RELEASE_ROOT=PRIVATE_700'

echo '========== VERIFY ORIGINAL SITE AND DATABASE BOUNDARY =========='
test -f "$SITE" && test ! -L "$SITE" &&
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$INDEX_SHA" ||
  fail EXISTING_SITE_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail EXISTING_VHOST_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail PRESERVATION_BACKUP_MISSING
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail PRESERVATION_BACKUP_CHANGED
test -x "$MYSQL" || fail MYSQL_UNAVAILABLE
for file in "$PUB_CNF" "$QA_CNF"; do
  test -f "$file" && test ! -L "$file" &&
  test "$(stat -c '%u:%a' "$file")" = '0:600' ||
    fail DB_CREDENTIAL_PERMISSIONS_CHANGED
done
PUB_MYSQL() {
  "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"
}
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names bambodia_oauthqa_browser "$@"
}
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail PUBLIC_SCHEMA_MISMATCH
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' ||
  fail PUBLIC_USER_MISMATCH
test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" = 0 ||
  fail PUBLIC_QA_DATABASE_NOT_EMPTY
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 ||
  fail INTERNAL_USERS_CHANGED
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail INTERNAL_CLIENTS_CHANGED
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0 ||
    fail INTERNAL_ACTIVE_OAUTH_GRANTS
done
echo 'ORIGINAL_SITE_VHOST_AND_BACKUP=UNCHANGED'
echo 'PUBLIC_QA_DATABASE=EMPTY'
echo 'FROZEN_QA_DATABASE=BASELINE_PASS'

echo '========== VERIFY ENTIRE STAGED GIT ARCHIVE BEFORE REPAIR =========='
# Compare the actual staged tree byte-for-byte to the exact Git archive.
# Reject extra/missing files, symlinks, non-regular archive entries and drift.
git -C "$QA" archive --format=tar "$SHA" |
python3 -c '
import hashlib, pathlib, stat, sys, tarfile
root=pathlib.Path(sys.argv[1])
seen=set()
directories=0
with tarfile.open(fileobj=sys.stdin.buffer,mode="r|") as tar:
    for member in tar:
        path=pathlib.PurePosixPath(member.name)
        if path.is_absolute() or not path.parts or any(p in ("..",".git") for p in path.parts):
            raise SystemExit("STEP34C4_RECOVERY_BLOCKED=UNSAFE_ARCHIVE_PATH")
        actual=root.joinpath(*path.parts)
        if member.isdir():
            if not actual.is_dir() or actual.is_symlink():
                raise SystemExit("STEP34C4_RECOVERY_BLOCKED=STAGED_DIRECTORY_MISMATCH")
            directories+=1
            continue
        if not member.isfile() or path in seen:
            raise SystemExit("STEP34C4_RECOVERY_BLOCKED=ARCHIVE_SPECIAL_OR_DUPLICATE")
        info=actual.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_size!=member.size:
            raise SystemExit("STEP34C4_RECOVERY_BLOCKED=STAGED_FILE_MISMATCH")
        source=tar.extractfile(member)
        if source is None:
            raise SystemExit("STEP34C4_RECOVERY_BLOCKED=ARCHIVE_READ_ERROR")
        a=hashlib.sha256()
        b=hashlib.sha256()
        for chunk in iter(lambda:source.read(1024*1024), b""): a.update(chunk)
        with actual.open("rb") as handle:
            for chunk in iter(lambda:handle.read(1024*1024), b""): b.update(chunk)
        if a.digest()!=b.digest():
            raise SystemExit("STEP34C4_RECOVERY_BLOCKED=STAGED_CONTENT_HASH_MISMATCH")
        seen.add(path.as_posix())
if len(seen)!=675:
    raise SystemExit("STEP34C4_RECOVERY_BLOCKED=ARCHIVED_FILE_COUNT_CHANGED")
actual_files=set()
for path in root.rglob("*"):
    if path.is_symlink():
        raise SystemExit("STEP34C4_RECOVERY_BLOCKED=STAGED_SYMLINK")
    if path.is_file():
        actual_files.add(path.relative_to(root).as_posix())
if actual_files!=seen:
    raise SystemExit("STEP34C4_RECOVERY_BLOCKED=STAGED_EXTRA_OR_MISSING_FILES")
print("STAGED_ARCHIVE_FILES=675")
print("STAGED_ARCHIVE_CONTENTS=BYTE_IDENTICAL")
print("STAGED_EXTRA_FILES=NONE")
' "$APP" || fail ARCHIVE_PARITY_CHECK_FAILED

test ! -e "$APP/composer.lock" && test ! -L "$APP/composer.lock" ||
  fail STAGED_LOCK_ALREADY_EXISTS
echo 'LOCAL_COMPOSER_LOCK_ONLY_MISSING_FILE=CONFIRMED'
if [ "$MODE" = '--check' ]; then
  echo 'STEP34C4_RECOVERY_READ_ONLY_PREFLIGHT=PASS'
  echo 'FILE_WRITES=NONE'
  echo 'DATABASE_AND_PUBLIC_SERVER_WRITES=NONE'
  exit 0
fi

echo '========== APPROVE SINGLE-FILE PRIVATE LOCK RECOVERY =========='
test -c /dev/tty || fail NO_INTERACTIVE_TERMINAL
echo 'Only the verified local composer.lock and one source manifest will be added'
echo "under $APP. No network downloads or application execution."
printf 'Type REPAIR-QA-COMPOSER-LOCK-ONLY to approve: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'REPAIR-QA-COMPOSER-LOCK-ONLY' || fail OPERATOR_DECLINED
echo 'STEP34C4_RECOVERY_APPROVAL=PASS'

echo '========== ATOMICALLY ADD VERIFIED LOCAL LOCK =========='
# Hard-link creation fails instead of replacing an existing destination.
TEMP="$(mktemp "$APP/.bambodia-lock-recover.XXXXXXXX")"
chmod 600 "$TEMP"
cat "$QA/composer.lock" > "$TEMP" ||
  fail TEMPORARY_LOCK_COPY_FAILED_REVIEW_REQUIRED
test "$(sha256sum "$TEMP" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail TEMPORARY_LOCK_HASH_MISMATCH
ln -- "$TEMP" "$APP/composer.lock" ||
  fail ATOMIC_LOCK_LINK_FAILED_REVIEW_REQUIRED
rm -f -- "$TEMP"
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock" &&
test "$(stat -c '%u:%a' "$APP/composer.lock")" = '0:600' &&
cmp -s "$QA/composer.lock" "$APP/composer.lock" &&
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail NEW_LOCK_VERIFICATION_FAILED

MANIFEST="$APP/.bambodia-qa-source-manifest"
test ! -e "$MANIFEST" && test ! -L "$MANIFEST" ||
  fail MANIFEST_PATH_COLLISION
( umask 077
  set -C
  printf 'source_sha=%s\ncomposer_lock_sha256=%s\npublic_endpoint=NOT_CONNECTED\n' \
    "$SHA" "$LOCK_SHA" > "$MANIFEST"
) || fail MANIFEST_WRITE_FAILED
test "$(stat -c '%u:%a' "$MANIFEST")" = '0:600' ||
  fail MANIFEST_MODE_MISMATCH
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$INDEX_SHA" ||
  fail ORIGINAL_SITE_CHANGED_AFTER_REPAIR
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail ORIGINAL_VHOST_CHANGED_AFTER_REPAIR
test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" ||
  fail FROZEN_QA_COMMIT_CHANGED
echo 'STEP34C4_RECOVERED_SOURCE_AND_LOCK=PASS'
echo 'RECOVERED_LOCK=BYTE_IDENTICAL_TO_ACCEPTED_INTERNAL_QA'
echo 'STAGED_DOTENV_AND_VENDOR=ABSENT'
echo 'PASSPORT_PRIVATE_KEYS=NOT_COPIED'
echo 'NEW_DATABASE_MIGRATIONS=NOT_RUN'
echo 'PUBLIC_OAUTH_ENDPOINT=NOT_DEPLOYED'
echo 'QA_DATABASE_WRITES=NONE'
echo 'ORIGINAL_SITE_DNS_NGINX_TLS_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
