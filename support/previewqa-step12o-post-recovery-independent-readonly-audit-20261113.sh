#!/usr/bin/env bash
# Step 12O — independent read-only cleanup audit after synthetic Step12N.
# Does NOT start/stop services, install connectors or change socket privileges.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
SOURCE='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
PHP='/www/server/php/85/bin/php'

echo '========== STEP 12O — INDEPENDENT POST-RECOVERY LOCAL-ONLY AUDIT =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for cmd in stat git systemctl runuser grep find getent id curl; do
  command -v "$cmd" >/dev/null || fail "missing_command:$cmd"
done
[[ -x "$PHP" ]] || fail QA_php_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail QA_source_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail QA_source_has_tracked_changes
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail QA_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail QA_env_permissions_changed
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail QA_http_config_permissions_changed
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail QA_http_unit_permissions_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail QA_fpm_config_permissions_changed
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail original_host_guard_backup_missing
for service in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  systemctl is-active --quiet "$service" || fail "QA_service_not_active:$service"
  [[ "$(systemctl is-enabled "$service" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_enabled_at_boot:$service"
done
grep -Fxq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$HTTP_CONF" ||
  fail canonical_host_guard_missing
grep -Fxq '            return 421;' "$HTTP_CONF" ||
  fail unknown_host_rejection_missing
grep -Fxq "        listen unix:$HTTP_SOCK;" "$HTTP_CONF" ||
  fail QA_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail additional_QA_HTTP_listener
grep -Fq "fastcgi_pass unix:$FPM_SOCK;" "$HTTP_CONF" ||
  fail FPM_socket_route_changed
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_origin_directory_mode_changed
[[ -S "$HTTP_SOCK" && ! -L "$HTTP_SOCK" &&
  "$(stat -c '%U:%G:%a' "$HTTP_SOCK")" == "$INGRESS:$INGRESS:666" ]] ||
  fail private_HTTP_socket_mode_changed
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail private_FPM_socket_mode_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_joined_private_ingress_group
fi
if runuser -u www -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); } exit(2);
' "$HTTP_SOCK"; then fail shared_www_private_socket_connection; fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail private_nginx_ingress_can_read_qa_secrets
fi
echo 'REAL_QA_HTTP_AND_FPM_ACTIVE_BOOT_DISABLED=PASS'
echo 'ORIGINAL_0700_PARENT_AND_0666_HTTP_SOCKET=PASS'
echo 'SHARED_WWW_AND_NGINX_SECRET_BOUNDARIES=PASS'

echo '========== POST-STEP12N TRANSIENT UNIT AND MOCK FILE CLEANUP =========='
for unit in \
  bambodia-previewqa-socketprobe-20261113.service \
  bambodia-previewqa-mockrebind-20261113.service \
  bambodia-previewqa-mockrecover-20261113.service; do
  state="$(systemctl show "$unit" -p LoadState --value 2>/dev/null || true)"
  [[ "$state" == not-found ]] || fail "transient_probe_unit_still_loaded:$unit:$state"
done
for runtime in \
  /run/bambodia-previewqa-socketprobe-20261113 \
  /run/bambodia-previewqa-mockrebind-20261113 \
  /run/bambodia-previewqa-mockrecover-20261113; do
  [[ ! -e "$runtime" && ! -L "$runtime" ]] ||
    fail "transient_runtime_directory_remaining:$runtime"
done
for pattern in 'bambodia-step12m.*' 'bambodia-step12n.*'; do
  stale="$(find /run -maxdepth 1 -mindepth 1 -name "$pattern" -print -quit)"
  [[ -z "$stale" ]] || fail "synthetic_root_private_mock_directory_remaining:$pattern"
done
echo 'STEP12K_M_OLD_AND_NEW_TRANSIENT_UNITS=ABSENT_CLEAN'
echo 'STEP12M_AND_N_SYNTHETIC_MOCK_DIRECTORIES=ABSENT_CLEAN'

echo '========== CANONICAL-ONLY LOCAL UNIX ORIGIN READINESS =========='
canonical="$(curl --silent --show-error --noproxy '*' --max-time 10 \
  --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$HTTP_SOCK" -H 'Host: preview-qa.bambodia.com' \
  http://localhost/up)" || fail canonical_local_up_request_failed
unknown="$(curl --silent --show-error --noproxy '*' --max-time 10 \
  --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$HTTP_SOCK" -H 'Host: unrecognized.invalid' \
  http://localhost/up)" || fail unknown_host_local_up_request_failed
[[ "$canonical" == 200 ]] || fail "canonical_local_up_expected_200:$canonical"
[[ "$unknown" == 421 ]] || fail "unknown_local_up_expected_421:$unknown"
echo 'CANONICAL_LOCAL_ORIGIN_GET_UP=HTTP_200'
echo 'UNKNOWN_HOST_LOCAL_ORIGIN_GET_UP=HTTP_421'

echo '========== SYNTHETIC QA DATABASE AND WITHDRAWAL WRITES =========='
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
echo "SYNTHETIC_QA_DATABASE_AND_PREVIEW_WRITES_OFF=PASS\n";
' "$APP" || fail synthetic_database_or_preview_write_flag_changed

echo '========== PRIVATE ORIGIN RESTART RISK / RELEASE BOUNDARIES =========='
[[ "$(systemctl show bambodia-previewqa-http.service -p Restart --value)" == no ]] ||
  fail QA_HTTP_restart_policy_changed
[[ "$(systemctl show bambodia-previewqa-http.service -p RuntimeDirectoryPreserve --value)" == no ]] ||
  fail QA_HTTP_runtime_directory_preservation_changed
printf 'LIVE_HTTP_SOCKET_INODE=%s\n' "$(stat -Lc '%i' "$HTTP_SOCK")"
printf 'LIVE_HTTP_SOCKET_DEVICE=%s\n' "$(stat -Lc '%d' "$HTTP_SOCK")"
if command -v cloudflared >/dev/null ||
   getent passwd cloudflared >/dev/null ||
   [[ "$(systemctl show cloudflared.service -p LoadState --value 2>/dev/null || true)" == loaded ]]; then
  fail cloudflared_present_unexpectedly
fi
echo 'CLOUDFLARED_BINARY_ACCOUNT_AND_SERVICE=ABSENT'
echo 'REAL_ORIGIN_RESTART_RECOVERY=NOT_TESTED'
echo 'LONG_RUNNING_CLOUDFLARE_CONNECTOR=NOT_INSTALLED'
echo 'CLOUDFLARE_ACCESS_JWT_DNS_TLS=NOT_VALIDATED'
echo 'NO_LIVE_SERVICE_RESTART_OR_MOUNT=YES'
echo 'NO_HOST_CONFIG_USER_OR_PERMISSION_MUTATION=YES'
echo 'STEP12O_POST_SYNTHETIC_RECOVERY_INDEPENDENT_AUDIT=PASS'
