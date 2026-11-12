#!/usr/bin/env bash
# Step 12E: repair ONLY the already-installed, stopped private QA HTTP service.
# --check: temporary test files only; --apply: explicit opt-in, one-time.
# No shared Nginx/php-fpm changes, DNS, TLS, Cloudflare or production writes.
set -Eeuo pipefail
set +x
umask 077

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" == 1 ]] || fail expected_check_or_apply
MODE="$1"
[[ "$MODE" == --check || "$MODE" == --apply ]] || fail invalid_mode

WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
APP='/var/lib/bambodia-preview-restore-20261112'
DOCROOT='/var/lib/bambodia-previewqa-http-static-20261112/public'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
FPM_BACKUP='/root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf'
HTTP_SVC='bambodia-previewqa-http.service'
FPM_SVC='bambodia-previewqa-fpm.service'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
LUA_MODULE='/www/server/nginx/lib/lua/resty/core.lua'
LUA_DIRECTIVE='    lua_package_path "/www/server/nginx/lib/lua/?.lua;;";'
NGINX='/usr/bin/nginx'
PHP='/www/server/php/85/bin/php'
BACKUP='/root/Bambodia-PreviewQA-Lua-Recovery-20261112'
TMP=''
STARTED=no
ACCEPTED=no
cleanup() {
  rc=$?
  if [[ "$STARTED" == yes && "$ACCEPTED" != yes ]]; then
    systemctl stop "$HTTP_SVC" >/dev/null 2>&1 || true
    systemctl stop "$FPM_SVC" >/dev/null 2>&1 || true
    echo 'PRIVATE_QA_SERVICES=STOPPED_ON_RECOVERY_FAILURE' >&2
  fi
  if [[ -n "$TMP" && -d "$TMP" ]]; then rm -rf -- "$TMP"; fi
  exit "$rc"
}
trap cleanup EXIT

echo '========== STEP 12E — PRIVATE QA LUA/LOGGING REPAIR PREFLIGHT =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for exe in stat id getent git grep awk sed install cmp runuser \
  systemctl sha256sum find wc mktemp curl readlink; do
  command -v "$exe" >/dev/null || fail "missing_binary:$exe"
done
[[ -x "$NGINX" && "$(readlink -f "$NGINX")" == '/www/server/nginx/sbin/nginx' ]] ||
  fail nginx_binary_changed
[[ -x "$PHP" ]] || fail qa_php_binary_missing
"$NGINX" -h 2>&1 | grep -Fq -- '-e filename' ||
  fail nginx_startup_stderr_flag_not_supported
[[ -f "$LUA_MODULE" && ! -L "$LUA_MODULE" ]] || fail installed_resty_core_missing
runuser -u "$INGRESS" -- test -r "$LUA_MODULE" ||
  fail ingress_cannot_read_existing_resty_core
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail existing_qa_http_unit_changed
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail existing_qa_nginx_config_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail qa_fpm_config_changed
[[ -f "$FPM_BACKUP" && ! -L "$FPM_BACKUP" &&
  "$(stat -c '%U:%G:%a' "$FPM_BACKUP")" == root:root:600 ]] ||
  fail original_qa_fpm_backup_changed
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail recovered_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail private_env_permissions_changed
[[ -d "$DOCROOT" && ! -L "$DOCROOT" &&
  "$(stat -c '%U:%G:%a' "$DOCROOT")" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_static_root_changed
[[ "$(find "$DOCROOT" -type f | wc -l | tr -d ' ')" == 43 ]] ||
  fail private_static_file_count_changed
[[ ! -e "$DOCROOT/index.php" && ! -e "$DOCROOT/.htaccess" ]] ||
  fail php_or_apache_config_in_static_root
[[ -z "$(find "$DOCROOT" -type l -print -quit)" ]] ||
  fail static_root_contains_symlinks
[[ ! -e "$BACKUP" && ! -L "$BACKUP" ]] ||
  fail recovery_backup_exists_do_not_rerun
getent passwd "$INGRESS" >/dev/null || fail ingress_account_missing
getent group "$INGRESS" >/dev/null || fail ingress_group_missing
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_member_of_private_ingress_group
fi
[[ "$(systemctl is-enabled "$HTTP_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail http_service_boot_enabled
[[ "$(systemctl is-enabled "$FPM_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail fpm_service_boot_enabled
[[ "$(systemctl is-active "$HTTP_SVC" 2>/dev/null || true)" != active ]] ||
  fail private_http_service_already_running
[[ "$(systemctl is-active "$FPM_SVC" 2>/dev/null || true)" != active ]] ||
  fail private_fpm_service_already_running
[[ ! -S "$HTTP_SOCK" && ! -S "$FPM_SOCK" ]] ||
  fail private_sockets_still_present_unexpected
for line in "listen.owner = $WORKER" "listen.group = $INGRESS" 'listen.mode = 0660'; do
  grep -Fxq "$line" "$FPM_CONF" || fail "scoped_fpm_baseline_changed:$line"
done
grep -Fxq "listen.group = $WORKER" "$FPM_BACKUP" ||
  fail original_fpm_backup_not_worker_scoped
grep -Fxq 'listen.mode = 0600' "$FPM_BACKUP" ||
  fail original_fpm_backup_not_private_mode
grep -Fxq 'error_log stderr warn;' "$HTTP_CONF" ||
  fail nginx_error_log_directive_changed
grep -Fxq 'http {' "$HTTP_CONF" || fail http_context_changed
if grep -Eq '^[[:space:]]*lua_package_path[[:space:]]' "$HTTP_CONF"; then
  fail existing_qa_http_lua_path_already_changed
fi
EXPECTED_EXEC="ExecStart=$NGINX -c $HTTP_CONF -g 'daemon off;'"
grep -Fxq "$EXPECTED_EXEC" "$HTTP_UNIT" || fail old_dedicated_execstart_changed
for needed in \
  "User=$INGRESS" "Group=$INGRESS" \
  'RuntimeDirectory=bambodia-previewqa-http' \
  'RuntimeDirectoryMode=0700' \
  'RestrictAddressFamilies=AF_UNIX' \
  'NoNewPrivileges=true' 'ProtectSystem=strict' 'ProtectHome=true'; do
  grep -Fxq "$needed" "$HTTP_UNIT" ||
    fail "private_service_guard_changed:$needed"
done
[[ -z "$(systemctl show "$HTTP_SVC" -p DropInPaths --value 2>/dev/null || true)" ]] ||
  fail unexpected_dedicated_service_override
[[ "$(systemctl show "$HTTP_SVC" -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail systemd_unit_path_mismatch
runuser -u "$WORKER" -- "$PHP" -r '
$p=$argv[1];require $p."/vendor/autoload.php";
$a=require $p."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    $d->db!=="bambodia_previewrestore_20261112" ||
    \App\Models\User::count()!==6) exit(2);
echo "RECOVERED_SYNTHETIC_QA=PASS_WRITES_OFF\n";
' "$APP" || fail private_qa_database_identity_mismatch

echo '========== CREATE TEMPORARY PRIVATE OFFLINE CANDIDATES =========='
TMP="$(mktemp -d /run/bambodia-step12e.XXXXXXXX)"
chown root:"$INGRESS" "$TMP"
chmod 770 "$TMP"
# Exact one-line insertion into the already-installed, QA-only Nginx config.
awk -v directive="$LUA_DIRECTIVE" '
  $0 == "http {" { print; print directive; count++; next }
  { print }
  END { if (count != 1) exit 2 }
' "$HTTP_CONF" > "$TMP/qa-http.new.conf" ||
  fail lua_path_insertion_failed
grep -Fxq "$LUA_DIRECTIVE" "$TMP/qa-http.new.conf" ||
  fail lua_path_directive_not_unique
[[ "$(grep -Fc "$LUA_DIRECTIVE" "$TMP/qa-http.new.conf")" == 1 ]] ||
  fail lua_path_directive_duplicated
NEW_EXEC="ExecStart=$NGINX -e stderr -c $HTTP_CONF -g 'daemon off;'"
awk -v before="$EXPECTED_EXEC" -v after="$NEW_EXEC" '
  $0 == before { print after; count++; next }
  { print }
  END { if (count != 1) exit 2 }
' "$HTTP_UNIT" > "$TMP/qa-http.new.service" ||
  fail nginx_unit_execstart_rewrite_failed
grep -Fxq "$NEW_EXEC" "$TMP/qa-http.new.service" ||
  fail nginx_unit_stderr_option_missing

# -t tests bind the listen socket and write a PID. Redirect ONLY those paths
# to a removable group-private /run location. The deploy config is unchanged.
sed -e "s#^pid /run/bambodia-previewqa-http/nginx.pid;#pid $TMP/nginx.pid;#" \
  -e "s#listen unix:/run/bambodia-previewqa-http/http.sock;#listen unix:$TMP/http.sock;#" \
  -e "s#/run/bambodia-previewqa-http/client_temp#$TMP/client_temp#" \
  -e "s#/run/bambodia-previewqa-http/fastcgi_temp#$TMP/fastcgi_temp#" \
  "$TMP/qa-http.new.conf" > "$TMP/qa-http.offline.conf"
grep -Fq "listen unix:$TMP/http.sock;" "$TMP/qa-http.offline.conf" ||
  fail offline_private_socket_rewrite_missing
install -o root -g "$INGRESS" -m 640 \
  "$TMP/qa-http.offline.conf" "$TMP/qa-http.offline.readable.conf"
if ! runuser -u "$INGRESS" -- "$NGINX" -t -e stderr \
  -c "$TMP/qa-http.offline.readable.conf" -g 'daemon off;' \
  > "$TMP/nginx-test.log" 2>&1; then
  sed -n '1,30p' "$TMP/nginx-test.log" >&2
  fail unprivileged_nginx_lua_offline_validation_failed
fi
echo 'DEDICATED_NGINX_UNPRIVILEGED_LUA_AND_STDERR_TEST=PASS'
echo 'PRIVATE_LUA_FILE=EXISTING_READABLE_AAPANEL_LIBRARY'
echo 'DEDICATED_SERVICE_CHANGES=NGINX_ONLY_LUA_PATH_AND_STARTUP_STDERR'
echo 'PREVIEW_VISIBILITY_WRITES=DISABLED'
if [[ "$MODE" == --check ]]; then
  echo 'PERSISTENT_WRITES=NONE'
  echo 'STEP12E_CHECK=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_LUA_RECOVERY || true)" == 'APPROVE_PRIVATE_QA_REPAIR_ONLY' ]] ||
  fail explicit_operator_approval_missing
echo '========== BACKUP ONLY EXISTING DEDICATED QA CONFIG =========='
install -d -o root -g root -m 700 "$BACKUP"
install -o root -g root -m 600 "$HTTP_CONF" "$BACKUP/nginx.conf"
install -o root -g root -m 600 "$HTTP_UNIT" "$BACKUP/http.service"
cmp -s "$HTTP_CONF" "$BACKUP/nginx.conf" || fail nginx_config_backup_mismatch
cmp -s "$HTTP_UNIT" "$BACKUP/http.service" || fail http_unit_backup_mismatch

STARTED=yes
install -o root -g "$INGRESS" -m 640 "$TMP/qa-http.new.conf" "$HTTP_CONF"
install -o root -g root -m 644 "$TMP/qa-http.new.service" "$HTTP_UNIT"
cmp -s "$TMP/qa-http.new.conf" "$HTTP_CONF" || fail installed_nginx_lua_config_mismatch
cmp -s "$TMP/qa-http.new.service" "$HTTP_UNIT" || fail installed_nginx_stderr_unit_mismatch
systemctl daemon-reload || fail systemd_reload_failed
systemctl reset-failed "$HTTP_SVC" >/dev/null 2>&1 || true
echo '========== START TWO DEDICATED QA SERVICES ONLY =========='
systemctl start "$FPM_SVC" || fail scoped_qa_fpm_restart_failed
for n in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$FPM_SVC" &&
    [[ -S "$FPM_SOCK" &&
      "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]]; then break; fi
  sleep 1
done
[[ -S "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail scoped_fpm_socket_after_restart_wrong
if runuser -u www -- test -w "$FPM_SOCK"; then fail shared_www_fpm_access; fi
systemctl start "$HTTP_SVC" || fail private_http_nginx_start_failed
for n in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$HTTP_SVC" && [[ -S "$HTTP_SOCK" ]]; then break; fi
  sleep 1
done
[[ -S "$HTTP_SOCK" &&
  "$(stat -c '%U:%G' "$HTTP_SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail private_http_socket_after_restart_wrong
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_runtime_dir_not_private
if runuser -u www -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); }
exit(2);
' "$HTTP_SOCK"; then fail shared_www_can_connect_http; fi
for uri in /up /login; do
  code="$(curl --silent --show-error --max-time 8 \
    --output /dev/null --write-out '%{http_code}' \
    --unix-socket "$HTTP_SOCK" \
    -H 'Host: preview-qa.bambodia.com' "http://localhost$uri")" ||
    fail "private_http_request_failed:$uri"
  [[ "$code" == 200 ]] || fail "private_http_expected_200:$uri:$code"
done
for uri in /index.php /.htaccess /storage/ /installer/; do
  code="$(curl --silent --show-error --max-time 8 \
    --output /dev/null --write-out '%{http_code}' \
    --unix-socket "$HTTP_SOCK" \
    -H 'Host: preview-qa.bambodia.com' "http://localhost$uri")" ||
    fail "blocked_private_path_request_failed:$uri"
  [[ "$code" == 404 ]] || fail "private_path_not_blocked:$uri:$code"
done
[[ "$(systemctl is-enabled "$HTTP_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail http_qa_service_boot_enabled
[[ "$(systemctl is-enabled "$FPM_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail fpm_qa_service_boot_enabled
ACCEPTED=yes
echo '========== STEP 12E LOCAL-ONLY QA INGRESS ACCEPTANCE =========='
echo 'DEDICATED_QA_NGINX_SERVICE=ACTIVE'
echo 'DEDICATED_QA_FPM_SERVICE=ACTIVE'
echo 'NGINX_LUA_RESTY_CORE_FROM_EXISTING_AAPANEL=PASS'
echo 'NGINX_STARTUP_ERROR_LOG=STDERR'
echo 'SHARED_WWW_FASTCGI_AND_HTTP=DENIED'
echo 'PRIVATE_HTTP_GET_UP_LOGIN=200'
echo 'PRIVATE_PATHS_INDEX_HTACCESS_STORAGE_INSTALLER=404'
echo 'QA_SERVICES_ENABLED_AT_BOOT=NO'
echo 'PRIVATE_RECOVERY_BACKUP=CREATED'
echo 'PUBLIC_DNS_TUNNEL_TLS=NOT_CONFIGURED'
echo 'PRODUCTION_SHARED_NGINX_AND_FPM=UNCHANGED'
echo 'PREVIEW_VISIBILITY_WRITES=DISABLED'
echo 'STEP12E=PASS'
