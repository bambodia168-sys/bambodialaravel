#!/usr/bin/env bash
# QA-only dedicated PHP-FPM service: --check performs no permanent changes;
# --apply installs and starts a separate, NOT boot-enabled service.
# Never edits current aaPanel FPM/Nginx, DNS, TLS or production apps.
set -Eeuo pipefail
set +x
umask 077

fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" -eq 1 ]] || fail usage_check_or_apply
MODE="$1"
[[ "$MODE" = --check || "$MODE" = --apply ]] || fail invalid_mode

QA='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
APPUSER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
FPM='/www/server/php/85/sbin/php-fpm'
SERVICE='bambodia-previewqa-fpm.service'
UNIT='/etc/systemd/system/bambodia-previewqa-fpm.service'
CONFDIR='/etc/bambodia-previewqa'
CONF='/etc/bambodia-previewqa/php-fpm.conf'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'

echo '========== STEP 8D — QA SERVICE SAFETY PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail must_run_as_root
for bin in git nginx stat id runuser install systemctl mktemp; do
  command -v "$bin" >/dev/null || fail "missing_command:$bin"
done
[[ -x "$PHP" && -x "$FPM" ]] || fail php85_unavailable
[[ -d "$QA/.git" && ! -L "$QA" &&
  "$(git -C "$QA" rev-parse HEAD)" = '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail original_qa_commit_mismatch
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail original_source_changed
[[ -d "$APP" && ! -L "$APP" && "$(realpath -e "$APP")" = "$APP" ]] || fail recovery_path
[[ -s "$APP/vendor/autoload.php" && -s "$APP/storage/app/installed.lock" ]] || fail incomplete_recovery
[[ -f "$APP/.env" && ! -L "$APP/.env" ]] || fail recovery_env
[[ "$(stat -c '%U:%G:%a' "$APP")" = "$APPUSER:$APPUSER:700" ]] || fail app_mode
[[ "$(stat -c '%U:%G:%a' "$APP/public")" = "$APPUSER:$APPUSER:700" ]] || fail public_mode
[[ "$(stat -c '%U:%G:%a' "$APP/.env")" = "$APPUSER:$APPUSER:600" ]] || fail env_mode
id "$APPUSER" >/dev/null || fail recovery_user_missing
id www >/dev/null || fail shared_web_user_missing
runuser -u "$APPUSER" -- test -r "$APP/public/index.php" || fail dedicated_user_read
if runuser -u www -- test -r "$APP/.env"; then fail shared_user_can_read_env; fi
if runuser -u www -- test -r "$APP/public/index.php"; then fail shared_user_can_read_app; fi
[[ ! -e "$UNIT" && ! -L "$UNIT" ]] || fail dedicated_systemd_unit_exists
[[ ! -e "$CONFDIR" && ! -L "$CONFDIR" ]] || fail dedicated_config_dir_exists
[[ ! -e "$SOCKET" && ! -L "$SOCKET" ]] || fail dedicated_socket_exists
if systemctl is-active --quiet "$SERVICE"; then fail dedicated_service_already_active; fi
[[ ! -e /www/wwwroot/preview-qa.bambodia.com ]] || fail proposed_qa_site_already_exists
[[ ! -e /www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf ]] ||
  fail proposed_nginx_vhost_exists
nginx -t -q >/dev/null 2>&1 || fail current_nginx_config_invalid
"$FPM" -t -y /www/server/php/85/etc/php-fpm.conf >/dev/null 2>&1 ||
  fail current_fpm_config_invalid

runuser -u "$APPUSER" -- "$PHP" -r '
$p=$argv[1];require $p."/vendor/autoload.php";
$a=require $p."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    config("preview_visibility.enabled")!==false ||
    config("database.default")!=="mysql" ||
    config("database.connections.mysql.database")!=="bambodia_previewrestore_20261112" ||
    config("database.connections.mysql.username")!=="pvrs_20261112") exit(2);
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db, CURRENT_USER() AS usr");
if ($db->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($db->usr,"pvrs_20261112@")) exit(2);
foreach (["users"=>2,"marketplace_listings"=>1,"marketplace_jobs"=>1,
          "preview_visibility_actions"=>0,"migrations"=>62] as $t=>$count) {
  if (\Illuminate\Support\Facades\DB::table($t)->count()!==$count) exit(2);
}
echo "QA_RESTORED_DB_IDENTITY=PASS\n";
' "$APP" || fail restored_db_identity

TMP="$(mktemp /root/bambodia-qa-fpm.XXXXXXXX.conf)"
TMP_UNIT="$(mktemp /root/bambodia-qa-fpm.XXXXXXXX.service)"
cleanup(){ rm -f -- "$TMP" "$TMP_UNIT"; }
trap cleanup EXIT

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

cat > "$TMP_UNIT" <<'UNITCONFIG'
[Unit]
Description=Bambodia dedicated isolated preview QA PHP-FPM (private)
After=network.target
ConditionPathExists=/var/lib/bambodia-preview-restore-20261112/storage/app/installed.lock

[Service]
Type=simple
ExecStart=/www/server/php/85/sbin/php-fpm -F -y /etc/bambodia-previewqa/php-fpm.conf
RuntimeDirectory=bambodia-previewqa
RuntimeDirectoryMode=0755
UMask=0077
PrivateTmp=true
ProtectHome=true
ProtectSystem=full
NoNewPrivileges=true
LimitNOFILE=4096
Restart=on-failure
RestartSec=3s
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNITCONFIG

"$FPM" -t -y "$TMP" >/dev/null 2>&1 || fail candidate_php_fpm_config_invalid
if command -v systemd-analyze >/dev/null 2>&1; then
  systemd-analyze verify "$TMP_UNIT" >/dev/null 2>&1 ||
    fail candidate_systemd_unit_invalid
fi
echo 'CANDIDATE_PHP_FPM_CONFIG=PASS'
echo 'RECOVERED_APPLICATION_PRIVATE_ACCESS=PASS'

if [[ "$MODE" = --check ]]; then
  echo 'PERSISTENT_WRITES=NONE'
  echo 'STEP8D_CHECK=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_FPM_APPLY || true)" = 'I_APPROVE_ISOLATED_QA_POOL_ONLY' ]] ||
  fail explicit_operator_authorization_missing

echo '========== INSTALL DEDICATED QA SERVICE ONLY =========='
install -d -o root -g root -m 700 "$CONFDIR"
install -o root -g root -m 600 "$TMP" "$CONF"
install -o root -g root -m 644 "$TMP_UNIT" "$UNIT"
"$FPM" -t -y "$CONF" >/dev/null 2>&1 || fail installed_fpm_config_invalid
systemctl daemon-reload || fail systemd_reload_failed
systemctl start "$SERVICE" || fail dedicated_service_start_failed_do_not_rerun

ready=0
for i in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$SERVICE" && [[ -S "$SOCKET" ]]; then
    ready=1
    break
  fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || fail dedicated_service_not_ready_do_not_rerun
[[ "$(stat -c '%U:%G:%a' "$SOCKET")" = 'www:www:660' ]] ||
  fail wrong_socket_permissions
runuser -u www -- test -w "$SOCKET" || fail nginx_worker_socket_access_denied
if runuser -u www -- test -r "$APP/.env"; then fail privacy_boundary_broken; fi
if systemctl is-enabled --quiet "$SERVICE"; then fail unexpected_boot_enablement; fi

echo '========== STEP 8D RESULT =========='
echo 'DEDICATED_FPM_SERVICE=ACTIVE'
echo 'DEDICATED_FPM_SOCKET=www:www:660'
echo 'FPM_WORKER_USER=bambodia_pvrsqa'
echo 'SHARED_AAPANEL_FPM_RESTART=NO'
echo 'DEDICATED_SERVICE_BOOT_ENABLEMENT=NO'
echo 'NGINX_DNS_TLS_CHANGES=NONE'
echo 'HTTPS_BROWSER_ACCEPTANCE=NOT_TESTED'
echo 'PRODUCTION_DATABASE=NOT_TOUCHED_BY_SCRIPT'
echo 'STEP8D=PASS'
