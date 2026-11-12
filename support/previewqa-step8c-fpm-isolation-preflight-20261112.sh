#!/usr/bin/env bash
# Read-only isolation and collision preflight for a standalone Bambodia QA PHP-FPM service.
# Does not create a pool, service, socket, vhost, DNS record, or TLS certificate.
set -Eeuo pipefail
set +x
umask 077

QA='/var/lib/bambodia-preview-qa-20261112'
RECOVER='/var/lib/bambodia-preview-restore-20261112'
APP_USER='bambodia_pvrsqa'
NGINX_USER='www'
FPM='/www/server/php/85/sbin/php-fpm'
FPM_CONFIG='/www/server/php/85/etc/php-fpm.conf'
QA_FPM_UNIT='/etc/systemd/system/bambodia-previewqa-fpm.service'
QA_FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
QA_FPM_SOCKET='/run/bambodia-previewqa/php-fpm.sock'
QA_STATIC='/www/wwwroot/preview-qa.bambodia.com'
QA_VHOST='/www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf'

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
echo '========== STEP 8C — READ-ONLY ISOLATION PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for cmd in git nginx stat runuser id find ss awk; do
  command -v "$cmd" >/dev/null || fail "missing_command:$cmd"
done
[[ -d "$QA/.git" && ! -L "$QA" ]] || fail qa_app_missing
[[ "$(git -C "$QA" rev-parse HEAD)" = '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail qa_source_commit
[[ -d "$RECOVER" && ! -L "$RECOVER" &&
    -s "$RECOVER/storage/app/installed.lock" ]] || fail restored_qa_not_ready
[[ "$(stat -c '%U:%G:%a' "$RECOVER")" = "$APP_USER:$APP_USER:700" ]] ||
  fail recovered_app_no_longer_private
[[ "$(stat -c '%U:%G:%a' "$RECOVER/public")" = "$APP_USER:$APP_USER:700" ]] ||
  fail recovered_public_no_longer_private
[[ -x "$FPM" && -f "$FPM_CONFIG" ]] || fail php85_fpm_unavailable
id "$APP_USER" >/dev/null || fail dedicated_app_user_missing
id "$NGINX_USER" >/dev/null || fail nginx_user_missing

echo '========== EXISTING STACK SYNTAX =========='
if nginx -t -q >/dev/null 2>&1; then
  echo 'NGINX_SYNTAX=PASS'
else
  echo 'NGINX_SYNTAX=EXISTING_PROBLEM'
fi
if "$FPM" -t -y "$FPM_CONFIG" >/dev/null 2>&1; then
  echo 'EXISTING_PHP85_FPM_CONFIG=PASS'
else
  echo 'EXISTING_PHP85_FPM_CONFIG=CHECK_REQUIRED'
fi

echo '========== FILESYSTEM ACCESS BOUNDARY =========='
if runuser -u "$APP_USER" -- test -r "$RECOVER/public/index.php"; then
  echo 'RECOVERY_USER_INDEX_ACCESS=PASS'
else
  echo 'RECOVERY_USER_INDEX_ACCESS=BLOCKED'
fi
if runuser -u "$NGINX_USER" -- test -r "$RECOVER/public/index.php"; then
  echo 'SHARED_WEB_USER_RECOVERY_ACCESS=UNEXPECTED'
else
  echo 'SHARED_WEB_USER_RECOVERY_ACCESS=DENIED_EXPECTED'
fi
if runuser -u "$NGINX_USER" -- test -r "$RECOVER/.env"; then
  echo 'SHARED_WEB_USER_ENV_ACCESS=UNEXPECTED'
else
  echo 'SHARED_WEB_USER_ENV_ACCESS=DENIED_EXPECTED'
fi

echo '========== SEPARATE SERVICE COLLISION CHECK =========='
for path in "$QA_FPM_UNIT" "$QA_FPM_CONF" "$QA_FPM_SOCKET" "$QA_STATIC" "$QA_VHOST"; do
  if [[ -e "$path" || -L "$path" ]]; then
    printf 'DEDICATED_TARGET=OCCUPIED PATH=%s\n' "$path"
  else
    printf 'DEDICATED_TARGET=FREE PATH=%s\n' "$path"
  fi
done
if command -v systemctl >/dev/null 2>&1; then
  if systemctl is-active --quiet bambodia-previewqa-fpm.service; then
    echo 'DEDICATED_FPM_SERVICE=ALREADY_ACTIVE'
  else
    echo 'DEDICATED_FPM_SERVICE=NOT_ACTIVE'
  fi
fi

echo '========== NGINX AND PHP-FPM SOCKET =========='
if [[ -S /tmp/php-cgi-85.sock ]]; then
  stat -c 'EXISTING_SHARED_FPM_SOCKET=%U:%G MODE=%a' /tmp/php-cgi-85.sock
else
  echo 'EXISTING_SHARED_FPM_SOCKET=NOT_DETECTED'
fi
for port in 80 443 18097 18098; do
  if [[ -n "$(ss -H -ltn "sport = :$port")" ]]; then
    echo "LISTENER_$port=PRESENT"
  else
    echo "LISTENER_$port=NOT_DETECTED"
  fi
done

echo '========== ISOLATION DESIGN REQUIREMENTS =========='
echo 'QA_PHP_WORKER=MUST_USE_DEDICATED_APP_USER'
echo 'QA_FPM_SOCKET=MUST_BE_SEPARATE_UNIX_SOCKET'
echo 'NGINX_STATIC_FILES=MUST_BE_SEPARATE_PUBLIC_ASSET_COPY'
echo 'RECOVERED_PRIVATE_APP_PERMISSIONS=MUST_REMAIN_0700'
echo 'PUBLIC_QA_ACCESS=MUST_REQUIRE_EXPLICIT_AUTHORIZATION'
echo 'MAIN_PHP_FPM_POOL=NOT_MODIFIED'
echo 'PRODUCTION_OR_DNS_CHANGES=NONE'
echo 'STEP8C_DISCOVERY=COMPLETE'
