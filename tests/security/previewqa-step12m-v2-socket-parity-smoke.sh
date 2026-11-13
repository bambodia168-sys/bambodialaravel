#!/usr/bin/env bash
# CI-only: Step12M v2 synthetic Unix socket must match accepted QA mode.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12m-v2-disposable-socket-rebind-20261113.sh'
OLD='support/previewqa-step12m-disposable-socket-rebind-20261113.sh'
test -f "$SRC"
test -f "$OLD"
test "$(grep -Fc "echo 'STEP12M_V2_MOCK_REBIND_PREFLIGHT=PASS'" "$SRC")" -eq 1
test "$(grep -Fc "echo 'STEP12M_V2_DISPOSABLE_MOCK_SOCKET_REBIND=OBSERVED'" "$SRC")" -eq 1
grep -Fq 'MOCK_SOCKET_MODE_0666_IN_ROOT_0700_PARENT=REQUIRED_AT_RUNTIME' "$SRC"
grep -Fq 'os.chmod(path, 0o666)' "$SRC"
grep -Fq 'MOCK_SOURCE_PARENT_MODE=0700' "$SRC"
grep -Fq 'MOCK_SOURCE_SOCKET_MODE=0666' "$SRC"
grep -Fq 'BLOCKED_MOCK_CLIENT_INITIAL_SOCKET_ERRNO=' "$SRC"
grep -Fq 'MOCK_FIRST_CLIENT_RESULT=' "$SRC"
grep -Fq 'APPROVE_SYNTHETIC_SOCKET_ONLY' "$SRC"
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
assert "os.chmod(path, 0o666)" not in old
head = 'cat > "$TMP/driver.py" <<' + "'" + 'PY' + "'\n"
tail = "\nPY\n\nLAUNCHED=yes\n"
assert source.count(head) == 1
driver = source.split(head, 1)[1].split(tail, 1)[0]
tree = ast.parse(driver, filename="mock-v2-driver.py")
client = [
    node for node in tree.body
    if isinstance(node, ast.Assign)
    and any(isinstance(target, ast.Name) and target.id == "client_code"
            for target in node.targets)
]
assert len(client) == 1
ast.parse(ast.literal_eval(client[0].value), filename="mock-v2-client.py")
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
with tempfile.TemporaryDirectory(prefix="mock-v2-mode-") as parent:
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
print("STEP12M_V2_EMBEDDED_PYTHON_SYNTAX_AND_SOCKET_MODE_PARITY=PASS")
PY
echo 'STEP12M_V2_MOCK_SOCKET_MODE_REGRESSION=PASS'
