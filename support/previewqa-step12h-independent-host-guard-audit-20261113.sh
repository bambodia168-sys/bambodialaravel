#!/usr/bin/env bash
# Step 12H: independent post-activation LOCAL-only Host-header security audit.
# No installer, no service changes, no public routing, no authenticated POSTs.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
TMP=''
cleanup(){ [[ -z "$TMP" ]] || rm -rf -- "$TMP"; }
trap cleanup EXIT

SOURCE='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
DOCROOT='/var/lib/bambodia-previewqa-http-static-20261112/public'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
NGINX='/usr/bin/nginx'
PHP='/www/server/php/85/bin/php'
ANCHOR='        server_name preview-qa.bambodia.com localhost 127.0.0.1;'

echo '========== STEP 12H — INDEPENDENT PRIVATE HOST GUARD ACCEPTANCE =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for bin in git stat systemctl grep awk sed cmp find wc mktemp curl runuser \
  getent id readlink install; do
  command -v "$bin" >/dev/null || fail "missing_command:$bin"
done
[[ -x "$NGINX" && "$(readlink -f "$NGINX")" == /www/server/nginx/sbin/nginx ]] ||
  fail dedicated_nginx_binary_changed
[[ -x "$PHP" ]] || fail php_binary_unavailable
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail pinned_qa_source_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail qa_source_tracked_changes
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail private_qa_laravel_owner_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail qa_env_permissions_changed
[[ -d "$DOCROOT" && ! -L "$DOCROOT" &&
  "$(stat -c '%U:%G:%a' "$DOCROOT")" == "$INGRESS:$INGRESS:700" ]] ||
  fail static_mirror_owner_changed
[[ "$(find "$DOCROOT" -type f | wc -l | tr -d ' ')" == 43 ]] ||
  fail private_static_file_count_changed
[[ -z "$(find "$DOCROOT" -type l -print -quit)" ]] ||
  fail private_static_symlink_found
[[ ! -e "$DOCROOT/index.php" && ! -e "$DOCROOT/.htaccess" ]] ||
  fail private_static_contains_executable_or_htaccess
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail host_guard_root_private_original_backup_missing
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail dedicated_nginx_config_changed
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail dedicated_http_unit_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail qa_fpm_conf_changed
for prior in \
  /root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf \
  /root/Bambodia-PreviewQA-Lua-Recovery-20261112/nginx.conf \
  /root/Bambodia-PreviewQA-Lua-Recovery-20261112/http.service; do
  [[ -f "$prior" && ! -L "$prior" &&
    "$(stat -c '%U:%G:%a' "$prior")" == root:root:600 ]] ||
    fail "prior_root_private_backup_missing:$prior"
done

echo '========== VERIFY ISOLATED SERVICE BOUNDARIES =========='
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail private_http_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail private_fpm_not_active
for unit in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$unit" 2>/dev/null || true)" == disabled ]] ||
    fail "qa_service_enabled_at_boot:$unit"
done
[[ "$(systemctl show bambodia-previewqa-http.service -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail private_nginx_unit_path_changed
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unexpected_dedicated_service_override
for key in "User=$INGRESS" "Group=$INGRESS" 'RuntimeDirectoryMode=0700' \
  'RestrictAddressFamilies=AF_UNIX' 'ProtectSystem=strict' \
  'ProtectHome=true' 'NoNewPrivileges=true' 'PrivateTmp=true'; do
  grep -Fxq "$key" "$HTTP_UNIT" ||
    fail "qa_nginx_systemd_security_changed:$key"
done
grep -Fxq "ExecStart=$NGINX -e stderr -c $HTTP_CONF -g 'daemon off;'" "$HTTP_UNIT" ||
  fail isolated_nginx_command_changed
for line in "listen.owner = $WORKER" "listen.group = $INGRESS" \
  'listen.mode = 0660'; do
  grep -Fxq "$line" "$FPM_CONF" ||
    fail "scoped_qa_fpm_setting_changed:$line"
done
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_http_parent_directory_broadened
[[ -S "$HTTP_SOCK" && ! -L "$HTTP_SOCK" &&
  "$(stat -c '%U:%G' "$HTTP_SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail private_http_socket_owner_changed
case "$(stat -c '%a' "$HTTP_SOCK")" in
  600|660|666) ;;
  *) fail unexpected_http_socket_mode ;;
esac
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail private_fpm_socket_mode_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_member_of_private_ingress_group
fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail ingress_can_read_private_laravel_env
fi
for user_name in www nobody; do
  if getent passwd "$user_name" >/dev/null; then
    runuser -u "$user_name" -- "$PHP" -r 'exit(0);' ||
      fail "unprivileged_test_client_unavailable:$user_name"
    for sock in "$HTTP_SOCK" "$FPM_SOCK"; do
      if runuser -u "$user_name" -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); }
exit(2);
' "$sock"; then fail "unprivileged_socket_connect_permitted:$user_name:$sock"; fi
    done
  fi
done
echo 'DEDICATED_QA_SERVICE_AND_SOCKET_ISOLATION=PASS'
echo 'SHARED_WWW_AND_NOBODY_PRIVATE_CONNECT=DENIED'

echo '========== INDEPENDENT CONFIG-BACKUP RECONCILIATION =========='
grep -Fxq "$ANCHOR" "$ORIGINAL" ||
  fail original_backup_server_name_changed
[[ "$(grep -Fxc "$ANCHOR" "$ORIGINAL")" == 1 ]] ||
  fail original_backup_anchor_not_unique
if grep -Fq 'return 421;' "$ORIGINAL"; then
  fail host_guard_already_present_in_original_backup
fi
TMP="$(mktemp -d /root/bambodia-step12h.XXXXXXXX)"
chmod 700 "$TMP"
cat > "$TMP/expected-guard" <<'GUARD'
        # Canonical QA origin only. Not a replacement for Cloudflare Access.
        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {
            return 421;
        }
GUARD
awk -v anchor="$ANCHOR" -v insert="$TMP/expected-guard" '
  $0 == anchor {
    print
    while ((getline line < insert) > 0) print line
    close(insert)
    count++
    next
  }
  { print }
  END { if (count != 1) exit 2 }
' "$ORIGINAL" > "$TMP/expected.conf" ||
  fail expected_origin_config_reconstruction_failed
cmp -s "$TMP/expected.conf" "$HTTP_CONF" ||
  fail installed_origin_differs_from_guard_only_change
grep -Fxq '    lua_package_path "/www/server/nginx/lib/lua/?.lua;;";' "$HTTP_CONF" ||
  fail preexisting_lua_path_changed
grep -Fxq 'error_log stderr warn;' "$HTTP_CONF" ||
  fail private_error_log_changed
grep -Fxq '    server_tokens off;' "$HTTP_CONF" ||
  fail private_nginx_version_hiding_changed
grep -Fxq "        listen unix:$HTTP_SOCK;" "$HTTP_CONF" ||
  fail dedicated_unix_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail additional_listener_detected
grep -Fq "fastcgi_pass unix:$FPM_SOCK;" "$HTTP_CONF" ||
  fail private_qa_fastcgi_changed
grep -Fq "fastcgi_param SCRIPT_FILENAME $APP/public/index.php;" "$HTTP_CONF" ||
  fail fixed_qa_laravel_front_controller_changed
if grep -Eq '^[[:space:]]*(include|load_module)[[:space:]]+' "$HTTP_CONF"; then
  fail unexpected_origin_include_or_module
fi
echo 'INSTALLED_HOST_GUARD_EQUALS_BACKUP_PLUS_EXACT_RESTRICTION=PASS'
echo 'ONE_PRIVATE_UNIX_HTTP_LISTENER=PASS'

echo '========== SYNTHETIC QA DATABASE AND WRITE FLAG =========='
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
echo "SYNTHETIC_QA_DB_AND_PREVIEW_WRITES_OFF=PASS\n";
' "$APP" || fail QA_database_or_preview_write_gate_changed

echo '========== INDEPENDENT GUEST-ONLY LOCAL UNIX GET TESTS =========='
probe(){
  local label="$1" host="$2" route="$3" status
  status="$(curl --silent --show-error --noproxy '*' --max-time 12 \
    --max-redirs 0 --path-as-is --output /dev/null \
    --dump-header "$TMP/$label.headers" --write-out '%{http_code}' \
    --unix-socket "$HTTP_SOCK" -H "Host: $host" -H 'Cookie:' \
    "http://localhost$route")" || fail "GET_failed:$label"
  [[ "$status" =~ ^[0-9][0-9][0-9]$ ]] ||
    fail "invalid_http_status:$label"
  printf '%s' "$status"
}
for route in /up /login; do
  label="canonical"$(printf '%s' "$route" | sed 's#[^a-zA-Z0-9]#_#g')
  code="$(probe "$label" 'preview-qa.bambodia.com' "$route")"
  [[ "$code" == 200 ]] || fail "canonical_host_not_200:$route:$code"
  grep -Eiq '^X-Content-Type-Options:[[:space:]]*nosniff[[:space:]]*\r?$' \
    "$TMP/$label.headers" || fail "missing_nosniff:$route"
done
echo 'CANONICAL_ORIGIN_UP_AND_LOGIN=HTTP_200_NOSNIFF'
upper="$(probe 'uppercase_host' 'PREVIEW-QA.BAMBODIA.COM' /up)"
[[ "$upper" == 200 ]] || fail "canonical_case_insensitive_host_failed:$upper"
for bad in unauthorized-origin.invalid localhost 127.0.0.1 \
           preview-qa.bambodia.com:443; do
  label="$(printf '%s' "$bad" | sed 's#[^a-zA-Z0-9]#_#g')"
  code="$(probe "bad_$label" "$bad" /up)"
  [[ "$code" == 421 ]] || fail "unknown_host_not_421:$bad:$code"
done
missing="$(curl --silent --show-error --noproxy '*' --max-time 12 \
  --http1.0 --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$HTTP_SOCK" -H 'Host:' http://localhost/up)" ||
  fail missing_host_check_failed
[[ "$missing" == 421 || "$missing" == 400 ]] ||
  fail "missing_host_not_blocked:$missing"
echo "MISSING_HOST_REJECTED_HTTP=$missing"
echo 'UNKNOWN_IP_LOCALHOST_PORT_HOSTS=HTTP_421'
for route in /index.php /.env /.htaccess /storage/ /installer/ /vendor/autoload.php; do
  label="$(printf '%s' "$route" | sed 's#[^a-zA-Z0-9]#_#g')"
  code="$(probe "private_$label" preview-qa.bambodia.com "$route")"
  [[ "$code" == 404 ]] || fail "restricted_file_not_404:$route:$code"
done
echo 'PRIVATE_PHP_ENV_HTACCESS_STORAGE_INSTALLER_PATHS=HTTP_404'
for route in /account /dashboard; do
  label="$(printf '%s' "$route" | sed 's#^/##')"
  code="$(probe "guest_$label" preview-qa.bambodia.com "$route")"
  [[ "$code" == 302 ]] || fail "guest_route_not_302:$route:$code"
done
echo 'GUEST_ACCOUNT_AND_DASHBOARD=HTTP_302'
echo 'HTTP_TRANSPORT=LOCAL_UNIX_SOCKET_GET_ONLY'
echo 'QA_SERVICES_ENABLED_AT_BOOT=NO'
echo 'PUBLIC_DNS_TLS_CLOUDFLARE=NOT_TESTED_OR_CHANGED'
echo 'NO_CONFIG_OR_SERVICE_MUTATIONS=YES'
echo 'STEP12H_INDEPENDENT_ORIGIN_HOST_AUDIT=PASS'
