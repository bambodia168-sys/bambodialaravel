#!/usr/bin/env bash
# Bambodia #35 Stage 8: recovery-only loopback GET acceptance.
# Briefly enables HTTP via TEMPORARY installed.lock on RECOVERY app only;
# guaranteed cleanup, NO SQL DDL, auth writes, Nginx or production changes.
set -Eeuo pipefail
set +x
umask 077
MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--check" || "$MODE" == "--probe" ) ]] || {
  echo 'Usage: --check|--probe' >&2; exit 2;
}
fail(){ echo "BLOCKED=$*" >&2; exit 2; }
PROD=/www/wwwroot/bambodia.com
QA=/www/wwwroot/bambodia-preview-qa
APP=/var/lib/bambodia-pvr-9e5847bc21b8
APP_DB=bambodia_pvr_9e5847bc21b8
APP_USER=b_pvr_9e5847bc21b8
QA_DB=bambodia_pvqa_29c824170b10
QA_USER=b_pvqa_29c824170b10
PHP=/www/server/php/85/bin/php
SOURCE_SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
EVIDENCE=/root/Bambodia-PreviewQA-HTTP-Proofs
[[ "$(id -u)" == 0 ]] || fail 'Authorized aaPanel root terminal required'
for bin in git python3 runuser realpath stat grep; do
  command -v "$bin" >/dev/null || fail "Missing $bin"
done
[[ -x "$PHP" ]] || fail 'Expected PHP 8.5 missing'
[[ ! -L /www && ! -L /www/wwwroot && ! -L /var &&
   ! -L /var/lib && ! -L "$PROD" && ! -L "$QA" && ! -L "$APP" ]] ||
  fail 'Symlink in protected application paths'
for dir in "$PROD" "$QA" "$APP"; do
  [[ -f "$dir/artisan" && "$(realpath -e "$dir")" == "$dir" ]] ||
    fail "Invalid application path: $dir"
done
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SOURCE_SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'QA source not clean at approved merge SHA'
[[ ! -e "$APP/.git" && ! -L "$APP/.git" ]] || fail 'Recovery app contains .git'
for envpath in "$APP/.env" "$QA/.env"; do
  [[ -f "$envpath" && ! -L "$envpath" &&
     "$(stat -c %a "$envpath")" == 600 &&
     "$(stat -c %U:%G "$envpath")" == www:www ]] ||
    fail 'Unexpected private env permissions'
done
for rel in artisan public/index.php bootstrap/app.php routes/web.php \
  vendor/autoload.php public/build/manifest.json config/preview_visibility.php; do
  [[ -s "$APP/$rel" && -s "$QA/$rel" && ! -L "$APP/$rel" ]] ||
    fail "Recovery file missing or linked: $rel"
  [[ "$(git hash-object "$APP/$rel")" == "$(git hash-object "$QA/$rel")" ]] ||
    fail "Recovery code differs from QA: $rel"
done
[[ ! -e "$APP/storage/app/installed.lock" &&
   ! -L "$APP/storage/app/installed.lock" &&
   ! -e "$QA/storage/app/installed.lock" &&
   ! -e "$APP/bootstrap/cache/config.php" ]] ||
  fail 'Unexpected existing installation marker or config cache'
[[ -d "$APP/storage/framework/sessions" &&
   ! -L "$APP/storage/framework/sessions" ]] ||
  fail 'Recovery file session directory missing or linked'
if [[ -d /www/server/panel/vhost/nginx ]] &&
   grep -rFq -- "$APP" /www/server/panel/vhost/nginx; then
  fail 'Recovery app unexpectedly referenced in public Nginx config'
fi
id www >/dev/null || fail 'aaPanel www account absent'
for ext in pdo_mysql mbstring openssl fileinfo; do
  "$PHP" -r 'exit(extension_loaded($argv[1])?0:1);' "$ext" ||
    fail "PHP extension missing: $ext"
done
python3 - "$APP/.env" "$QA/.env" "$APP_DB" "$APP_USER" \
  "$QA_DB" "$QA_USER" <<'PY'
import re,sys
from pathlib import Path
def parse(p):
    data={}
    for line in Path(p).read_text().splitlines():
        if line and not line.lstrip().startswith("#") and "=" in line:
            k,v=line.split("=",1)
            data[k.strip()]=v.strip().strip("'").strip('"')
    return data
for label,path,db,user,prefix in (
    ("RECOVERY",sys.argv[1],sys.argv[3],sys.argv[4],"bambodia_pvr_"),
    ("SOURCE_QA",sys.argv[2],sys.argv[5],sys.argv[6],"bambodia_pvqa_")):
    env=parse(path)
    settings={
        "APP_ENV":"staging","APP_DEBUG":"false","APP_URL":"http://127.0.0.1",
        "DB_CONNECTION":"mysql","DB_HOST":"127.0.0.1","DB_PORT":"3306",
        "DB_DATABASE":db,"DB_USERNAME":user,"SESSION_DRIVER":"file",
        "CACHE_STORE":"file","MAIL_MAILER":"array","QUEUE_CONNECTION":"sync",
        "FILESYSTEM_DISK":"local","MEDIA_LIBRARY_DISK":"local",
        "PREVIEW_VISIBILITY_WRITES_ENABLED":"false"
    }
    for key,val in settings.items():
        if env.get(key)!=val:
            raise SystemExit("BLOCKED=incorrect "+label+" setting: "+key)
    if not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=",env.get("APP_KEY","")) or not env.get("DB_PASSWORD"):
        raise SystemExit("BLOCKED="+label+" invalid private credentials")
    if env.get("DB_URL","").lower() not in ("","null"):
        raise SystemExit("BLOCKED="+label+" unexpected DB_URL")
    if env.get("SESSION_DOMAIN","").lower() not in ("","null"):
        raise SystemExit("BLOCKED="+label+" cross-domain cookie")
    for key in ("SESSION_COOKIE","CACHE_PREFIX"):
        if not env.get(key,"").startswith(prefix):
            raise SystemExit("BLOCKED="+label+" session/cache namespace mismatch")
print("RECOVERY_AND_QA_CONFIG=ISOLATED_DEFAULT_OFF")
PY

# Read-only source and recovery inventory for all base tables.
DIGEST="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false ||
 config("database.default")!=="mysql" ||
 config("database.connections.mysql.database")!==$argv[1] ||
 config("database.connections.mysql.username")!==$argv[2]) exit(2);
$pdo=\Illuminate\Support\Facades\DB::connection("mysql")->getPdo();
if($pdo->query("SELECT DATABASE()")->fetchColumn()!==$argv[1]) exit(3);
$q=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
$inventory=[];
while(($t=$q->fetchColumn())!==false){
 if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t)) exit(4);
 $quote=chr(96);
 $count=$pdo->query("SELECT COUNT(*) FROM ".$quote.$t.$quote)->fetchColumn();
 $sum=$pdo->query("CHECKSUM TABLE ".$quote.$t.$quote." EXTENDED")->fetch(\PDO::FETCH_NUM)[1]??null;
 if(!is_numeric($sum)) exit(5);
 $inventory[]=[$t,(string)$count,(string)$sum];
}
if(count($inventory)!==81) exit(6);
foreach(["users","marketplace_listings","marketplace_jobs","preview_visibility_actions"] as $t){
 if((int)$pdo->query("SELECT COUNT(*) FROM ".chr(96).$t.chr(96))->fetchColumn()!==0) exit(7);
}
if((int)$pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn()!==62) exit(8);
echo hash("sha256",json_encode($inventory,JSON_THROW_ON_ERROR));
PHP
)"
get_hash(){
  (cd "$1" && runuser -u www -- env -i HOME="$1" PATH=/usr/bin:/bin:/usr/local/bin \
    "$PHP" -r "$DIGEST" "$2" "$3")
}
QA_BEFORE="$(get_hash "$QA" "$QA_DB" "$QA_USER")" ||
  fail 'Source QA inventory read failed'
APP_BEFORE="$(get_hash "$APP" "$APP_DB" "$APP_USER")" ||
  fail 'Recovery inventory read failed'
[[ "$QA_BEFORE" == "$APP_BEFORE" && "$QA_BEFORE" =~ ^[a-f0-9]{64}$ ]] ||
  fail 'Restored schema inventory does not match source'
echo '========== BAMBODIA STAGE 8 RECOVERY HTTP PREFLIGHT =========='
echo "MODE=$MODE"
echo 'SOURCE_AND_RESTORE_TABLES=81_EXACT_COUNT_AND_CHECKSUM_MATCH'
echo 'MIGRATIONS=62'
echo 'CUSTOMER_DATA=EMPTY'
echo 'WRITE_SWITCH=OFF'
echo 'PRODUCTION=NOT_A_TARGET'
if [[ "$MODE" == --check ]]; then
  echo 'HTTP_LISTENER=NOT_STARTED'
  echo 'TEMP_INSTALL_MARKER=NOT_CREATED'
  echo 'RESULT=READY_FOR_RECOVERY_LOOPBACK_HTTP_PROBE'
  exit 0
fi

[[ ! -L "$EVIDENCE" ]] || fail 'Evidence root is symlinked'
mkdir -p -m 0700 "$EVIDENCE"
[[ "$(stat -c %U:%G "$EVIDENCE")" == root:root ]] ||
  fail 'Evidence root must be root-owned'
chmod 0700 "$EVIDENCE"
OUT="$(mktemp -d "$EVIDENCE/http-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$OUT"

python3 - "$APP" "$PHP" "$OUT" <<'PY'
import http.client
import json
import os
from pathlib import Path
import pwd,grp
import signal
import socket
import subprocess
import sys
import time
from urllib.parse import urlsplit
app,php,evidence=Path(sys.argv[1]),sys.argv[2],Path(sys.argv[3])
marker_path=app/"storage/app/installed.lock"
if marker_path.exists() or marker_path.is_symlink():
    raise SystemExit("BLOCKED=recovery app already has installed.lock")
marker=b"Bambodia Stage8 TEMPORARY RECOVERY-ONLY loopback acceptance\n"
server=None
created=False
results=[]
try:
    flags=os.O_CREAT|os.O_EXCL|os.O_WRONLY
    if hasattr(os,"O_NOFOLLOW"): flags|=os.O_NOFOLLOW
    fd=os.open(marker_path,flags,0o600)
    created=True
    with os.fdopen(fd,"wb") as file:
        file.write(marker)
        file.flush()
        os.fsync(file.fileno())
    os.chown(marker_path,pwd.getpwnam("www").pw_uid,grp.getgrnam("www").gr_gid)
    os.chmod(marker_path,0o600)
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1",0))
        port=sock.getsockname()[1]
    print("HTTP_SERVER_BIND=127.0.0.1_ONLY")
    with (evidence/"private-http-server.log").open("wb") as logfile:
        server=subprocess.Popen([
            "runuser","-u","www","--",php,"-S","127.0.0.1:"+str(port),
            "-t","public","public/index.php"],
            cwd=str(app),stdout=logfile,stderr=subprocess.STDOUT,
            start_new_session=True,close_fds=True)
        def request(path):
            conn=http.client.HTTPConnection("127.0.0.1",port,timeout=6)
            try:
                conn.request("GET",path,headers={"User-Agent":"Bambodia-Private-QA",
                                              "Accept":"text/html"})
                response=conn.getresponse()
                status=response.status
                headers={k.lower():v for k,v in response.getheaders()}
                data=response.read(2*1024*1024)
                return status,headers,data
            finally:
                conn.close()
        ready=False
        for i in range(35):
            if server.poll() is not None:
                break
            try:
                request("/up")
                ready=True
                break
            except (OSError,http.client.HTTPException):
                time.sleep(0.2)
        if not ready:
            raise RuntimeError("Loopback server did not start")
        paths=[
            ("/up",200),("/",200),("/login",200),("/jobs",200),("/blog",200),
            ("/dashboard/preview-content",302),("/installer",404)
        ]
        for path,expected in paths:
            status,headers,body=request(path)
            if path=="/dashboard/preview-content":
                actual_path=urlsplit(headers.get("location","")).path
                passed=(status==302 and actual_path=="/login") or status==403
            else:
                passed=(status==expected)
            if status==200 and path!="/up" and b"<html" not in body.lower():
                passed=False
            if any(s in body for s in (b"SQLSTATE[",b"APP_KEY=",b"DB_PASSWORD=",b"Stack trace:")):
                passed=False
            results.append({"path":path,"status":status,"pass":passed})
            label=path.strip("/").replace("/","_").replace("-","_").upper() or "HOME"
            print("HTTP_"+label+"="+str(status)+("_PASS" if passed else "_BLOCKED"))
            if not passed:
                raise RuntimeError("Loopback HTTP acceptance failed: "+path)
finally:
    if server is not None:
        if server.poll() is None:
            try: os.killpg(server.pid,signal.SIGTERM)
            except ProcessLookupError: pass
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            try: os.killpg(server.pid,signal.SIGKILL)
            except ProcessLookupError: pass
            server.wait(timeout=5)
    if created:
        if (marker_path.is_file() and not marker_path.is_symlink() and
            marker_path.read_bytes()==marker):
            marker_path.unlink()
        else:
            raise RuntimeError("Temporary recovery marker changed: manual inspection required")
    (evidence/"responses.json").write_text(json.dumps(results,indent=2)+"\n")
if marker_path.exists() or marker_path.is_symlink():
    raise RuntimeError("Temporary recovery marker remains")
print("TEMP_RECOVERY_INSTALL_MARKER=REMOVED")
print("LOOPBACK_SERVER=STOPPED")
print("HTTP_READ_ONLY_RECOVERY_ROUTES=PASS")
PY
QA_AFTER="$(get_hash "$QA" "$QA_DB" "$QA_USER")" ||
  fail 'Source QA inventory read failed after HTTP'
APP_AFTER="$(get_hash "$APP" "$APP_DB" "$APP_USER")" ||
  fail 'Recovery inventory read failed after HTTP'
[[ "$QA_BEFORE" == "$QA_AFTER" && "$APP_BEFORE" == "$APP_AFTER" ]] ||
  fail 'Unexpected source QA or restore DB changes during GET-only smoke'
[[ ! -e "$APP/storage/app/installed.lock" ]] ||
  fail 'Temporary recovery marker was not removed'
echo 'SOURCE_AND_RECOVERY_DB_INVENTORIES=UNCHANGED'
echo 'HTTP_LISTENER=STOPPED_LOOPBACK_ONLY'
echo 'ORIGINAL_QA=UNMODIFIED'
echo 'PRODUCTION=UNTOUCHED_BY_SCRIPT'
echo "PRIVATE_HTTP_EVIDENCE=$OUT"
echo 'RESULT=RECOVERY_LOOPBACK_GET_ACCEPTANCE_PASS'
echo 'NEXT=QA_ONLY_AUTHENTICATED_SYNTHETIC_TESTS_AND_DEDICATED_HTTPS'
