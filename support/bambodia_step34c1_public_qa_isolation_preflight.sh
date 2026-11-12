#!/usr/bin/env bash
# Bambodia Step 34C.1: READ-ONLY public OAuth QA release isolation preflight.
# Checks existing occupancy, backup, frozen source and DB; DOES NOT provision.
set -Eeuo pipefail
set +x
umask 077

QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_HASH="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
HOST="oauth-qa.bambodia.com"
SITE="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
INDEX_HASH="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST_HASH="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
RELEASE="/var/lib/bambodia-oauth-public-qa"
PUBLIC_DB="bambodia_oauthqa_public"
QA_DB="bambodia_oauthqa_browser"
CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
PHP84="/www/server/php/84/bin/php"
PHP85="/www/server/php/85/bin/php"

echo '========== VERIFY ORIGINAL FROZEN QA =========='
test "$(id -u)" -eq 0
for tool in git sha256sum stat realpath awk python3 grep; do
  command -v "$tool" >/dev/null
done
test -d "$QA/.git" && test ! -L "$QA"
test "$(realpath -e "$QA")" = "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -f "$QA/composer.lock" && test ! -L "$QA/composer.lock"
test "$(sha256sum "$QA/composer.lock" | awk '{print $1}')" = "$LOCK_HASH"
test -f "$QA/.env" && test ! -L "$QA/.env"
test "$(stat -c '%U:%G:%a' "$QA/.env")" = "root:www:640"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env"
grep -Fxq "DB_DATABASE=$QA_DB" "$QA/.env"
test -s "$QA/public/build/manifest.json"
test ! -e "$QA/public/hot"
test -f "$QA/config/mcp.php"
grep -Fq "'https://chatgpt.com'" "$QA/config/mcp.php"
echo 'FROZEN_INTERNAL_QA_SOURCE=PASS'
echo 'FROZEN_QA_HOST_AND_DB=ISOLATED_INVALID_ORIGIN'

echo '========== VERIFY ORIGINAL QA DATABASE =========='
test -x "$MYSQL"
test -f "$CNF" && test ! -L "$CNF"
test "$(stat -c '%u:%a' "$CNF")" = "0:600"
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$QA_DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$QA_DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL -e "SELECT COUNT(*) FROM users WHERE email='oauth-admin@oauth-browser-qa.invalid' AND role='super_admin' AND account_status='active'")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
echo 'ORIGINAL_QA_DATABASE_BASELINE=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== VERIFY PRESERVED OCCUPIED HOSTNAME =========='
test -d "$SITE" && test ! -L "$SITE"
test "$(realpath -e "$SITE")" = "$SITE"
test -f "$SITE/index.html" && test ! -L "$SITE/index.html"
test "$(sha256sum "$SITE/index.html" | awk '{print $1}')" = "$INDEX_HASH"
test -f "$VHOST" && test ! -L "$VHOST"
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_HASH"
test -d "$BACKUP" && test ! -L "$BACKUP"
test "$(realpath -e "$BACKUP")" = "$BACKUP"
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700"
for part in site.tar nginx-vhost.conf public-cert-sha256.txt RESTORE_NOTES.txt SHA256SUMS; do
  test -f "$BACKUP/$part" && test ! -L "$BACKUP/$part"
  test "$(stat -c '%u:%a' "$BACKUP/$part")" = "0:600"
done
(
  cd "$BACKUP"
  sha256sum --status -c SHA256SUMS
)
echo 'EXISTING_PUBLIC_SITE=OCCUPIED_AND_PRESERVED'
echo 'EXISTING_VHOST_AND_INDEX_FINGERPRINTS=PASS'
echo 'STEP34B5_BACKUP_CHECKSUMS=PASS'

echo '========== CHECK CANDIDATE PUBLIC RELEASE COLLISIONS =========='
echo "PROPOSED_APP_DIRECTORY=$RELEASE"
echo "PROPOSED_PUBLIC_DATABASE=$PUBLIC_DB"
if [ -L "$RELEASE" ]; then
  echo 'PUBLIC_RELEASE_PATH=SYMLINK_COLLISION'
elif [ -e "$RELEASE" ]; then
  echo 'PUBLIC_RELEASE_PATH=OCCUPIED_REVIEW_REQUIRED'
else
  echo 'PUBLIC_RELEASE_PATH=ABSENT_NOT_PROVISIONED'
fi
if [ ! -d /var/lib ] || [ -L /var/lib ]; then
  echo 'PUBLIC_RELEASE_PARENT=BLOCKED_OR_SYMLINK'
else
  echo 'PUBLIC_RELEASE_PARENT=EXISTS_NOT_SYMLINK'
fi
if [ "$RELEASE" = "$QA" ] || [ "$RELEASE" = "$SITE" ]; then
  echo 'PUBLIC_RELEASE_PATH_ISOLATION=FAIL'
  exit 34
fi
echo 'PUBLIC_RELEASE_PATH_DISTINCT_FROM_FROZEN_QA_AND_WEBROOT=PASS'

# Restricted browser-QA MySQL credentials may not see other databases.
# NEVER infer schema absence from a negative information_schema query.
echo 'PUBLIC_DATABASE_NAME_RESERVED_FOR_FUTURE=YES'
echo 'PUBLIC_DATABASE_COLLISION=REQUIRES_PRIVILEGED_READ_ONLY_CHECK'
echo 'PUBLIC_DATABASE_CREATION=NOT_ATTEMPTED'
echo 'PUBLIC_DATABASE_CREDENTIALS=NOT_CREATED'
echo 'PUBLIC_OAUTH_CLIENT_AND_PRIVATE_KEYS=NOT_CREATED'

echo '========== RUNTIME AVAILABILITY, NO EXECUTION =========='
for file in "$PHP84" "$PHP85"; do
  if [ -x "$file" ]; then
    case "$file" in
      */84/*) echo 'PHP84_BINARY=AVAILABLE';;
      */85/*) echo 'PHP85_BINARY=AVAILABLE';;
    esac
  else
    case "$file" in
      */84/*) echo 'PHP84_BINARY=UNAVAILABLE';;
      */85/*) echo 'PHP85_BINARY=UNAVAILABLE';;
    esac
  fi
done
if [ -x /www/server/nginx/sbin/nginx ] &&
   /www/server/nginx/sbin/nginx -t >/dev/null 2>&1; then
  echo 'EXISTING_NGINX_SYNTAX=PASS'
else
  echo 'EXISTING_NGINX_SYNTAX=NOT_VERIFIED'
fi

echo '========== PUBLIC RELEASE APPROVAL GATES =========='
echo 'PUBLIC_RELEASE_REVIEWED_SOURCE_SHA=NOT_SELECTED'
echo 'PR15_MERGE_APPROVAL=NOT_GRANTED'
echo 'PUBLIC_ISSUER_URL=NOT_CONFIGURED'
echo 'PUBLIC_SESSION_COOKIE_AND_TRUSTED_PROXIES=NOT_VERIFIED'
echo 'PUBLIC_OAUTH_REDIRECT_CLIENT_REGISTRATION=NOT_CONFIGURED'
echo 'PUBLIC_WEBROOT_REUSE=NOT_AUTHORIZED'
echo 'PUBLIC_ORIGIN_CERTIFICATE_TRUST=NOT_VERIFIED_END_TO_END'
echo 'DNS_CLOUDFLARE_CHANGES=NONE'
echo 'NGINX_RELOAD_OR_VHOST_CHANGES=NONE'
echo 'CERTIFICATE_OR_PRIVATE_KEY_CHANGES=NONE'
echo 'QA_DATABASE_WRITES=NONE'
echo 'PUBLIC_DATABASE_WRITES=NONE'
echo 'FROZEN_SOURCE_CHANGES=NONE'
echo 'PRODUCTION_DEPLOYMENT=NONE'
echo 'STEP34C1_READ_ONLY_ISOLATION_PREFLIGHT=COMPLETE'
