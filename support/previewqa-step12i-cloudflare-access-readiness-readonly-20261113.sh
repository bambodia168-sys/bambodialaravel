#!/usr/bin/env bash
# Step 12I: read-only readiness inventory for a future Access-first Tunnel.
# Does NOT install/start a connector, read its credentials, or create routes.
set -Eeuo pipefail
set +x
umask 077
fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

SOURCE='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
CONF='/etc/bambodia-previewqa-http/nginx.conf'
UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
INGRESS='bambodia_pvhttp'
WORKER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'

echo '========== STEP 12I — ACCESS-FIRST TUNNEL READINESS INVENTORY =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for bin in git stat systemctl grep id getent runuser curl readlink; do
  command -v "$bin" >/dev/null || fail "missing_tool:$bin"
done
[[ -x "$PHP" ]] || fail isolated_qa_php_binary_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail immutable_synthetic_source_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail synthetic_source_tracked_changes
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail private_qa_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail private_qa_env_permissions_changed
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail independent_host_guard_backup_missing
[[ -f "$CONF" && ! -L "$CONF" &&
  "$(stat -c '%U:%G:%a' "$CONF")" == root:"$INGRESS":640 ]] ||
  fail private_origin_conf_permissions_changed
[[ -f "$UNIT" && ! -L "$UNIT" &&
  "$(stat -c '%U:%G:%a' "$UNIT")" == root:root:644 ]] ||
  fail private_origin_systemd_unit_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail private_fpm_conf_permissions_changed
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail private_http_service_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail private_fpm_service_not_active
for unit in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$unit" 2>/dev/null || true)" == disabled ]] ||
    fail "private_qa_unit_enabled_at_boot:$unit"
done
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unreviewed_private_http_unit_override
grep -Fxq 'RestrictAddressFamilies=AF_UNIX' "$UNIT" ||
  fail private_http_systemd_family_scope_changed
grep -Fxq 'RuntimeDirectoryMode=0700' "$UNIT" ||
  fail private_runtime_directory_mode_changed
grep -Fxq "User=$INGRESS" "$UNIT" || fail private_http_user_changed
grep -Fxq "Group=$INGRESS" "$UNIT" || fail private_http_group_changed
grep -Fxq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$CONF" ||
  fail canonical_origin_host_filter_missing
grep -Fxq '            return 421;' "$CONF" ||
  fail unknown_origin_host_rejection_missing
grep -Fq "        listen unix:$SOCK;" "$CONF" ||
  fail expected_private_unix_listener_missing
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$CONF")" == 1 ]] ||
  fail unexpected_second_http_listener
grep -Fq "fastcgi_pass unix:$FPM_SOCK;" "$CONF" ||
  fail unexpected_fastcgi_upstream_changed
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail Unix_socket_parent_not_private
[[ -S "$SOCK" && ! -L "$SOCK" &&
  "$(stat -c '%U:%G' "$SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail private_http_socket_owner_changed
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail private_fastcgi_socket_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_in_ingress_group
fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail ingress_user_can_read_private_qa_env
fi
if runuser -u www -- "$PHP" -r '
$c=@stream_socket_client("unix://".$argv[1],$errno,$errstr,2);
if ($c) { fclose($c); exit(0); } exit(2);
' "$SOCK"; then
  fail shared_www_can_connect_private_http
fi
echo 'PRIVATE_ORIGIN_AND_LINUX_SOCKET_ISOLATION=PASS'

echo '========== PINNED SYNTHETIC QA DATA AND WRITES-OFF =========='
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
echo "ISOLATED_QA_DATABASE_AND_PREVIEW_WRITES_OFF=PASS\n";
' "$APP" || fail synthetic_qa_db_or_feature_flag_changed

echo '========== LOCAL PRIVATE ORIGIN — GET-ONLY CHECK =========='
canonical="$(curl --silent --show-error --noproxy '*' --max-time 10 \
  --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$SOCK" -H 'Host: preview-qa.bambodia.com' \
  http://localhost/up)" || fail canonical_host_health_failed
unrecognized="$(curl --silent --show-error --noproxy '*' --max-time 10 \
  --max-redirs 0 --output /dev/null --write-out '%{http_code}' \
  --unix-socket "$SOCK" -H 'Host: unauthorized-origin.invalid' \
  http://localhost/up)" || fail unknown_host_health_failed
[[ "$canonical" == 200 ]] || fail "canonical_host_expected_200:$canonical"
[[ "$unrecognized" == 421 ]] || fail "unknown_host_expected_421:$unrecognized"
echo 'CANONICAL_HOST_UP=HTTP_200'
echo 'UNRECOGNIZED_HOST_UP=HTTP_421'

echo '========== CLOUDFLARED RUNTIME DISCOVERY (NO SECRETS) =========='
if command -v cloudflared >/dev/null; then
  echo 'CLOUDFLARED_BINARY=INSTALLED'
  cloudflared --version 2>&1 | head -n 1
else
  echo 'CLOUDFLARED_BINARY=NOT_INSTALLED'
fi
cloudflared_load_state="$(systemctl show cloudflared.service -p LoadState --value 2>/dev/null || true)"
if [[ "$cloudflared_load_state" == loaded ]]; then
  echo 'DEFAULT_CLOUDFLARED_UNIT=EXISTS_MAY_SERVE_UNRELATED_APPS'
  systemctl show cloudflared.service -p ActiveState -p UnitFileState \
    -p User -p Group --no-pager
else
  echo 'DEFAULT_CLOUDFLARED_UNIT=NOT_LOADED'
fi
if getent passwd cloudflared >/dev/null; then
  echo 'NAMED_CLOUDFLARED_ACCOUNT=EXISTS'
  if runuser -u cloudflared -- test -x /run/bambodia-previewqa-http; then
    echo 'NAMED_CLOUDFLARED_ACCOUNT_CAN_TRAVERSE_QA_SOCKET_DIR=YES_REVIEW_REQUIRED'
  else
    echo 'NAMED_CLOUDFLARED_ACCOUNT_CAN_TRAVERSE_QA_SOCKET_DIR=NO'
  fi
else
  echo 'NAMED_CLOUDFLARED_ACCOUNT=NOT_FOUND'
fi
echo 'RECOMMENDED_FUTURE_ORIGIN_TRANSPORT=UNIX_SOCKET_NOT_TCP'
echo 'RECOMMENDED_FUTURE_SERVICE=unix:/run/bambodia-previewqa-http/http.sock'
echo 'CONNECTOR_UNIX_SOCKET_IDENTITY=REQUIRES_INDEPENDENT_DESIGN'
echo 'ACCESS_APPLICATION_POLICY=NOT_VERIFIED_IN_CLOUDFLARE_ACCOUNT'
echo 'ACCESS_JWT_AUDIENCE_AND_VALIDATION=NOT_CONFIGURED_BY_THIS_SCRIPT'
echo 'CONNECTOR_CREDENTIALS=NOT_READ_OR_CREATED'
echo 'PUBLIC_DNS_TUNNEL_TLS=NOT_CHANGED'
echo 'NO_SERVICE_STARTED_RESTARTED_OR_ENABLED=YES'
echo 'NO_CONFIG_OR_DATABASE_WRITE_COMMANDS=YES'
echo 'STEP12I_ACCESS_FIRST_READINESS_DISCOVERY=PASS'
