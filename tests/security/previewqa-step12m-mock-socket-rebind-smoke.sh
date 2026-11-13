#!/usr/bin/env bash
# CI-only: ensure mock lifecycle test is isolated and embedded Python compiles.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12m-disposable-socket-rebind-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12M_MOCK_REBIND_PREFLIGHT=PASS'" "$SRC")" -eq 1
test "$(grep -Fc "echo 'STEP12M_DISPOSABLE_MOCK_SOCKET_REBIND=OBSERVED'" "$SRC")" -eq 1
for required in \
  'APPROVE_SYNTHETIC_SOCKET_ONLY' \
  'REAL_QA_SERVICES_AND_ISOLATION=PASS' \
  'PINNED_QA_DATABASE_AND_WITHDRAWAL_WRITES_OFF=PASS' \
  'MOCK_DYNAMIC_USER_MOUNT_PARSER=PASS' \
  'BindReadOnlyPaths=' \
  'RuntimeMaxSec=35s' \
  'RestrictAddressFamilies=AF_UNIX' \
  'DYNAMIC_UID_INITIAL_MOCK_HTTP=200' \
  'BOUND_MOUNT_AFTER_SOURCE_REBIND=' \
  'MOCK_ORIGINAL_AND_REPLACED_INODES=DIFFERENT' \
  'MOCK_REPLACEMENT_SOURCE_HTTP=200' \
  'MOCK_TRANSIENT_SERVICE_AND_RUNTIME=COLLECTED' \
  'MOCK_SOCKET_UNLINK_REBIND_OBSERVATION=COMPLETE' \
  'CONNECTOR_REBIND_CONCERN=STALE_BOUND_SOCKET_REQUIRES_RECOVERY_DESIGN' \
  'REAL_QA_SERVICES_UNCHANGED=YES' \
  'CLOUDFLARE_PUBLIC_DNS_TLS=NOT_CONFIGURED'; do
  grep -Fq "$required" "$SRC"
done
if grep -Eq '^[[:space:]]*systemctl[[:space:]]+(restart|enable|disable|daemon-reload|reload)' "$SRC"; then
  echo 'BLOCKED=shared_service_mutation_in_mock' >&2; exit 2
fi
if grep -Fq 'systemctl stop bambodia-previewqa-http.service' "$SRC" ||
   grep -Fq 'systemctl stop bambodia-previewqa-fpm.service' "$SRC"; then
  echo 'BLOCKED=real_QA_service_stop_in_mock' >&2; exit 2
fi
if grep -Eq '^[[:space:]]*(useradd|usermod|groupadd|groupmod|mount|umount|cloudflared|apt|apt-get)[[:space:]]' "$SRC"; then
  echo 'BLOCKED=permanent_user_mount_or_cloudflared_mutation' >&2; exit 2
fi

# Extract and syntax-check the Python driver, then the nested transient
# client code. No server, systemd unit or socket is created by this test.
python3 - "$SRC" <<'PY'
import ast
import pathlib
import sys
source = pathlib.Path(sys.argv[1]).read_text()
before = "cat > \"$TMP/driver.py\" <<'PY'\n"
after = "\nPY\n\nLAUNCHED=yes\n"
assert source.count(before) == 1
driver = source.split(before, 1)[1].split(after, 1)[0]
tree = ast.parse(driver, filename="step12m-driver.py")
client_assign = [
    node for node in tree.body
    if isinstance(node, ast.Assign)
    and any(isinstance(target, ast.Name) and target.id == "client_code"
            for target in node.targets)
]
assert len(client_assign) == 1
client = ast.literal_eval(client_assign[0].value)
ast.parse(client, filename="step12m-dynamic-client.py")
assert "systemd-run" in driver
assert 'sys.stdin.readline().strip()!="GO"' in client
assert '"BindReadOnlyPaths=" + mock + ":" + target' in driver
assert '"systemctl", "stop", unit' in driver
assert 'os.unlink(mock)' in driver
print("STEP12M_SYNTHETIC_DRIVER_AND_TRANSIENT_CLIENT_PYTHON_SYNTAX=PASS")
PY
echo 'STEP12M_DISPOSABLE_MOCK_REBIND_REGRESSION=PASS'
