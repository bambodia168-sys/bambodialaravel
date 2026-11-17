#!/usr/bin/env bash
# CI-only: independent Step12O audit must remain a read-only QA gate.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12o-post-recovery-independent-readonly-audit-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12O_POST_SYNTHETIC_RECOVERY_INDEPENDENT_AUDIT=PASS'" "$SRC")" -eq 1
for marker in \
  'REAL_QA_HTTP_AND_FPM_ACTIVE_BOOT_DISABLED=PASS' \
  'ORIGINAL_0700_PARENT_AND_0666_HTTP_SOCKET=PASS' \
  'SHARED_WWW_AND_NGINX_SECRET_BOUNDARIES=PASS' \
  'STEP12K_M_OLD_AND_NEW_TRANSIENT_UNITS=ABSENT_CLEAN' \
  'STEP12M_AND_N_SYNTHETIC_MOCK_DIRECTORIES=ABSENT_CLEAN' \
  'CANONICAL_LOCAL_ORIGIN_GET_UP=HTTP_200' \
  'UNKNOWN_HOST_LOCAL_ORIGIN_GET_UP=HTTP_421' \
  'SYNTHETIC_QA_DATABASE_AND_PREVIEW_WRITES_OFF=PASS' \
  'LIVE_HTTP_SOCKET_INODE=' \
  'LIVE_HTTP_SOCKET_DEVICE=' \
  'CLOUDFLARED_BINARY_ACCOUNT_AND_SERVICE=ABSENT' \
  'REAL_ORIGIN_RESTART_RECOVERY=NOT_TESTED' \
  'LONG_RUNNING_CLOUDFLARE_CONNECTOR=NOT_INSTALLED' \
  'CLOUDFLARE_ACCESS_JWT_DNS_TLS=NOT_VALIDATED' \
  'NO_LIVE_SERVICE_RESTART_OR_MOUNT=YES' \
  'NO_HOST_CONFIG_USER_OR_PERMISSION_MUTATION=YES'; do
  grep -Fq "$marker" "$SRC"
done
if grep -Eq '^[[:space:]]*(systemctl[[:space:]]+(start|stop|restart|reload|enable|disable|daemon-reload)|systemd-run[[:space:]]|mount[[:space:]]|umount[[:space:]])' "$SRC"; then
  echo 'BLOCKED=service_or_mount_action_in_readonly_acceptance' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(chmod|chown|chgrp|install|cp|mv|rm|tee|useradd|usermod|groupadd|groupmod)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=filesystem_or_identity_change_in_readonly_acceptance' >&2; exit 2
fi
echo 'STEP12O_POST_RECOVERY_READONLY_REGRESSION=PASS'
