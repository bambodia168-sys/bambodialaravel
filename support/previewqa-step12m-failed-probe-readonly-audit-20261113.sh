#!/usr/bin/env bash
# Step12M failure follow-up: read-only, fail-closed host cleanup and QA audit.
# Does not restart either QA service, access credentials, or launch new probes.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

UNIT='bambodia-previewqa-mockrebind-20261113.service'
RUNTIME='/run/bambodia-previewqa-mockrebind-20261113'
APP='/var/lib/bambodia-preview-restore-20261112'
SOURCE='/var/lib/bambodia-preview-qa-20261112'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
PHP='/www/server/php/85/bin/php'

echo '========== STEP12M FAILED PROBE — NO-MUTATION HOST DIAGNOSTIC =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for cmd in stat git systemctl runuser grep find getent id journalctl; do
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
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail QA_original_host_guard_backup_missing
for service in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  systemctl is-active --quiet "$service" ||
    fail "QA_service_not_active:$service"
  [[ "$(systemctl is-enabled "$service" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_boot_enabled:$service"
done
grep -Fxq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$HTTP_CONF" ||
  fail canonical_host_guard_missing
grep -Fxq '            return 421;' "$HTTP_CONF" ||
  fail unknown_host_rejection_missing
grep -Fxq "        listen unix:$HTTP_SOCK;" "$HTTP_CONF" ||
  fail private_origin_socket_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail additional_QA_http_listener
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_origin_parent_mode_changed
[[ -S "$HTTP_SOCK" && ! -L "$HTTP_SOCK" &&
  "$(stat -c '%U:%G:%a' "$HTTP_SOCK")" == "$INGRESS:$INGRESS:666" ]] ||
  fail QA_real_http_socket_mode_changed
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail QA_fpm_socket_mode_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_joined_ingress_group
fi
if runuser -u www -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); } exit(2);
' "$HTTP_SOCK"; then fail shared_www_private_socket_connection; fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail ingress_can_read_QA_secrets
fi
echo 'REAL_QA_SERVICES_AND_PRIVATE_SOCKET=PASS'
echo 'SHARED_WWW_PRIVATE_CONNECT=DENIED'
echo 'ORIGINAL_0700_PARENT_AND_0666_HTTP_SOCKET=PASS'

echo '========== FAILED TRANSIENT REHEARSAL CLEANUP =========='
load="$(systemctl show "$UNIT" -p LoadState --value 2>/dev/null || true)"
[[ "$load" == not-found ]] || fail "mock_transient_unit_not_collected:$load"
[[ ! -e "$RUNTIME" && ! -L "$RUNTIME" ]] ||
  fail transient_runtime_dir_not_removed
stale="$(find /run -maxdepth 1 -mindepth 1 -name 'bambodia-step12m.*' -print -quit)"
[[ -z "$stale" ]] || fail stale_root_private_synthetic_test_directory
echo 'FAILED_TRANSIENT_UNIT_AND_RUNTIME=ABSENT_CLEAN'
echo 'SYNTHETIC_TEST_DIRECTORIES=ABSENT_CLEAN'

echo '========== ISOLATED SYNTHETIC DATABASE / WRITE FLAG =========='
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
' "$APP" || fail synthetic_qa_database_or_write_gate_changed

echo '========== FAILED-UNIT JOURNAL CLASSIFICATION (NO JOURNAL TEXT) =========='
# Only classify known failure text; never print records, command arguments,
# transient Python source or any credential. Absence of a journal hit is
# inconclusive, because the one-time unit was already collected.
records="$(journalctl --no-pager --quiet --output=cat \
  -u "$UNIT" --since '2026-11-13 12:00:00' \
  --until '2026-11-14 00:00:00' 2>/dev/null || true)"
case "$records" in
  *PermissionError*|*'Permission denied'*|*'Errno 13'*)
    echo 'TRANSIENT_JOURNAL_PERMISSION_ERROR=OBSERVED' ;;
  *) echo 'TRANSIENT_JOURNAL_PERMISSION_ERROR=NOT_OBSERVED' ;;
esac
case "$records" in
  *'Failed to set up mount namespacing'*|*'Failed to set up mount namespace'*)
    echo 'TRANSIENT_JOURNAL_MOUNT_SETUP_FAILURE=OBSERVED' ;;
  *) echo 'TRANSIENT_JOURNAL_MOUNT_SETUP_FAILURE=NOT_OBSERVED' ;;
esac
case "$records" in
  *'Failed to execute'*|*'Failed at step EXEC'*)
    echo 'TRANSIENT_JOURNAL_EXEC_FAILURE=OBSERVED' ;;
  *) echo 'TRANSIENT_JOURNAL_EXEC_FAILURE=NOT_OBSERVED' ;;
esac
echo 'SYNTHETIC_SOCKET_MODE_WITH_UMASK_077=SOURCE_LEVEL_RISK_NOT_HOST_VERIFIED'
echo 'RECONNECTION_AFTER_SOCKET_REPLACEMENT=NOT_TESTED'
echo 'NO_TRANSIENT_SERVICES_STARTED_OR_STOPPED=YES'
echo 'NO_CONFIG_FILE_OR_PERMISSION_CHANGES=YES'
echo 'NO_CLOUDFLARE_DNS_TLS_OR_PUBLICATION=YES'
echo 'STEP12M_FAILURE_CLEANUP_READONLY_AUDIT=PASS'
