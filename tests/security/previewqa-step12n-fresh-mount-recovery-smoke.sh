#!/usr/bin/env bash
# CI-only: Step12N mock fresh namespace must recover from stale socket inode.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12n-fresh-mount-recovery-20261113.sh'
OLD='support/previewqa-step12m-disposable-socket-rebind-20261113.sh'
test -f "$SRC"
test -f "$OLD"
test "$(grep -Fc "echo 'STEP12N_FRESH_MOUNT_RECOVERY_PREFLIGHT=PASS'" "$SRC")" -eq 1
test "$(grep -Fc "echo 'STEP12N_DISPOSABLE_MOCK_SOCKET_RECOVERY=OBSERVED'" "$SRC")" -eq 1
grep -Fq 'MOCK_SOCKET_MODE_0666_IN_ROOT_0700_PARENT=REQUIRED_AT_RUNTIME' "$SRC"
grep -Fq 'os.chmod(path, 0o666)' "$SRC"
grep -Fq 'MOCK_SOURCE_PARENT_MODE=0700' "$SRC"
grep -Fq 'MOCK_SOURCE_SOCKET_MODE=0666' "$SRC"
grep -Fq 'BLOCKED_MOCK_CLIENT_INITIAL_SOCKET_ERRNO=' "$SRC"
grep -Fq 'MOCK_FIRST_CLIENT_RESULT=' "$SRC"
grep -Fq 'APPROVE_SYNTHETIC_FRESH_MOUNT_ONLY' "$SRC"
grep -Fq 'FRESH_NAMESPACE_NEW_SOCKET_HTTP=200' "$SRC"
grep -Fq 'OLD_NAMESPACE_STALE_MOUNT=ECONNREFUSED' "$SRC"
grep -Fq 'CONNECTOR_RECOVERY_MECHANISM=NEW_MOUNT_NAMESPACE_REQUIRED' "$SRC"
grep -Fq 'fresh_cmd = cmd + ["FRESH"]' "$SRC"
grep -Fq 'fresh_transient_namespace_did_not_reconnect_200' "$SRC"
grep -Fq 'fresh_transient_unit_or_runtime_not_collected' "$SRC"
grep -Fq 'old_stale_mount_expected_errno111_not_observed' "$SRC"
grep -Fq 'REAL_QA_SERVICES_UNCHANGED=YES' "$SRC"
grep -Fq 'CLOUDFLARE_PUBLIC_DNS_TLS=NOT_CONFIGURED' "$SRC"
if grep -Eq '^[[:space:]]*systemctl[[:space:]]+(restart|enable|disable|daemon-reload)' "$SRC"; then
  echo 'BLOCKED=service_change_detected_in_mock_v2' >&2
  exit 2
fi
if grep -Fq 'systemctl stop bambodia-previewqa-http.service' "$SRC" ||
   grep -Fq 'systemctl stop bambodia-previewqa-fpm.service' "$SRC"; then
  echo 'BLOCKED=real_QA_restart_detected_in_mock_v2' >&2
  exit 2
fi

python3 - "$SRC" "$OLD" <<'PY'
import ast
import os
import pathlib
import socket
import stat
import sys
import tempfile
import threading

source = pathlib.Path(sys.argv[1]).read_text()
old = pathlib.Path(sys.argv[2]).read_text()
assert "os.chmod(path, 0o666)" in old
head = 'cat > "$TMP/driver.py" <<' + "'" + 'PY' + "'\n"
tail = "\nPY\n\nLAUNCHED=yes\n"
assert source.count(head) == 1
driver = source.split(head, 1)[1].split(tail, 1)[0]
tree = ast.parse(driver, filename="mock-step12n-driver.py")
client = [
    node for node in tree.body
    if isinstance(node, ast.Assign)
    and any(isinstance(target, ast.Name) and target.id == "client_code"
            for target in node.targets)
]
assert len(client) == 1
client_source = ast.literal_eval(client[0].value)
ast.parse(client_source, filename="mock-step12n-client.py")
assert 'sys.argv[2]=="FRESH"' in client_source
assert 'print("FRESH_NAMESPACE_NEW_SOCKET_HTTP=200"' in client_source
assert 'result = "CONNECT_ERRNO_%s"' in client_source
assert 'fresh_cmd = cmd + ["FRESH"]' in driver
assert 'if outcome != "CONNECT_ERRNO_111":' in driver
assert 'if not fetch(mock):' in driver
server_nodes = [
    node for node in tree.body
    if isinstance(node, ast.ClassDef) and node.name == "Server"
]
assert len(server_nodes) == 1
namespace = {
    "socket": socket, "stat": stat, "os": os, "threading": threading,
    "response": b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
}
server_module = ast.Module(body=server_nodes, type_ignores=[])
ast.fix_missing_locations(server_module)
exec(compile(server_module, "<synthetic-server-class>", "exec"), namespace)
with tempfile.TemporaryDirectory(prefix="mock-step12n-mode-") as parent:
    os.chmod(parent, 0o700)
    path = os.path.join(parent, "mock.sock")
    old_umask = os.umask(0o077)
    server = None
    try:
        server = namespace["Server"](path)
        assert stat.S_IMODE(os.stat(path).st_mode) == 0o666
        assert stat.S_IMODE(os.stat(parent).st_mode) == 0o700
    finally:
        if server is not None:
            server.close()
        os.umask(old_umask)
print("STEP12N_EMBEDDED_PYTHON_SYNTAX_AND_SOCKET_MODE_PARITY=PASS")
PY
echo 'STEP12N_FRESH_MOUNT_RECOVERY_SYNTHETIC_REGRESSION=PASS'
