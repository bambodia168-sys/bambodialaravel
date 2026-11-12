#!/usr/bin/env bash
# Step 12: read-only inspection of protected QA HTTPS ingress readiness.
# Never creates DNS, Cloudflare Tunnel/Access, Nginx vhost, or services.
set -Eeuo pipefail
set +x
umask 077
fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

APP='/var/lib/bambodia-preview-restore-20261112'
QA='/var/lib/bambodia-preview-qa-20261112'
ASSETS='/var/lib/bambodia-previewqa-static-20261112'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'
CONF='/etc/bambodia-previewqa/php-fpm.conf'
SVC='bambodia-previewqa-fpm.service'
QAUSER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
HOSTNAME='preview-qa.bambodia.com'

echo '========== STEP 12 — READ-ONLY PROTECTED INGRESS DISCOVERY =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for command_name in git stat runuser systemctl nginx ss grep sha256sum; do
  command -v "$command_name" >/dev/null || fail "missing_binary:$command_name"
done
[[ -x "$PHP" && -d "$QA/.git" && ! -L "$QA" ]] || fail original_qa_missing
[[ "$(git -C "$QA" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] || fail pinned_commit_changed
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail original_source_modified
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$QAUSER:$QAUSER:700" ]] || fail private_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$QAUSER:$QAUSER:600" ]] || fail private_env_permissions_changed
[[ -f "$CONF" && ! -L "$CONF" ]] || fail fpm_config_missing
[[ -S "$SOCKET" && ! -L "$SOCKET" &&
  "$(stat -c '%U:%G:%a' "$SOCKET")" == "$QAUSER:$QAUSER:600" ]] || fail private_socket_missing
for directive in "listen = $SOCKET" "listen.owner = $QAUSER" \
                 "listen.group = $QAUSER" 'listen.mode = 0600'; do
  grep -Fxq "$directive" "$CONF" || fail "fpm_config_changed:$directive"
done
systemctl is-active --quiet "$SVC" || fail dedicated_fpm_inactive
[[ "$(systemctl is-enabled "$SVC" 2>/dev/null || true)" == disabled ]] || fail qa_service_boot_status_changed
if runuser -u www -- test -w "$SOCKET"; then fail shared_www_still_has_socket_access; fi
runuser -u "$QAUSER" -- test -w "$SOCKET" || fail qa_worker_socket_denied
nginx -t -q >/dev/null 2>&1 || fail shared_nginx_syntax_invalid

runuser -u "$QAUSER" -- "$PHP" -r '
$r=$argv[1];require $r."/vendor/autoload.php";
$a=require $r."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db, CURRENT_USER() AS usr");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    $db->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($db->usr,"pvrs_20261112@") ||
    \App\Models\User::count()!==6) exit(2);
echo "RECOVERED_QA_IDENTITY=PASS_DEFAULT_OFF\n";
' "$APP" || fail recovered_qa_db_changed

echo '========== PRIVATE STATIC ASSET INTEGRITY =========='
[[ -d "$ASSETS" && ! -L "$ASSETS" &&
  "$(stat -c '%U:%G:%a' "$ASSETS")" == root:root:700 ]] || fail private_static_stage_changed
[[ -s "$ASSETS/build.sha256" && ! -L "$ASSETS/build.sha256" ]] || fail asset_manifest_missing
(cd "$ASSETS/build" && sha256sum --check --status "$ASSETS/build.sha256") ||
  fail static_assets_checksum
echo 'PRIVATE_STATIC_ASSET_SHA256=PASS'

echo '========== LOCAL SERVICE DISCOVERY =========='
if command -v cloudflared >/dev/null 2>&1; then
  echo 'CLOUDFLARED_BINARY=INSTALLED'
  cloudflared --version 2>/dev/null | head -n 1 || true
else
  echo 'CLOUDFLARED_BINARY=NOT_INSTALLED_OR_NOT_IN_PATH'
fi
for service_name in cloudflared.service bambodia-previewqa-http.service; do
  state="$(systemctl is-active "$service_name" 2>/dev/null || true)"
  echo "SERVICE=$service_name STATE=$state"
done
for port in 18097 18098; do
  listeners="$(ss -H -ltn "sport = :$port" || true)"
  if [[ -z "$listeners" ]]; then
    printf 'LISTENER_%s=NONE\n' "$port"
  else
    printf 'LISTENER_%s=PRESENT\n' "$port"
    if [[ "$listeners" == *"127.0.0.1:$port"* &&
          "$listeners" != *"0.0.0.0:$port"* &&
          "$listeners" != *"[::]:$port"* ]]; then
      printf 'LISTENER_%s_LOOPBACK_ONLY=YES\n' "$port"
    else
      printf 'LISTENER_%s_LOOPBACK_ONLY=NOT_PROVEN\n' "$port"
    fi
  fi
done

echo '========== HOSTNAME AND VHOST COLLISIONS =========='
for target in \
  '/www/wwwroot/preview-qa.bambodia.com' \
  '/www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf' \
  '/www/server/panel/vhost/ssl/preview-qa.bambodia.com'
do
  if [[ -e "$target" || -L "$target" ]]; then
    echo "SITE_TARGET=EXISTS PATH=$target"
  else
    echo "SITE_TARGET=FREE PATH=$target"
  fi
done
if command -v dig >/dev/null 2>&1; then
  for rr in A AAAA CNAME; do
    result="$(dig +time=2 +tries=1 +short "$HOSTNAME" "$rr" || true)"
    if [[ -n "$result" ]]; then
      echo "CANDIDATE_DNS_$rr=RESULTS_PRESENT"
    else
      echo "CANDIDATE_DNS_$rr=NO_RESULT_HERE"
    fi
  done
else
  echo 'CANDIDATE_DNS_CHECK=UNAVAILABLE'
fi

echo '========== RELEASE BOUNDARIES =========='
echo 'CLOUDFLARE_ACCESS_APPLICATION=NOT_VERIFIED'
echo 'ACCESS_JWT_ORIGIN_VALIDATION=NOT_VERIFIED'
echo 'PUBLIC_QA_TUNNEL_AND_DNS=NOT_CONFIGURED_BY_SCRIPT'
echo 'PERSISTENT_WRITES=NONE'
echo 'FPM_NGINX_RESTARTS=NONE'
echo 'PRODUCTION_OR_QA_DATABASE_WRITES=NONE'
echo 'STEP12_DISCOVERY=COMPLETE'
