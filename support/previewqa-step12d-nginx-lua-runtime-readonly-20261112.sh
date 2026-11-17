#!/usr/bin/env bash
# Step 12D: inspect aaPanel Nginx Lua runtime without touching services.
# No downloads, installs, restarts, configuration writes or token disclosure.
set -Eeuo pipefail
set +x
umask 077
fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
CONF='/etc/bambodia-previewqa-http/nginx.conf'
FPM='/etc/bambodia-previewqa/php-fpm.conf'
BACKUP='/root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf'
HTTP_ROOT='/var/lib/bambodia-previewqa-http-static-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
USER_NAME='bambodia_pvhttp'
FPM_USER='bambodia_pvrsqa'
NGINX_BIN="$(command -v nginx || true)"

echo '========== STEP 12D — NGINX LUA RUNTIME READ-ONLY INSPECTION =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for bin in nginx systemctl stat readlink namei grep find getent runuser; do
  command -v "$bin" >/dev/null || fail "missing_command:$bin"
done
[[ -n "$NGINX_BIN" && -x "$NGINX_BIN" ]] || fail nginx_missing
[[ -f "$UNIT" && ! -L "$UNIT" &&
  "$(stat -c '%U:%G:%a' "$UNIT")" == root:root:644 ]] ||
  fail qa_only_systemd_unit_changed
[[ -f "$CONF" && ! -L "$CONF" &&
  "$(stat -c '%U:%G:%a' "$CONF")" == root:bambodia_pvhttp:640 ]] ||
  fail dedicated_nginx_config_changed
[[ -f "$FPM" && ! -L "$FPM" &&
  "$(stat -c '%U:%G:%a' "$FPM")" == root:root:600 ]] ||
  fail qa_fpm_config_changed
[[ -f "$BACKUP" && ! -L "$BACKUP" &&
  "$(stat -c '%U:%G:%a' "$BACKUP")" == root:root:600 ]] ||
  fail qa_fpm_backup_not_private
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$FPM_USER:$FPM_USER:700" ]] ||
  fail qa_app_permissions_changed
[[ -d "$HTTP_ROOT" && ! -L "$HTTP_ROOT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_ROOT")" == "$USER_NAME:$USER_NAME:700" ]] ||
  fail private_static_root_changed
getent passwd "$USER_NAME" >/dev/null || fail dedicated_http_user_missing
systemctl is-active --quiet bambodia-previewqa-http.service &&
  fail qa_http_must_remain_stopped_until_review
systemctl is-active --quiet bambodia-previewqa-fpm.service &&
  fail qa_fpm_must_remain_stopped_until_review
[[ "$(systemctl is-enabled bambodia-previewqa-http.service 2>/dev/null || true)" == disabled ]] ||
  fail qa_http_autostart_unexpected
[[ "$(systemctl is-enabled bambodia-previewqa-fpm.service 2>/dev/null || true)" == disabled ]] ||
  fail qa_fpm_autostart_unexpected
grep -Fxq "listen.group = $USER_NAME" "$FPM" ||
  fail scoped_fpm_group_changed
grep -Fxq 'listen.mode = 0660' "$FPM" || fail scoped_fpm_mode_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$USER_NAME"; then
  fail shared_www_entered_ingress_group
fi

echo '========== NGINX EXECUTABLE AND OPTIONS =========='
printf 'NGINX_PATH=%s\n' "$(readlink -f "$NGINX_BIN")"
nginx -v 2>&1 | head -n 1
if nginx -h 2>&1 | grep -Fq -- '-e filename'; then
  echo 'NGINX_STARTUP_ERROR_LOG_FLAG=SUPPORTED'
elif nginx -h 2>&1 | grep -Eq -- '(^|[[:space:]])-e[[:space:]]'; then
  echo 'NGINX_STARTUP_ERROR_LOG_FLAG=LIKELY_SUPPORTED'
else
  echo 'NGINX_STARTUP_ERROR_LOG_FLAG=NOT_SHOWN_IN_HELP'
fi
nginx -V 2>&1 |
  grep -oE -- '--(prefix|error-log-path|conf-path|modules-path)=[^[:space:]]+' |
  head -n 12 || true
if nginx -V 2>&1 | grep -Eiq 'lua|luajit|resty'; then
  echo 'NGINX_BUILD_LUA=DETECTED'
else
  echo 'NGINX_BUILD_LUA=NOT_IDENTIFIED_BY_VERSION_OUTPUT'
fi
echo '========== DEDICATED UNIT SETTINGS (SAFE FIELDS ONLY) =========='
grep -E '^(User|Group|ExecStart|ProtectHome|ProtectSystem|PrivateTmp|NoNewPrivileges|RestrictAddressFamilies|RuntimeDirectory|RuntimeDirectoryMode)=' \
  "$UNIT" || true
echo '========== NGINX LUA SEARCH DIRECTIVES =========='
for path in \
  '/www/server/nginx/conf/nginx.conf' \
  '/www/server/nginx/conf/lua.conf' \
  '/usr/local/openresty/nginx/conf/nginx.conf' \
  "$CONF"; do
  if [[ -f "$path" && ! -L "$path" ]]; then
    printf 'CHECKED_NGINX_CONFIG=%s\n' "$path"
    grep -E '^[[:space:]]*(lua_package_path|lua_package_cpath|lua_load_resty_core)[[:space:]]+' "$path" |
      head -n 12 || true
  fi
done

echo '========== INSTALLED RESTY CORE FILE SEARCH =========='
FOUND=0
for root in /www/server/nginx /usr/local/openresty /usr/local/share/lua \
            /usr/local/lib/lua /usr/share/lua /usr/lib/lua; do
  if [[ -d "$root" && ! -L "$root" ]]; then
    echo "SEARCHED_ROOT=$root"
    while IFS= read -r -d '' file; do
      FOUND=$((FOUND + 1))
      if (( FOUND <= 12 )); then
        printf 'RESTY_CORE_FILE=%s OWNER_MODE=%s\n' "$file" \
          "$(stat -c '%U:%G:%a' "$file")"
        namei -l "$file" | tail -n 5 || true
        if runuser -u "$USER_NAME" -- test -r "$file"; then
          echo 'RESTY_CORE_READABLE_BY_DEDICATED_INGRESS=YES'
        else
          echo 'RESTY_CORE_READABLE_BY_DEDICATED_INGRESS=NO'
        fi
      fi
    done < <(find "$root" -maxdepth 9 -type f -path '*/resty/core.lua' -print0 2>/dev/null)
  fi
done
echo "RESTY_CORE_FILES_FOUND=$FOUND"
echo '========== CURRENT RECOVERY BOUNDARIES =========='
echo 'QA_SERVICES_NOT_STARTED=YES'
echo 'AAPPANEL_MAIN_NGINX_AND_FPM_NOT_RESTARTED=YES'
echo 'CONFIG_AND_PRODUCTION_WRITES=NONE'
echo 'NO_DEPENDENCIES_DOWNLOADED=YES'
echo 'STEP12D_NGINX_LUA_DISCOVERY=COMPLETE'
