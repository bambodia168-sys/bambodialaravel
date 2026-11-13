#!/usr/bin/env bash
# Step 12M — synthetic socket unlink/rebind across a private mount namespace.
# --check is offline-only; --probe creates a disposable synthetic socket and
# transient DynamicUser client. Never restarts the real QA Nginx/FPM services.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" == 1 ]] || fail check_or_probe_argument_required
MODE="$1"
[[ "$MODE" == --check || "$MODE" == --probe ]] || fail unsupported_mode
SOURCE='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
CONF='/etc/bambodia-previewqa-http/nginx.conf'
UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
ORIGINAL='/root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf'
SOCK='/run/bambodia-previewqa-http/http.sock'
FPM='/run/bambodia-previewqa/php-fpm.sock'
QA_USER='bambodia_pvhttp'
WORKER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
TRANSIENT='bambodia-previewqa-mockrebind-20261113.service'
TRANSIENT_DIR='/run/bambodia-previewqa-mockrebind-20261113'
TMP=''
LAUNCHED=no
cleanup(){
  local rc=$?
  if [[ "$LAUNCHED" == yes ]]; then
    systemctl stop "$TRANSIENT" >/dev/null 2>&1 || true
  fi
  if [[ -n "$TMP" && "$TMP" == /run/bambodia-step12m.* && -d "$TMP" && ! -L "$TMP" ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT

echo '========== STEP 12M — MOCK SOCKET REBIND PREFLIGHT =========='
[[ "$(id -u)" == 0 ]] || fail root_required
for command_name in git stat systemctl systemd-analyze systemd-run \
  grep id getent runuser mktemp python3 install readlink; do
  command -v "$command_name" >/dev/null || fail "missing_program:$command_name"
done
[[ -x "$PHP" ]] || fail private_php_binary_missing
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
  "$(git -C "$SOURCE" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail pinned_synthetic_source_changed
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail pinned_synthetic_source_dirty
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail QA_app_private_dir_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail QA_env_file_mode_changed
[[ -f "$ORIGINAL" && ! -L "$ORIGINAL" &&
  "$(stat -c '%U:%G:%a' "$ORIGINAL")" == root:root:600 ]] ||
  fail QA_host_guard_backup_missing
[[ -f "$CONF" && ! -L "$CONF" &&
  "$(stat -c '%U:%G:%a' "$CONF")" == root:"$QA_USER":640 ]] ||
  fail QA_origin_config_changed
[[ -f "$UNIT" && ! -L "$UNIT" &&
  "$(stat -c '%U:%G:%a' "$UNIT")" == root:root:644 ]] ||
  fail QA_origin_unit_changed
for unit in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  systemctl is-active --quiet "$unit" || fail "QA_service_not_active:$unit"
  [[ "$(systemctl is-enabled "$unit" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_enabled_at_boot:$unit"
done
for line in "User=$QA_USER" "Group=$QA_USER" \
  'RuntimeDirectoryMode=0700' 'RestrictAddressFamilies=AF_UNIX' \
  'ProtectSystem=strict' 'ProtectHome=true' 'NoNewPrivileges=true' \
  'Restart=no'; do
  grep -Fxq "$line" "$UNIT" || fail "QA_systemd_guard_changed:$line"
done
grep -Fxq '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$CONF" ||
  fail canonical_host_guard_missing
grep -Fxq '            return 421;' "$CONF" || fail unknown_host_guard_missing
grep -Fxq "        listen unix:$SOCK;" "$CONF" ||
  fail QA_private_unix_listener_changed
[[ "$(grep -Ec '^[[:space:]]*listen[[:space:]]+' "$CONF")" == 1 ]] ||
  fail additional_QA_HTTP_listener_detected
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$QA_USER:$QA_USER:700" ]] ||
  fail private_qa_socket_parent_changed
[[ -S "$SOCK" && ! -L "$SOCK" &&
  "$(stat -c '%U:%G' "$SOCK")" == "$QA_USER:$QA_USER" ]] ||
  fail QA_HTTP_socket_changed
[[ -S "$FPM" && ! -L "$FPM" &&
  "$(stat -c '%U:%G:%a' "$FPM")" == "$WORKER:$QA_USER:660" ]] ||
  fail QA_FPM_socket_changed
if id -nG www | tr ' ' '\n' | grep -Fxq "$QA_USER"; then
  fail shared_www_in_private_origin_group
fi
if runuser -u "$QA_USER" -- test -r "$APP/.env"; then
  fail ingress_can_read_qa_env
fi
if runuser -u www -- "$PHP" -r '
$s=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($s) { fclose($s); exit(0); } exit(2);
' "$SOCK"; then fail www_can_access_private_origin; fi
runuser -u "$WORKER" -- "$PHP" -r '
$p=$argv[1];require $p."/vendor/autoload.php";
$app=require $p."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    $db->db!=="bambodia_previewrestore_20261112" ||
    \App\Models\User::count()!==6 ||
    \Illuminate\Support\Facades\DB::table("preview_visibility_actions")->count()!==0) exit(2);
echo "PINNED_QA_DATABASE_AND_WITHDRAWAL_WRITES_OFF=PASS\n";
' "$APP" || fail QA_database_or_write_flag_changed

if command -v cloudflared >/dev/null ||
   getent passwd cloudflared >/dev/null ||
   [[ "$(systemctl show cloudflared.service -p LoadState --value 2>/dev/null || true)" == loaded ]]; then
  fail cloudflared_found_require_reassessment
fi
[[ "$(systemctl show "$TRANSIENT" -p LoadState --value 2>/dev/null || true)" == not-found ]] ||
  fail mock_transient_unit_already_exists
[[ ! -e "$TRANSIENT_DIR" && ! -L "$TRANSIENT_DIR" ]] ||
  fail mock_transient_runtime_dir_exists
[[ "$(systemd-analyze --version | head -n 1)" == systemd\ 249* ]] ||
  fail unexpected_systemd_version
echo 'REAL_QA_SERVICES_AND_ISOLATION=PASS'
echo 'MOCK_TRANSIENT_UNIT_AND_DIRECTORY=ABSENT'
echo 'CLOUDFLARED_AND_PUBLIC_ACCESS=NOT_CONFIGURED'

TMP="$(mktemp -d /run/bambodia-step12m.XXXXXXXX)"
chmod 700 "$TMP"
cat > "$TMP/parser.service" <<'SERVICE'
[Unit]
Description=Step 12M disposable mock Unix socket rebinding parser
[Service]
Type=oneshot
DynamicUser=yes
ExecStart=/usr/bin/python3 -c "print('PARSER_ONLY')"
RuntimeDirectory=bambodia-previewqa-mockrebind-20261113
RuntimeDirectoryMode=0700
BindReadOnlyPaths=/run/step12m-synthetic-original.sock:/run/bambodia-previewqa-mockrebind-20261113/http.sock
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
RestrictAddressFamilies=AF_UNIX
CapabilityBoundingSet=
SERVICE
if ! systemd-analyze verify "$TMP/parser.service" > "$TMP/parser.log" 2>&1; then
  fail mock_socket_private_mount_parser_failed
fi
echo 'MOCK_DYNAMIC_USER_MOUNT_PARSER=PASS'
echo 'REAL_QA_HTTP_AND_FPM_NOT_RESTARTED=YES'
if [[ "$MODE" == --check ]]; then
  echo 'NO_TRANSIENT_SYSTEMD_SERVICE_STARTED=YES'
  echo 'STEP12M_MOCK_REBIND_PREFLIGHT=PASS'
  exit 0
fi
[[ "$(printenv BAMBODIA_QA_MOCK_REBIND_APPROVAL || true)" == 'APPROVE_SYNTHETIC_SOCKET_ONLY' ]] ||
  fail mock_rebind_explicit_approval_missing

# All paths in this Python driver are under the root-private random TMP.
# The only service created is the fixed-name, time-limited DynamicUser
# synthetic client. The real QA HTTP/FPM units are never touched.
cat > "$TMP/driver.py" <<'PY'
import errno
import os
import select
import shutil
import socket
import subprocess
import sys
import threading
import time

base = sys.argv[1]
unit = "bambodia-previewqa-mockrebind-20261113.service"
mock = os.path.join(base, "mock.sock")
target = "/run/bambodia-previewqa-mockrebind-20261113/http.sock"
runtime = "/run/bambodia-previewqa-mockrebind-20261113"
response = b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK"
old = None
new = None
proc = None

class Server:
    def __init__(self, path):
        self.sock = socket.socket(socket.AF_UNIX)
        self.sock.settimeout(0.2)
        self.sock.bind(path)
        self.sock.listen(4)
        self.stop_event = threading.Event()
        self.thread = threading.Thread(target=self.serve, daemon=True)
        self.thread.start()

    def serve(self):
        while not self.stop_event.is_set():
            try:
                conn, _ = self.sock.accept()
            except (socket.timeout, OSError):
                continue
            with conn:
                conn.settimeout(2)
                try:
                    if not conn.recv(128).startswith(b"GET /mock HTTP/1.1"):
                        continue
                    conn.sendall(response)
                except OSError:
                    pass

    def close(self):
        self.stop_event.set()
        self.sock.close()
        self.thread.join(timeout=2)

def fetch(path):
    with socket.socket(socket.AF_UNIX) as s:
        s.settimeout(5)
        s.connect(path)
        s.sendall(b"GET /mock HTTP/1.1\r\nHost: synthetic.invalid\r\nConnection: close\r\n\r\n")
        reply = s.recv(128)
    return reply.startswith(b"HTTP/1.1 200 OK\r\n")

client_code = r'''
import errno,os,socket,sys
target="/run/bambodia-previewqa-mockrebind-20261113/http.sock"
original=sys.argv[1]
real_http="/run/bambodia-previewqa-http/http.sock"
real_fpm="/run/bambodia-previewqa/php-fpm.sock"
secret="/var/lib/bambodia-preview-restore-20261112/.env"
if os.geteuid()==0: sys.exit("BLOCKED_MOCK_CLIENT_ROOT")
if os.access(secret,os.R_OK): sys.exit("BLOCKED_MOCK_CLIENT_READ_ENV")
for path in (original,real_http,real_fpm):
    c=socket.socket(socket.AF_UNIX);c.settimeout(2)
    try: c.connect(path)
    except (PermissionError,FileNotFoundError,OSError): pass
    else: sys.exit("BLOCKED_MOCK_CLIENT_PRIVATE_ORIGINAL_ACCESS")
    finally: c.close()
def fetch(path):
    with socket.socket(socket.AF_UNIX) as s:
        s.settimeout(5)
        s.connect(path)
        s.sendall(b"GET /mock HTTP/1.1\r\nHost: synthetic.invalid\r\nConnection: close\r\n\r\n")
        return s.recv(128).startswith(b"HTTP/1.1 200 OK\r\n")
if not fetch(target): sys.exit("BLOCKED_MOCK_CLIENT_FIRST_FETCH")
print("DYNAMIC_UID_INITIAL_MOCK_HTTP=200",flush=True)
if sys.stdin.readline().strip()!="GO": sys.exit("BLOCKED_MOCK_CLIENT_SIGNAL")
try:
    result = "HTTP_200" if fetch(target) else "HTTP_NON200"
except OSError as e:
    result = "CONNECT_ERRNO_%s" % (e.errno if e.errno is not None else "UNKNOWN")
print("BOUND_MOUNT_AFTER_SOURCE_REBIND="+result,flush=True)
'''

def next_line(proc, seconds):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        readable, _, _ = select.select([proc.stdout], [], [], 0.25)
        if readable:
            line = proc.stdout.readline()
            if line:
                return line.strip()
            if proc.poll() is not None:
                break
        elif proc.poll() is not None:
            break
    return None

try:
    old = Server(mock)
    inode_old = os.stat(mock).st_ino
    if not fetch(mock):
        raise RuntimeError("synthetic_original_listener_not_200")

    cmd = ["systemd-run", "--system", "--quiet", "--wait", "--collect", "--pipe",
           "--unit=" + unit, "--service-type=exec",
           "-p", "DynamicUser=yes",
           "-p", "RuntimeDirectory=bambodia-previewqa-mockrebind-20261113",
           "-p", "RuntimeDirectoryMode=0700",
           "-p", "BindReadOnlyPaths=" + mock + ":" + target,
           "-p", "RestrictAddressFamilies=AF_UNIX",
           "-p", "NoNewPrivileges=yes",
           "-p", "PrivateTmp=yes",
           "-p", "ProtectSystem=strict",
           "-p", "ProtectHome=yes",
           "-p", "CapabilityBoundingSet=",
           "-p", "Restart=no",
           "-p", "RuntimeMaxSec=35s",
           "-p", "TimeoutStartSec=20s",
           "/usr/bin/python3", "-u", "-c", client_code, mock]
    proc = subprocess.Popen(cmd, stdin=subprocess.PIPE,
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            text=True, bufsize=1)
    first = next_line(proc, 18)
    if first != "DYNAMIC_UID_INITIAL_MOCK_HTTP=200":
        raise RuntimeError("transient_mock_client_first_response_not_200")

    # Recreate ONLY a synthetic Unix listener at the same source pathname.
    old.close()
    old = None
    os.unlink(mock)
    new = Server(mock)
    inode_new = os.stat(mock).st_ino
    if inode_new == inode_old:
        raise RuntimeError("mock_source_inode_was_not_replaced")
    if not fetch(mock):
        raise RuntimeError("replacement_synthetic_listener_not_200")
    proc.stdin.write("GO\n")
    proc.stdin.flush()

    result = next_line(proc, 12)
    if not result or not result.startswith("BOUND_MOUNT_AFTER_SOURCE_REBIND="):
        raise RuntimeError("transient_client_rebind_result_missing")
    outcome = result.split("=", 1)[1]
    if outcome != "HTTP_200" and not outcome.startswith("CONNECT_ERRNO_"):
        raise RuntimeError("unrecognized_transient_reconnect_result")
    if proc.stdin:
        proc.stdin.close()
    code = proc.wait(timeout=8)
    if code != 0:
        raise RuntimeError("transient_mock_service_nonzero_exit")

    for _ in range(12):
        load = subprocess.run(["systemctl", "show", unit,
                               "-p", "LoadState", "--value"],
                              text=True, capture_output=True).stdout.strip()
        if load == "not-found" and not os.path.lexists(runtime):
            break
        time.sleep(0.2)
    else:
        raise RuntimeError("mock_transient_unit_or_runtime_not_collected")

    print("MOCK_ORIGINAL_AND_REPLACED_INODES=DIFFERENT")
    print("MOCK_REPLACEMENT_SOURCE_HTTP=200")
    print("DYNAMIC_UID_INITIAL_MOCK_HTTP=200")
    print(result)
    if outcome == "HTTP_200":
        print("CONNECTOR_REBIND_CONCERN=UNEXPECTED_SUCCESS_REQUIRES_SEPARATE_REVIEW")
    else:
        print("CONNECTOR_REBIND_CONCERN=STALE_BOUND_SOCKET_REQUIRES_RECOVERY_DESIGN")
    print("MOCK_TRANSIENT_SERVICE_AND_RUNTIME=COLLECTED")
    print("MOCK_SOCKET_UNLINK_REBIND_OBSERVATION=COMPLETE")
except Exception as e:
    print("BLOCKED_STEP12M_MOCK_REBIND=" + str(e), file=sys.stderr)
    sys.exit(2)
finally:
    if proc is not None and proc.poll() is None:
        subprocess.run(["systemctl", "stop", unit], capture_output=True)
        try:
            proc.kill()
            proc.wait(timeout=3)
        except (OSError,subprocess.TimeoutExpired):
            pass
    if old is not None:
        old.close()
    if new is not None:
        new.close()
    try:
        os.unlink(mock)
    except FileNotFoundError:
        pass
PY

LAUNCHED=yes
echo '========== DISPOSABLE SYNTHETIC SOCKET REBIND OBSERVATION =========='
/usr/bin/python3 -B "$TMP/driver.py" "$TMP" ||
  fail synthetic_socket_rebind_rehearsal_failed

[[ "$(systemctl show "$TRANSIENT" -p LoadState --value 2>/dev/null || true)" == not-found ]] ||
  fail mock_transient_unit_remaining_after_test
[[ ! -e "$TRANSIENT_DIR" && ! -L "$TRANSIENT_DIR" ]] ||
  fail mock_transient_runtime_remaining_after_test
LAUNCHED=no
systemctl is-active --quiet bambodia-previewqa-http.service ||
  fail QA_HTTP_service_changed
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail QA_FPM_service_changed
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$QA_USER:$QA_USER:700" ]] ||
  fail QA_origin_socket_parent_changed
for unit in bambodia-previewqa-http.service bambodia-previewqa-fpm.service; do
  [[ "$(systemctl is-enabled "$unit" 2>/dev/null || true)" == disabled ]] ||
    fail "QA_service_enabled_during_test:$unit"
done
echo 'REAL_QA_SERVICES_UNCHANGED=YES'
echo 'PERSISTENT_USERS_GROUPS_AND_CONFIGS=UNCHANGED'
echo 'CLOUDFLARE_PUBLIC_DNS_TLS=NOT_CONFIGURED'
echo 'STEP12M_DISPOSABLE_MOCK_SOCKET_REBIND=OBSERVED'
