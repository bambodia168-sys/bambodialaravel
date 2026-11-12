#!/usr/bin/env bash
# Bambodia #35 Stage 10: real six-role localhost auth and default-off acceptance.
# The *only* writable application/DB is the disposable Stage 9 synthetic QA.
# No public Nginx, production, original QA, or recovered QA modifications.
set -Eeuo pipefail
set +x
umask 077
MODE=--check
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == --check || "$MODE" == --probe ) ]] || {
  echo 'Usage: --check|--probe' >&2; exit 2;
}
fail(){ echo "BLOCKED=$*" >&2; exit 2; }

PROD=/www/wwwroot/bambodia.com
QA=/www/wwwroot/bambodia-preview-qa
REC=/var/lib/bambodia-pvr-9e5847bc21b8
APP=/var/lib/bambodia-pvt-53309a26f4cd
DB=bambodia_pvt_53309a26f4cd
DBUSER=b_pvt_53309a26f4cd
QA_DB=bambodia_pvqa_29c824170b10
QA_USER=b_pvqa_29c824170b10
REC_DB=bambodia_pvr_9e5847bc21b8
REC_USER=b_pvr_9e5847bc21b8
EVIDENCE=/root/Bambodia-PreviewQA-Synthetic-Stage9/fixture-53309a26f4cd.YWeq6qDu
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
PHP=/www/server/php/85/bin/php

[[ "$(id -u)" == 0 ]] || fail 'Authorized aaPanel root shell required'
for bin in git python3 realpath runuser stat grep; do
  command -v "$bin" >/dev/null || fail "Missing $bin"
done
[[ -x "$PHP" ]] || fail 'Missing PHP 8.5'
for p in /www /www/wwwroot /var /var/lib "$PROD" "$QA" "$REC" "$APP" "$EVIDENCE"; do
  [[ ! -L "$p" ]] || fail 'Protected path contains symlink'
done
for dir in "$PROD" "$QA" "$REC" "$APP"; do
  [[ -f "$dir/artisan" && "$(realpath -e "$dir")" == "$dir" ]] ||
    fail 'Application path unavailable or unexpected'
done
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" &&
   ! -e "$APP/.git" && ! -e "$REC/.git" ]] ||
  fail 'Reviewed QA source or private runtime identity mismatch'
[[ ! -e "$APP/storage/app/installed.lock" &&
   ! -L "$APP/storage/app/installed.lock" &&
   ! -e "$QA/storage/app/installed.lock" &&
   ! -e "$REC/storage/app/installed.lock" &&
   ! -e "$APP/bootstrap/cache/config.php" ]] ||
  fail 'Unexpected install marker or cached configuration'
for p in "$APP/.env" "$REC/.env" "$QA/.env"; do
  [[ -f "$p" && ! -L "$p" && "$(stat -c %a "$p")" == 600 &&
    "$(stat -c %U:%G "$p")" == www:www ]] ||
    fail 'Private environment ownership/permissions invalid'
done
[[ -d "$EVIDENCE" && "$(stat -c %a "$EVIDENCE")" == 700 &&
   "$(stat -c %U:%G "$EVIDENCE")" == root:root &&
   -f "$EVIDENCE/accounts.json" &&
   "$(stat -c %a "$EVIDENCE/accounts.json")" == 600 &&
   "$(stat -c %U:%G "$EVIDENCE/accounts.json")" == root:root ]] ||
  fail 'Private Stage 9 synthetic identity file missing/unsafe'
for f in artisan bootstrap/app.php public/index.php routes/web.php \
  vendor/autoload.php config/preview_visibility.php \
  resources/views/auth/login.blade.php \
  resources/views/dashboard/preview-visibility.blade.php; do
  [[ -s "$QA/$f" && -s "$REC/$f" && -s "$APP/$f" &&
     ! -L "$APP/$f" &&
     "$(git hash-object "$QA/$f")" == "$(git hash-object "$REC/$f")" &&
     "$(git hash-object "$QA/$f")" == "$(git hash-object "$APP/$f")" ]] ||
    fail "Source/runtime checksum differs: $f"
done
if [[ -d /www/server/panel/vhost/nginx ]] &&
   grep -rFq "$APP" /www/server/panel/vhost/nginx; then
  fail 'Synthetic QA referenced by public Nginx'
fi
id www >/dev/null || fail 'aaPanel www user unavailable'
for ext in pdo_mysql mbstring openssl fileinfo; do
  "$PHP" -r 'exit(extension_loaded($argv[1])?0:1);' "$ext" ||
    fail "Missing PHP extension: $ext"
done

python3 - "$QA/.env" "$REC/.env" "$APP/.env" \
  "$QA_DB" "$QA_USER" "$REC_DB" "$REC_USER" "$DB" "$DBUSER" \
  "$EVIDENCE/accounts.json" <<'PY'
import json,re,sys
from pathlib import Path
def parse(p):
    d={}
    for line in Path(p).read_text().splitlines():
        if line and not line.lstrip().startswith("#") and "=" in line:
            k,v=line.split("=",1)
            d[k.strip()]=v.strip().strip("'").strip('"')
    return d
for label,path,db,user,prefix in [
    ("SOURCE",sys.argv[1],sys.argv[4],sys.argv[5],"bambodia_pvqa_"),
    ("RECOVERY",sys.argv[2],sys.argv[6],sys.argv[7],"bambodia_pvr_"),
    ("SYNTHETIC",sys.argv[3],sys.argv[8],sys.argv[9],"bambodia_pvt_")
]:
    e=parse(path)
    for k,val in {
        "APP_ENV":"staging","APP_DEBUG":"false",
        "APP_URL":"http://127.0.0.1","DB_CONNECTION":"mysql",
        "DB_HOST":"127.0.0.1","DB_PORT":"3306",
        "DB_DATABASE":db,"DB_USERNAME":user,
        "SESSION_DRIVER":"file","CACHE_STORE":"file",
        "QUEUE_CONNECTION":"sync","MAIL_MAILER":"array",
        "FILESYSTEM_DISK":"local","MEDIA_LIBRARY_DISK":"local",
        "PREVIEW_VISIBILITY_WRITES_ENABLED":"false"
    }.items():
        if e.get(k)!=val:
            raise SystemExit("BLOCKED=unsafe "+label+" env key "+k)
    if not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=",e.get("APP_KEY","")):
        raise SystemExit("BLOCKED=invalid "+label+" application key")
    if not re.fullmatch(r"[A-Za-z0-9_-]{32,100}",e.get("DB_PASSWORD","")):
        raise SystemExit("BLOCKED=invalid "+label+" MySQL secret")
    for k in ("DB_URL","DB_SOCKET","REDIS_URL","SESSION_CONNECTION"):
        if e.get(k,"").lower() not in ("","null"):
            raise SystemExit("BLOCKED=unsupported "+label+" connection "+k)
    for k in ("SESSION_COOKIE","CACHE_PREFIX"):
        if not e.get(k,"").startswith(prefix):
            raise SystemExit("BLOCKED=unsafe "+label+" session/cache namespace")
accounts=json.loads(Path(sys.argv[10]).read_text())
if list(accounts)!=["super_admin","admin","staff","freelancer","customer","outsider"]:
    raise SystemExit("BLOCKED=synthetic accounts list mismatch")
for alias,expected in [
    ("super_admin","super_admin"),("admin","admin"),("staff","staff"),
    ("freelancer","freelancer"),("customer","customer"),("outsider","subscriber")
]:
    a=accounts[alias]
    if a["role"]!=expected or not a["email"].endswith("@example.invalid") or len(a["password"])<32:
        raise SystemExit("BLOCKED=invalid synthetic account identity")
print("ALL_THREE_QA_ENVIRONMENTS=ISOLATED_WRITE_OFF")
print("SIX_TEST_ONLY_IDENTITIES=VERIFIED")
PY

# Every-table counts+extended checksums for protected source and recovery DBs.
INVENTORY="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false||
 config("database.connections.mysql.database")!==$argv[1]||
 config("database.connections.mysql.username")!==$argv[2])exit(2);
$p=\Illuminate\Support\Facades\DB::connection()->getPdo();
if($p->query("SELECT DATABASE()")->fetchColumn()!==$argv[1])exit(3);
$q=$p->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
$result=[];
while(($t=$q->fetchColumn())!==false){
 if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t))exit(4);
 $name=chr(96).$t.chr(96);
 $n=$p->query("SELECT COUNT(*) FROM ".$name)->fetchColumn();
 $sum=$p->query("CHECKSUM TABLE ".$name." EXTENDED")->fetch(\PDO::FETCH_NUM)[1]??null;
 if(!is_numeric($sum))exit(5);
 $result[]=[$t,(string)$n,(string)$sum];
}
if(count($result)!==81||
 (int)$p->query("SELECT COUNT(*) FROM migrations")->fetchColumn()!==62)exit(6);
echo hash("sha256",json_encode($result,JSON_THROW_ON_ERROR));
PHP
)"
fingerprint(){
  (cd "$1" && runuser -u www -- env -i HOME="$1" \
    PATH=/usr/bin:/bin:/usr/local/bin "$PHP" -r "$INVENTORY" "$2" "$3")
}
QA_BEFORE="$(fingerprint "$QA" "$QA_DB" "$QA_USER")" ||
  fail 'Could not fingerprint original QA'
REC_BEFORE="$(fingerprint "$REC" "$REC_DB" "$REC_USER")" ||
  fail 'Could not fingerprint verified recovery'
[[ "$QA_BEFORE" == "$REC_BEFORE" && "$QA_BEFORE" =~ ^[0-9a-f]{64}$ ]] ||
  fail 'Protected QA and recovery tables differ'

FIXTURE="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false||
 config("database.connections.mysql.database")!==$argv[1]||
 config("database.connections.mysql.username")!==$argv[2])exit(2);
$db=\Illuminate\Support\Facades\DB::connection();
foreach([
 "users"=>6,"marketplace_listings"=>2,"marketplace_jobs"=>2,
 "saved_listings"=>1,"job_proposals"=>1,
 "preview_visibility_actions"=>0,"account_notifications"=>0,"audit_events"=>0
] as $t=>$n){if((int)$db->table($t)->count()!==$n)exit(3);}
foreach(["marketplace_listings","marketplace_jobs"] as $t){
 if((int)$db->table($t)->where("status","published")->count()!==2)exit(4);
}
foreach(["super_admin","admin","staff","freelancer","customer","subscriber"] as $role){
 if((int)$db->table("users")->where("role",$role)->count()!==1)exit(5);
}
echo "SYNTHETIC_FIXTURES=PASS\n";
PHP
)"
(cd "$APP" && runuser -u www -- env -i HOME="$APP" \
  PATH=/usr/bin:/bin:/usr/local/bin "$PHP" -r "$FIXTURE" "$DB" "$DBUSER") ||
  fail 'Synthetic fixture dataset already modified or invalid'

echo '========== BAMBODIA STAGE 10 SIX-ROLE HTTP PREFLIGHT =========='
echo "MODE=$MODE"
echo 'SOURCE_AND_RECOVERY_81_TABLE_CHECKSUMS=MATCH'
echo 'SYNTHETIC_ROLES=6'
echo 'SYNTHETIC_LISTINGS=2'
echo 'SYNTHETIC_JOBS=2'
echo 'PREVIEW_VISIBILITY_WRITES=OFF'
echo 'PRODUCTION=NOT_A_TARGET'
if [[ "$MODE" == --check ]]; then
  echo 'HTTP_LISTENER=NOT_STARTED'
  echo 'RESULT=READY_FOR_SIX_ROLE_LOCALHOST_AUTH_QA'
  exit 0
fi

OUT="$(mktemp -d "$EVIDENCE/stage10-http-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$OUT"

python3 - "$APP" "$PHP" "$EVIDENCE/accounts.json" "$OUT" <<'PY'
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode,urlsplit
from urllib.request import (
  Request,build_opener,HTTPCookieProcessor,HTTPRedirectHandler,ProxyHandler
)
import json,os,pwd,grp,re,signal,socket,subprocess,sys,time
from pathlib import Path
app,php,cred,evidence=Path(sys.argv[1]),sys.argv[2],Path(sys.argv[3]),Path(sys.argv[4])
accounts=json.loads(cred.read_text())
marker=app/"storage/app/installed.lock"
if marker.exists() or marker.is_symlink():
    raise SystemExit("BLOCKED=synthetic installation marker already exists")
marker_bytes=b"Bambodia synthetic Stage10 temporary HTTP validation\n"
server=None
created=False
results=[]
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*args):
        return None
class Token(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=="meta" and a.get("name")=="csrf-token":
            self.value=a.get("content")
        if tag=="input" and a.get("name")=="_token":
            self.value=a.get("value")
def require(ok,label):
    if not ok:raise RuntimeError(label)
try:
    flags=os.O_WRONLY|os.O_CREAT|os.O_EXCL
    if hasattr(os,"O_NOFOLLOW"):flags|=os.O_NOFOLLOW
    fd=os.open(marker,flags,0o600)
    created=True
    with os.fdopen(fd,"wb") as f:
        f.write(marker_bytes)
        f.flush()
        os.fsync(f.fileno())
    os.chown(marker,pwd.getpwnam("www").pw_uid,grp.getgrnam("www").gr_gid)
    os.chmod(marker,0o600)
    with socket.socket() as s:
        s.bind(("127.0.0.1",0))
        port=s.getsockname()[1]
    base="http://127.0.0.1:"+str(port)
    print("SYNTHETIC_HTTP_BIND=127.0.0.1_ONLY")
    with (evidence/"server.log").open("wb") as log:
        server=subprocess.Popen(
          ["runuser","-u","www","--",php,"-S","127.0.0.1:"+str(port),
           "-t","public","public/index.php"],
          cwd=str(app),stdout=log,stderr=subprocess.STDOUT,
          start_new_session=True,close_fds=True)
        def opener():
            return build_opener(ProxyHandler({}),HTTPCookieProcessor(CookieJar()),NoRedirect())
        def req(client,path,form=None):
            body=None if form is None else urlencode(form).encode("utf-8")
            r=Request(base+path,data=body,method="GET" if form is None else "POST",
              headers={"Accept":"text/html","User-Agent":"Bambodia-Synthetic-Stage10"})
            try:
                response=client.open(r,timeout=8)
            except HTTPError as err:
                response=err
            with response:
                return (
                  response.status,
                  response.headers.get("Location",""),
                  response.read(2*1024*1024)
                )
        ready=False
        for _ in range(40):
            if server.poll() is not None:break
            try:
                code,_,_=req(opener(),"/up")
                if code==200:
                    ready=True
                    break
            except Exception:
                time.sleep(0.2)
        require(ready,"Synthetic local PHP server did not start")
        status,loc,_=req(opener(),"/dashboard/preview-content")
        require(status==302 and urlsplit(loc).path=="/login",
                "Guest preview page was not redirected to login")
        print("GUEST_PREVIEW=302_LOGIN_PASS")
        super_form=None
        for role in ["super_admin","admin","staff","freelancer","customer","outsider"]:
            a=accounts[role]
            client=opener()
            code,_,body=req(client,"/login")
            require(code==200,"Login GET unavailable for "+role)
            tok=Token();tok.feed(body.decode("utf-8","replace"))
            require(tok.value and len(tok.value)>15,"CSRF unavailable for "+role)
            code,location,_=req(client,"/login",{
              "_token":tok.value,"email":a["email"],"password":a["password"]
            })
            require(code==302 and urlsplit(location).path in ("/dashboard","/account"),
                    "Synthetic login failed for "+role)
            code,_,html=req(client,"/dashboard/preview-content")
            expected=200 if role=="super_admin" else 403
            require(code==expected,"Preview GET permission failed for "+role)
            if role=="super_admin":
                require(b"Changes locked" in html,
                        "Super-admin preview page not locked")
                require(b"Stage9 Synthetic Clean Service" in html and
                        b"Stage9 Synthetic Saved Service" in html,
                        "Synthetic review records not shown")
                tok=Token();tok.feed(html.decode("utf-8","replace"))
                require(tok.value and len(tok.value)>15,
                        "Authenticated administrator CSRF not found")
                match=re.search(
                  r'action="[^"]*/dashboard/preview-content/listing/([0-9]{9})/withdraw"'
                  r'[\s\S]{0,1400}?name="expected_fingerprint" value="([a-f0-9]{64})"',
                  html.decode("utf-8","replace")
                )
                require(match is not None,"Synthetic reviewed withdrawal form missing")
                super_form=(client,tok.value,match.group(1),match.group(2))
                code,_,job_html=req(client,"/dashboard/preview-content?kind=job")
                require(code==200 and b"Stage9 Synthetic Clean Job" in job_html,
                        "Synthetic preview jobs invisible to super-admin")
                print("SUPER_ADMIN_LISTINGS_AND_JOBS=200_LOCKED_PASS")
            else:
                print("ROLE_"+role.upper()+"_PREVIEW=403_PASS")
            results.append({"role":role,"authenticated":True,"preview_get":expected})
        require(super_form is not None,"No super-admin form to verify write gate")
        client,csrf,public_id,fingerprint=super_form
        code,_,_=req(client,"/dashboard/preview-content/listing/"+public_id+"/withdraw",{
          "_token":csrf,"_method":"PATCH",
          "confirm_public_id":public_id,
          "expected_fingerprint":fingerprint,
          "owner_confirmation_reference":"SYNTHETIC-OWNER-REVIEW-EVIDENCE",
          "backup_reference":"SYNTHETIC-QA-BACKUP-EVIDENCE",
          "reason":"Synthetic private acceptance with withdrawal switch disabled.",
          "acknowledge":"1"
        })
        require(code==403,"Default-off super-admin withdrawal not forbidden")
        print("SUPER_ADMIN_WITHDRAW_DEFAULT_OFF=403_PASS")
finally:
    if server is not None:
        if server.poll() is None:
            try:os.killpg(server.pid,signal.SIGTERM)
            except ProcessLookupError:pass
        try:server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            try:os.killpg(server.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            server.wait(timeout=5)
    if created:
        if marker.is_file() and not marker.is_symlink() and marker.read_bytes()==marker_bytes:
            marker.unlink()
        else:
            raise RuntimeError("Temporary QA installed marker changed; manual inspection needed")
    (evidence/"role-results.json").write_text(json.dumps(results,indent=2)+"\n")
if marker.exists() or marker.is_symlink():
    raise SystemExit("BLOCKED=synthetic install marker not removed")
print("TEMP_SYNTHETIC_INSTALLED_MARKER=REMOVED")
print("SYNTHETIC_HTTP_SERVER=STOPPED")
print("SIX_ROLE_HTTP_TEST=PASS")
PY

POST_ASSERT="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false||
 config("database.connections.mysql.database")!==$argv[1]||
 config("database.connections.mysql.username")!==$argv[2])exit(2);
$db=\Illuminate\Support\Facades\DB::connection();
foreach([
 "users"=>6,"marketplace_listings"=>2,"marketplace_jobs"=>2,
 "saved_listings"=>1,"job_proposals"=>1,
 "preview_visibility_actions"=>0,"account_notifications"=>0
] as $t=>$n){if((int)$db->table($t)->count()!==$n)exit(3);}
foreach(["marketplace_listings","marketplace_jobs"] as $t){
 if((int)$db->table($t)->where("status","published")->count()!==2)exit(4);
}
$success=(int)$db->table("audit_events")->where("event","auth.login.success")->count();
if($success!==6)exit(5);
echo "SYNTHETIC_LOGIN_AUDIT_EVENTS=6\n";
echo "SYNTHETIC_PREVIEW_HISTORY=0\n";
echo "SYNTHETIC_PUBLIC_RECORD_STATUS=UNCHANGED\n";
PHP
)"
(cd "$APP" && runuser -u www -- env -i HOME="$APP" \
 PATH=/usr/bin:/bin:/usr/local/bin "$PHP" -r "$POST_ASSERT" "$DB" "$DBUSER") ||
  fail 'Synthetic auth audit or preview write invariants failed'
QA_AFTER="$(fingerprint "$QA" "$QA_DB" "$QA_USER")" ||
  fail 'Original QA post-HTTP fingerprint failed'
REC_AFTER="$(fingerprint "$REC" "$REC_DB" "$REC_USER")" ||
  fail 'Recovery post-HTTP fingerprint failed'
[[ "$QA_BEFORE" == "$QA_AFTER" && "$REC_BEFORE" == "$REC_AFTER" ]] ||
  fail 'Protected baseline was changed'
echo 'ORIGINAL_QA_AND_RECOVERY_CHECKSUMS=UNCHANGED'
echo 'PRODUCTION=UNTOUCHED_BY_SCRIPT'
echo "PRIVATE_EVIDENCE_DIR=$OUT"
echo 'RESULT=STAGE10_SIX_ROLE_AUTH_AND_DEFAULT_OFF_PASS'
