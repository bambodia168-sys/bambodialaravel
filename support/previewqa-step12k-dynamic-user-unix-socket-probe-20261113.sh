#!/usr/bin/env bash
# Step 12K: disposable systemd mount-namespace probe, never cloudflared.
# --check = offline only. --probe = explicitly authorized transient service.
# No persistent accounts, credentials, DNS, TCP ingress or QA service restarts.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" == 1 ]] || fail expected_check_or_probe
MODE="$1"
[[ "$MODE" == --check || "$MODE" == --probe ]] || fail unsupported_mode

APP='/var/lib/bambodia-preview-restore-20261112'
SOURCE='/var/lib/bambodia-preview-qa-20261112'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
INGRESS='bambodia_pvhttp'
WORKER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
PROBE_UNIT='bambodia-previewqa-socketprobe-20261113.service'
PROBE_DIR='/run/bambodia-previewqa-socketprobe-20261113'
PROBE_SOCK='/run/bambodia-previewqa-socketprobe-20261113/http.sock'
PROBE_ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
TMP=''
PROBE_STARTED=no
cleanup(){
  rc=$?
  if [[ "$PROBE_STARTED" == yes ]]; then
    systemctl stop "$PROBE_UNIT" >/dev/null 2>&1 || true
  fi
  [[ -z "$TMP" ]] || rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo '========== STEP 12K — DISPOSABLE DYNAMIC-USER SOCKET PROBE PREFLIGHT =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for bin in stat id systemctl systemd-run systemd-analyze grep git \
   runuser getent mktemp cmp install readlink; do
  command -v "$bin" >/dev/null || fail "missing_program:$bin"
done
[[ -x /usr/bin/python3 ]] || fail python3_not_available
[[ -x "$PHP" ]] || fail QA_php_not_available
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail QA_source_revision_mismatch
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail QA_source_modified
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail QA_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail QA_env_permissions_changed
[[ -f "$PROBE_ORIGINAL" && ! -L "$PROBE_ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$PROBE_ORIGINAL")" == root:root:600 ]] ||
  fail host_guard_backup_missing
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$INGRESS":640 ]] ||
  fail private_nginx_conf_changed
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail private_nginx_unit_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail QA_FPM_conf_changed
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail QA_HTTP_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail QA_FPM_not_active
for svc in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$svc" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_unit_boot_enabled:$svc"
done
[[ "$(systemctl show bambodia-previewqa-http.service -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail dedicated_http_unit_path_changed
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unexpected_QA_HTTP_dropin
for setting in "User=$INGRESS" "Group=$INGRESS" \
  'RuntimeDirectoryMode=0700' 'RestrictAddressFamilies=AF_UNIX' \
  'ProtectSystem=strict' 'ProtectHome=true' 'NoNewPrivileges=true'; do
  grep -Fxq "$setting" "$HTTP_UNIT" ||
    fail "QA_HTTP_security_setting_changed:$setting"
done
grep -Fxq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$HTTP_CONF" ||
  fail QA_origin_host_guard_changed
grep -Fxq '            return 421;' "$HTTP_CONF" ||
  fail unknown_host_rejection_missing
grep -Fxq "        listen unix:$SOCK;" "$HTTP_CONF" ||
  fail dedicated_HTTP_Unix_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail unexpected_additional_QA_HTTP_listener
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail private_HTTP_directory_broadened
[[ -S "$SOCK" && ! -L "$SOCK" &&
  "$(stat -c '%U:%G' "$SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail private_HTTP_socket_changed
case "$(stat -c '%a' "$SOCK")" in
  600|660|666) ;;
  *) fail unexpected_QA_socket_mode ;;
esac
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail QA_FPM_socket_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_in_private_ingress_group
fi
if runuser -u "$INGRESS" -- test -r "$APP/.env"; then
  fail QA_ingress_can_read_Laravel_secrets
fi
for account in www nobody; do
  if getent passwd "$account" >/dev/null; then
    runuser -u "$account" -- /usr/bin/python3 -c 'import socket' ||
      fail "negative_test_account_cannot_run_python:$account"
    if runuser -u "$account" -- /usr/bin/python3 -c '
import socket,sys
s=socket.socket(socket.AF_UNIX);s.settimeout(2)
try:s.connect(sys.argv[1])
except (PermissionError,FileNotFoundError,OSError):sys.exit(2)
sys.exit(0)
' "$SOCK"; then fail "unauthorized_account_connected:$account"; fi
  fi
done
echo 'QA_ORIGIN_0700_SOCKET_AND_SHARED_WWW_BOUNDARIES=PASS'

[[ "$(systemctl show "$PROBE_UNIT" -p LoadState --value 2>/dev/null || true)" == not-found ]] ||
  fail transient_probe_unit_name_not_available
[[ ! -e "$PROBE_DIR" && ! -L "$PROBE_DIR" ]] ||
  fail disposable_probe_directory_already_exists
[[ -x /usr/bin/systemd-run || -x /bin/systemd-run ]] ||
  fail systemd_run_missing
[[ "$(systemd-analyze --version | head -n 1)" == systemd\ 249* ]] ||
  fail systemd_version_changed_require_review
if command -v cloudflared >/dev/null ||
   getent passwd cloudflared >/dev/null; then
  fail real_connector_already_installed_or_account_present
fi
[[ "$(systemctl show cloudflared.service -p LoadState --value 2>/dev/null || true)" != loaded ]] ||
  fail cloudflared_service_present_requires_new_review

echo '========== TEMPORARY SYSTEMD UNIT PARSER CHECK ONLY =========='
TMP="$(mktemp -d /root/bambodia-step12k.XXXXXXXX)"
chmod 700 "$TMP"
cat > "$TMP/probe.service" <<'SERVICE'
[Unit]
Description=Private Bambodia QA socket accessibility rehearsal (parser only)
[Service]
Type=oneshot
DynamicUser=yes
ExecStart=/usr/bin/python3 -c "print('PARSER_ONLY')"
RuntimeDirectory=bambodia-previewqa-socketprobe-20261113
RuntimeDirectoryMode=0700
BindReadOnlyPaths=/run/bambodia-previewqa-http/http.sock:/run/bambodia-previewqa-socketprobe-20261113/http.sock
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
RestrictAddressFamilies=AF_UNIX
CapabilityBoundingSet=
SERVICE
if ! systemd-analyze verify "$TMP/probe.service" > "$TMP/verify.log" 2>&1; then
  fail transient_dynamic_user_mount_unit_parser_failed
fi
echo 'DYNAMIC_USER_SOCKET_MOUNT_PARSER=PASS'
echo 'TRANSIENT_UNIT=NOT_YET_STARTED'
echo 'ORIGINAL_0700_DIRECTORY_AND_USERS=UNCHANGED'
echo 'NO_CLOUDFLARED_OR_TUNNEL_CREDENTIALS=YES'
echo 'NO_HOST_CONFIG_OR_QA_SERVICE_CHANGES=YES'
if [[ "$MODE" == --check ]]; then
  echo 'STEP12K_DISPOSABLE_CONNECTIVITY_PREFLIGHT=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_SOCKET_PROBE || true)" == 'APPROVE_DISPOSABLE_DYNAMIC_USER_ONLY' ]] ||
  fail explicit_disposable_probe_approval_missing
echo '========== ONE-SHOT DYNAMIC UID, PRIVATE-MOUNT SOCKET PROBE =========='
# This service creates a temporary systemd-run unit and private mount
# namespace, and deletes its RuntimeDirectory upon service completion.
# It does NOT install cloudflared or change origin socket path permissions.
# The test itself sends only an unauthenticated GET /up over the private
# Unix socket. It must NOT use any public TCP network.
PROBE_STARTED=yes
OUTPUT=''
if ! OUTPUT="$(systemd-run --system --quiet --wait --collect --pipe \
  --unit="$PROBE_UNIT" --service-type=exec \
  -p 'DynamicUser=yes' \
  -p 'RuntimeDirectory=bambodia-previewqa-socketprobe-20261113' \
  -p 'RuntimeDirectoryMode=0700' \
  -p "BindReadOnlyPaths=$SOCK:$PROBE_SOCK" \
  -p 'RestrictAddressFamilies=AF_UNIX' \
  -p 'NoNewPrivileges=yes' -p 'PrivateTmp=yes' \
  -p 'ProtectSystem=strict' -p 'ProtectHome=yes' \
  -p 'CapabilityBoundingSet=' -p 'Restart=no' \
  /usr/bin/python3 -c '
import os,socket,sys
target="/run/bambodia-previewqa-socketprobe-20261113/http.sock"
source="/run/bambodia-previewqa-http/http.sock"
app="/var/lib/bambodia-preview-restore-20261112/.env"
fpm="/run/bambodia-previewqa/php-fpm.sock"
if os.geteuid()==0:sys.exit("BLOCKED_PROBE_ROOT_UID")
if os.access(app,os.R_OK):sys.exit("BLOCKED_PROBE_SECRET_READ")
for private_path in (source,fpm):
  t=socket.socket(socket.AF_UNIX);t.settimeout(2)
  try:t.connect(private_path)
  except (PermissionError,FileNotFoundError,OSError):pass
  else:sys.exit("BLOCKED_PROBE_UNAUTHORIZED_ORIGIN_OR_FPM")
  finally:t.close()
s=socket.socket(socket.AF_UNIX);s.settimeout(7)
try:
  s.connect(target)
  s.sendall(b"GET /up HTTP/1.1\r\nHost: preview-qa.bambodia.com\r\nConnection: close\r\n\r\n")
  status=s.recv(128).split(b"\r\n",1)[0]
  if status not in (b"HTTP/1.1 200 OK",b"HTTP/1.0 200 OK"):
    sys.exit("BLOCKED_PROBE_HTTP_STATUS")
finally:s.close()
print("TRANSIENT_DYNAMIC_UID_NONROOT=PASS")
print("TRANSIENT_LARAVEL_ENV_AND_FPM_ORIGINAL_SOCKET=DENIED")
print("TRANSIENT_MOUNTED_QA_HTTP_SOCKET_GET_UP=HTTP_200")
' 2>&1)"; then
  printf '%s\n' "$OUTPUT" | tail -n 12 >&2
  fail transient_socket_rehearsal_did_not_pass
fi
printf '%s\n' "$OUTPUT" | grep -Fxq 'TRANSIENT_DYNAMIC_UID_NONROOT=PASS' ||
  fail missing_nonroot_proof
printf '%s\n' "$OUTPUT" | grep -Fxq 'TRANSIENT_LARAVEL_ENV_AND_FPM_ORIGINAL_SOCKET=DENIED' ||
  fail missing_secret_and_FPM_isolation_proof
printf '%s\n' "$OUTPUT" | grep -Fxq 'TRANSIENT_MOUNTED_QA_HTTP_SOCKET_GET_UP=HTTP_200' ||
  fail missing_bound_socket_get_200
printf '%s\n' "$OUTPUT" | grep -E '^TRANSIENT_'
PROBE_STARTED=no

[[ ! -e "$PROBE_DIR" && ! -L "$PROBE_DIR" ]] ||
  fail transient_runtime_directory_not_cleaned
[[ "$(systemctl show "$PROBE_UNIT" -p LoadState --value 2>/dev/null || true)" == not-found ]] ||
  fail transient_unit_not_collected
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail private_QA_HTTP_service_changed_during_probe
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail private_QA_FPM_service_changed_during_probe
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail origin_parent_changed_during_probe
for svc in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$svc" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_enabled_during_probe:$svc"
done
echo 'TRANSIENT_UNIT_AND_RUNTIME_DIRECTORY=COLLECTED'
echo 'PERMANENT_LINUX_USERS_GROUPS_AND_QA_FILES=UNCHANGED'
echo 'PRODUCTION_AND_SHARED_SERVICES=NOT_RESTARTED'
echo 'PUBLIC_DNS_TLS_CLOUDFLARE=NOT_CONFIGURED'
echo 'STEP12K_DYNAMIC_USER_SOCKET_REHEARSAL=PASS'
