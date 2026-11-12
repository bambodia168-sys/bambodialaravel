#!/usr/bin/env bash
# Step 8F: read-only pre-publication audit of recovered Bambodia QA app,
# dedicated FastCGI socket, and frontend assets. Does NOT provision HTTPS.
set -Eeuo pipefail
set +x
umask 077

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

QA='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
WORKER='bambodia_pvrsqa'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'
SERVICE='bambodia-previewqa-fpm.service'
FPMCONF='/etc/bambodia-previewqa/php-fpm.conf'
PUBROOT='/www/wwwroot/preview-qa.bambodia.com'
VHOST='/www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf'
SSLROOT='/www/server/panel/vhost/ssl/preview-qa.bambodia.com'
HOST='preview-qa.bambodia.com'

echo '========== STEP 8F — READ-ONLY SECURITY AND ASSET REVIEW =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for bin in git stat find grep awk wc ss nginx systemctl runuser; do
  command -v "$bin" >/dev/null || fail "missing_command:$bin"
done
[[ -d "$QA/.git" && ! -L "$QA" ]] || fail original_qa_missing
[[ "$(git -C "$QA" rev-parse HEAD)" = '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail original_qa_commit_changed
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail original_qa_source_changed
[[ -d "$APP" && ! -L "$APP" && "$(realpath -e "$APP")" = "$APP" ]] ||
  fail recovered_app_path
[[ "$(stat -c '%U:%G:%a' "$APP")" = "$WORKER:$WORKER:700" ]] ||
  fail recovered_directory_no_longer_private
[[ "$(stat -c '%U:%G:%a' "$APP/public")" = "$WORKER:$WORKER:700" ]] ||
  fail recovered_public_dir_no_longer_private
[[ -s "$APP/public/index.php" && -s "$APP/public/build/manifest.json" ]] ||
  fail frontend_build_or_index_missing
[[ -s "$APP/storage/app/installed.lock" ]] || fail recovered_install_lock_missing
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" = "$WORKER:$WORKER:600" ]] ||
  fail recovered_env_private_mode
[[ -x /www/server/php/85/bin/php ]] || fail php85_cli_missing
runuser -u "$WORKER" -- /www/server/php/85/bin/php -r '
$p=$argv[1]; require $p."/vendor/autoload.php";
$a=require $p."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    config("database.default")!=="mysql" ||
    config("database.connections.mysql.database")!=="bambodia_previewrestore_20261112" ||
    config("database.connections.mysql.username")!=="pvrs_20261112") exit(2);
echo "RESTORED_QA_CONFIG=SAFE_AND_DEFAULT_OFF\n";
' "$APP" || fail recovered_qa_configuration_changed
[[ -f "$FPMCONF" && ! -L "$FPMCONF" ]] || fail dedicated_fpm_config_missing
grep -Fxq 'user = bambodia_pvrsqa' "$FPMCONF" ||
  fail fpm_worker_identity_changed
grep -Fxq 'listen = /run/bambodia-previewqa/php-fpm.sock' "$FPMCONF" ||
  fail dedicated_socket_changed
[[ -S "$SOCKET" && ! -L "$SOCKET" ]] || fail dedicated_socket_unavailable
systemctl is-active --quiet "$SERVICE" || fail dedicated_fpm_inactive
[[ "$(systemctl is-enabled "$SERVICE" 2>/dev/null || true)" = disabled ]] ||
  fail dedicated_fpm_unexpectedly_enabled
nginx -t -q >/dev/null 2>&1 || fail preexisting_nginx_invalid

echo '========== PRIVATE APPLICATION BOUNDARY =========='
if runuser -u www -- test -r "$APP/.env"; then
  fail shared_www_can_read_recovered_env
else
  echo 'SHARED_WWW_READ_ENV=DENIED_EXPECTED'
fi
if runuser -u www -- test -r "$APP/public/index.php"; then
  fail shared_www_can_read_recovered_php
else
  echo 'SHARED_WWW_READ_RECOVERED_INDEX=DENIED_EXPECTED'
fi
if runuser -u "$WORKER" -- test -r "$APP/public/index.php"; then
  echo 'DEDICATED_WORKER_READ_INDEX=PASS'
else
  fail dedicated_worker_cannot_read_index
fi

echo '========== FASTCGI CAPABILITY REVIEW =========='
stat -c 'DEDICATED_SOCKET_OWNER=%U:%G MODE=%a' "$SOCKET"
if runuser -u www -- test -w "$SOCKET"; then
  echo 'SHARED_WWW_CAN_CONNECT_FASTCGI=YES_ACCESS_BOUNDARY_REVIEW_REQUIRED'
else
  echo 'SHARED_WWW_CAN_CONNECT_FASTCGI=NO'
fi
echo 'MULTI_TENANT_SOCKET_ISOLATION=NOT_PROVEN'
echo 'PUBLICATION_AUTHORIZATION=NOT_GRANTED'

echo '========== STATIC FRONTEND INVENTORY =========='
ASSETS="$(find "$APP/public/build" -type f | wc -l)"
SYMLINKS="$(find "$APP/public" -type l | wc -l)"
SCRIPTS="$(find "$APP/public" -type f -name '*.php' | wc -l)"
[[ "$ASSETS" -gt 0 ]] || fail build_asset_inventory_empty
printf 'BUILT_ASSET_FILES=%s\n' "$ASSETS"
printf 'PUBLIC_TREE_SYMLINK_COUNT=%s\n' "$SYMLINKS"
printf 'PUBLIC_TREE_PHP_FILE_COUNT=%s\n' "$SCRIPTS"
if [[ "$SYMLINKS" -gt 0 ]]; then
  echo 'STATIC_COPY_REQUIRES_SYMLINK_REVIEW=YES'
else
  echo 'STATIC_COPY_REQUIRES_SYMLINK_REVIEW=NO'
fi
echo 'STATIC_PUBLIC_DIRECTORY=NOT_CREATED_BY_SCRIPT'

echo '========== AA PANEL SITE COLLISIONS =========='
for target in "$PUBROOT" "$VHOST" "$SSLROOT"; do
  if [[ -e "$target" || -L "$target" ]]; then
    echo "QA_SITE_TARGET=OCCUPIED PATH=$target"
  else
    echo "QA_SITE_TARGET=FREE PATH=$target"
  fi
done
if command -v dig >/dev/null 2>&1; then
  for rr in A AAAA CNAME; do
    answer="$(dig +time=2 +tries=1 +short "$HOST" "$rr" || true)"
    if [[ -n "$answer" ]]; then
      echo "QA_DNS_$rr=RESULTS_PRESENT"
    else
      echo "QA_DNS_$rr=NOT_RESOLVED_HERE"
    fi
  done
else
  echo 'QA_DNS=NOT_CHECKED_DIG_UNAVAILABLE'
fi

echo '========== STEP 8F SUMMARY =========='
echo 'PERSISTENT_WRITES=NONE'
echo 'FPM_NGINX_RESTARTS=NONE'
echo 'DNS_TLS_CHANGES=NONE'
echo 'PRODUCTION_DATABASE=NOT_TOUCHED'
echo 'QA_PUBLIC_HTTPS_HOST=NOT_CONFIGURED'
echo 'REAL_BROWSER_DEVICE_ACCEPTANCE=NOT_TESTED'
echo 'STEP8F_READONLY_AUDIT=COMPLETE'
