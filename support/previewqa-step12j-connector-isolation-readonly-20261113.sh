#!/usr/bin/env bash
# Step 12J — offline connector isolation capability inventory, NOT an installer.
# No connectors, credentials, accounts, groups, services, routes, or DNS changed.
# Writes only root-private temporary dry-run unit for systemd-analyze verify.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
TMP=''
cleanup(){ [[ -z "$TMP" ]] || rm -rf -- "$TMP"; }
trap cleanup EXIT

HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
FPM_UNIT='/etc/systemd/system/bambodia-previewqa-fpm.service'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
APP='/var/lib/bambodia-preview-restore-20261112'
SOCKET='/run/bambodia-previewqa-http/http.sock'
FPM_SOCKET='/run/bambodia-previewqa/php-fpm.sock'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
QA_USER='bambodia_pvhttp'
FPM_USER='bambodia_pvrsqa'

echo '========== STEP 12J — READ-ONLY CONNECTOR ISOLATION CAPABILITY =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for command_name in stat systemctl systemd-analyze grep getent runuser \
  git mktemp install wc id readlink; do
  command -v "$command_name" >/dev/null ||
    fail "required_command_missing:$command_name"
done
[[ -f "$HTTP_UNIT" && ! -L "$HTTP_UNIT" &&
  "$(stat -c '%U:%G:%a' "$HTTP_UNIT")" == root:root:644 ]] ||
  fail private_http_unit_changed
[[ -f "$FPM_UNIT" && ! -L "$FPM_UNIT" ]] ||
  fail dedicated_fpm_unit_missing
[[ -f "$HTTP_CONF" && ! -L "$HTTP_CONF" &&
  "$(stat -c '%U:%G:%a' "$HTTP_CONF")" == root:"$QA_USER":640 ]] ||
  fail private_http_config_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == root:root:600 ]] ||
  fail private_fpm_config_changed
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail private_host_guard_backup_missing
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$FPM_USER:$FPM_USER:700" ]] ||
  fail isolated_qa_app_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$FPM_USER:$FPM_USER:600" ]] ||
  fail isolated_qa_env_permissions_changed
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail private_qa_http_not_active
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail private_qa_fpm_not_active
for svc in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$svc" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_enabled_at_boot:$svc"
done
[[ "$(systemctl show bambodia-previewqa-http.service -p FragmentPath --value)" == "$HTTP_UNIT" ]] ||
  fail unexpected_qa_http_unit_path
[[ -z "$(systemctl show bambodia-previewqa-http.service -p DropInPaths --value)" ]] ||
  fail unexpected_qa_http_unit_dropin
for guard in "User=$QA_USER" "Group=$QA_USER" \
             'RuntimeDirectoryMode=0700' 'RestrictAddressFamilies=AF_UNIX' \
             'ProtectHome=true' 'ProtectSystem=strict' \
             'NoNewPrivileges=true'; do
  grep -Fxq "$guard" "$HTTP_UNIT" ||
    fail "QA_HTTP_security_guard_changed:$guard"
done
grep -Fq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$HTTP_CONF" ||
  fail canonical_origin_host_check_missing
grep -Fxq '            return 421;' "$HTTP_CONF" ||
  fail origin_host_rejection_missing
grep -Fq "        listen unix:$SOCKET;" "$HTTP_CONF" ||
  fail private_unix_origin_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$HTTP_CONF")" == 1 ]] ||
  fail private_qa_unexpected_additional_listener
[[ -S "$SOCKET" && ! -L "$SOCKET" &&
  "$(stat -c '%U:%G' "$SOCKET")" == "$QA_USER:$QA_USER" ]] ||
  fail private_unix_socket_owner_changed
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$QA_USER:$QA_USER:700" ]] ||
  fail private_http_socket_directory_changed
[[ -S "$FPM_SOCKET" && ! -L "$FPM_SOCKET" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCKET")" == "$FPM_USER:$QA_USER:660" ]] ||
  fail QA_FPM_socket_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$QA_USER"; then
  fail shared_www_added_to_private_ingress_group
fi
if runuser -u "$QA_USER" -- test -r "$APP/.env"; then
  fail private_http_user_can_read_qa_env
fi
if runuser -u www -- /www/server/php/85/bin/php -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); } exit(2);
' "$SOCKET"; then fail shared_www_can_connect_private_http; fi
echo 'LOCAL_QA_ORIGIN_LINUX_BOUNDARIES=PASS'
echo 'ROOT_PRIVATE_BACKUP_AND_0700_UNIX_DIRECTORY=PASS'
echo 'SHARED_WWW_UNIX_CONNECT=DENIED'

echo '========== CLOUDFLARED IS STILL ABSENT — FAIL CLOSED =========='
if command -v cloudflared >/dev/null; then
  echo 'CONNECTOR_BINARY_APPEARED_SINCE_STEP12I=YES'
  fail existing_cloudflared_binary_requires_separate_review
fi
[[ "$(systemctl show cloudflared.service -p LoadState --value 2>/dev/null || true)" != loaded ]] ||
  fail preexisting_cloudflared_service_requires_independent_review
if getent passwd cloudflared >/dev/null; then
  fail cloudflared_account_appeared_since_step12I
fi
echo 'CLOUDFLARED_BINARY=ABSENT'
echo 'CLOUDFLARED_SYSTEMD_UNIT=NOT_LOADED'
echo 'CLOUDFLARED_OS_ACCOUNT=ABSENT'
echo 'CONNECTOR_SECRETS_OR_ACCESS_POLICY=NOT_ACCESSED'

echo '========== SYSTEMD/FILESYSTEM FEATURES — READ-ONLY INVENTORY =========='
systemd-analyze --version | head -n 1
echo 'UNIT_NAMESPACE_OPTION_BINDPATHS=SYNTAX_TO_BE_TESTED_NOT_RUNTIME_PROOF'
if command -v findmnt >/dev/null; then
  findmnt -n -o TARGET,FSTYPE --target /run/bambodia-previewqa-http || true
fi
if [[ -e /sys/fs/cgroup/cgroup.controllers ]]; then
  echo 'CGROUP_UNIFIED_V2=DETECTED'
else
  echo 'CGROUP_UNIFIED_V2=NOT_DETECTED'
fi
if [[ -e /sys/module/apparmor/parameters/enabled ]]; then
  echo 'APPARMOR_KERNEL_FLAG=AVAILABLE'
else
  echo 'APPARMOR_KERNEL_FLAG=NOT_PRESENT'
fi
echo 'CROSS_UID_SOCKET_BIND_MOUNT=NOT_ATTEMPTED'
echo 'CROSS_UID_SOCKET_CONNECTIVITY=NOT_PROVEN'

echo '========== EPHEMERAL SYSTEMD PARSER TEST (NO SERVICE LOADED) =========='
TMP="$(mktemp -d /root/bambodia-step12j.XXXXXXXX)"
chmod 700 "$TMP"
cat > "$TMP/bambodia-previewqa-connector-dryrun.service" <<'UNITFILE'
[Unit]
Description=Bambodia QA connector isolated socket parser-only dry run
[Service]
Type=oneshot
User=nobody
ExecStart=/bin/true
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
RuntimeDirectory=bambodia-previewqa-connector-dryrun
RuntimeDirectoryMode=0700
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
CapabilityBoundingSet=
BindReadOnlyPaths=/run/bambodia-previewqa-http/http.sock:/run/bambodia-previewqa-connector-dryrun/http.sock
UNITFILE
if systemd-analyze verify "$TMP/bambodia-previewqa-connector-dryrun.service" \
  > "$TMP/systemd-verify.log" 2>&1; then
  echo 'SYSTEMD_BINDREADONLYPATHS_UNIT_PARSER=PASS'
else
  echo 'SYSTEMD_BINDREADONLYPATHS_UNIT_PARSER=NOT_VERIFIED'
  # Don't print full log: host-specific systemd diagnostic paths are not
  # needed for routine status reports. No actual unit was loaded.
fi
echo 'TEMPORARY_DRY_RUN_UNIT=NEVER_INSTALLED_OR_STARTED'
echo 'ORIGIN_PARENT_DIRECTORY_CHMOD=NOT_PERFORMED'
echo 'NO_TUNNEL_OR_CREDENTIALS_CREATED=YES'
echo 'NO_DNS_TLS_CLOUDFLARE_CONFIGURATION_CHANGED=YES'
echo 'NO_SHARED_OR_QA_SERVICES_RESTARTED=YES'
echo 'NO_PUBLIC_TCP_BINDINGS=YES'
echo 'STEP12J_CONNECTOR_IDENTITY_CAPABILITY_INVENTORY=COMPLETE'
