#!/usr/bin/env bash
# Bambodia #35 - Stage 11B. Diagnose effective Laravel config inside PHP's
# HTTP built-in-server SAPI; previous CLI-only diagnosis was not conclusive.
# READ-ONLY: no SQL writes, no login, no installed.lock, no .env modification.
set -Eeuo pipefail
set +x
umask 077
[[ $# -eq 1 && "$1" == "--check" ]] || { echo 'Usage: --check' >&2; exit 2; }
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

APP=/var/lib/bambodia-pvt-53309a26f4cd
QA=/www/wwwroot/bambodia-preview-qa
REC=/var/lib/bambodia-pvr-9e5847bc21b8
PROD=/www/wwwroot/bambodia.com
PHP=/www/server/php/85/bin/php
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
[[ "$(id -u)" == 0 ]] || fail 'Authorized aaPanel root shell required'
for cmd in git python3 runuser realpath stat mktemp; do
  command -v "$cmd" >/dev/null || fail "Missing command: $cmd"
done
[[ -x "$PHP" ]] || fail 'PHP 8.5 missing'
for path in /www /www/wwwroot /var /var/lib "$APP" "$QA" "$REC" "$PROD"; do
  [[ ! -L "$path" ]] || fail 'Unexpected symlink in QA application path'
done
for path in "$APP" "$QA" "$REC" "$PROD"; do
  [[ -f "$path/artisan" && "$(realpath -e "$path")" == "$path" ]] ||
    fail 'Expected QA/production app absent or resolves unexpectedly'
done
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'Original QA source no longer at approved commit'
[[ ! -e "$APP/.git" && ! -e "$APP/bootstrap/cache/config.php" &&
   ! -e "$APP/storage/app/installed.lock" &&
   ! -L "$APP/storage/app/installed.lock" ]] ||
  fail 'Disposable QA has cached config, Git checkout, or installed marker'
for path in "$APP/.env" "$QA/.env" "$REC/.env"; do
  [[ -f "$path" && ! -L "$path" &&
     "$(stat -c %U:%G "$path")" == www:www &&
     "$(stat -c %a "$path")" == 600 ]] ||
    fail 'Unexpected QA env ownership or permissions'
done
[[ -s "$APP/vendor/autoload.php" &&
   -s "$APP/bootstrap/app.php" &&
   ! -L "$APP/vendor/autoload.php" ]] ||
  fail 'Disposable verified runtime unavailable'
if [[ -d /www/server/panel/vhost/nginx ]] &&
   grep -rFq -- "$APP" /www/server/panel/vhost/nginx; then
  fail 'Synthetic QA unexpectedly listed in public Nginx'
fi
id www >/dev/null || fail 'Missing aaPanel service user'
python3 - "$APP/.env" <<'PY'
from pathlib import Path
import sys
env={}
for line in Path(sys.argv[1]).read_text().splitlines():
    if line and not line.lstrip().startswith('#') and '=' in line:
        key,val=line.split('=',1)
        env[key.strip()]=val.strip().strip('"').strip("'")
required={
    "APP_ENV":"staging","APP_DEBUG":"false",
    "DB_CONNECTION":"mysql","DB_HOST":"127.0.0.1",
    "DB_DATABASE":"bambodia_pvt_53309a26f4cd",
    "DB_USERNAME":"b_pvt_53309a26f4cd",
    "PREVIEW_VISIBILITY_WRITES_ENABLED":"false",
    "MAIL_MAILER":"array","CACHE_STORE":"file","SESSION_DRIVER":"file"
}
for key,wanted in required.items():
    if env.get(key)!=wanted:
        raise SystemExit("BLOCKED=unexpected disposable environment: "+key)
print("PERSISTED_SYNTHETIC_PREVIEW_WRITES=OFF")
PY

TMP="$(mktemp -d /tmp/bambodia-preview-http-env.XXXXXXXX)"
[[ -d "$TMP" && ! -L "$TMP" ]] || fail 'Private temporary router directory invalid'
chmod 0755 "$TMP"
cleanup(){
  local code=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /tmp/bambodia-preview-http-env.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$code"
}
trap cleanup EXIT
cat > "$TMP/router.php" <<'PHP'
<?php
// Restricted localhost-only private QA diagnostic; never displays credentials.
chdir('/var/lib/bambodia-pvt-53309a26f4cd');
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='mysql' ||
   config('database.connections.mysql.database')!=='bambodia_pvt_53309a26f4cd' ||
   config('database.connections.mysql.username')!=='b_pvt_53309a26f4cd') {
    http_response_code(503);
    exit;
}
$key='PREVIEW_VISIBILITY_WRITES_ENABLED';
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'getenv_true' => getenv($key)==='true',
    'env_superglobal_present' => isset($_ENV[$key]),
    'server_superglobal_present' => isset($_SERVER[$key]),
    'laravel_env_true' => \Illuminate\Support\Env::get($key)===true,
    'effective_preview_config_on' => config('preview_visibility.enabled')===true,
    'sapi_is_cli_server' => PHP_SAPI==='cli-server',
], JSON_THROW_ON_ERROR);
PHP
chmod 0644 "$TMP/router.php"
echo '========== PHP HTTP SAPI / LARAVEL ENV RECONCILIATION =========='
python3 - "$TMP/router.php" "$TMP" "$APP" "$PHP" <<'PY'
import http.client
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import time

router, docroot, app, php = sys.argv[1:]
if Path(router).is_symlink() or Path(docroot).is_symlink():
    raise SystemExit("BLOCKED=diagnostic router path linked")
results={}
for label,extra in [
    ("DEFAULT",[]),
    ("EGPCS",["-d","variables_order=EGPCS"])
]:
    server=None
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1",0))
        port=s.getsockname()[1]
    try:
        with open(os.devnull,"wb") as sink:
            server=subprocess.Popen([
                "runuser","-u","www","--","env","-i",
                "HOME="+app,"PATH=/usr/bin:/bin:/usr/local/bin",
                "PREVIEW_VISIBILITY_WRITES_ENABLED=true",php,
                *extra,"-S","127.0.0.1:"+str(port),
                "-t",docroot,router
            ], cwd=app, stdout=sink, stderr=sink,
               close_fds=True, start_new_session=True)
            reply=None
            for _ in range(45):
                if server.poll() is not None:
                    break
                try:
                    c=http.client.HTTPConnection("127.0.0.1",port,timeout=5)
                    try:
                        c.request("GET","/__isolated_qa_config_only")
                        response=c.getresponse()
                        data=response.read(8192)
                        if response.status != 200:
                            raise RuntimeError("Diagnostic Laravel HTTP bootstrap failed")
                        reply=json.loads(data)
                        break
                    finally:
                        c.close()
                except (OSError,http.client.HTTPException):
                    time.sleep(0.2)
            if not isinstance(reply,dict) or reply.get("sapi_is_cli_server") is not True:
                raise RuntimeError("PHP HTTP diagnostic failed to collect SAPI output")
            results[label]=reply
            print("SAPI_"+label+"_GETENV="+str(reply.get("getenv_true")).upper())
            print("SAPI_"+label+"_PHP_ENV_PRESENT="+str(reply.get("env_superglobal_present")).upper())
            print("SAPI_"+label+"_PHP_SERVER_PRESENT="+str(reply.get("server_superglobal_present")).upper())
            print("SAPI_"+label+"_LARAVEL_ENV_TRUE="+str(reply.get("laravel_env_true")).upper())
            print("SAPI_"+label+"_EFFECTIVE_CONFIG_ON="+str(reply.get("effective_preview_config_on")).upper())
    finally:
        if server is not None:
            if server.poll() is None:
                try: os.killpg(server.pid, signal.SIGTERM)
                except ProcessLookupError: pass
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                try: os.killpg(server.pid, signal.SIGKILL)
                except ProcessLookupError: pass
                server.wait(timeout=5)
if len(results)!=2:
    raise SystemExit("BLOCKED=missing a PHP HTTP diagnostic result")
print("HTTP_SERVERS=STOPPED")
print("HTTP_DIAGNOSTIC=NO_LOGIN_NO_DB_WRITE")
print("RESULT=STAGE11_HTTP_SAPI_ENV_DIAGNOSIS_COMPLETE")
PY
echo 'ORIGINAL_QA=UNMODIFIED'
echo 'RESTORED_QA=UNMODIFIED'
echo 'SYNTHETIC_QA_DATABASE=UNMODIFIED_BY_DIAGNOSTIC'
echo 'PRODUCTION=UNTOUCHED_BY_DIAGNOSTIC'
