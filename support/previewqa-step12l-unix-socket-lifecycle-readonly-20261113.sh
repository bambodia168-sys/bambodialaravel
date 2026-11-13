#!/usr/bin/env bash
# Step12L: read-only live socket inode and QA HTTP lifecycle inventory.
# No temporary services/mounts, Nginx restarts, credentials, DNS, TCP or writes.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

SOURCE='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_UNIT='/etc/systemd/system/bambodia-previewqa-fpm.service'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
INGRESS='bambodia_pvhttp'
WORKER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
TRANSIENT_UNIT='bambodia-previewqa-socketprobe-20261113.service'
TRANSIENT_DIR='/run/bambodia-previewqa-socketprobe-20261113'

echo '========== STEP 12L — READ-ONLY UNIX SOCKET LIFECYCLE INVENTORY =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for exe in stat git systemctl grep id getent runuser sha256sum; do
  command -v "$exe" >/dev/null || fail "missing_tool:$exe"
done
[[ -x "$PHP" ]] || fail private_php_binary_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail immutable_source_revision_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail immutable_source_tracked_changes
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail QA_app_directory_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail QA_environment_file_permissions_changed
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail root_only_original_host_guard_backup_changed
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail QA_nginx_configuration_permissions_changed
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail QA_nginx_systemd_unit_permissions_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail QA_FPM_configuration_permissions_changed
[[ -f "$FPM_UNIT" && ! -L "$FPM_UNIT" ]] || fail QA_FPM_unit_missing

echo '========== DEDICATED LIVE UNIT AND RESTART LIFECYCLE =========='
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail private_QA_Nginx_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail private_QA_FPM_not_active
for unit in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$unit" 2>/dev/null || true)" == disabled ]] ||
    fail "private_QA_service_boot_enabled:$unit"
done
[[ "$(systemctl show bambodia-previewqa-http.service -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail QA_Nginx_unit_path_changed
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unexpected_QA_Nginx_unit_override
for expected in "User=$INGRESS" "Group=$INGRESS" \
  'RuntimeDirectory=bambodia-previewqa-http' 'RuntimeDirectoryMode=0700' \
  'RestrictAddressFamilies=AF_UNIX' 'ProtectSystem=strict' \
  'ProtectHome=true' 'NoNewPrivileges=true' 'Restart=no'; do
  grep -Fxq "$expected" "$HTTP_UNIT" ||
    fail "private_QA_Nginx_service_guard_changed:$expected"
done
grep -Fxq "ExecStart=/usr/bin/nginx -e stderr -c $HTTP_CONF -g 'daemon off;'" "$HTTP_UNIT" ||
  fail approved_QA_Nginx_ExecStart_changed
grep -Fq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$HTTP_CONF" ||
  fail canonical_origin_Host_guard_missing
grep -Fxq '            return 421;' "$HTTP_CONF" ||
  fail unknown_Host_421_guard_missing
grep -Fxq "        listen unix:$HTTP_SOCK;" "$HTTP_CONF" ||
  fail private_Unix_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail additional_private_Nginx_listener_found
grep -Fq "fastcgi_pass unix:$FPM_SOCK;" "$HTTP_CONF" ||
  fail approved_private_FastCGI_socket_changed
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_socket_parent_broadened
[[ -S "$HTTP_SOCK" && ! -L "$HTTP_SOCK" &&
  "$(stat -c '%U:%G' "$HTTP_SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail QA_HTTP_socket_missing_or_owner_changed
case "$(stat -c '%a' "$HTTP_SOCK")" in
  600|660|666) ;;
  *) fail unexpected_Nginx_Unix_socket_mode ;;
esac
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail dedicated_FPM_socket_permissions_changed
for required in "listen.owner = $WORKER" "listen.group = $INGRESS" \
  'listen.mode = 0660'; do
  grep -Fxq "$required" "$FPM_CONF" || fail "FPM_socket_policy_changed:$required"
done
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_added_to_private_origin_group
fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail Nginx_can_read_QA_application_secrets
fi
if runuser -u www -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); } exit(2);
' "$HTTP_SOCK"; then
  fail shared_www_can_connect_private_HTTP_socket
fi
echo 'DEDICATED_HTTP_AND_FPM_LIVE_AND_BOOT_DISABLED=PASS'
echo 'PRIVATE_SOCKET_0700_PARENT_AND_OTHER_USER_DENIAL=PASS'

echo '========== SOCKET PATH VS INODE — OBSERVATION ONLY =========='
printf 'HTTP_SOCKET_INODE=%s\n' "$(stat -Lc '%i' "$HTTP_SOCK")"
printf 'HTTP_SOCKET_DEVICE=%s\n' "$(stat -Lc '%d' "$HTTP_SOCK")"
printf 'HTTP_SOCKET_LINKS=%s\n' "$(stat -Lc '%h' "$HTTP_SOCK")"
printf 'HTTP_SOCKET_MODE=%s\n' "$(stat -Lc '%a' "$HTTP_SOCK")"
printf 'HTTP_SERVICE_RESTART_POLICY=%s\n' \
  "$(systemctl show bambodia-previewqa-http.service -p Restart --value)"
printf 'HTTP_RUNTIME_DIRECTORY_PRESERVE=%s\n' \
  "$(systemctl show bambodia-previewqa-http.service -p RuntimeDirectoryPreserve --value)"
printf 'HTTP_SERVICE_TYPE=%s\n' \
  "$(systemctl show bambodia-previewqa-http.service -p Type --value)"
[[ "$(systemctl show "$TRANSIENT_UNIT" -p LoadState --value 2>/dev/null || true)" == not-found ]] ||
  fail previous_dynamic_user_probe_not_collected
[[ ! -e "$TRANSIENT_DIR" && ! -L "$TRANSIENT_DIR" ]] ||
  fail previous_dynamic_user_probe_directory_persisted
echo 'STEP12K_TRANSIENT_UNIT_AND_RUNTIME_DIR=ABSENT_CLEAN'

echo '========== PINNED SYNTHETIC TESTING DATA / WRITE FLAG =========='
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
' "$APP" || fail testing_database_or_preview_writes_changed

echo '========== EXTERNAL CONNECTOR AND RELEASE BOUNDARIES =========='
if command -v cloudflared >/dev/null ||
   getent passwd cloudflared >/dev/null ||
   [[ "$(systemctl show cloudflared.service -p LoadState --value 2>/dev/null || true)" == loaded ]]; then
  fail cloudflared_found_since_step12K_requires_review
fi
echo 'CLOUDFLARED_BINARY_ACCOUNT_UNIT=ABSENT'
echo 'BIND_MOUNT_SOCKET_INODE_REBIND_AFTER_RESTART=NOT_TESTED'
echo 'DYNAMIC_USER_LONG_RUNNING_RECONNECT=NOT_TESTED'
echo 'NO_QA_NGINX_OR_FPM_RESTART=YES'
echo 'NO_TEMP_SERVICE_MOUNT_OR_LINUX_IDENTITY_CREATED=YES'
echo 'NO_DNS_TLS_CLOUDFLARE_ACCESS_OR_TUNNEL_CHANGED=YES'
echo 'NO_HOST_OR_CONFIG_WRITE_COMMANDS=YES'
echo 'STEP12L_SOCKET_LIFECYCLE_INVENTORY=PASS'
