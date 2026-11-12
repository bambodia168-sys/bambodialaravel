#!/usr/bin/env bash
# Bambodia Step 34C.2 - privileged READ-ONLY MySQL collision audit.
# Does NOT create/alter a DB, user, grant, website, public release or keys.
set -Eeuo pipefail
set +x
umask 077

QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
PUBLIC_RELEASE="/var/lib/bambodia-oauth-public-qa"
HOST="oauth-qa.bambodia.com"
SITE="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
INDEX_HASH="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST_HASH="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
MYSQL="/www/server/mysql/bin/mysql"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
QA_DB="bambodia_oauthqa_browser"
PUBLIC_DB="bambodia_oauthqa_public"
PUBLIC_USER="bambodiaqa_public"

echo '========== VERIFY FROZEN QA, SITE AND PRESERVATION BACKUP =========='
test "$(id -u)" -eq 0
for tool in git sha256sum stat realpath awk; do command -v "$tool" >/dev/null; done
test -x "$MYSQL"
test -d "$QA/.git" && test ! -L "$QA"
test "$(realpath -e "$QA")" = "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -f "$QA/.env" && test ! -L "$QA/.env"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env"
grep -Fxq "DB_DATABASE=$QA_DB" "$QA/.env"
test -s "$QA/public/build/manifest.json"
test -d "$SITE" && test ! -L "$SITE"
test -f "$SITE/index.html" && test ! -L "$SITE/index.html"
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_HASH"
test -f "$VHOST" && test ! -L "$VHOST"
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_HASH"
test -d "$BACKUP" && test ! -L "$BACKUP"
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700"
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS )
test ! -e "$PUBLIC_RELEASE" && test ! -L "$PUBLIC_RELEASE"
test -f "$QA_CNF" && test ! -L "$QA_CNF"
test "$(stat -c '%u:%a' "$QA_CNF")" = "0:600"
echo 'FROZEN_QA_AND_EXISTING_HOST_FINGERPRINTS=PASS'
echo 'PUBLIC_RELEASE_PATH=ABSENT'
echo 'ORIGINAL_BACKUP_INTEGRITY=PASS'

echo '========== CONFIRM RESTRICTED QA DATABASE BASELINE =========='
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$QA_DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$QA_DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL -e "SELECT COUNT(*) FROM users WHERE email='oauth-admin@oauth-browser-qa.invalid' AND role='super_admin' AND account_status='active'")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
echo 'QA_MYSQL_RESTRICTED_BASELINE=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== CHECK FOR EXISTING LOCAL MYSQL ADMIN CONFIG =========='
# Never request, print or create a password. Only an existing root-owned
# private option file can supply credentials for the SELECT-only audit.
ADMIN_CNF="$(printenv BAMBODIA_MYSQL_ADMIN_CNF 2>/dev/null || true)"
if [ -z "$ADMIN_CNF" ]; then
  for candidate in "/root/bambodia-mysql-admin.cnf" "/root/.my.cnf"; do
    if [ -f "$candidate" ] && [ ! -L "$candidate" ]; then
      ADMIN_CNF="$candidate"
      break
    fi
  done
fi
if [ -z "$ADMIN_CNF" ]; then
  echo 'MYSQL_ADMIN_CLIENT_CONFIG=NOT_FOUND'
  echo 'STEP34C2_STATUS=BLOCKED_NO_PRIVILEGED_READ_ONLY_ACCESS'
  exit 34
fi
case "$ADMIN_CNF" in
  /root/*) ;;
  *) echo 'MYSQL_ADMIN_CLIENT_CONFIG=BLOCKED_PATH_OUTSIDE_ROOT'; exit 34 ;;
esac
test -f "$ADMIN_CNF" && test ! -L "$ADMIN_CNF"
case "$(stat -c '%u:%a' "$ADMIN_CNF")" in
  0:400|0:600) ;;
  *) echo 'MYSQL_ADMIN_CLIENT_CONFIG=BLOCKED_INSECURE_OWNER_OR_PERMISSIONS'; exit 34 ;;
esac
echo 'MYSQL_ADMIN_CLIENT_CONFIG=FOUND_ROOT_PRIVATE'
ADMIN_MYSQL() {
  "$MYSQL" --defaults-extra-file="$ADMIN_CNF" --protocol=SOCKET \
    --user=root --batch --raw --skip-column-names \
    --connect-timeout=5 -e "$1" 2>/dev/null
}
if ! IDENTITY="$(ADMIN_MYSQL 'SELECT CURRENT_USER()')"; then
  echo 'PRIVILEGED_MYSQL_CONNECTION=BLOCKED_UNAVAILABLE'
  exit 34
fi
case "$IDENTITY" in
  root@*) echo 'PRIVILEGED_MYSQL_IDENTITY=ROOT_LOCAL';;
  *) echo 'PRIVILEGED_MYSQL_IDENTITY=BLOCKED_NOT_ROOT'; exit 34 ;;
esac

echo '========== QUERY DATABASE, USER, AND OLD GRANT COLLISIONS =========='
if ! ORIGINAL_EXISTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bambodia_oauthqa_browser'")"; then
  echo 'ORIGINAL_QA_SCHEMA_VISIBILITY=BLOCKED'; exit 34
fi
test "$ORIGINAL_EXISTS" = 1
echo 'ORIGINAL_QA_SCHEMA_VISIBLE_TO_ADMIN=PASS'
if ! PUBLIC_EXISTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bambodia_oauthqa_public'")"; then
  echo 'PUBLIC_SCHEMA_VISIBILITY=BLOCKED'; exit 34
fi
if ! USER_EXISTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM mysql.user WHERE User='bambodiaqa_public'")"; then
  echo 'PUBLIC_PRINCIPAL_VISIBILITY=BLOCKED'; exit 34
fi
if ! DB_GRANTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM mysql.db WHERE Db='bambodia_oauthqa_public'")"; then
  echo 'PUBLIC_DB_GRANT_VISIBILITY=BLOCKED'; exit 34
fi
if ! TABLE_GRANTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM mysql.tables_priv WHERE Db='bambodia_oauthqa_public'")"; then
  echo 'PUBLIC_TABLE_GRANT_VISIBILITY=BLOCKED'; exit 34
fi
if ! COLUMN_GRANTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM mysql.columns_priv WHERE Db='bambodia_oauthqa_public'")"; then
  echo 'PUBLIC_COLUMN_GRANT_VISIBILITY=BLOCKED'; exit 34
fi
if ! PROC_GRANTS="$(ADMIN_MYSQL "SELECT COUNT(*) FROM mysql.procs_priv WHERE Db='bambodia_oauthqa_public'")"; then
  echo 'PUBLIC_ROUTINE_GRANT_VISIBILITY=BLOCKED'; exit 34
fi
for count in "$PUBLIC_EXISTS" "$USER_EXISTS" "$DB_GRANTS" \
  "$TABLE_GRANTS" "$COLUMN_GRANTS" "$PROC_GRANTS"; do
  [[ "$count" =~ ^[0-9]+$ ]] || {
    echo 'PUBLIC_MYSQL_COLLISION_READ=BLOCKED_UNEXPECTED_RESPONSE'
    exit 34
  }
done

echo "CANDIDATE_PUBLIC_SCHEMA_COUNT=$PUBLIC_EXISTS"
echo "CANDIDATE_PUBLIC_USERNAME_COUNT=$USER_EXISTS"
echo "ORPHAN_DATABASE_GRANT_ROWS=$DB_GRANTS"
echo "ORPHAN_TABLE_GRANT_ROWS=$TABLE_GRANTS"
echo "ORPHAN_COLUMN_GRANT_ROWS=$COLUMN_GRANTS"
echo "ORPHAN_ROUTINE_GRANT_ROWS=$PROC_GRANTS"
if [ "$PUBLIC_EXISTS" -ne 0 ] || [ "$USER_EXISTS" -ne 0 ] ||
   [ "$DB_GRANTS" -ne 0 ] || [ "$TABLE_GRANTS" -ne 0 ] ||
   [ "$COLUMN_GRANTS" -ne 0 ] || [ "$PROC_GRANTS" -ne 0 ]; then
  echo 'STEP34C2_COLLISION_STATUS=BLOCKED_EXISTING_MYSQL_OBJECTS'
  echo 'DATABASE_USER_AND_GRANTS_CREATION=NONE'
  exit 34
fi
echo 'STEP34C2_DATABASE_USERNAME_AND_GRANT_COLLISIONS=ABSENT'

echo '========== CONFIRM NO APPLICATION OR SERVER MODIFICATIONS =========='
test ! -e "$PUBLIC_RELEASE" && test ! -L "$PUBLIC_RELEASE"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_HASH"
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_HASH"
echo 'PUBLIC_QA_SCHEMA_USER_GRANTS=NOT_CREATED'
echo 'PUBLIC_OAUTH_CREDENTIALS=NOT_CREATED'
echo 'PUBLIC_QA_RELEASE=NOT_CREATED'
echo 'PUBLIC_HOSTNAME_REUSE=NOT_AUTHORIZED'
echo 'QA_DATABASE_WRITES=NONE'
echo 'PRIVILEGED_ADMIN_QUERIES=SELECT_ONLY'
echo 'DNS_NGINX_TLS_CHANGES=NONE'
echo 'GITHUB_MAIN_AND_PRODUCTION=UNCHANGED'
echo 'STEP34C2_READ_ONLY_DATABASE_COLLISION_AUDIT=PASS'
