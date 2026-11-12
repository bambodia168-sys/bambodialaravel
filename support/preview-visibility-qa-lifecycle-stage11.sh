#!/usr/bin/env bash
# Bambodia #35 Stage 11: private synthetic HTTP withdraw/restore lifecycle acceptance.
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

# Stage 10 completed before this step; distinguish test-only accounts by
# their already logged authentication events. One-time Stage 11 ONLY.
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
 "preview_visibility_actions"=>0,"account_notifications"=>0
] as $t=>$n){if((int)$db->table($t)->count()!==$n)exit(3);}
foreach(["marketplace_listings","marketplace_jobs"] as $t){
 if((int)$db->table($t)->where("status","published")->count()!==2)exit(4);
}
foreach(["super_admin","admin","staff","freelancer","customer","subscriber"] as $role){
 if((int)$db->table("users")->where("role",$role)->count()!==1)exit(5);
}
if((int)$db->table("audit_events")->count()!==6 ||
   (int)$db->table("audit_events")->where("event","auth.login.success")->count()!==6)exit(6);
echo "STAGE10_LOGIN_AUDITS=6_VERIFIED\n";
echo "SYNTHETIC_VISIBILITY_HISTORY=EMPTY\n";
PHP
)"
(cd "$APP" && runuser -u www -- env -i HOME="$APP" \
  PATH=/usr/bin:/bin:/usr/local/bin "$PHP" -r "$FIXTURE" "$DB" "$DBUSER") ||
  fail 'Stage10 synthetic database has unexpected records or audit events'

STAGE10_EVIDENCE="$EVIDENCE/stage10-http-20261112T043143Z.eRaWgMtv"
[[ -d "$STAGE10_EVIDENCE" && ! -L "$STAGE10_EVIDENCE" &&
   -f "$STAGE10_EVIDENCE/role-results.json" ]] ||
  fail 'Required Stage10 private HTTP acceptance evidence missing'
python3 - "$STAGE10_EVIDENCE/role-results.json" <<'PY'
import json,sys
from pathlib import Path
records=json.loads(Path(sys.argv[1]).read_text())
if len(records)!=6 or set(x.get("role") for x in records)!={
    "super_admin","admin","staff","freelancer","customer","outsider"
} or not all(x.get("authenticated") for x in records):
    raise SystemExit("BLOCKED=Stage10 six-role evidence incomplete")
print("STAGE10_SIX_ROLE_HTTP_EVIDENCE=VERIFIED")
PY

echo '========== BAMBODIA STAGE 11 SYNTHETIC WITHDRAW/RESTORE PREFLIGHT =========='
echo "MODE=$MODE"
echo 'ORIGINAL_QA_AND_RECOVERY=81_TABLE_CHECKSUMS_MATCH'
echo 'DISPOSABLE_USERS=6'
echo 'DISPOSABLE_LISTINGS=2'
echo 'DISPOSABLE_JOBS=2'
echo 'LINKED_RECORDS=SAVED_LISTING_AND_JOB_PROPOSAL'
echo 'SYNTHETIC_HISTORY=EMPTY'
echo 'SAVED_QA_ENV_WRITE_SWITCH=OFF'
echo 'PRODUCTION=NOT_A_TARGET'
if [[ "$MODE" == --check ]]; then
  echo 'MY_SQL_WRITES=NONE'
  echo 'HTTP_LISTENER=NOT_STARTED'
  echo 'RESULT=READY_FOR_ONE_TIME_SYNTHETIC_LIFECYCLE_ACCEPTANCE'
  exit 0
fi

# Store a private before-state backup of the disposable schema. No backup from
# production/source/recovery may be imported or modified here.
OUT="$(mktemp -d "$EVIDENCE/stage11-lifecycle-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$OUT"
TMP="$(mktemp -d /root/bambodia-stage11-secret.XXXXXXXX)"
cleanup(){
  local rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-stage11-secret.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT
python3 - "$APP/.env" "$TMP/disposable.cnf" "$DB" "$DBUSER" <<'PY'
from pathlib import Path
import os,re,sys
e={}
for line in Path(sys.argv[1]).read_text().splitlines():
    if line and not line.lstrip().startswith("#") and "=" in line:
        k,v=line.split("=",1)
        e[k.strip()]=v.strip().strip("'").strip('"')
if (e.get("DB_DATABASE")!=sys.argv[3] or
    e.get("DB_USERNAME")!=sys.argv[4] or
    e.get("DB_HOST")!="127.0.0.1" or
    e.get("PREVIEW_VISIBILITY_WRITES_ENABLED")!="false" or
    not re.fullmatch("[A-Za-z0-9_-]{32,100}",e.get("DB_PASSWORD",""))):
    raise SystemExit("BLOCKED=synthetic database config changed")
fd=os.open(sys.argv[2],os.O_CREAT|os.O_WRONLY|os.O_EXCL,0o600)
with os.fdopen(fd,"w") as f:
    f.write('[client]\nuser='+e["DB_USERNAME"]+
            '\npassword="'+e["DB_PASSWORD"]+
            '"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n')
PY

MYSQL=/www/server/mysql/bin/mysql
MYSQLDUMP=/www/server/mysql/bin/mysqldump
[[ -x "$MYSQL" && -x "$MYSQLDUMP" ]] ||
  fail 'MySQL client or mysqldump unavailable'
DISPOSABLE_DB="$( "$MYSQL" "--defaults-extra-file=$TMP/disposable.cnf" --batch --skip-column-names "$DB" -e 'SELECT DATABASE()' )" ||
  fail 'Disposable database cannot be opened'
[[ "$DISPOSABLE_DB" == "$DB" ]] ||
  fail 'MySQL target is not the authorized synthetic schema'

echo '========== BACK UP DISPOSABLE QA BEFORE ENABLED HTTP TEST =========='
if ! "$MYSQLDUMP" "--defaults-extra-file=$TMP/disposable.cnf" \
   --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
   --triggers --hex-blob "$DB" | gzip -c > "$OUT/synthetic-before.sql.gz"; then
  fail 'Disposable QA snapshot failed; no HTTP writes attempted'
fi
gzip -t "$OUT/synthetic-before.sql.gz" ||
  fail 'Disposable snapshot integrity failure; no HTTP writes attempted'
sha256sum "$OUT/synthetic-before.sql.gz" > "$OUT/synthetic-before.sha256"
(cd "$OUT" && sha256sum --check --status synthetic-before.sha256) ||
  fail 'Disposable snapshot checksum mismatch'
echo 'DISPOSABLE_PRE_WRITE_BACKUP=GZIP_SHA256_VERIFIED'

# Read-only list of IDs and fingerprinted dependency status. Never disclose
# private account credentials or use customer/prod data.
READ_FIXTURE="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false ||
 config("database.connections.mysql.database")!==$argv[1])exit(2);
$listing=\App\Models\MarketplaceListing::query()->get();
$jobs=\App\Models\MarketplaceJob::query()->get();
$out=[];
foreach([["listing",$listing],["job",$jobs]] as [$type,$set]){
 foreach($set as $item){
  $title=(string)$item->title;
  if(!str_starts_with($title,"[LAYOUT TEST] Stage9 Synthetic "))exit(3);
  $linked=str_contains($title,"Saved Service")||str_contains($title,"Applicant Job");
  $review=\App\Support\PreviewContentReview::record($type,(int)$item->id);
  if($review["customer_activity_blocked"]!==$linked)exit(4);
  $out[]=["type"=>$type,"public_id"=>(string)$item->public_id,
          "title"=>$title,"linked"=>$linked];
 }
}
if(count($out)!==4)exit(5);
echo json_encode($out,JSON_THROW_ON_ERROR);
PHP
)"
(cd "$APP" && runuser -u www -- env -i HOME="$APP" \
 PATH=/usr/bin:/bin:/usr/local/bin "$PHP" -r "$READ_FIXTURE" "$DB" ) \
 > "$OUT/fixtures.json" ||
  fail 'Synthetic fixture identity or dependency status not independently verified'
chmod 0600 "$OUT/fixtures.json"

echo '========== TEST PRIVATE HTTP LIFECYCLE: SYNTHETIC RECORDS ONLY =========='
python3 - "$APP" "$PHP" "$EVIDENCE/accounts.json" "$OUT" <<'PY'
from html import unescape
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode,urlsplit,urlparse
from urllib.request import Request,build_opener,HTTPCookieProcessor,HTTPRedirectHandler,ProxyHandler
import grp,json,os,pwd,re,signal,socket,subprocess,sys,time

app,php,cred,out=Path(sys.argv[1]),sys.argv[2],Path(sys.argv[3]),Path(sys.argv[4])
fixture=json.loads((out/"fixtures.json").read_text())
accounts=json.loads(cred.read_text())
marker=app/"storage/app/installed.lock"
marker_bytes=b"Bambodia Stage11 temporary disposable QA localhost HTTP acceptance\n"
if marker.exists() or marker.is_symlink():
    raise SystemExit("BLOCKED=unexpected installed marker before Stage11")
server=None
created=False
events=[]
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,*args):
        return None
class Token(HTMLParser):
    def __init__(self):
        super().__init__()
        self.value=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=="input" and a.get("name")=="_token":
            self.value=a.get("value")
        if tag=="meta" and a.get("name")=="csrf-token":
            self.value=a.get("content")
def must(ok,why):
    if not ok: raise RuntimeError(why)
def record(kind,label,code):
    events.append({"kind":kind,"action":label,"status":code})
    print("HTTP_"+kind.upper()+"_"+label.upper()+"="+str(code)+"_PASS")
try:
    flags=os.O_CREAT|os.O_EXCL|os.O_WRONLY
    if hasattr(os,"O_NOFOLLOW"): flags|=os.O_NOFOLLOW
    fd=os.open(marker,flags,0o600)
    created=True
    with os.fdopen(fd,"wb") as f:
        f.write(marker_bytes)
        f.flush()
        os.fsync(f.fileno())
    os.chown(marker,pwd.getpwnam("www").pw_uid,grp.getgrnam("www").gr_gid)
    os.chmod(marker,0o600)
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as bound:
        bound.bind(("127.0.0.1",0))
        port=bound.getsockname()[1]
    base="http://127.0.0.1:"+str(port)
    print("STAGE11_HTTP_BIND=127.0.0.1_ONLY")
    print("STAGE11_TEMP_PROCESS_WRITE_SWITCH=ENABLED_FOR_DISPOSABLE_APP_ONLY")
    with (out/"server.log").open("wb") as server_log:
        # -i strips all ambient environment; only this disposable process
        # receives the enabled switch. The actual .env is NEVER edited.
        server=subprocess.Popen(
          ["runuser","-u","www","--","env","-i",
           "HOME="+str(app),"PATH=/usr/bin:/bin:/usr/local/bin",
           "PREVIEW_VISIBILITY_WRITES_ENABLED=true",php,
           "-S","127.0.0.1:"+str(port),"-t","public","public/index.php"],
          cwd=str(app),stdout=server_log,stderr=subprocess.STDOUT,
          start_new_session=True,close_fds=True)
        def client():
            return build_opener(ProxyHandler({}),HTTPCookieProcessor(CookieJar()),NoRedirect())
        def req(opener,path,form=None):
            body=None if form is None else urlencode(form).encode("utf-8")
            request=Request(base+path,data=body,
              method="POST" if form is not None else "GET",
              headers={"Accept":"text/html","User-Agent":"Bambodia-Stage11-Synthetic-Lifecycle"})
            try:
                response=opener.open(request,timeout=8)
            except HTTPError as error:
                response=error
            with response:
                status=response.status
                target=response.headers.get("Location","")
                content=response.read(2*1024*1024)
                return status,target,content
        ready=False
        for _ in range(40):
            if server.poll() is not None:break
            try:
                status,_,_=req(client(),"/up")
                if status==200:
                    ready=True
                    break
            except Exception:
                time.sleep(0.2)
        must(ready,"Disposable localhost HTTP server not ready")
        admin=client()
        code,_,login=req(admin,"/login")
        must(code==200,"Synthetic admin login not available")
        token=Token()
        token.feed(login.decode("utf-8","replace"))
        must(token.value and len(token.value)>15,"Missing login CSRF token")
        code,target,_=req(admin,"/login",{
            "_token":token.value,
            "email":accounts["super_admin"]["email"],
            "password":accounts["super_admin"]["password"]
        })
        must(code==302 and urlsplit(target).path=="/dashboard",
             "Synthetic super-admin authentication failed")
        print("STAGE11_SYNTHETIC_SUPER_ADMIN_LOGIN=PASS")
        def get_forms(kind,status):
            uri="/dashboard/preview-content?kind="+kind+"&status="+status
            code,_,page=req(admin,uri)
            must(code==200,"Review screen unavailable for "+kind+":"+status)
            text=page.decode("utf-8","replace")
            if status=="published":
                must("Changes locked" not in text,
                     "Temporary disposable write switch was not effective")
            csrf=Token();csrf.feed(text)
            must(csrf.value and len(csrf.value)>15,"Review page missing CSRF")
            forms={}
            for m in re.finditer(
              r'<form method="post" action="([^"]+)">([\s\S]*?)</form>',
              text,re.I
            ):
                action=unescape(m.group(1))
                body=m.group(2)
                fp=re.search(
                  r'name="expected_fingerprint" value="([a-f0-9]{64})"',body
                )
                if fp:
                    url_path=urlparse(action).path
                    forms[url_path]=fp.group(1)
            return csrf.value,forms,text
        def form_path(kind,public_id,action):
            return "/dashboard/preview-content/"+kind+"/"+public_id+"/"+action
        def patch(kind,public_id,action,fingerprint,expect):
            csrf,forms,_=get_forms(kind,"published" if action=="withdraw" else "withdrawn")
            # On repeated withdrawal after successful action the withdrawn item
            # no longer appears under published status; use the provided original
            # fingerprint specifically for the duplicate request.
            path=form_path(kind,public_id,action)
            fields={
                "_token":csrf,"_method":"PATCH",
                "confirm_public_id":public_id,
                "expected_fingerprint":fingerprint,
                "owner_confirmation_reference":"SYNTHETIC-STAGE11-OWNER-CONSENT",
                "backup_reference":"SYNTHETIC-STAGE11-BACKUP-PROOF",
                "reason":"Stage11 isolated synthetic QA record, not a real customer decision.",
                "acknowledge":"1"
            }
            code,target,_=req(admin,path,fields)
            must(code==expect,
                 "Unexpected HTTP response for "+kind+":"+action+" expected "+str(expect)+" got "+str(code))
            return code,target
        mapping={}
        for item in fixture:
            key=(item["type"],"linked" if item["linked"] else "clean")
            must(key not in mapping,"Duplicate synthetic fixture grouping")
            mapping[key]=item["public_id"]
        must(len(mapping)==4,"Missing clean/linked fixture types")
        clean_listing=mapping[("listing","clean")]
        saved_listing=mapping[("listing","linked")]
        clean_job=mapping[("job","clean")]
        proposal_job=mapping[("job","linked")]
        form_data={}
        for kind,ids in [("listing",[clean_listing,saved_listing]),("job",[clean_job,proposal_job])]:
            csrf,forms,text=get_forms(kind,"published")
            for public_id in ids:
                path=form_path(kind,public_id,"withdraw")
                must(path in forms,"Expected exact marked fixture form missing")
                form_data[(kind,public_id)]=forms[path]
        # 1) stale snapshot must never write.
        code,_=patch("listing",clean_listing,"withdraw","0"*64,409)
        record("listing","stale_fingerprint_blocked",code)
        # 2) customers' saved services must remain visible and intact.
        code,_=patch("listing",saved_listing,"withdraw",
                     form_data[("listing",saved_listing)],409)
        record("listing","saved_customer_blocked",code)
        # 3) actual job applicant proposal must also prevent withdrawal.
        code,_=patch("job",proposal_job,"withdraw",
                     form_data[("job",proposal_job)],409)
        record("job","proposal_blocked",code)
        # 4) clean synthetic listing withdrawal and rejection of duplicate.
        code,_=patch("listing",clean_listing,"withdraw",
                     form_data[("listing",clean_listing)],302)
        record("listing","withdraw",code)
        code,_=patch("listing",clean_listing,"withdraw",
                     form_data[("listing",clean_listing)],409)
        record("listing","duplicate_blocked",code)
        # 5) clean job withdrawal.
        code,_=patch("job",clean_job,"withdraw",
                     form_data[("job",clean_job)],302)
        record("job","withdraw",code)
        # 6) restore each synthetic record to PENDING, never directly published.
        for kind,public_id in [("listing",clean_listing),("job",clean_job)]:
            _,forms,_=get_forms(kind,"withdrawn")
            path=form_path(kind,public_id,"restore")
            must(path in forms,"Restoration form/fingerprint absent")
            code,_=patch(kind,public_id,"restore",forms[path],302)
            record(kind,"restore_to_pending",code)
        # Read the published listing/job inventories: only the customer-linked
        # fixtures may remain published after restoration.
        for kind,clean,linked in [
            ("listing",clean_listing,saved_listing),
            ("job",clean_job,proposal_job)
        ]:
            _,forms,text=get_forms(kind,"published")
            must(form_path(kind,linked,"withdraw") in forms,
                 "Protected linked fixture no longer public")
            must(form_path(kind,clean,"withdraw") not in forms,
                 "Restored-to-pending fixture incorrectly remains public")
        print("STAGE11_HTTP_LIFECYCLE=8_CHECKS_PASS")
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
            raise RuntimeError("Temporary Stage11 marker altered: manual inspection needed")
    (out/"lifecycle-results.json").write_text(json.dumps(events,indent=2)+"\n")
if marker.exists() or marker.is_symlink():
    raise RuntimeError("Stage11 temporary installed marker remains")
print("STAGE11_TEMP_INSTALLED_MARKER=REMOVED")
print("STAGE11_HTTP_SERVER=STOPPED")
PY

echo '========== VERIFY IMMUTABLE SYNTHETIC AUDITS AND DEPENDENCIES =========='
ASSERT="$(cat <<'PHP'
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
 "preview_visibility_actions"=>4,"account_notifications"=>4
] as $table=>$count){
 if((int)$db->table($table)->count()!==$count)exit(3);
}
foreach(["marketplace_listings","marketplace_jobs"] as $table){
 if((int)$db->table($table)->where("status","pending")->count()!==1 ||
    (int)$db->table($table)->where("status","published")->count()!==1)exit(4);
}
$actions=$db->table("preview_visibility_actions")->orderBy("id")->get();
foreach(["withdraw","withdraw","restore","restore"] as $idx=>$expected){
 if($actions[$idx]->action!==$expected)exit(5);
 if($actions[$idx]->from_status!==($expected==="withdraw"?"published":"withdrawn"))exit(6);
 if($actions[$idx]->to_status!==($expected==="withdraw"?"withdrawn":"pending"))exit(7);
}
if((int)$db->table("audit_events")->where("event","auth.login.success")->count()!==7)exit(8);
if((int)$db->table("preview_visibility_actions")
   ->where("subject_kind","listing")->count()!==2 ||
   (int)$db->table("preview_visibility_actions")
   ->where("subject_kind","job")->count()!==2)exit(9);
echo "SYNTHETIC_LISTING_AND_JOB_RETAINED=4\n";
echo "CLEAN_SYNTHETIC_ITEMS=RESTORED_PENDING\n";
echo "CUSTOMER_LINKED_ITEMS=STILL_PUBLISHED\n";
echo "IMMUTABLE_VISIBILITY_AUDIT_EVENTS=4\n";
echo "OWNER_ACCOUNT_NOTIFICATIONS=4\n";
echo "TEST_ONLY_LOGIN_AUDITS=7\n";
echo "SAVED_QA_ENV_WRITE_SWITCH=OFF\n";
PHP
)"
(cd "$APP" && runuser -u www -- env -i HOME="$APP" \
 PATH=/usr/bin:/bin:/usr/local/bin "$PHP" -r "$ASSERT" "$DB" "$DBUSER") ||
  fail 'Disposable QA lifecycle evidence differs from expected'

QA_AFTER="$(fingerprint "$QA" "$QA_DB" "$QA_USER")" ||
  fail 'Original QA database fingerprint failed after lifecycle'
REC_AFTER="$(fingerprint "$REC" "$REC_DB" "$REC_USER")" ||
  fail 'Recovered QA database fingerprint failed after lifecycle'
[[ "$QA_BEFORE" == "$QA_AFTER" && "$REC_BEFORE" == "$REC_AFTER" ]] ||
  fail 'Original or recovery QA baseline changed during synthetic write test'
[[ ! -e "$APP/storage/app/installed.lock" ]] ||
  fail 'Synthetic install marker not removed'

echo 'ORIGINAL_QA_AND_RECOVERY_CHECKSUMS=UNCHANGED'
echo 'PRODUCTION=UNTOUCHED_BY_SCRIPT'
echo "PRIVATE_EVIDENCE_DIR=$OUT"
echo 'HTTP_LOCALHOST_LISTENER=STOPPED'
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE_IN_ALL_SAVED_ENVIRONMENTS'
echo 'RESULT=STAGE11_SYNTHETIC_WITHDRAW_RESTORE_LIFECYCLE_PASS'
echo 'NEXT=SEPARATE_HTTPS_DEVICE_AND_INDEPENDENT_RELEASE_REVIEW'
