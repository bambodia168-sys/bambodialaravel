#!/usr/bin/env bash
# Step 12G: one-time guarded activation of QA-only canonical Host filtering.
# --check: ephemeral /run syntax test only, no persistent change or restart.
# --apply: explicit operator approval, backup, restart only private QA HTTP.
# No shared nginx/FPM, production, DNS, TLS, cloudflared or public TCP.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" == 1 ]] || fail usage_check_or_apply
MODE="$1"
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail invalid_mode
TMP=''
CHANGE_STARTED=no
ACCEPTED=no
BACKUP='/root/Bambodia-PreviewQA-Host-Guard-20261113'
cleanup(){
  local rc=$?
  if [[ "$CHANGE_STARTED" == yes && "$ACCEPTED" != yes ]]; then
    # Stop only the dedicated QA HTTP origin; QA FastCGI and shared aaPanel
    # services are not touched during a failed Host guard activation.
    systemctl stop bambodia-previewqa-http.service >/dev/null 2>&1 || true
    if systemctl is-active --quiet bambodia-previewqa-http.service; then
      echo 'CRITICAL_QA_HTTP_STILL_ACTIVE_MANUAL_ISOLATION_REQUIRED' >&2
    else
      echo 'QA_HTTP=STOPPED_ON_FAILURE' >&2
    fi
    if [[ -f "$BACKUP/nginx.conf" && ! -L "$BACKUP/nginx.conf" ]] &&
       install -o root -g bambodia_pvhttp -m 640 "$BACKUP/nginx.conf" \
         /etc/bambodia-previewqa-http/nginx.conf &&
       cmp -s "$BACKUP/nginx.conf" /etc/bambodia-previewqa-http/nginx.conf; then
      echo 'QA_HTTP_ORIGINAL_CONFIG=RESTORED' >&2
    else
      echo 'CRITICAL_QA_HTTP_CONFIG_ROLLBACK_FAILED_INSPECT_ROOT_BACKUP' >&2
    fi
    echo 'QA_FPM=UNCHANGED' >&2
  fi
  [[ -z "$TMP" ]] || rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

APP='/var/lib/bambodia-preview-restore-20261112'
SOURCE='/var/lib/bambodia-preview-qa-20261112'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
STATIC='/var/lib/bambodia-previewqa-http-static-20261112/public'
SOCKET='/run/bambodia-previewqa-http/http.sock'
FPM_SOCKET='/run/bambodia-previewqa/php-fpm.sock'
INGRESS='bambodia_pvhttp'
WORKER='bambodia_pvrsqa'
NGINX='/usr/bin/nginx'
PHP='/www/server/php/85/bin/php'
ANCHOR='        server_name preview-qa.bambodia.com localhost 127.0.0.1;'

echo '========== STEP 12G — PRIVATE ORIGIN HOST RESTRICTION PREFLIGHT =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for bin in stat systemctl git grep find wc awk sed mktemp install runuser \
  getent curl id readlink cmp; do
  command -v "$bin" >/dev/null || fail "missing_command:$bin"
done
[[ -x "$NGINX" && "$(readlink -f "$NGINX")" == /www/server/nginx/sbin/nginx ]] ||
  fail dedicated_nginx_binary_changed
[[ -x "$PHP" ]] || fail qa_php_binary_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail synthetic_qa_source_revision_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail synthetic_qa_source_dirty
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail isolated_laravel_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail isolated_laravel_environment_changed
[[ -d "$STATIC" && ! -L "$STATIC" &&
  "$(stat -c '%U:%G:%a' "$STATIC")" == "$INGRESS:$INGRESS:700" ]] ||
  fail static_only_public_root_changed
[[ "$(find "$STATIC" -type f | wc -l | tr -d ' ')" == 43 ]] ||
  fail private_static_assets_changed
[[ -z "$(find "$STATIC" -type l -print -quit)" ]] || fail static_symlink_added
[[ ! -e "$STATIC/index.php" && ! -e "$STATIC/.htaccess" ]] ||
  fail unsafe_static_front_controller_or_apache_file
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail dedicated_http_config_owner_changed
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail dedicated_http_unit_owner_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail private_fpm_config_changed
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail isolated_http_nginx_not_running
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail isolated_fastcgi_not_running
[[ "$(systemctl is-enabled bambodia-previewqa-http.service 2>/dev/null || true)" == disabled ]] ||
  fail http_service_boot_enabled
[[ "$(systemctl is-enabled bambodia-previewqa-fpm.service 2>/dev/null || true)" == disabled ]] ||
  fail fpm_service_boot_enabled
[[ "$(systemctl show bambodia-previewqa-http.service -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail systemd_unit_path_changed
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unexpected_nginx_systemd_override
grep -Fxq "ExecStart=$NGINX -e stderr -c $HTTP_CONF -g 'daemon off;'" "$HTTP_UNIT" ||
  fail scoped_nginx_execstart_changed
for flag in "User=$INGRESS" "Group=$INGRESS" 'RuntimeDirectoryMode=0700' \
  'RestrictAddressFamilies=AF_UNIX' 'ProtectSystem=strict' 'ProtectHome=true' \
  'NoNewPrivileges=true'; do
  grep -Fxq "$flag" "$HTTP_UNIT" || fail "dedicated_http_sandbox_changed:$flag"
done
[[ -S "$SOCKET" && ! -L "$SOCKET" &&
  "$(stat -c '%U:%G' "$SOCKET")" == "$INGRESS:$INGRESS" ]] ||
  fail private_http_socket_changed
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_runtime_directory_changed
[[ -S "$FPM_SOCKET" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCKET")" == "$WORKER:$INGRESS:660" ]] ||
  fail dedicated_fastcgi_socket_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_in_private_ingress_group
fi
for line in "listen.owner = $WORKER" "listen.group = $INGRESS" \
            'listen.mode = 0660'; do
  grep -Fxq "$line" "$FPM_CONF" || fail "dedicated_fpm_guard_changed:$line"
done
grep -Fxq "$ANCHOR" "$HTTP_CONF" ||
  fail original_dedicated_server_names_changed
[[ "$(grep -Fxc "$ANCHOR" "$HTTP_CONF")" == 1 ]] ||
  fail duplicate_dedicated_server_name_anchors
grep -Fxq "        listen unix:$SOCKET;" "$HTTP_CONF" ||
  fail private_unix_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail another_http_listener_present
grep -Fxq '    lua_package_path "/www/server/nginx/lib/lua/?.lua;;";' "$HTTP_CONF" ||
  fail repaired_lua_path_changed
grep -Fxq 'error_log stderr warn;' "$HTTP_CONF" ||
  fail repaired_stderr_log_changed
grep -Fq "fastcgi_pass unix:$FPM_SOCKET;" "$HTTP_CONF" ||
  fail qa_fastcgi_upstream_changed
grep -Fq "fastcgi_param SCRIPT_FILENAME $APP/public/index.php;" "$HTTP_CONF" ||
  fail qa_laravel_entrypoint_changed
if grep -Eq '^[[:space:]]*(include|load_module|if[[:space:]]*\()[[:space:]]*' "$HTTP_CONF"; then
  fail unexpected_nginx_include_or_existing_if
fi
for path in \
  /root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf \
  /root/Bambodia-PreviewQA-Lua-Recovery-20261112/nginx.conf \
  /root/Bambodia-PreviewQA-Lua-Recovery-20261112/http.service; do
  [[ -f "$path" && ! -L "$path" &&
    "$(stat -c '%U:%G:%a' "$path")" == root:root:600 ]] ||
    fail "required_private_backup_unavailable:$path"
done
[[ ! -e "$BACKUP" && ! -L "$BACKUP" ]] ||
  fail one_time_recovery_backup_path_not_free
runuser -u "$WORKER" -- "$PHP" -r '
$p=$argv[1];require $p."/vendor/autoload.php";
$app=require $p."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    $db->db!=="bambodia_previewrestore_20261112" ||
    \App\Models\User::count()!==6 ||
    \Illuminate\Support\Facades\DB::table("preview_visibility_actions")->count()!==0) exit(2);
echo "SYNTHETIC_QA_DATABASE_AND_WITHDRAWAL_WRITES_OFF=PASS\n";
' "$APP" || fail synthetic_QA_database_or_flag_changed
echo 'DEDICATED_QA_HTTP_FPM_AND_BOUNDARIES=PASS'

echo '========== OBSERVE CURRENT UNRECOGNIZED HOST RESPONSE =========='
ACTUAL="$(curl --silent --show-error --noproxy '*' --max-time 10 \
  --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$SOCKET" -H 'Host: unauthorized-origin.invalid' \
  http://localhost/up)" || fail origin_host_baseline_request_failed
[[ "$ACTUAL" == 200 ]] ||
  fail "origin_host_response_changed_before_guard:$ACTUAL"
echo 'UNKNOWN_HOST_BASELINE=HTTP_200_CURRENTLY_UNRESTRICTED'

echo '========== BUILD NGINX HOST GUARD AS PRIVATE OFFLINE CANDIDATE =========='
TMP="$(mktemp -d /run/bambodia-step12g.XXXXXXXX)"
chown root:"$INGRESS" "$TMP"
# An unprivileged nginx -t must be able to create its temporary Unix
# listener/PID inside this ephemeral directory; group write is required.
# The directory is still inaccessible to other users and is removed on exit.
chmod 770 "$TMP"
cat > "$TMP/host.guard" <<'NGINXGUARD'
        # Canonical QA origin only. Not a replacement for Cloudflare Access.
        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {
            return 421;
        }
NGINXGUARD
awk -v anchor="$ANCHOR" -v insert="$TMP/host.guard" '
  $0 == anchor {
    print
    while ((getline line < insert) > 0) print line
    close(insert)
    count++
    next
  }
  { print }
  END { if (count != 1) exit 2 }
' "$HTTP_CONF" > "$TMP/restricted.conf" ||
  fail scoped_host_guard_insertion_failed
[[ "$(grep -Fxc '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$TMP/restricted.conf")" == 1 ]] ||
  fail exact_canonical_host_guard_missing
[[ "$(grep -Fxc '            return 421;' "$TMP/restricted.conf")" == 1 ]] ||
  fail unknown_host_rejection_missing
sed -e "s#^pid /run/bambodia-previewqa-http/nginx.pid;#pid $TMP/nginx.pid;#" \
  -e "s#listen unix:$SOCKET;#listen unix:$TMP/http.sock;#" \
  -e "s#/run/bambodia-previewqa-http/client_temp#$TMP/client_temp#" \
  -e "s#/run/bambodia-previewqa-http/fastcgi_temp#$TMP/fastcgi_temp#" \
  "$TMP/restricted.conf" > "$TMP/offline.conf"
grep -Fq "listen unix:$TMP/http.sock;" "$TMP/offline.conf" ||
  fail offline_socket_not_rewritten
install -o root -g "$INGRESS" -m 640 "$TMP/offline.conf" "$TMP/offline-readable.conf"
if ! runuser -u "$INGRESS" -- "$NGINX" -t -e stderr \
  -c "$TMP/offline-readable.conf" -g 'daemon off;' \
  > "$TMP/nginx-validation.log" 2>&1; then
  sed -n '1,25p' "$TMP/nginx-validation.log" >&2
  fail unprivileged_Nginx_host_guard_syntax_check_failed
fi
echo 'UNPRIVILEGED_QA_NGINX_HOST_GUARD_SYNTAX=PASS'
echo 'EXPECTED_CANONICAL_HOST=preview-qa.bambodia.com'
echo 'UNKNOWN_OR_MISSING_HOST_AFTER_ACTIVATION=HTTP_421_NOT_YET_INSTALLED'
echo 'CLOUDFLARE_ACCESS_AND_PUBLIC_DNS=NOT_CONFIGURED'
if [[ "$MODE" == '--check' ]]; then
  echo 'NO_EXISTING_SERVICES_RESTARTED=YES'
  echo 'PERSISTENT_WRITES=NONE'
  echo 'STEP12G_HOST_GUARD_INSTALL_PREFLIGHT=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_HOST_GUARD_APPROVAL || true)" == 'APPROVE_PRIVATE_QA_HOST_GUARD_ONLY' ]] ||
  fail explicit_private_only_approval_missing
echo '========== BACKUP PRIVATE QA ORIGIN CONFIG ONLY =========='
install -d -o root -g root -m 700 "$BACKUP"
install -o root -g root -m 600 "$HTTP_CONF" "$BACKUP/nginx.conf"
cmp -s "$HTTP_CONF" "$BACKUP/nginx.conf" || fail private_origin_backup_mismatch
[[ "$(stat -c '%U:%G:%a' "$BACKUP/nginx.conf")" == root:root:600 ]] ||
  fail private_origin_backup_permissions_wrong

echo '========== ACTIVATE QA-ONLY HOST RESTRICTION =========='
CHANGE_STARTED=yes
systemctl stop bambodia-previewqa-http.service ||
  fail dedicated_qa_http_stop_failed
# Replace only the dedicated QA HTTP config; shared Nginx is untouched.
install -o root -g "$INGRESS" -m 640 "$TMP/restricted.conf" "$HTTP_CONF"
cmp -s "$TMP/restricted.conf" "$HTTP_CONF" ||
  fail installed_host_guard_config_mismatch
systemctl start bambodia-previewqa-http.service ||
  fail dedicated_qa_http_start_failed
for n in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet bambodia-previewqa-http.service &&
     [[ -S "$SOCKET" ]]; then break; fi
  sleep 1
done
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail dedicated_qa_http_service_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail dedicated_qa_fpm_changed_during_http_only_restart
[[ -S "$SOCKET" && ! -L "$SOCKET" &&
  "$(stat -c '%U:%G' "$SOCKET")" == "$INGRESS:$INGRESS" ]] ||
  fail private_http_socket_missing_or_wrong_owner
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_http_runtime_dir_broadened
[[ "$(stat -c '%U:%G:%a' "$FPM_SOCKET")" == "$WORKER:$INGRESS:660" ]] ||
  fail private_fpm_socket_changed

echo '========== VERIFY ACTUAL LOCAL UNIX HTTP HOST ISOLATION =========='
probe(){
  local label="$1" host="$2" route="$3" status
  status="$(curl --silent --show-error --noproxy '*' --max-time 12 \
    --max-redirs 0 --path-as-is --output /dev/null --write-out '%{http_code}' \
    --unix-socket "$SOCKET" -H "Host: $host" -H 'Cookie:' \
    "http://localhost$route")" || fail "http_request_failed:$label"
  printf '%s' "$status"
}
for route in /up /login; do
  status="$(probe "canonical_$route" preview-qa.bambodia.com "$route")"
  [[ "$status" == 200 ]] ||
    fail "canonical_route_not_200:$route:$status"
done
echo 'CANONICAL_QA_HOST_UP_AND_LOGIN=HTTP_200'
for bad_host in unauthorized-origin.invalid localhost 127.0.0.1 \
                preview-qa.bambodia.com:443; do
  code="$(probe "unrecognized_host" "$bad_host" /up)"
  [[ "$code" == 421 ]] ||
    fail "unexpected_host_not_rejected:$bad_host:$code"
done
echo 'UNRECOGNIZED_LOCALHOST_IP_AND_PORT_QUALIFIED_HOSTS=HTTP_421'
# An HTTP/1.0 request without a Host header must not reach the app.
missing="$(curl --silent --show-error --noproxy '*' --max-time 12 \
  --http1.0 --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$SOCKET" -H 'Host:' http://localhost/up)" ||
  fail missing_host_request_failed
[[ "$missing" == 421 || "$missing" == 400 ]] ||
  fail "missing_host_not_rejected:$missing"
echo "MISSING_HOST_HTTP=$missing"
for route in /index.php /.htaccess /.env /storage/ /installer/; do
  code="$(probe "private_$route" preview-qa.bambodia.com "$route")"
  [[ "$code" == 404 ]] || fail "private_path_not_404:$route:$code"
done
echo 'PRIVATE_PHP_DOTFILES_STORAGE_INSTALLER=HTTP_404'
for route in /account /dashboard; do
  code="$(probe "guest_$route" preview-qa.bambodia.com "$route")"
  [[ "$code" == 302 ]] || fail "guest_route_not_redirected:$route:$code"
done
echo 'GUEST_ACCOUNT_AND_DASHBOARD=HTTP_302'
if runuser -u www -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); }
exit(2);
' "$SOCKET"; then fail shared_www_accessible_private_http_socket; fi
if getent passwd nobody >/dev/null; then
  if runuser -u nobody -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); }
exit(2);
' "$SOCKET"; then fail nobody_accessible_private_http_socket; fi
fi
[[ "$(systemctl is-enabled bambodia-previewqa-http.service 2>/dev/null || true)" == disabled ]] ||
  fail http_unit_boot_enabled
[[ "$(systemctl is-enabled bambodia-previewqa-fpm.service 2>/dev/null || true)" == disabled ]] ||
  fail fpm_unit_boot_enabled
ACCEPTED=yes
echo '========== STEP 12G PRIVATE ORIGIN HOST GUARD ACCEPTANCE =========='
echo 'QA_HTTP_SERVICE=ACTIVE_PRIVATE_UNIX_ONLY'
echo 'QA_FPM_SERVICE=ACTIVE_UNCHANGED'
echo 'HTTP_HOST_GUARD=ENABLED_QA_ONLY'
echo 'UNKNOWN_HOST=HTTP_421'
echo 'PRIVATE_SOCKET_OTHER_USERS=DENIED'
echo 'BACKUP_ROOT_PRIVATE=CREATED'
echo 'QA_SERVICES_ENABLED_AT_BOOT=NO'
echo 'PUBLIC_DNS_TUNNEL_TLS=NOT_CONFIGURED'
echo 'SHARED_NGINX_AND_PRODUCTION=UNTOUCHED'
echo 'PREVIEW_WITHDRAWAL_WRITES=DISABLED'
echo 'STEP12G_HOST_GUARD=PASS'
