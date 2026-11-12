#!/usr/bin/env bash
# Step 11: eliminate shared "www" FastCGI access to the standalone restored QA worker.
# --check does not persist changes. --apply changes ONLY the dedicated QA FPM
# config/socket, leaves production, Nginx and the localhost Laravel test server alone.
set -Eeuo pipefail
set +x
umask 077

fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

[[ "$#" -eq 1 ]] || fail usage_check_or_apply
MODE="$1"
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail invalid_mode

APP='/var/lib/bambodia-preview-restore-20261112'
SOURCE='/var/lib/bambodia-preview-qa-20261112'
QAUSER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
FPM='/www/server/php/85/sbin/php-fpm'
SERVICE='bambodia-previewqa-fpm.service'
UNIT='/etc/systemd/system/bambodia-previewqa-fpm.service'
CONF='/etc/bambodia-previewqa/php-fpm.conf'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'
LOG='/var/log/bambodia-previewqa/php-fpm.log'
BACKUP_DIR='/root/Bambodia-PreviewQA-PrivateSocket-20261112'
EXPECTED=''
NEXT=''
APPLY_STARTED=no
READY=no
cleanup() {
  rc=$?
  [[ -z "$EXPECTED" ]] || rm -f -- "$EXPECTED"
  [[ -z "$NEXT" ]] || rm -f -- "$NEXT"
  if [[ "$APPLY_STARTED" == yes && "$READY" != yes ]]; then
    systemctl stop "$SERVICE" >/dev/null 2>&1 || true
    echo 'QA_FPM=STOPPED_ON_FAILED_APPLY_REQUIRES_REVIEW' >&2
  fi
  exit "$rc"
}
trap cleanup EXIT

echo '========== STEP 11 — SHARED-WWW FASTCGI ACCESS REMOVAL =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for bin in git stat runuser systemctl nginx install mktemp sed cmp grep; do
  command -v "$bin" >/dev/null || fail "missing_command:$bin"
done
[[ -x "$PHP" && -x "$FPM" ]] || fail php85_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" ]] || fail qa_source_missing
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail qa_source_commit_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail original_qa_source_modified
[[ -d "$APP" && ! -L "$APP" && "$(realpath -e "$APP")" == "$APP" ]] || fail recovered_app_missing
[[ "$(stat -c '%U:%G:%a' "$APP")" == "$QAUSER:$QAUSER:700" ]] ||
  fail qa_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$QAUSER:$QAUSER:600" ]] ||
  fail qa_environment_permissions_changed
[[ -s "$APP/storage/app/installed.lock" ]] || fail recovered_lock_missing
[[ -f "$UNIT" && ! -L "$UNIT" &&
  "$(stat -c '%U:%G:%a' "$UNIT")" == 'root:root:644' ]] ||
  fail unexpected_dedicated_systemd_unit
[[ -f "$CONF" && ! -L "$CONF" &&
  "$(stat -c '%U:%G:%a' "$CONF")" == 'root:root:600' ]] ||
  fail unexpected_dedicated_fpm_config
[[ -f "$LOG" && ! -L "$LOG" &&
  "$(stat -c '%U:%G:%a' "$LOG")" == 'root:root:600' ]] ||
  fail qa_private_log_missing_or_unsafe
[[ -S "$SOCKET" && ! -L "$SOCKET" &&
  "$(stat -c '%U:%G:%a' "$SOCKET")" == 'www:www:660' ]] ||
  fail existing_socket_not_shared_expected_state
[[ ! -e "$BACKUP_DIR" && ! -L "$BACKUP_DIR" ]] ||
  fail private_backup_already_exists_do_not_rerun
[[ ! -e /www/wwwroot/preview-qa.bambodia.com &&
  ! -L /www/wwwroot/preview-qa.bambodia.com ]] ||
  fail public_qa_root_present
[[ ! -e /www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf &&
  ! -L /www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf ]] ||
  fail qa_public_nginx_vhost_exists
[[ "$(systemctl is-enabled "$SERVICE" 2>/dev/null || true)" == 'disabled' ]] ||
  fail dedicated_service_boot_state_changed
systemctl is-active --quiet "$SERVICE" || fail dedicated_service_inactive
runuser -u www -- test -w "$SOCKET" || fail original_shared_www_socket_access_not_present
if runuser -u www -- test -r "$APP/.env"; then fail shared_www_can_read_qa_env; fi
if runuser -u www -- test -r "$APP/public/index.php"; then fail shared_www_can_read_qa_php; fi
nginx -t -q >/dev/null 2>&1 || fail preexisting_nginx_invalid

# Check every known aaPanel Nginx site config, to avoid disabling a real endpoint.
if grep -RqsF -- "$SOCKET" /www/server/panel/vhost/nginx /www/server/nginx/conf 2>/dev/null; then
  fail existing_nginx_configuration_uses_dedicated_qa_socket
fi

# Read-only environment identity prevents touching any shared/prod Laravel database.
runuser -u "$QAUSER" -- "$PHP" -r '
$p=$argv[1]; require $p."/vendor/autoload.php";
$a=require $p."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db, CURRENT_USER() AS usr");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    $db->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($db->usr,"pvrs_20261112@") ||
    \App\Models\User::query()->count()!==6) exit(2);
echo "QA_RESTORED_DB_ONLY=PASS\n";
' "$APP" || fail isolated_database_identity_mismatch

EXPECTED="$(mktemp /root/bambodia-qa-oldfpm.XXXXXXXX.conf)"
NEXT="$(mktemp /root/bambodia-qa-newfpm.XXXXXXXX.conf)"
cat > "$EXPECTED" <<'FPMCONF'
[global]
error_log = /var/log/bambodia-previewqa/php-fpm.log
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
FPMCONF
cmp -s "$EXPECTED" "$CONF" || fail current_fpm_config_differs_from_verified_install
sed -e 's/^listen.owner = www$/listen.owner = bambodia_pvrsqa/' \
    -e 's/^listen.group = www$/listen.group = bambodia_pvrsqa/' \
    -e 's/^listen.mode = 0660$/listen.mode = 0600/' \
    "$EXPECTED" > "$NEXT"
[[ "$(grep -Fc 'listen.owner = bambodia_pvrsqa' "$NEXT")" -eq 1 ]] ||
  fail intended_socket_user_not_unique
[[ "$(grep -Fc 'listen.group = bambodia_pvrsqa' "$NEXT")" -eq 1 ]] ||
  fail intended_socket_group_not_unique
[[ "$(grep -Fc 'listen.mode = 0600' "$NEXT")" -eq 1 ]] ||
  fail intended_socket_mode_not_unique
"$FPM" -t -y "$NEXT" >/dev/null 2>&1 || fail candidate_php_fpm_syntax

echo 'CURRENT_SOCKET_SHARED_WWW=CONFIRMED'
echo 'TARGET_SOCKET=bambodia_pvrsqa:bambodia_pvrsqa:600'
echo 'NEXT_CONFIG_SYNTAX=PASS'
echo 'QA_WITHDRAWAL_WRITES=DISABLED'

if [[ "$MODE" == --check ]]; then
  echo 'PERSISTENT_CHANGES=NONE'
  echo 'STEP11_CHECK=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_SOCKET_HARDEN || true)" == 'I_APPROVE_QA_SOCKET_PRIVATE_ONLY' ]] ||
  fail explicit_operator_approval_required

APPLY_STARTED=yes
echo '========== STOP DEDICATED QA FPM ONLY =========='
systemctl stop "$SERVICE" || fail qa_fpm_stop_failed
install -d -o root -g root -m 700 "$BACKUP_DIR"
install -o root -g root -m 600 "$CONF" "$BACKUP_DIR/php-fpm.conf"
cmp -s "$BACKUP_DIR/php-fpm.conf" "$EXPECTED" || fail private_backup_mismatch
install -o root -g root -m 600 "$NEXT" "$CONF"
cmp -s "$NEXT" "$CONF" || fail replacement_config_mismatch
"$FPM" -t -y "$CONF" >/dev/null 2>&1 || fail installed_config_invalid

# Stopping a disabled systemd service can unload its unit; reload definitions.
systemctl daemon-reload || fail dedicated_systemd_reload_failed
[[ "$(systemctl show "$SERVICE" -p LoadState --value)" == loaded ]] ||
  fail qa_unit_not_loadable
echo '========== RESTART DEDICATED QA FPM ONLY =========='
systemctl start "$SERVICE" || fail dedicated_fpm_start_failed_do_not_rerun
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$SERVICE" &&
      [[ -S "$SOCKET" ]] &&
      [[ "$(stat -c '%U:%G:%a' "$SOCKET")" == "$QAUSER:$QAUSER:600" ]]; then
    READY=yes
    break
  fi
  sleep 1
done
[[ "$READY" == yes ]] || fail private_fpm_socket_not_ready_do_not_rerun
if runuser -u www -- test -w "$SOCKET"; then
  READY=no
  fail shared_www_still_has_socket_write_permissions
fi
# Verify actual Unix-socket connect, not only stat(2) permissions.
runuser -u www -- "$PHP" -r '
$fp=@stream_socket_client("unix://".$argv[1],$e,$msg,2);
if ($fp) { fclose($fp); exit(2); }
' "$SOCKET" || { READY=no; fail shared_www_socket_connect_succeeded; }
runuser -u "$QAUSER" -- "$PHP" -r '
$fp=@stream_socket_client("unix://".$argv[1],$e,$msg,2);
if (!$fp) exit(2);
fclose($fp);
' "$SOCKET" || { READY=no; fail dedicated_worker_socket_connect_failed; }
[[ "$(systemctl is-enabled "$SERVICE" 2>/dev/null || true)" == disabled ]] ||
  { READY=no; fail qa_service_enabled_at_boot; }

echo '========== STEP 11 PRIVATE SOCKET ACCEPTANCE =========='
echo 'DEDICATED_QA_FPM=ACTIVE'
echo 'PRIVATE_SOCKET=bambodia_pvrsqa:bambodia_pvrsqa:600'
echo 'SHARED_WWW_FASTCGI_CONNECT=DENIED'
echo 'QA_WORKER_FASTCGI_CONNECT=PASS'
echo 'QA_FPM_ENABLED_AT_BOOT=NO'
echo 'PRIVATE_QA_CONFIG_BACKUP=CREATED'
echo 'LOCALHOST_ARTISAN_BROWSER_TESTING=UNCHANGED'
echo 'NGINX_SHARED_FPM_PRODUCTION_DNS_TLS=UNCHANGED'
echo 'STEP11=PASS'
