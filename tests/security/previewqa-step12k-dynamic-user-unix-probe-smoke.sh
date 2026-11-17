#!/usr/bin/env bash
# CI-only static regression: Step12K is isolated, opt-in and transient.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12k-dynamic-user-unix-socket-probe-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12K_DISPOSABLE_CONNECTIVITY_PREFLIGHT=PASS'" "$SRC")" -eq 1
test "$(grep -Fc "echo 'STEP12K_DYNAMIC_USER_SOCKET_REHEARSAL=PASS'" "$SRC")" -eq 1
for marker in \
  "MODE=" \
  'APPROVE_DISPOSABLE_DYNAMIC_USER_ONLY' \
  'DynamicUser=yes' \
  'BindReadOnlyPaths=' \
  'RuntimeDirectoryMode=0700' \
  'RestrictAddressFamilies=AF_UNIX' \
  "NoNewPrivileges=yes" \
  'RuntimeMaxSec=25s' \
  'TimeoutStartSec=20s' \
  'systemd-run --system --quiet --wait --collect --pipe' \
  'TRANSIENT_DYNAMIC_UID_NONROOT=PASS' \
  'TRANSIENT_MOUNTED_QA_HTTP_SOCKET_GET_UP=HTTP_200' \
  'PINNED_SYNTHETIC_QA_AND_PREVIEW_WRITES_OFF=PASS' \
  'unauthorized_account_connected:' \
  'missing_nonroot_proof' \
  'transient_unit_not_collected' \
  'TRANSIENT_UNIT_AND_RUNTIME_DIRECTORY=COLLECTED' \
  'NO_CLOUDFLARED_OR_TUNNEL_CREDENTIALS=YES' \
  'PUBLIC_DNS_TLS_CLOUDFLARE=NOT_CONFIGURED'; do
  grep -Fq "$marker" "$SRC"
done
if grep -Eq '^[[:space:]]*(useradd|usermod|groupadd|groupmod|mount|umount|apt|apt-get|cloudflared)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=permanent_identity_mount_or_connector_mutation_in_probe' >&2
  exit 2
fi
if grep -Eq '^[[:space:]]*systemctl[[:space:]]+(restart|enable|disable|daemon-reload)' "$SRC"; then
  echo 'BLOCKED=service_mutation_in_transient_probe' >&2
  exit 2
fi
if grep -Fq 'systemctl stop bambodia-previewqa-http.service' "$SRC"; then
  echo 'BLOCKED=QA_HTTP_restart_or_stop_in_probe' >&2
  exit 2
fi
echo 'STEP12K_DYNAMIC_USER_UNIX_SOCKET_REGRESSION=PASS'
