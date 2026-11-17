#!/usr/bin/env bash
# CI-only: Step12J must remain inventory, not connector installation.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12j-connector-isolation-readonly-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12J_CONNECTOR_IDENTITY_CAPABILITY_INVENTORY=COMPLETE'" "$SRC")" -eq 1
for marker in \
  'LOCAL_QA_ORIGIN_LINUX_BOUNDARIES=PASS' \
  'ROOT_PRIVATE_BACKUP_AND_0700_UNIX_DIRECTORY=PASS' \
  'SHARED_WWW_UNIX_CONNECT=DENIED' \
  'CLOUDFLARED_BINARY=ABSENT' \
  'CONNECTOR_SECRETS_OR_ACCESS_POLICY=NOT_ACCESSED' \
  'SYSTEMD_BINDREADONLYPATHS_UNIT_PARSER=PASS' \
  'SYSTEMD_BINDREADONLYPATHS_UNIT_PARSER=NOT_VERIFIED' \
  'CROSS_UID_SOCKET_CONNECTIVITY=NOT_PROVEN' \
  'CROSS_UID_SOCKET_BIND_MOUNT=NOT_ATTEMPTED' \
  'TEMPORARY_DRY_RUN_UNIT=NEVER_INSTALLED_OR_STARTED' \
  'ORIGIN_PARENT_DIRECTORY_CHMOD=NOT_PERFORMED' \
  'NO_TUNNEL_OR_CREDENTIALS_CREATED=YES' \
  'NO_SHARED_OR_QA_SERVICES_RESTARTED=YES' \
  'NO_PUBLIC_TCP_BINDINGS=YES'; do
  grep -Fq "$marker" "$SRC"
done
grep -Fq 'BindReadOnlyPaths=/run/bambodia-previewqa-http/http.sock:' "$SRC"
grep -Fq 'systemd-analyze verify "$TMP/bambodia-previewqa-connector-dryrun.service"' "$SRC"
if grep -Eq '^[[:space:]]*systemctl[[:space:]]+(start|stop|restart|daemon-reload|enable|disable)' "$SRC"; then
  echo 'BLOCKED=service_write_in_discovery' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(cloudflared[[:space:]]+tunnel|apt[[:space:]]+install|apt-get[[:space:]]+install|curl[[:space:]])' "$SRC"; then
  echo 'BLOCKED=connector_install_or_control_plane_call' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(usermod|groupmod|groupadd|useradd|mount|umount)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=identity_or_mount_mutation' >&2; exit 2
fi
echo 'STEP12J_CONNECTOR_CAPABILITY_READONLY_REGRESSION=PASS'
