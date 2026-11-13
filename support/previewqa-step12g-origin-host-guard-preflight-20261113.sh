#!/usr/bin/env bash
# Step 12G: PREPARE an isolated Nginx Host-header restriction, never install it.
# Fail closed if the live QA service/config no longer matches local acceptance.
# Root-private /run temporary candidate only; no service restart, no DNS, no TCP.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
TMP=''
cleanup(){ [[ -z "$TMP" ]] || rm -rf -- "$TMP"; }
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
  getent curl id readlink; do
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
echo 'NO_EXISTING_SERVICES_RESTARTED=YES'
echo 'PERSISTENT_WRITES=NONE'
echo 'STEP12G_HOST_GUARD_PREFLIGHT=PASS'
