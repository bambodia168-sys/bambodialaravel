#!/usr/bin/env bash
# Step 34C.4: stage previously accepted source in separate root-private QA app.
# Does not copy .env, Passport keys, DB data, vendor or alter public routing.
set -Eeuo pipefail
set +x
umask 077
QA="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
APP="/var/lib/bambodia-oauth-public-qa"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
PUB_DB="bambodia_oauthqa_public"
PUB_USER="bambodiaqa_public"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
QA_DB="bambodia_oauthqa_browser"
MYSQL="/www/server/mysql/bin/mysql"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
INDEX_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
fail() { echo "STEP34C4_BLOCKED=$1" >&2; exit 34; }

echo '========== READ-ONLY RELEASE PRECONDITIONS =========='
test "$(id -u)" = 0 && test -c /dev/tty || fail OPERATOR_OR_TERMINAL
for bin in git tar stat sha256sum realpath find; do
  command -v "$bin" >/dev/null || fail MISSING_TOOL
done
test -x "$MYSQL" || fail MYSQL_MISSING
test -d "$QA/.git" && test ! -L "$QA" || fail FROZEN_QA_MISSING
test "$(realpath -e "$QA")" = "$QA" || fail FROZEN_QA_PATH
test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" || fail SOURCE_COMMIT_CHANGED
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail SOURCE_TRACKED_DIRTY
test "$(sha256sum "$QA/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail LOCKFILE_CHANGED
test -f "$QA/.env" && test ! -L "$QA/.env" || fail INTERNAL_ENV_MISSING
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env" ||
  fail INTERNAL_ISSUER_CHANGED
grep -Fxq "DB_DATABASE=$QA_DB" "$QA/.env" || fail INTERNAL_DB_CHANGED
test ! -e "$APP" && test ! -L "$APP" || fail NEW_RELEASE_PATH_OCCUPIED
test -d /var/lib && test ! -L /var/lib || fail NEW_RELEASE_PARENT
test -f "$SITE" && test ! -L "$SITE" &&
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$INDEX_SHA" ||
  fail EXISTING_SITE_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail EXISTING_VHOST_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700" ||
  fail ORIGINAL_BACKUP_MISSING
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail ORIGINAL_BACKUP_CHANGED
echo 'FROZEN_SOURCE_SITE_VHOST_AND_BACKUP=PASS'

echo '========== CONFIRM SEPARATE DATABASES =========='
test -f "$PUB_CNF" && test ! -L "$PUB_CNF" &&
test "$(stat -c '%u:%a' "$PUB_CNF")" = "0:600" ||
  fail NEW_DB_CREDENTIAL_MISSING
test -f "$QA_CNF" && test ! -L "$QA_CNF" &&
test "$(stat -c '%u:%a' "$QA_CNF")" = "0:600" ||
  fail INTERNAL_DB_CREDENTIAL_MISSING
PUB_MYSQL() { "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"; }
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$QA_DB" "$@"
}
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = "$PUB_DB" || fail PUBLIC_DB_NOT_AVAILABLE
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = "$PUB_USER@127.0.0.1" ||
  fail PUBLIC_DB_WRONG_PRINCIPAL
test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" = 0 ||
  fail PUBLIC_DB_NOT_EMPTY
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$QA_DB" || fail INTERNAL_DB_NOT_AVAILABLE
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 || fail INTERNAL_USERS_CHANGED
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail INTERNAL_CLIENTS_CHANGED
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0 ||
    fail INTERNAL_ACTIVE_GRANTS
done
echo 'SEPARATE_PUBLIC_DB_EMPTY=PASS'
echo 'FROZEN_QA_DB_UNCHANGED=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== TRACKED SOURCE SAFETY =========='
for file in .env .env.production .env.staging storage/oauth-private.key \
  storage/oauth-public.key database/database.sqlite public/storage; do
  if git -C "$QA" ls-tree -r --name-only "$SHA" | grep -Fxq "$file"; then
    fail REPOSITORY_TRACKS_RUNTIME_SECRET
  fi
done
for file in artisan composer.json composer.lock bootstrap/app.php \
  public/index.php config/mcp.php; do
  test -f "$QA/$file" || fail REQUIRED_SOURCE_FILE_MISSING
done
echo 'TRACKED_SECRET_EXCLUSION=PASS'

echo '========== OPERATOR APPROVAL, SOURCE STAGING ONLY =========='
echo "New private directory: $APP"
echo 'Stage committed Laravel source ONLY, no .env, no vendor, no OAuth keys.'
echo 'Existing public website and database will remain unchanged.'
printf 'Type STAGE-ISOLATED-OAUTH-QA-SOURCE to approve: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = "STAGE-ISOLATED-OAUTH-QA-SOURCE" || fail OPERATOR_DECLINED
echo 'STEP34C4_STAGING_APPROVAL=PASS'

echo '========== CREATE PRIVATE SOURCE RELEASE =========='
mkdir -m 700 -- "$APP" || fail APP_DIRECTORY_CREATE_FAILED
test "$(stat -c '%u:%a' "$APP")" = '0:700' || fail APP_ROOT_PERMISSIONS
# Git archive only includes tracked content at the frozen accepted SHA.
# If extraction fails, keep the partial private folder for inspection.
git -C "$QA" archive --format=tar "$SHA" | tar -xf - -C "$APP" ||
  fail GIT_ARCHIVE_FAILED_PARTIAL_DIR_REQUIRES_REVIEW
if find "$APP" -type l -print -quit | grep -q .; then
  fail ARCHIVED_SYMLINK_REQUIRES_MANUAL_REVIEW
fi
for file in .env .env.production .env.staging storage/oauth-private.key \
  storage/oauth-public.key database/database.sqlite; do
  test ! -e "$APP/$file" && test ! -L "$APP/$file" ||
    fail PRIVATE_RUNTIME_FILE_DETECTED
done
test ! -d "$APP/vendor" && test ! -e "$APP/public/hot" ||
  fail UNEXPECTED_RUNTIME_FILES
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail STAGED_LOCK_HASH_MISMATCH
for file in artisan composer.json bootstrap/app.php public/index.php config/mcp.php; do
  test -f "$APP/$file" && test ! -L "$APP/$file" ||
    fail STAGED_REQUIRED_SOURCE_MISSING
  test "$(git -C "$QA" show "$SHA:$file" | sha256sum | awk '{print $1}')" = \
       "$(sha256sum "$APP/$file" | awk '{print $1}')" ||
    fail STAGED_SOURCE_HASH_MISMATCH
done
printf 'source_sha=%s\ncomposer_lock_sha256=%s\npublic_endpoint=NOT_CONNECTED\n' \
  "$SHA" "$LOCK_SHA" > "$APP/.bambodia-qa-source-manifest"
chmod 600 "$APP/.bambodia-qa-source-manifest"
test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
  fail APP_ROOT_PERMISSIONS_CHANGED
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$INDEX_SHA" ||
  fail EXISTING_SITE_CHANGED_POST
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail EXISTING_VHOST_CHANGED_POST
test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" ||
  fail INTERNAL_SOURCE_CHANGED_POST

echo 'STEP34C4_PRIVATE_SOURCE_STAGED=PASS'
echo "STAGED_SOURCE_SHA=$SHA"
echo 'STAGED_RELEASE_ROOT_MODE=ROOT_700'
echo 'STAGED_DOTENV=ABSENT'
echo 'STAGED_VENDOR_AND_PASSPORT_KEYS=ABSENT'
echo 'MIGRATIONS=NOT_RUN'
echo 'PUBLIC_RELEASE_CONNECTED_TO_NGINX=NO'
echo 'FROZEN_QA_AND_ORIGINAL_SITE=UNCHANGED'
echo 'DNS_NGINX_TLS_CHANGES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
