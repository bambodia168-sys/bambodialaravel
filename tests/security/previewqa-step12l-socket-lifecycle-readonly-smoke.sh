#!/usr/bin/env bash
# CI-only static checks for a read-only socket-lifecycle inventory.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12l-unix-socket-lifecycle-readonly-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12L_SOCKET_LIFECYCLE_INVENTORY=PASS'" "$SRC")" -eq 1
for needed in \
  'PRIVATE_SOCKET_0700_PARENT_AND_OTHER_USER_DENIAL=PASS' \
  'STEP12K_TRANSIENT_UNIT_AND_RUNTIME_DIR=ABSENT_CLEAN' \
  'SYNTHETIC_QA_DATABASE_AND_WITHDRAWAL_WRITES_OFF=PASS' \
  'HTTP_SOCKET_INODE=' \
  'HTTP_SOCKET_DEVICE=' \
  'HTTP_SERVICE_RESTART_POLICY=' \
  'HTTP_RUNTIME_DIRECTORY_PRESERVE=' \
  'BIND_MOUNT_SOCKET_INODE_REBIND_AFTER_RESTART=NOT_TESTED' \
  'DYNAMIC_USER_LONG_RUNNING_RECONNECT=NOT_TESTED' \
  'CLOUDFLARED_BINARY_ACCOUNT_UNIT=ABSENT' \
  'NO_QA_NGINX_OR_FPM_RESTART=YES' \
  'NO_TEMP_SERVICE_MOUNT_OR_LINUX_IDENTITY_CREATED=YES' \
  'NO_DNS_TLS_CLOUDFLARE_ACCESS_OR_TUNNEL_CHANGED=YES' \
  'NO_HOST_OR_CONFIG_WRITE_COMMANDS=YES'; do
  grep -Fq "$needed" "$SRC"
done
if grep -Eq '^[[:space:]]*(systemctl[[:space:]]+(start|stop|restart|enable|disable|daemon-reload|reload)|systemd-run[[:space:]]|mount[[:space:]]|umount[[:space:]])' "$SRC"; then
  echo 'BLOCKED=service_or_mount_change_in_readonly_audit' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(useradd|usermod|groupadd|groupmod|chmod|chown|install|cp|mv|rm|tee)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=filesystem_or_identity_mutation_in_readonly_audit' >&2; exit 2
fi
echo 'STEP12L_SOCKET_LIFECYCLE_READONLY_REGRESSION=PASS'
