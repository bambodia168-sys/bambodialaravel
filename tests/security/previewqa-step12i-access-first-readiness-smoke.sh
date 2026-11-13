#!/usr/bin/env bash
# Synthetic CI only: verify Step12I never mutates or publishes a connector.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12i-cloudflare-access-readiness-readonly-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12I_ACCESS_FIRST_READINESS_DISCOVERY=PASS'" "$SRC")" -eq 1
for required in \
  'PRIVATE_ORIGIN_AND_LINUX_SOCKET_ISOLATION=PASS' \
  'ISOLATED_QA_DATABASE_AND_PREVIEW_WRITES_OFF=PASS' \
  'CANONICAL_HOST_UP=HTTP_200' \
  'UNRECOGNIZED_HOST_UP=HTTP_421' \
  'RECOMMENDED_FUTURE_ORIGIN_TRANSPORT=UNIX_SOCKET_NOT_TCP' \
  'ACCESS_APPLICATION_POLICY=NOT_VERIFIED_IN_CLOUDFLARE_ACCOUNT' \
  'CONNECTOR_CREDENTIALS=NOT_READ_OR_CREATED' \
  'NO_SERVICE_STARTED_RESTARTED_OR_ENABLED=YES' \
  'NO_CONFIG_OR_DATABASE_WRITE_COMMANDS=YES'; do
  grep -Fq "$required" "$SRC"
done
if grep -Eq '^[[:space:]]*(systemctl[[:space:]]+(start|stop|restart|enable|disable|reload)|cloudflared[[:space:]]+tunnel[[:space:]]+(create|run|route|delete))' "$SRC"; then
  echo 'BLOCKED=runtime_or_tunnel_mutation_in_readonly_discovery' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(chmod|chown|chgrp|install|cp|mv|rm|tee|sed[[:space:]]+-i)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=file_mutation_in_readonly_discovery' >&2; exit 2
fi
echo 'STEP12I_ACCESS_FIRST_DISCOVERY_REGRESSION=PASS'
