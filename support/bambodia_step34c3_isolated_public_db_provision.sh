#!/usr/bin/env bash
# Step 34C.3 - new isolated public OAuth QA MySQL schema/user ONLY.
# Requires explicit operator approval. Does NOT alter internal QA, public vhost,
# TLS/DNS, Laravel source or production. No migration or public exposure.
set -Eeuo pipefail
set +x
umask 077

QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
QA_DB="bambodia_oauthqa_browser"
RELEASE="/var/lib/bambodia-oauth-public-qa"
HOST="oauth-qa.bambodia.com"
SITE="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
INDEX_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
MYSQL="/www/server/mysql/bin/mysql"
PUBLIC_DB="bambodia_oauthqa_public"
PUBLIC_USER="bambodiaqa_public"
PUBLIC_CNF="/root/bambodia-oauth-public-qa.cnf"
DB_PASSWORD=''
ADMIN_ERR=''

cleanup() {
  DB_PASSWORD=''
  if [ -n "$ADMIN_ERR" ] && [ -f "$ADMIN_ERR" ] && [ ! -L "$ADMIN_ERR" ]; then
    rm -f -- "$ADMIN_ERR"
  fi
}
trap cleanup EXIT

fail() {
  echo "STEP34C3_BLOCKED=$1" >&2
  exit 34
}

echo '========== VERIFY ISOLATION AND PREVIOUS BACKUP =========='
test "$(id -u)" -eq 0 || fail NOT_ROOT
test -c /dev/tty || fail NO_INTERACTIVE_TTY
for name in git sha256sum stat realpath awk openssl mysql; do
  if [ "$name" = mysql ]; then continue; fi
  command -v "$name" >/dev/null || fail MISSING_TOOL
done
test -x "$MYSQL" || fail MYSQL_BINARY_MISSING
test -d "$QA/.git" && test ! -L "$QA" || fail FROZEN_QA_MISSING
test "$(realpath -e "$QA")" = "$QA" || fail FROZEN_QA_SYMLINK
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" || fail FROZEN_SHA_CHANGED
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" || fail FROZEN_SOURCE_DIRTY
test -f "$QA/.env" && test ! -L "$QA/.env" || fail FROZEN_ENV_MISSING
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env" || fail FROZEN_URL_CHANGED
grep -Fxq "DB_DATABASE=$QA_DB" "$QA/.env" || fail FROZEN_DB_CHANGED
test ! -e "$RELEASE" && test ! -L "$RELEASE" || fail PUBLIC_RELEASE_OCCUPIED
test ! -e "$PUBLIC_CNF" && test ! -L "$PUBLIC_CNF" || fail PUBLIC_CREDENTIAL_FILE_EXISTS
test -f "$QA_CNF" && test ! -L "$QA_CNF" || fail QA_CREDENTIAL_MISSING
test "$(stat -c '%u:%a' "$QA_CNF")" = "0:600" || fail QA_CREDENTIAL_PERMISSIONS
test -f "$SITE/index.html" && test ! -L "$SITE/index.html" || fail SITE_CHANGED
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_SHA" || fail SITE_HASH_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" || fail VHOST_CHANGED
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" || fail VHOST_HASH_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" || fail BACKUP_MISSING
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700" || fail BACKUP_PERMISSIONS
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) || fail BACKUP_HASH_CHANGED
echo 'FROZEN_SOURCE_SITE_AND_BACKUP=PASS'

QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$QA_DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$QA_DB" || fail ORIGINAL_DB_MISSING
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 || fail ORIGINAL_USERS_CHANGED
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 || fail ORIGINAL_CLIENTS_CHANGED
for t in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $t WHERE revoked=0")" = 0 || fail ORIGINAL_OAUTH_GRANTS_ACTIVE
done
echo 'FROZEN_QA_DATABASE_BASELINE=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== PRIVILEGED SELECT-ONLY COLLISION RECHECK =========='
echo 'The MySQL client will prompt for your existing root password locally.'
echo 'Enter the password ONLY in the masked aaPanel terminal prompt.'
SQL_CHECK="
SELECT 'IDENTITY',CURRENT_USER();
SELECT 'ORIGINAL',COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bambodia_oauthqa_browser';
SELECT 'SCHEMA',COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bambodia_oauthqa_public';
SELECT 'USERNAME',COUNT(*) FROM mysql.user WHERE User='bambodiaqa_public';
SELECT 'DBGRANTS',COUNT(*) FROM mysql.db WHERE Db='bambodia_oauthqa_public';
SELECT 'TABLEGRANTS',COUNT(*) FROM mysql.tables_priv WHERE Db='bambodia_oauthqa_public';
SELECT 'COLUMNGRANTS',COUNT(*) FROM mysql.columns_priv WHERE Db='bambodia_oauthqa_public';
SELECT 'ROUTINEGRANTS',COUNT(*) FROM mysql.procs_priv WHERE Db='bambodia_oauthqa_public';
"
if ! SQL_RESULT="$("$MYSQL" --no-defaults --protocol=TCP --host=127.0.0.1 \
    --port=3306 --user=root --password --connect-timeout=5 \
    --batch --raw --skip-column-names --execute "$SQL_CHECK" 2>/dev/null)"; then
  fail MYSQL_ADMIN_NOT_AUTHORIZED
fi
count=0
while IFS=$'\t' read -r label value; do
  count=$((count+1))
  case "$count:$label" in
    1:IDENTITY) [[ "$value" = root@* ]] || fail MYSQL_ADMIN_NOT_ROOT ;;
    2:ORIGINAL) [ "$value" = 1 ] || fail ORIGINAL_SCHEMA_UNAVAILABLE ;;
    3:SCHEMA|4:USERNAME|5:DBGRANTS|6:TABLEGRANTS|7:COLUMNGRANTS|8:ROUTINEGRANTS)
      [ "$value" = 0 ] || fail DATABASE_USERNAME_OR_GRANT_COLLISION ;;
    *) fail MYSQL_PRECHECK_BAD_RESPONSE ;;
  esac
done <<< "$SQL_RESULT"
[ "$count" = 8 ] || fail MYSQL_PRECHECK_BAD_ROW_COUNT
unset SQL_RESULT SQL_CHECK
echo 'PUBLIC_DB_USER_AND_GRANT_COLLISIONS=ABSENT_AT_RECHECK'

echo '========== EXPLICIT APPROVAL FOR NEW QA DATABASE AND USER =========='
echo 'This will CREATE only the isolated MySQL database bambodia_oauthqa_public,'
echo 'a restricted MySQL user bambodiaqa_public@127.0.0.1 and its database grants,'
echo 'plus a root-only credential file /root/bambodia-oauth-public-qa.cnf.'
echo 'It will NOT run migrations or configure a public endpoint.'
printf 'Type CREATE-ISOLATED-OAUTH-QA-DATABASE to approve: ' > /dev/tty
IFS= read -r approval < /dev/tty
[ "$approval" = 'CREATE-ISOLATED-OAUTH-QA-DATABASE' ] || fail USER_DECLINED
echo 'STEP34C3_EXPLICIT_PROVISIONING_APPROVAL=PASS'

echo '========== GENERATE NEW ISOLATED DATABASE CREDENTIAL =========='
DB_PASSWORD="$(openssl rand -hex 32)"
[[ "$DB_PASSWORD" =~ ^[0-9a-f]{64}$ ]] || fail RANDOM_GENERATOR_FAILED
test ! -e "$PUBLIC_CNF" && test ! -L "$PUBLIC_CNF" || fail PUBLIC_CREDENTIAL_COLLISION
( umask 077
  set -C
  {
    printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\nport=3306\nprotocol=tcp\ndatabase=%s\n' \
      "$PUBLIC_USER" "$DB_PASSWORD" "$PUBLIC_DB"
  } > "$PUBLIC_CNF"
)
test "$(stat -c '%u:%a' "$PUBLIC_CNF")" = "0:600" || fail PUBLIC_CREDENTIAL_PERMISSIONS
echo 'NEW_RANDOM_DB_CREDENTIAL=GENERATED_ROOT_PRIVATE'
echo 'PASSWORD_PRINTED_OR_PASSED_AS_ARGUMENT=NO'

echo '========== CREATE ONLY ISOLATED DATABASE, USER AND GRANTS =========='
echo 'MySQL will ask for the administrator password again, locally.'
ADMIN_ERR="$(mktemp /root/bambodia-oauth-public-mysql.XXXXXXXX)"
if ! "$MYSQL" --no-defaults --protocol=TCP --host=127.0.0.1 \
    --port=3306 --user=root --password --connect-timeout=5 \
    --batch --raw --skip-column-names \
    >/dev/null 2>"$ADMIN_ERR" <<SQL
CREATE DATABASE \`bambodia_oauthqa_public\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bambodiaqa_public'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
  CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`bambodia_oauthqa_public\`.* TO 'bambodiaqa_public'@'127.0.0.1';
SQL
then
  echo 'STEP34C3_PROVISIONING=BLOCKED_POSSIBLE_PARTIAL_MYSQL_CHANGES'
  echo 'PRIVATE_CREDENTIAL_FILE=RETAINED_FOR_AUTHORIZED_RECOVERY'
  fail MYSQL_ADMIN_WRITE_FAILED
fi
DB_PASSWORD=''

echo '========== VERIFY NEW RESTRICTED USER AND EMPTY DATABASE =========='
if ! NEW_ID="$("$MYSQL" --defaults-extra-file="$PUBLIC_CNF" \
  --batch --raw --skip-column-names \
  -e 'SELECT CURRENT_USER()' 2>/dev/null)"; then
  fail NEW_USER_CONNECT_FAILED_REQUIRES_REVIEW
fi
case "$NEW_ID" in
  "bambodiaqa_public@127.0.0.1") ;;
  *) fail NEW_USER_WRONG_PRINCIPAL ;;
esac
test "$("$MYSQL" --defaults-extra-file="$PUBLIC_CNF" --batch --skip-column-names \
  -e 'SELECT DATABASE()')" = "$PUBLIC_DB" || fail NEW_SCHEMA_WRONG_NAME
test "$("$MYSQL" --defaults-extra-file="$PUBLIC_CNF" --batch --skip-column-names \
  -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public'")" = 0 ||
    fail NEW_SCHEMA_NOT_EMPTY
echo 'PUBLIC_QA_SCHEMA_CREATED=PASS'
echo 'PUBLIC_QA_RESTRICTED_ACCOUNT_AUTHENTICATED=PASS'
echo 'PUBLIC_QA_SCHEMA_EMPTY=PASS'

echo '========== VERIFY ORIGINAL SOURCE, SITE, AND BACKUP UNCHANGED =========='
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" || fail FROZEN_SHA_CHANGED_POST
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" || fail FROZEN_DIRTY_POST
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_SHA" || fail SITE_CHANGED_POST
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" || fail VHOST_CHANGED_POST
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) || fail BACKUP_CHANGED_POST
test ! -e "$RELEASE" && test ! -L "$RELEASE" || fail PUBLIC_RELEASE_CREATED_UNEXPECTEDLY
echo 'FROZEN_QA_ORIGINAL_SITE_BACKUP=UNCHANGED'
echo 'PASSPORT_KEYS_AND_OAUTH_CLIENT=NOT_CREATED'
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'PUBLIC_QA_RELEASE=NOT_CREATED'
echo 'PUBLIC_HOSTNAME_REUSE=NOT_AUTHORIZED'
echo 'DNS_NGINX_TLS_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
echo 'STEP34C3_ISOLATED_PUBLIC_QA_DATABASE_PROVISIONING=PASS'
