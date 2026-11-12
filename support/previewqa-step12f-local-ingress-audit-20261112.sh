#!/usr/bin/env bash
# Step 12F — GET-only independent acceptance of private QA Unix HTTP ingress.
# No service restarts, configuration changes, credentials, POST or publication.
set -Eeuo pipefail
set +x
umask 077
fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
TMP=''
cleanup() { [[ -z "$TMP" ]] || rm -rf -- "$TMP"; }
trap cleanup EXIT

APP='/var/lib/bambodia-preview-restore-20261112'
SOURCE='/var/lib/bambodia-preview-qa-20261112'
DOCROOT='/var/lib/bambodia-previewqa-http-static-20261112/public'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
PHP='/www/server/php/85/bin/php'

echo '========== STEP 12F — LOCAL-ONLY HTTP INGRESS SECURITY REVIEW =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for tool in git stat systemctl runuser grep find wc curl mktemp sed id; do
  command -v "$tool" >/dev/null || fail "missing_tool:$tool"
done
[[ -x "$PHP" ]] || fail qa_php_binary_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail recovered_QA_source_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail recovered_QA_tracked_files_dirty
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail private_QA_app_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail QA_env_permissions_changed
[[ -d "$DOCROOT" && ! -L "$DOCROOT" &&
  "$(stat -c '%U:%G:%a' "$DOCROOT")" == "$INGRESS:$INGRESS:700" ]] ||
  fail dedicated_static_copy_changed
[[ "$(find "$DOCROOT" -type f | wc -l | tr -d ' ')" == 43 ]] ||
  fail unexpected_static_file_count
[[ -z "$(find "$DOCROOT" -type l -print -quit)" ]] ||
  fail static_copy_has_symlinks
[[ ! -e "$DOCROOT/index.php" && ! -e "$DOCROOT/.htaccess" ]] ||
  fail unexpected_PHP_or_Apache_file

for backup in '/root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf' '/root/Bambodia-PreviewQA-Lua-Recovery-20261112/nginx.conf' '/root/Bambodia-PreviewQA-Lua-Recovery-20261112/http.service'; do
  [[ -f "$backup" && ! -L "$backup" && "$(stat -c '%U:%G:%a' "$backup")" == root:root:600 ]] || fail "private_recovery_backup_unavailable:$backup"
done
echo '========== SERVICE AND SOCKET ISOLATION =========='
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail dedicated_HTTP_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail dedicated_FPM_not_active
for svc in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$svc" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_enabled_at_boot:$svc"
done
[[ "$(systemctl show bambodia-previewqa-http.service -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail dedicated_HTTP_unit_path_changed
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unreviewed_HTTP_systemd_override
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail isolated_HTTP_config_permissions_changed
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail isolated_HTTP_unit_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail isolated_FPM_config_changed
for line in "User=$INGRESS" "Group=$INGRESS" 'RuntimeDirectoryMode=0700' \
  'RestrictAddressFamilies=AF_UNIX' 'NoNewPrivileges=true' \
  'ProtectHome=true' 'ProtectSystem=strict' 'PrivateTmp=true'; do
  grep -Fxq "$line" "$HTTP_UNIT" || fail "lost_private_HTTP_guard:$line"
done
grep -Fxq "ExecStart=/usr/bin/nginx -e stderr -c $HTTP_CONF -g 'daemon off;'" "$HTTP_UNIT" ||
  fail private_nginx_command_changed
grep -Fxq '    lua_package_path "/www/server/nginx/lib/lua/?.lua;;";' "$HTTP_CONF" ||
  fail isolated_lua_runtime_path_missing
grep -Fxq '    server_tokens off;' "$HTTP_CONF" || fail private_nginx_version_redaction_changed
grep -Fxq 'error_log stderr warn;' "$HTTP_CONF" ||
  fail dedicated_nginx_stderr_logging_changed
grep -Fq "listen unix:$HTTP_SOCK;" "$HTTP_CONF" ||
  fail private_only_unix_listener_missing
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail additional_listener_in_private_config
grep -Fq "fastcgi_pass unix:$FPM_SOCK;" "$HTTP_CONF" ||
  fail unexpected_FastCGI_upstream
grep -Fq "fastcgi_param SCRIPT_FILENAME $APP/public/index.php;" "$HTTP_CONF" ||
  fail unexpected_FastCGI_front_controller
if grep -Eq '^[[:space:]]*(include|load_module)[[:space:]]+' "$HTTP_CONF"; then
  fail unexpected_nginx_include_or_module
fi
for line in "listen.owner = $WORKER" "listen.group = $INGRESS" \
  'listen.mode = 0660'; do
  grep -Fxq "$line" "$FPM_CONF" || fail "private_FPM_guard_changed:$line"
done
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_HTTP_socket_directory_changed
[[ -S "$HTTP_SOCK" && ! -L "$HTTP_SOCK" &&
  "$(stat -c '%U:%G' "$HTTP_SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail HTTP_unix_socket_owner_changed
mode="$(stat -c '%a' "$HTTP_SOCK")"
(( (8#$mode & 077) == 0 )) || fail HTTP_unix_socket_too_permissive
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail FPM_unix_socket_permissions_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_member_of_ingress_group
fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail HTTP_ingress_can_read_private_QA_secrets
fi
for socket in "$HTTP_SOCK" "$FPM_SOCK"; do
  if runuser -u www -- "$PHP" -r '
$fp=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($fp) { fclose($fp); exit(0); }
exit(2);
' "$socket"; then
    fail "shared_www_could_connect:$socket"
  fi
done
echo 'PRIVATE_HTTP_AND_FASTCGI_SOCKET_ISOLATION=PASS'
echo 'SHARED_WWW_CONNECT_BOTH_SOCKETS=DENIED'
echo 'DEDICATED_HTTP_ACCOUNT_CANNOT_READ_ENV=PASS'
echo 'QA_SERVICES_ACTIVE_BOOT_DISABLED=PASS'

echo '========== SYNTHETIC DATABASE AND WRITE GATE =========='
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
echo "SYNTHETIC_DB_AND_PREVIEW_WRITES_OFF=PASS\n";
' "$APP" || fail synthetic_database_identity_changed

echo '========== GET-ONLY PRIVATE UNIX HTTP ACCEPTANCE =========='
TMP="$(mktemp -d /root/bambodia-step12f.XXXXXXXX)"
chmod 700 "$TMP"
probe() {
  local label="$1" url="$2" host="$3" status
  status="$(curl --silent --show-error --noproxy '*' --max-time 12 \
    --path-as-is --max-redirs 0 --output /dev/null \
    --dump-header "$TMP/$label.headers" --write-out '%{http_code}' \
    --unix-socket "$HTTP_SOCK" -H "Host: $host" -H 'Cookie:' \
    "http://localhost$url")" || fail "HTTP_GET_failed:$label"
  [[ "$status" =~ ^[0-9][0-9][0-9]$ ]] || fail "bad_HTTP_status:$label"
  printf '%s' "$status"
}
for route in /up /login; do
  label="$(printf '%s' "$route" | sed 's#^/##')"
  status="$(probe "$label" "$route" 'preview-qa.bambodia.com')"
  [[ "$status" == 200 ]] || fail "expected_200:$route:$status"
  grep -Eiq '^X-Content-Type-Options:[[:space:]]*nosniff[[:space:]]*\r?$' \
    "$TMP/$label.headers" || fail "missing_nosniff:$route"
done
echo 'PUBLIC_HEALTH_AND_LOGIN_HEADERS=PASS'
for route in /index.php /.htaccess /.env /storage/ /installer/ /vendor/autoload.php; do
  label="$(printf '%s' "$route" | sed 's#[^a-zA-Z0-9]#_#g')"
  status="$(probe "blocked_$label" "$route" 'preview-qa.bambodia.com')"
  [[ "$status" == 404 ]] || fail "private_path_exposed:$route:$status"
done
echo 'DIRECT_PHP_DOTFILES_STORAGE_INSTALLER_PATHS=HTTP_404'
for route in /account /dashboard; do
  label="$(printf '%s' "$route" | sed 's#^/##')"
  status="$(probe "guest_$label" "$route" 'preview-qa.bambodia.com')"
  case "$status" in
    301|302|303|307|308|401|403) ;;
    *) fail "private_route_not_protected_for_guest:$route:$status" ;;
  esac
  printf 'GUEST_%s_HTTP=%s\n' "$label" "$status"
done
echo 'PRIVATE_ACCOUNT_DASHBOARD_GUEST_ACCESS=DENIED'

# This is only an observation, never evidence of Cloudflare Access protection.
unexpected="$(probe 'unknown_host' /up 'unauthorized-origin.invalid')"
printf 'UNRECOGNIZED_HOST_HEALTH_HTTP=%s\n' "$unexpected"
if [[ "$unexpected" == 200 ]]; then
  echo 'BEFORE_PUBLICATION=REVIEW_ORIGIN_HOST_ENFORCEMENT_AND_ACCESS'
fi
echo 'NO_POST_REQUESTS_OR_CREDENTIALS=YES'
echo 'HTTP_TRANSPORT=LOCAL_UNIX_SOCKET_ONLY'
echo 'PUBLIC_TLS_DNS_CLOUDFLARE=NOT_CONFIGURED_BY_SCRIPT'
echo 'NO_CONFIG_OR_SERVICE_MUTATIONS=YES'
echo 'STEP12F_LOCAL_INGRESS_AUDIT=PASS'
