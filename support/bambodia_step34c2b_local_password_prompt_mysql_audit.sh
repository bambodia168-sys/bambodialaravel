#!/usr/bin/env bash
# Step 34C.2B: alternate interactive LOCAL MySQL administrator collision audit.
# Root MySQL password stays at the operator terminal; never stored or echoed.
# Fixed SELECT statements only; no user, database, grant, or release changes.
set -Eeuo pipefail
set +x
umask 077
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
QA_DB="bambodia_oauthqa_browser"
PUB_RELEASE="/var/lib/bambodia-oauth-public-qa"
SITE="/www/wwwroot/oauth-qa.bambodia.com"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
INDEX_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
MYSQL="/www/server/mysql/bin/mysql"

echo '========== VERIFY FROZEN QA, SITE AND BACKUP =========='
test "$(id -u)" -eq 0
test -c /dev/tty && test -x "$MYSQL"
test -d "$QA/.git" && test ! -L "$QA"
test "$(realpath -e "$QA")" = "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -f "$QA/.env" && test ! -L "$QA/.env"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env"
grep -Fxq "DB_DATABASE=$QA_DB" "$QA/.env"
test ! -e "$PUB_RELEASE" && test ! -L "$PUB_RELEASE"
test -f "$QA_CNF" && test ! -L "$QA_CNF"
test "$(stat -c '%u:%a' "$QA_CNF")" = '0:600'
test -f "$SITE/index.html" && test ! -L "$SITE/index.html"
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_SHA"
test -f "$VHOST" && test ! -L "$VHOST"
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA"
test -d "$BACKUP" && test ! -L "$BACKUP"
test "$(stat -c '%u:%a' "$BACKUP")" = '0:700'
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS )
echo 'QA_SOURCE_SITE_AND_BACKUP=PASS'

QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$QA_DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$QA_DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
for t in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $t WHERE revoked=0")" = 0
done
echo 'ORIGINAL_QA_DATABASE=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== CONFIRM LOCAL INTERACTIVE MYSQL AUDIT =========='
echo 'The MySQL client may request its administrator password at your terminal.'
echo 'Enter it only into the masked local terminal prompt, never into ChatGPT.'
echo 'No password is saved in script files, arguments, or shell history.'
printf 'Type MYSQL-READ-ONLY-AUDIT to continue: ' > /dev/tty
IFS= read -r approval < /dev/tty
if [ "$approval" != 'MYSQL-READ-ONLY-AUDIT' ]; then
  echo 'STEP34C2B_CONFIRMATION=DECLINED'
  exit 1
fi
echo 'STEP34C2B_CONFIRMATION=PASS'

SQL="
SELECT 'IDENTITY',CURRENT_USER();
SELECT 'ORIGINAL_SCHEMA',COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bambodia_oauthqa_browser';
SELECT 'PUBLIC_SCHEMA',COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bambodia_oauthqa_public';
SELECT 'PUBLIC_USER',COUNT(*) FROM mysql.user WHERE User='bambodiaqa_public';
SELECT 'DB_GRANTS',COUNT(*) FROM mysql.db WHERE Db='bambodia_oauthqa_public';
SELECT 'TABLE_GRANTS',COUNT(*) FROM mysql.tables_priv WHERE Db='bambodia_oauthqa_public';
SELECT 'COLUMN_GRANTS',COUNT(*) FROM mysql.columns_priv WHERE Db='bambodia_oauthqa_public';
SELECT 'ROUTINE_GRANTS',COUNT(*) FROM mysql.procs_priv WHERE Db='bambodia_oauthqa_public';
"
unset MYSQL_PWD MYSQL_TEST_LOGIN_FILE MYSQL_HOME
# --no-defaults avoids option-file credentials; --password has no argument.
# Authentication prompts locally on the operator's terminal with input hidden.
if ! RESULTS="$("$MYSQL" --no-defaults --protocol=TCP --host=127.0.0.1 \
  --port=3306 --user=root --password --connect-timeout=5 \
  --batch --raw --skip-column-names --execute "$SQL")"; then
  echo 'STEP34C2B_MYSQL_AUTH_OR_PERMISSION=BLOCKED'
  echo 'PUBLIC_DATABASE_COLLISION=UNKNOWN'
  exit 34
fi

n=0
while IFS=$'\t' read -r label value; do
  n=$((n + 1))
  case "$n:$label" in
    1:IDENTITY)
      case "$value" in root@*) echo 'STEP34C2B_MYSQL_IDENTITY=ROOT_LOCAL_LOOPBACK';;
        *) echo 'STEP34C2B_MYSQL_IDENTITY=BLOCKED_NOT_ROOT'; exit 34;; esac
      ;;
    2:ORIGINAL_SCHEMA)
      test "$value" = 1 || { echo 'ORIGINAL_QA_SCHEMA_VISIBILITY=BLOCKED'; exit 34; }
      echo 'ORIGINAL_QA_SCHEMA_VISIBLE_TO_ADMIN=PASS'
      ;;
    3:PUBLIC_SCHEMA|4:PUBLIC_USER|5:DB_GRANTS|6:TABLE_GRANTS|7:COLUMN_GRANTS|8:ROUTINE_GRANTS)
      [[ "$value" =~ ^[0-9]+$ ]] || { echo 'STEP34C2B_RESPONSE=BLOCKED_INVALID_COUNT'; exit 34; }
      echo "$label=$value"
      if [ "$value" != 0 ]; then
        echo 'STEP34C2B_COLLISION=BLOCKED_EXISTING_DB_USER_OR_GRANTS'
        exit 34
      fi
      ;;
    *)
      echo 'STEP34C2B_RESPONSE=BLOCKED_UNEXPECTED_ROW'
      exit 34
      ;;
  esac
done <<< "$RESULTS"
test "$n" -eq 8 || { echo 'STEP34C2B_RESPONSE=BLOCKED_ROW_COUNT'; exit 34; }

test ! -e "$PUB_RELEASE" && test ! -L "$PUB_RELEASE"
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_SHA"
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
echo 'STEP34C2B_MYSQL_COLLISIONS=ABSENT_AT_CHECK_TIME'
echo 'PUBLIC_DATABASE_AND_PRINCIPAL=NOT_CREATED'
echo 'ADMIN_PASSWORD_NOT_STORED=PASS'
echo 'PUBLIC_OAUTH_ENDPOINT=NOT_DEPLOYED'
echo 'DNS_NGINX_TLS_WRITES=NONE'
echo 'MYSQL_QUERIES=SELECT_ONLY'
echo 'FROZEN_QA_AND_PRODUCTION=UNCHANGED'
echo 'STEP34C2B_READ_ONLY_MYSQL_COLLISION_AUDIT=PASS'
