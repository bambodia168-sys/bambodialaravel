#!/usr/bin/env bash
# Bambodia MCP OAuth Step 34A: read-only external HTTPS readiness inventory.
# This is NOT a TLS/browser/ChatGPT acceptance test. Do not deploy or mutate.
set -Eeuo pipefail
set +x
umask 077

APP="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_HASH="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
PHP="/www/server/php/84/bin/php"
CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
DB="bambodia_oauthqa_browser"

printf '%s\n' '========== CHECK SOURCE AND CONTAINMENT =========='
test "$(id -u)" -eq 0
test -d "$APP/.git" && test ! -L "$APP"
test "$(realpath -e "$APP")" = "$APP"
test "$(git -C "$APP" rev-parse HEAD)" = "$SHA"
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
test -f "$APP/.env" && test ! -L "$APP/.env"
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock"
test "$(sha256sum "$APP/composer.lock" | cut -d ' ' -f1)" = "$LOCK_HASH"
test -s "$APP/public/build/manifest.json"
test ! -L "$APP/public/build"
test ! -e "$APP/public/hot"
test -x "$PHP" && test -x "$MYSQL"
test -f "$CNF" && test ! -L "$CNF"
test "$(stat -c '%u:%a' "$CNF")" = '0:600'
test "$(stat -c '%U:%G:%a' "$APP/.env")" = 'root:www:640'
test ! -e "$APP/bootstrap/cache/config.php"
for expected in \
 'APP_ENV=staging' 'APP_DEBUG=false' \
 'APP_URL=https://oauth-browser-qa.invalid' \
 'DB_CONNECTION=mysql' 'DB_HOST=127.0.0.1' \
 "DB_DATABASE=$DB" 'DB_USERNAME=bambodiaqa_browser' \
 'SESSION_DRIVER=file' 'CACHE_STORE=file' 'MAIL_MAILER=log'; do
  grep -Fxq "$expected" "$APP/.env"
done
for key in oauth-private.key oauth-public.key; do
  test -f "$APP/storage/$key" && test ! -L "$APP/storage/$key"
  test "$(stat -c '%U:%G:%a' "$APP/storage/$key")" = 'www:www:600'
done
printf '%s\n' 'QA_SOURCE_ISOLATION=PASS'
printf '%s\n' 'QA_COMPILED_ASSETS=PASS'
printf '%s\n' 'QA_PRIVATE_KEYS_AND_ENV_PERMISSIONS=PASS'

printf '%s\n' '========== CHECK QA DATABASE IN READ-ONLY QUERIES =========='
QA_MYSQL() {
 "$MYSQL" --defaults-extra-file="$CNF" \
   --protocol=TCP --host=127.0.0.1 \
   --batch --skip-column-names "$DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL -e "SELECT COUNT(*) FROM users WHERE email='oauth-admin@oauth-browser-qa.invalid' AND role='super_admin' AND account_status='active'")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
printf '%s\n' 'QA_ORIGINAL_USER_AND_CLIENT=PASS'
printf '%s\n' 'ACTIVE_OAUTH_GRANTS=0'

printf '%s\n' '========== INVENTORY HOST TOOLS =========='
for tool in curl openssl nginx ss getent systemctl; do
  if command -v "$tool" >/dev/null 2>&1; then
    printf '%s=AVAILABLE\n' "$(printf '%s' "$tool" | tr '[:lower:]' '[:upper:]')"
  else
    printf '%s=NOT_IN_PATH\n' "$(printf '%s' "$tool" | tr '[:lower:]' '[:upper:]')"
  fi
done
if test -x /www/server/nginx/sbin/nginx; then
  if /www/server/nginx/sbin/nginx -t >/dev/null 2>&1; then
    echo 'AAPANEL_NGINX_SYNTAX=PASS'
  else
    echo 'AAPANEL_NGINX_SYNTAX=FAIL'
  fi
elif command -v nginx >/dev/null 2>&1; then
  if nginx -t >/dev/null 2>&1; then
    echo 'NGINX_SYNTAX=PASS'
  else
    echo 'NGINX_SYNTAX=FAIL'
  fi
else
  echo 'NGINX_SYNTAX=NOT_TESTED'
fi

if command -v ss >/dev/null 2>&1; then
  if ss -ltnH 2>/dev/null | awk '{ print $4 }' |
     grep -Eq '(^|:|\])443$'; then
    echo 'HOST_HTTPS_PORT_443_LISTENER=PRESENT'
  else
    echo 'HOST_HTTPS_PORT_443_LISTENER=NOT_FOUND'
  fi
else
  echo 'HOST_HTTPS_PORT_443_LISTENER=NOT_TESTED'
fi

printf '%s\n' '========== CHECK APP PHP REQUIREMENTS =========='
"$PHP" -r '
$required = ["pdo","pdo_mysql","openssl","mbstring","fileinfo","json"];
$missing = array_values(array_filter($required,
    static fn($extension) => !extension_loaded($extension)));
echo "PHP84_REQUIRED_EXTENSIONS=".
    ($missing === [] ? "PASS" : "MISSING_".implode("_",$missing))."\n";
if ($missing !== []) exit(34);
'
printf '%s\n' 'PHP84_RUNTIME=PASS'

printf '%s\n' '========== EXTERNAL TEST READINESS BOUNDARY =========='
# .invalid is reserved by RFC 2606 and cannot be used for public
# ChatGPT -> Bambodia network acceptance. No DNS/cert/vhost changes here.
printf '%s\n' \
 'CURRENT_QA_HOST=RESERVED_INVALID_TEST_ONLY' \
 'PUBLIC_TEST_HOSTNAME=NOT_CONFIGURED_BY_THIS_SCRIPT' \
 'EXTERNAL_DNS_AND_TLS=NOT_TESTED' \
 'LIVE_CHATGPT_CONNECTOR=NOT_TESTED' \
 'QA_SOURCE_CHANGES=NONE' \
 'DATABASE_WRITES=NONE' \
 'DNS_OR_VHOST_CHANGES=NONE' \
 'PRODUCTION_DEPLOYMENT=NONE' \
 'STEP34A_READ_ONLY_HOST_INVENTORY=PASS'
