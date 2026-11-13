#!/usr/bin/env bash
# CI-only: failed Step12M probe cleanup audit must never mutate host state.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12m-failed-probe-readonly-audit-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12M_FAILURE_CLEANUP_READONLY_AUDIT=PASS'" "$SRC")" -eq 1
for marker in \
  'REAL_QA_SERVICES_AND_PRIVATE_SOCKET=PASS' \
  'SHARED_WWW_PRIVATE_CONNECT=DENIED' \
  'ORIGINAL_0700_PARENT_AND_0666_HTTP_SOCKET=PASS' \
  'FAILED_TRANSIENT_UNIT_AND_RUNTIME=ABSENT_CLEAN' \
  'SYNTHETIC_TEST_DIRECTORIES=ABSENT_CLEAN' \
  'SYNTHETIC_QA_DATABASE_AND_PREVIEW_WRITES_OFF=PASS' \
  'TRANSIENT_JOURNAL_PERMISSION_ERROR=' \
  'TRANSIENT_JOURNAL_MOUNT_SETUP_FAILURE=' \
  'TRANSIENT_JOURNAL_EXEC_FAILURE=' \
  'RECONNECTION_AFTER_SOCKET_REPLACEMENT=NOT_TESTED' \
  'NO_TRANSIENT_SERVICES_STARTED_OR_STOPPED=YES' \
  'NO_CONFIG_FILE_OR_PERMISSION_CHANGES=YES' \
  'NO_CLOUDFLARE_DNS_TLS_OR_PUBLICATION=YES'; do
  grep -Fq "$marker" "$SRC"
done
if grep -Eq '^[[:space:]]*(systemctl[[:space:]]+(start|stop|restart|reload|enable|disable|daemon-reload)|systemd-run[[:space:]]|mount[[:space:]]|umount[[:space:]])' "$SRC"; then
  echo 'BLOCKED=service_or_mount_action_in_failure_audit' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(chmod|chown|chgrp|install|cp|mv|rm|tee|useradd|usermod|groupadd|groupmod)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=filesystem_or_identity_change_in_failure_audit' >&2; exit 2
fi
echo 'STEP12M_FAILURE_READONLY_AUDIT_REGRESSION=PASS'
