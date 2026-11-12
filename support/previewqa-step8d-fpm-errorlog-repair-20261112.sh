#!/usr/bin/env bash
# Step 8D targeted QA-only recovery: replace unopenable FPM stderr log with
# root-private disk log; stop flapping ONLY the dedicated Bambodia QA unit.
# --check is read-only. --apply is one-time and explicitly authorized.
set -Eeuo pipefail
set +x
umask 077

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

[[ "$#" -eq 1 && ( "$1" = --check || "$1" = --apply ) ]] || fail usage_check_or_apply
MODE="$1"
SVC='bambodia-previewqa-fpm.service'
UNIT="/etc/systemd/system/$SVC"
CONF='/etc/bambodia-previewqa/php-fpm.conf'
FPM='/www/server/php/85/sbin/php-fpm'
APP='/var/lib/bambodia-preview-restore-20261112'
USER='bambodia_pvrsqa'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'
LOGDIR='/var/log/bambodia-previewqa'
LOG="$LOGDIR/php-fpm.log"
BACKUP='/root/bambodia-previewqa-fpm.conf.pre-errorlog-fix-20261112'
TMP="$(mktemp /root/bambodia-previewqa-fpm-expected.XXXXXXXX)"
NEXT="$(mktemp /root/bambodia-previewqa-fpm-next.XXXXXXXX)"
APPLY_STARTED=0
READY=0

cleanup(){
  rc=$?
  rm -f -- "$TMP" "$NEXT"
  if [[ "$APPLY_STARTED" -eq 1 && "$READY" -eq 0 ]]; then
    systemctl stop "$SVC" >/dev/null 2>&1 || true
    echo 'DEDICATED_QA_SERVICE_STOPPED_AFTER_FAILURE=YES' >&2
  fi
  exit "$rc"
}
trap cleanup EXIT

echo '========== STEP 8D ERROR-LOG REPAIR PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for cmd in systemctl stat cmp sed install grep mktemp runuser; do
  command -v "$cmd" >/dev/null || fail "missing_command:$cmd"
done
[[ -x "$FPM" ]] || fail php85_fpm_binary_missing
[[ -f "$UNIT" && ! -L "$UNIT" &&
   "$(stat -c '%U:%G:%a' "$UNIT")" = 'root:root:644' ]] || fail unexpected_dedicated_unit
[[ -f "$CONF" && ! -L "$CONF" &&
   "$(stat -c '%U:%G:%a' "$CONF")" = 'root:root:600' ]] || fail unexpected_dedicated_config
[[ -d "$APP" && ! -L "$APP" && -s "$APP/storage/app/installed.lock" ]] || fail recovered_app_missing
[[ "$(stat -c '%U:%G:%a' "$APP")" = "$USER:$USER:700" ]] || fail recovered_app_permissions
[[ ! -e "$BACKUP" && ! -L "$BACKUP" ]] || fail recovery_backup_already_exists_do_not_rerun
[[ ! -e "$LOGDIR" && ! -L "$LOGDIR" ]] || fail qa_log_directory_already_exists_do_not_rerun
[[ ! -S "$SOCKET" ]] || fail dedicated_socket_already_exists_no_repair
# systemd may unload an inactive, disabled unit after a stop; the on-disk
# unit is already verified above. If systemd reports a fragment path, it
# must match the dedicated QA unit; absence is permitted at preflight.
REPORTED_UNIT="$(systemctl show "$SVC" -p FragmentPath --value 2>/dev/null || true)"
[[ -z "$REPORTED_UNIT" || "$REPORTED_UNIT" = "$UNIT" ]] || fail unexpected_service_unit
ENABLED="$(systemctl is-enabled "$SVC" 2>/dev/null || true)"
[[ "$ENABLED" = disabled ]] || fail dedicated_service_boot_status_changed
grep -Fxq 'ExecStart=/www/server/php/85/sbin/php-fpm -F -y /etc/bambodia-previewqa/php-fpm.conf' "$UNIT" ||
  fail unexpected_php_fpm_execstart
grep -Fxq 'RuntimeDirectory=bambodia-previewqa' "$UNIT" || fail unexpected_runtime_directory
grep -Fxq 'Restart=on-failure' "$UNIT" || fail unexpected_restart_policy

cat > "$TMP" <<'FPMCONFIG'
[global]
error_log = /proc/self/fd/2
log_level = notice
daemonize = no

[previewqa]
user = bambodia_pvrsqa
group = bambodia_pvrsqa
listen = /run/bambodia-previewqa/php-fpm.sock
listen.owner = www
listen.group = www
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
pm.max_requests = 100
chdir = /var/lib/bambodia-preview-restore-20261112
clear_env = yes
catch_workers_output = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
FPMCONFIG

cmp -s "$TMP" "$CONF" || fail installed_fpm_config_differs_from_pinned_source
sed 's#^error_log = /proc/self/fd/2$#error_log = /var/log/bambodia-previewqa/php-fpm.log#' "$TMP" > "$NEXT"
[[ "$(grep -Fc 'error_log = /var/log/bambodia-previewqa/php-fpm.log' "$NEXT")" = 1 ]] ||
  fail corrected_error_log_not_unique
echo 'RECOVERY_SCOPE=ONE_DEDICATED_QA_FPM_CONFIG'
echo 'ORIGINAL_CONFIG_EXACT_MATCH=PASS'
echo 'SYSTEMD_UNIT_UNLOADED_AT_PREFLIGHT=PERMITTED'
echo 'FIX=ROOT_PRIVATE_ERROR_LOG_FILE'
echo 'SHARED_PHP_FPM_UNIT=UNTOUCHED'

if [[ "$MODE" = --check ]]; then
  echo 'PERSISTENT_CHANGES=NONE'
  echo 'STEP8D_ERRORLOG_CHECK=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_FPM_REPAIR || true)" = 'APPROVE_QA_LOG_FIX_ONLY' ]] ||
  fail explicit_operator_authorization_missing

echo '========== STOP DEDICATED RESTART LOOP ONLY =========='
APPLY_STARTED=1
systemctl stop "$SVC" || fail dedicated_service_stop_failed
# systemctl stop may unload this disabled QA unit; reset-failed then reports
# "Unit not loaded" even though the on-disk service configuration is valid.
# Re-read only systemd's unit definitions. No shared service is restarted.
systemctl daemon-reload || fail dedicated_systemd_daemon_reload_failed
LOADED="$(systemctl show "$SVC" -p LoadState --value 2>/dev/null || true)"
[[ "$LOADED" = loaded ]] || fail dedicated_qa_unit_not_loadable
if systemctl reset-failed "$SVC" >/dev/null 2>&1; then
  echo 'DEDICATED_QA_FAILURE_COUNTER=RESET'
else
  echo 'DEDICATED_QA_FAILURE_COUNTER=NOT_APPLICABLE_UNIT_WAS_UNLOADED'
fi
[[ "$(systemctl is-active "$SVC" 2>/dev/null || true)" != active ]] ||
  fail dedicated_service_still_active

echo '========== INSTALL ROOT-PRIVATE QA ERROR LOG =========='
install -d -o root -g root -m 700 "$LOGDIR"
install -o root -g root -m 600 /dev/null "$LOG"
install -o root -g root -m 600 "$CONF" "$BACKUP"
cmp -s "$BACKUP" "$TMP" || fail backup_integrity_mismatch
install -o root -g root -m 600 "$NEXT" "$CONF"
cmp -s "$NEXT" "$CONF" || fail installed_config_mismatch
[[ "$(stat -c '%U:%G:%a' "$LOG")" = root:root:600 ]] || fail log_permissions
[[ "$(stat -c '%U:%G:%a' "$LOGDIR")" = root:root:700 ]] || fail logdir_permissions
"$FPM" -t -y "$CONF" >/dev/null 2>&1 || fail corrected_php_fpm_syntax

echo '========== START ONLY DEDICATED QA FPM =========='
systemctl start "$SVC" || fail dedicated_service_start_failed
UP=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$SVC" && [[ -S "$SOCKET" ]]; then
    UP=1
    break
  fi
  sleep 1
done
[[ "$UP" = 1 ]] || fail qa_service_not_ready_inspect_private_log
[[ "$(stat -c '%U:%G:%a' "$SOCKET")" = www:www:660 ]] ||
  fail dedicated_fpm_socket_permissions
runuser -u www -- test -w "$SOCKET" || fail nginx_user_socket_access_denied
if runuser -u www -- test -r "$APP/.env"; then fail shared_user_can_read_qa_env; fi
if runuser -u www -- test -r "$APP/public/index.php"; then fail shared_user_can_read_private_qa; fi
[[ "$(systemctl is-enabled "$SVC" 2>/dev/null || true)" = disabled ]] ||
  fail qa_service_unexpectedly_enabled
READY=1

echo '========== STEP 8D REPAIR RESULT =========='
echo 'QA_PHP_FPM=ACTIVE'
echo 'QA_SOCKET=www:www:660'
echo 'QA_FPM_WORKER_CONFIG=bambodia_pvrsqa'
echo 'QA_PRIVATE_LOG=root:root:600'
echo 'SHARED_PHP_FPM_RESTARTED=NO'
echo 'NGINX_DNS_TLS_CHANGED=NO'
echo 'QA_SERVICE_ENABLED_AT_BOOT=NO'
echo 'HTTPS_BROWSER_ACCEPTANCE=NOT_TESTED'
echo 'STEP8D_ERRORLOG_RECOVERY=PASS'
