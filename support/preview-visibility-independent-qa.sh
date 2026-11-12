#!/usr/bin/env bash
# Bambodia Issue #35: stage the reviewed source in a NEW aaPanel QA directory.
# SOURCE-ONLY: never connects to MySQL, reads .env secrets, runs artisan,
# writes production files, creates a vhost, or enables visibility writes.
set -Eeuo pipefail
set +x
umask 077

REPO="bambodia168-sys/bambodialaravel"
SOURCE_SHA="133f82a3e8681f3efbc8de9424a83dc3306e15d5"
PROD="/www/wwwroot/bambodia.com"
QA="/www/wwwroot/bambodia-preview-qa"
MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi

fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
(( $# <= 1 )) || fail 'Usage: --check or --stage'
[[ "$MODE" == "--check" || "$MODE" == "--stage" ]] || fail 'Usage: --check or --stage'
[[ "$(id -u)" -eq 0 ]] || fail 'Run as root on the authorized aaPanel server'
[[ ! -L /www && ! -L /www/wwwroot && -d /www/wwwroot ]] || fail 'Unexpected or linked aaPanel parent'
[[ ! -L "$PROD" && -d "$PROD" && -f "$PROD/artisan" ]] || fail 'Production path not an ordinary Laravel directory'
[[ "$(realpath -e "$PROD")" != "$(realpath -m "$QA")" ]] || fail 'Production and QA resolve to the same directory'
for tool in git realpath find chown chmod install mv mktemp; do
  command -v "$tool" >/dev/null 2>&1 || fail "Missing prerequisite: $tool"
done
id www >/dev/null 2>&1 || fail 'aaPanel www service user is missing'

echo '========== BAMBODIA PREVIEW VISIBILITY INDEPENDENT QA =========='
printf 'MODE=%s\nSOURCE_SHA=%s\n' "$MODE" "$SOURCE_SHA"
echo 'PRODUCTION=READ_ONLY_NO_CHANGES'
echo 'DB_ACCESS=NONE'
echo 'MIGRATIONS=NONE'
echo 'WRITE_SWITCH=NOT_ENABLED'
echo 'HTTP_VHOST=NOT_MODIFIED'

if [[ -e "$QA" || -L "$QA" ]]; then
  [[ ! -L "$QA" && -d "$QA/.git" && -f "$QA/artisan" ]] ||
    fail 'QA destination already exists but is not the expected staged checkout'
  git -C "$QA" rev-parse HEAD | grep -Fxq "$SOURCE_SHA" ||
    fail 'Existing QA directory is not at the approved commit'
  [[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
    fail 'Existing QA source has tracked modifications'
  echo 'QA_SOURCE=ALREADY_STAGED_AT_APPROVED_COMMIT'
  if [[ -e "$QA/.env" ]]; then
    echo 'QA_ENV=EXISTS_REQUIRES_INDEPENDENT_ISOLATION_AUDIT'
  else
    echo 'QA_ENV=NOT_CONFIGURED'
  fi
  echo 'RESULT=STOP_BEFORE_DB_OR_MIGRATION'
  exit 0
fi

echo 'QA_SOURCE=NOT_YET_STAGED'
if [[ "$MODE" == "--check" ]]; then
  [[ -x /www/server/php/85/bin/php ]] && echo 'PHP85=AVAILABLE' || echo 'PHP85=MISSING'
  [[ -x /www/server/mysql/bin/mysql ]] && echo 'AAPANEL_MYSQL_CLIENT=AVAILABLE' || echo 'AAPANEL_MYSQL_CLIENT=NOT_FOUND'
  echo 'RESULT=READY_FOR_EXPLICIT_STAGE_IF_PREREQUISITES_PASS'
  exit 0
fi

[[ -x /www/server/php/85/bin/php ]] || fail 'PHP 8.5 CLI missing; do not stage for a different runtime'
[[ ! -e "$QA" && ! -L "$QA" ]] || fail 'QA destination must be empty'
[[ ! -e "$QA/.env" ]] || fail 'Existing QA environment cannot be overwritten'

TMP="$(mktemp -d /www/wwwroot/.bambodia-preview-qa-incoming.XXXXXXXX)"
completed=0
cleanup() {
  rc=$?
  trap - EXIT
  if (( completed == 0 )) && [[ -n "$TMP" && -d "$TMP" && ! -L "$TMP" &&
    "$TMP" == /www/wwwroot/.bambodia-preview-qa-incoming.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT

# Clone from GitHub, NOT from the live aaPanel application.
# Full main history lets us checkout the exact reviewed commit even if main advances.
git clone --quiet --no-checkout --single-branch --branch main \
  "https://github.com/$REPO.git" "$TMP"
[[ "$(git -C "$TMP" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail 'Unrecognized repository origin'
git -C "$TMP" cat-file -e "$SOURCE_SHA^{commit}" ||
  fail 'Approved merge commit not found in fetched main history'
git -C "$TMP" -c advice.detachedHead=false checkout --quiet --detach "$SOURCE_SHA"
[[ "$(git -C "$TMP" rev-parse HEAD)" == "$SOURCE_SHA" ]] ||
  fail 'Incorrect checkout commit'
[[ -z "$(git -C "$TMP" status --porcelain)" ]] ||
  fail 'Unexpected source modifications'
[[ -f "$TMP/artisan" &&
   -f "$TMP/database/migrations/2026_11_11_000020_create_preview_visibility_actions_table.php" &&
   -f "$TMP/config/preview_visibility.php" ]] ||
  fail 'Required source or visibility migration missing'
[[ ! -e "$TMP/.env" && ! -e "$TMP/vendor" ]] ||
  fail 'Clone unexpectedly contains private environment or installed vendor code'
[[ -z "$(find "$TMP" -type l -print -quit)" ]] ||
  fail 'Repository has symlinks; require manual review before staging'

# Create private runtime directories; NO application key or database configuration yet.
for dir in \
  storage/app/private storage/framework/cache/data \
  storage/framework/sessions storage/framework/views storage/logs \
  bootstrap/cache; do
  install -d -m 0700 "$TMP/$dir"
done

chown -R root:www "$TMP"
chmod -R u+rwX,g+rX,o-rwx "$TMP"
chmod 0700 "$TMP/.git"
chown -R www:www "$TMP/storage" "$TMP/bootstrap/cache"
find "$TMP/storage" "$TMP/bootstrap/cache" -type d -exec chmod 0700 {} +
[[ ! -e "$QA" && ! -L "$QA" ]] || fail 'Destination appeared during staging'
mv -T -- "$TMP" "$QA"
completed=1

[[ "$(git -C "$QA" rev-parse HEAD)" == "$SOURCE_SHA" ]] ||
  fail 'Post-stage source SHA mismatch'
echo 'QA_SOURCE=STAGED_EXACT_MERGE_COMMIT'
echo 'QA_PRIVATE_STORAGE=SEPARATE_EMPTY_DIRECTORIES'
echo 'QA_ENV=NOT_CREATED'
echo 'QA_MYSQL_DATABASE=NOT_CREATED'
echo 'QA_SESSIONS=NOT_STARTED'
echo 'QA_APPLICATION_KEY=NOT_CREATED'
if [[ -f "$QA/composer.lock" && -f "$QA/package-lock.json" ]]; then
  echo 'PACKAGE_LOCKFILES=PRESENT_REVIEW_REQUIRED'
else
  echo 'PACKAGE_LOCKFILES=INCOMPLETE_REPRODUCIBLE_BUILD_NOT_PROVEN'
fi
echo 'RESULT=SOURCE_STAGED_STOP_BEFORE_DATABASE_CREATION'
echo 'NEXT=Perform DB credential and separate APP_KEY preflight before any migration'
