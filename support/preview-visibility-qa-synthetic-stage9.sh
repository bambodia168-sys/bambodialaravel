#!/usr/bin/env bash
# Bambodia Issue #35, Stage 9: NEW disposable MySQL + private Laravel workspace.
# Seeds only synthetic users, listings and jobs; original QA/recovery are read-only.
# Absolutely no production, public vhost, existing-schema DDL, or withdrawal writes.
set -Eeuo pipefail
set +x
umask 077

MODE=--check
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == --check || "$MODE" == --prepare ) ]] || {
  echo 'Usage: --check|--prepare' >&2; exit 2;
}
fail(){ echo "BLOCKED=$*" >&2; exit 2; }

QA=/www/wwwroot/bambodia-preview-qa
REC=/var/lib/bambodia-pvr-9e5847bc21b8
PROD=/www/wwwroot/bambodia.com
QA_DB=bambodia_pvqa_29c824170b10
REC_DB=bambodia_pvr_9e5847bc21b8
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
BACKUP=/root/Bambodia-PreviewQA-Migration-Backups/qa-migration-20261112T030423Z.7QncYRhO
EVIDENCE=/root/Bambodia-PreviewQA-Synthetic-Stage9
PHP=/www/server/php/85/bin/php
MYSQL=/www/server/mysql/bin/mysql

[[ "$(id -u)" == 0 ]] || fail 'Run in the authorized aaPanel root terminal'
for bin in git python3 realpath stat sha256sum gzip mktemp runuser awk cmp grep wc; do
  command -v "$bin" >/dev/null || fail "Required command unavailable: $bin"
done
[[ -x "$PHP" && -x "$MYSQL" ]] || fail 'aaPanel PHP/MySQL missing'
[[ ! -L /www && ! -L /www/wwwroot && ! -L /var && ! -L /var/lib &&
   ! -L "$QA" && ! -L "$REC" && ! -L "$PROD" && ! -L "$BACKUP" ]] ||
  fail 'Symlink detected in protected directory path'
for dir in "$QA" "$REC" "$PROD"; do
  [[ -f "$dir/artisan" && "$(realpath -e "$dir")" == "$dir" ]] ||
    fail "Protected application missing or linked: $dir"
done
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'Approved QA source SHA mismatch'
[[ ! -e "$REC/.git" && ! -e "$REC/storage/app/installed.lock" &&
   ! -e "$QA/storage/app/installed.lock" ]] ||
  fail 'Unexpected source/recovery installer or repository state'
for file in artisan bootstrap/app.php public/index.php routes/web.php \
  app/Models/User.php app/Models/MarketplaceListing.php \
  app/Models/MarketplaceJob.php vendor/autoload.php \
  public/build/manifest.json config/preview_visibility.php; do
  [[ -s "$QA/$file" && -s "$REC/$file" &&
     ! -L "$QA/$file" && ! -L "$REC/$file" ]] ||
    fail "Missing or linked source file: $file"
  [[ "$(git hash-object "$QA/$file")" == "$(git hash-object "$REC/$file")" ]] ||
    fail "Recovery code hash differs: $file"
done
for file in "$QA/.env" "$REC/.env"; do
  [[ -f "$file" && ! -L "$file" && "$(stat -c %a "$file")" == 600 &&
     "$(stat -c %U:%G "$file")" == www:www ]] ||
    fail 'Private environment ownership or permissions invalid'
done
[[ -d "$BACKUP" && ! -L "$BACKUP" &&
   "$(stat -c %U:%G "$BACKUP")" == root:root &&
   "$(stat -c %a "$BACKUP")" == 700 ]] ||
  fail 'Stage 6 backup missing or not privately owned'
(cd "$BACKUP" &&
  sha256sum --check --status post-migration-checksum.sha256 &&
  gzip -t qa-after-schema.sql.gz) ||
  fail 'Approved Stage 6 schema backup corrupt'
if [[ -d /www/server/panel/vhost/nginx ]] &&
   grep -rEq 'bambodia-pvr-9e5847bc21b8|bambodia-preview-qa' \
   /www/server/panel/vhost/nginx; then
  fail 'QA/recovery unexpectedly referenced by production Nginx configuration'
fi
id www >/dev/null || fail 'Missing aaPanel www service user'
"$PHP" -r 'exit(extension_loaded("pdo_mysql")?0:1);' ||
  fail 'pdo_mysql extension unavailable'

TMP="$(mktemp -d /root/bambodia-pvt9.XXXXXXXX)"
cleanup(){
  rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-pvt9.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT

python3 - "$QA/.env" "$REC/.env" "$TMP/qa.cnf" "$TMP/rec.cnf" \
  "$QA_DB" "$REC_DB" <<'PY'
import os,re,sys
from pathlib import Path
for label,filename,out,db,userprefix in (
    ("QA",sys.argv[1],sys.argv[3],sys.argv[5],"b_pvqa_"),
    ("RECOVERY",sys.argv[2],sys.argv[4],sys.argv[6],"b_pvr_")):
    env={}
    for line in Path(filename).read_text().splitlines():
        if line and not line.lstrip().startswith("#") and "=" in line:
            k,v=line.split("=",1)
            env[k.strip()]=v.strip().strip("'").strip('"')
    expected={"APP_ENV":"staging","APP_DEBUG":"false",
        "APP_URL":"http://127.0.0.1","DB_CONNECTION":"mysql",
        "DB_HOST":"127.0.0.1","DB_DATABASE":db,
        "SESSION_DRIVER":"file","CACHE_STORE":"file","QUEUE_CONNECTION":"sync",
        "MAIL_MAILER":"array","PREVIEW_VISIBILITY_WRITES_ENABLED":"false"}
    for k,want in expected.items():
        if env.get(k)!=want:
            raise SystemExit("BLOCKED=unsafe "+label+" configuration: "+k)
    if not env.get("DB_USERNAME","").startswith(userprefix):
        raise SystemExit("BLOCKED="+label+" unexpected DB user")
    if (not re.fullmatch("[A-Za-z0-9_-]{32,100}",env.get("DB_PASSWORD","")) or
        not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=",env.get("APP_KEY",""))):
        raise SystemExit("BLOCKED="+label+" unrecognized credentials")
    if env.get("DB_URL","").lower() not in ("","null"):
        raise SystemExit("BLOCKED="+label+" DB_URL override")
    fd=os.open(out,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,"w") as f:
        f.write("[client]\nuser="+env["DB_USERNAME"]+
                '\npassword="'+env["DB_PASSWORD"]+
                '"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n')
print("SOURCE_AND_RECOVERY_ENV=ISOLATED_DEFAULT_OFF")
PY

qa_mysql(){ "$MYSQL" "--defaults-extra-file=$TMP/qa.cnf" --batch --raw --skip-column-names "$QA_DB" "$@"; }
rec_mysql(){ "$MYSQL" "--defaults-extra-file=$TMP/rec.cnf" --batch --raw --skip-column-names "$REC_DB" "$@"; }
[[ "$(qa_mysql -e 'SELECT DATABASE()')" == "$QA_DB" &&
   "$(rec_mysql -e 'SELECT DATABASE()')" == "$REC_DB" ]] ||
  fail 'Source/recovery schema mismatched'
qa_mysql -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" > "$TMP/tables"
[[ "$(wc -l < "$TMP/tables")" == 81 ]] ||
  fail 'Expected 81 source tables'
inventory(){
  local kind="$1" output="$2" table n hash
  : > "$output"
  while IFS= read -r table; do
    [[ "$table" =~ ^[a-zA-Z0-9_]+$ ]] ||
      fail 'Unsafe table name in baseline'
    if [[ "$kind" == qa ]]; then
      n="$(qa_mysql -e "SELECT COUNT(*) FROM $table")" || fail 'QA count failed'
      hash="$(qa_mysql -e "CHECKSUM TABLE $table EXTENDED" | awk '{print $NF}')" ||
        fail 'QA checksum failed'
    elif [[ "$kind" == recovery ]]; then
      n="$(rec_mysql -e "SELECT COUNT(*) FROM $table")" || fail 'Restore count failed'
      hash="$(rec_mysql -e "CHECKSUM TABLE $table EXTENDED" | awk '{print $NF}')" ||
        fail 'Restore checksum failed'
    else
      n="$(test_mysql -e "SELECT COUNT(*) FROM $table")" || fail 'Test DB count failed'
      hash="$(test_mysql -e "CHECKSUM TABLE $table EXTENDED" | awk '{print $NF}')" ||
        fail 'Test DB checksum failed'
    fi
    [[ "$n" =~ ^[0-9]+$ && "$hash" =~ ^[0-9]+$ ]] ||
      fail 'Non-numeric row count or checksum'
    printf '%s|%s|%s\n' "$table" "$n" "$hash" >> "$output"
  done < "$TMP/tables"
}
inventory qa "$TMP/qa-before"
inventory recovery "$TMP/rec-before"
cmp -s "$TMP/qa-before" "$TMP/rec-before" ||
  fail 'Source QA and restored DB do not match'
[[ "$(qa_mysql -e 'SELECT COUNT(*) FROM migrations')" == 62 ]] ||
  fail 'Expected 62 migrations'
for t in users marketplace_listings marketplace_jobs preview_visibility_actions; do
  [[ "$(qa_mysql -e "SELECT COUNT(*) FROM $t")" == 0 ]] ||
    fail 'Source QA already contains customer or history records'
done
echo '========== BAMBODIA STAGE 9 SYNTHETIC QA PREFLIGHT =========='
echo "MODE=$MODE"
echo "SOURCE_SHA=$SHA"
echo 'SOURCE_AND_RECOVERY_INVENTORY=81_TABLES_MATCH'
echo 'QA_MIGRATIONS=62'
echo 'SOURCE_CUSTOMER_CONTENT=ZERO'
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE'
echo 'PRODUCTION=NOT_A_TARGET'
if [[ "$MODE" == --check ]]; then
  echo 'SYNTHETIC_MYSQL_AND_APP=NOT_CREATED'
  echo 'RESULT=READY_FOR_SEPARATE_SYNTHETIC_QA_WORKSPACE'
  exit 0
fi

SUFFIX="$(python3 -c 'import secrets;print(secrets.token_hex(6))')"
DB="bambodia_pvt_$SUFFIX"
USER="b_pvt_$SUFFIX"
APP="/var/lib/bambodia-pvt-$SUFFIX"
[[ "$DB" =~ ^bambodia_pvt_[a-f0-9]{12}$ &&
   "$USER" =~ ^b_pvt_[a-f0-9]{12}$ &&
   ! -e "$APP" && ! -L "$APP" ]] ||
  fail 'Unsafe new synthetic identity'
[[ ! -L "$EVIDENCE" ]] || fail 'Evidence root linked'
mkdir -p -m 0700 "$EVIDENCE"
[[ "$(stat -c %U:%G "$EVIDENCE")" == root:root ]] ||
  fail 'Evidence root must be root-owned'
chmod 0700 "$EVIDENCE"
OUT="$(mktemp -d "$EVIDENCE/fixture-$SUFFIX.XXXXXXXX")"
chmod 0700 "$OUT"
printf '%s\n' "$DB" > "$OUT/db-name"
printf '%s\n' "$USER" > "$OUT/db-user"
printf '%s\n' "$APP" > "$OUT/app-path"
ADMIN_CNF=''
if [[ -v MYSQL_ADMIN_CNF ]]; then ADMIN_CNF="$MYSQL_ADMIN_CNF"; fi
if [[ -n "$ADMIN_CNF" ]]; then
  [[ "$ADMIN_CNF" == /root/* && -f "$ADMIN_CNF" &&
     ! -L "$ADMIN_CNF" && "$(stat -c %u "$ADMIN_CNF")" == 0 ]] ||
    fail 'Private administrator MySQL cnf must be root-owned under /root'
  PERM="$(stat -c %a "$ADMIN_CNF")"
  (( (8#$PERM & 077) == 0 )) || fail 'Admin cnf must be root-private'
fi
admin_mysql(){
  if [[ -n "$ADMIN_CNF" ]]; then
    "$MYSQL" "--defaults-extra-file=$ADMIN_CNF" --user=root --protocol=socket \
      --batch --raw --skip-column-names "$@"
  else
    "$MYSQL" --user=root --protocol=socket \
      --batch --raw --skip-column-names "$@"
  fi
}
if ! admin_mysql -e 'SELECT 1' >/dev/null 2>&1; then
  [[ -r /dev/tty ]] || fail 'Private MySQL admin password requires terminal'
  printf 'MySQL root password (hidden; do not share): ' > /dev/tty
  IFS= read -rs ADMIN_PASSWORD < /dev/tty ||
    fail 'Could not read private administrator password'
  printf '\n' > /dev/tty
  [[ -n "$ADMIN_PASSWORD" ]] || fail 'Blank MySQL administrator password'
  printf '%s' "$ADMIN_PASSWORD" | python3 -c '
import sys
p=sys.stdin.read().replace("\\","\\\\").replace("\"","\\\"")
with open(sys.argv[1],"w") as f:f.write("[client]\nuser=root\npassword=\""+p+"\"\n")
' "$TMP/admin.cnf"
  chmod 0600 "$TMP/admin.cnf"
  unset ADMIN_PASSWORD
  ADMIN_CNF="$TMP/admin.cnf"
  admin_mysql -e 'SELECT 1' >/dev/null 2>&1 ||
    fail 'MySQL administrator authentication failed'
fi
[[ "$(admin_mysql -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")" == 0 &&
   "$(admin_mysql -e "SELECT COUNT(*) FROM mysql.user WHERE User='$USER'")" == 0 ]] ||
  fail 'Randomly generated MySQL schema or account already exists'
PASS="$(python3 -c 'import secrets;print(secrets.token_urlsafe(48))')"
[[ "$PASS" =~ ^[a-zA-Z0-9_-]+$ ]] || fail 'Invalid generated DB secret'
echo 'NEW_SYNTHETIC_SCHEMA_AND_USER=CONFIRMED_ABSENT'
if ! admin_mysql > "$OUT/provision.stdout" 2> "$OUT/provision.stderr" <<SQL
CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$USER'@'localhost' IDENTIFIED BY '$PASS';
CREATE USER '$USER'@'127.0.0.1' IDENTIFIED BY '$PASS';
GRANT ALL PRIVILEGES ON $DB.* TO '$USER'@'localhost';
GRANT ALL PRIVILEGES ON $DB.* TO '$USER'@'127.0.0.1';
SQL
then
  echo "PARTIAL_SYNTHETIC_SCHEMA=$DB"
  echo "EVIDENCE_DIR=$OUT"
  fail 'Provisioning incomplete; do not retry against partial schema'
fi
printf '[client]\nuser=%s\npassword="%s"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n' "$USER" "$PASS" > "$TMP/test.cnf"
chmod 0600 "$TMP/test.cnf"
test_mysql(){ "$MYSQL" "--defaults-extra-file=$TMP/test.cnf" --batch --raw --skip-column-names "$DB" "$@"; }
[[ "$(test_mysql -e 'SELECT DATABASE()')" == "$DB" &&
   "$(test_mysql -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')" == 0 ]] ||
  fail 'Fresh synthetic schema identity or emptiness failed'
if ! (gzip -dc "$BACKUP/qa-after-schema.sql.gz" |
  "$MYSQL" "--defaults-extra-file=$TMP/test.cnf" --database="$DB" --batch --raw) \
  > "$OUT/import.stdout" 2> "$OUT/import.stderr"; then
  echo "PARTIAL_SYNTHETIC_SCHEMA=$DB"
  echo "EVIDENCE_DIR=$OUT"
  fail 'Post-migration schema import failed: manual QA diagnosis only'
fi
[[ "$(test_mysql -e 'SELECT COUNT(*) FROM migrations')" == 62 ]] ||
  fail 'Synthetic database migration ledger not complete'
test_mysql -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" > "$TMP/test-tables"
cmp -s "$TMP/tables" "$TMP/test-tables" ||
  fail 'Fresh synthetic schema table names differ'
inventory fixture "$TMP/test-empty"
cmp -s "$TMP/qa-before" "$TMP/test-empty" ||
  fail 'Fresh synthetic schema differs from original QA snapshot'
echo 'SYNTHETIC_SCHEMA=81_TABLES_VERIFIED'

printf '%s' "$PASS" > "$TMP/db-secret"
chmod 0600 "$TMP/db-secret"
unset PASS
python3 - "$REC" "$APP" "$DB" "$USER" "$SUFFIX" \
  "$TMP/db-secret" "$OUT/accounts.json" <<'PY'
import base64,grp,json,os,pwd,re,secrets,shutil,sys
from pathlib import Path
src,dst,dbname,dbuser,suffix,passfile,accountsfile=sys.argv[1:]
src,dst=Path(src),Path(dst)
if dst.parent!=Path("/var/lib") or src.is_symlink() or dst.exists() or dst.is_symlink():
    raise SystemExit("BLOCKED=untrusted new application directory")
if Path("/var/lib").is_symlink():
    raise SystemExit("BLOCKED=application parent linked")
v=os.statvfs("/var/lib")
if v.f_bavail*v.f_frsize<450*1024*1024:
    raise SystemExit("BLOCKED=less than 450 MiB available for isolated QA app")
def ignore(folder,names):
    skip={".git",".env","storage"} if Path(folder)==src else set()
    if Path(folder).name=="bootstrap":skip.add("cache")
    return skip.intersection(names)
for root,dirs,files in os.walk(src):
    if Path(root)==src:dirs[:]=[x for x in dirs if x not in (".git","storage")]
    if Path(root).name=="bootstrap":dirs[:]=[x for x in dirs if x!="cache"]
    for name in dirs+files:
        if (Path(root)/name).is_symlink():
            raise SystemExit("BLOCKED=recovery code contains symlink")
shutil.copytree(src,dst,ignore=ignore,symlinks=False)
uid=pwd.getpwnam("www").pw_uid
gid=grp.getgrnam("www").gr_gid
for root,dirs,files in os.walk(dst):
    os.chown(root,0,gid)
    os.chmod(root,0o750)
    for name in files:
        f=Path(root)/name
        if not f.is_file() or f.is_symlink():
            raise SystemExit("BLOCKED=unsupported cloned runtime entry")
        executable=bool(f.stat().st_mode&0o111)
        os.chown(f,0,gid)
        os.chmod(f,0o750 if executable else 0o640)
for rel in ("storage","storage/app","storage/app/private","storage/app/public",
  "storage/framework","storage/framework/sessions","storage/framework/views",
  "storage/framework/cache","storage/framework/cache/data",
  "storage/framework/cache/locks","storage/logs","bootstrap/cache"):
    p=dst/rel
    p.mkdir(parents=True,exist_ok=True)
    os.chown(p,uid,gid)
    os.chmod(p,0o700)
password=Path(passfile).read_text()
if not re.fullmatch(r"[A-Za-z0-9_-]{32,100}",password):
    raise SystemExit("BLOCKED=invalid fixture database password")
key="base64:"+base64.b64encode(secrets.token_bytes(32)).decode()
env="\n".join([
    "APP_NAME=BambodiaSyntheticQA","APP_ENV=staging","APP_DEBUG=false",
    "APP_URL=http://127.0.0.1","APP_KEY="+key,
    "APP_LOCALE=en","APP_FALLBACK_LOCALE=en",
    "LOG_CHANNEL=single","LOG_LEVEL=warning",
    "DB_CONNECTION=mysql","DB_HOST=127.0.0.1","DB_PORT=3306",
    "DB_DATABASE="+dbname,"DB_USERNAME="+dbuser,"DB_PASSWORD="+password,
    "SESSION_DRIVER=file","SESSION_COOKIE=bambodia_pvt_"+suffix,
    "SESSION_DOMAIN=null","SESSION_SECURE_COOKIE=false",
    "CACHE_STORE=file","CACHE_PREFIX=bambodia_pvt_"+suffix,
    "QUEUE_CONNECTION=sync","FILESYSTEM_DISK=local",
    "MEDIA_LIBRARY_DISK=local","MAIL_MAILER=array",
    "PREVIEW_VISIBILITY_WRITES_ENABLED=false",""
])
flags=os.O_CREAT|os.O_EXCL|os.O_WRONLY
if hasattr(os,"O_NOFOLLOW"):flags|=os.O_NOFOLLOW
fd=os.open(dst/".env",flags,0o600)
with os.fdopen(fd,"w") as f:
    f.write(env)
    f.flush()
    os.fsync(f.fileno())
os.chown(dst/".env",uid,gid)
os.chmod(dst/".env",0o600)
roles={
    "super_admin":"super_admin","admin":"admin","staff":"staff",
    "freelancer":"freelancer","customer":"customer","outsider":"subscriber"
}
accounts={}
for label,role in roles.items():
    accounts[label]={
        "name":"Synthetic QA "+label,
        "email":"qa-"+label.replace("_","-")+"-"+suffix+"@example.invalid",
        "password":secrets.token_urlsafe(36),
        "role":role
    }
with open(accountsfile,"x") as f:
    json.dump(accounts,f,indent=2)
    f.write("\n")
os.chmod(accountsfile,0o600)
print("SYNTHETIC_APP=SEPARATE_PRIVATE_RUNTIME")
print("SYNTHETIC_APP_KEY=NEW_AND_PRIVATE")
print("SYNTHETIC_ROLE_CREDENTIALS=6_PRIVATE_ONLY")
PY
[[ -f "$APP/.env" && "$(stat -c %a "$APP/.env")" == 600 &&
   "$(stat -c %U:%G "$APP/.env")" == www:www ]] ||
  fail 'Synthetic .env ownership or mode invalid'

SEED="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false ||
  config("database.connections.mysql.database")!==$argv[1] ||
  config("database.connections.mysql.username")!==$argv[2] ||
  config("mail.default")!=="array") exit(2);
$users=\App\Models\User::class;
$listings=\App\Models\MarketplaceListing::class;
$jobs=\App\Models\MarketplaceJob::class;
if($users::count()||$listings::count()||$jobs::count())exit(3);
$accounts=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(array_keys($accounts)!==["super_admin","admin","staff","freelancer","customer","outsider"])exit(4);
\Illuminate\Support\Facades\DB::transaction(function()use($accounts,$users,$listings,$jobs){
  $ids=[];
  foreach($accounts as $label=>$entry){
    if(!str_ends_with($entry["email"],"@example.invalid"))throw new \RuntimeException("Non-synthetic account");
    $ids[$label]=$users::create([
      "name"=>$entry["name"],"email"=>$entry["email"],
      "password"=>$entry["password"],"role"=>$entry["role"]
    ]);
  }
  $owner=$ids["freelancer"];
  $customer=$ids["customer"];
  $listings::create([
    "user_id"=>$owner->id,"type"=>"service",
    "title"=>"[LAYOUT TEST] Stage9 Synthetic Clean Service",
    "description"=>"Disposable unlinked QA-only service.","status"=>"published"
  ]);
  $linked=$listings::create([
    "user_id"=>$owner->id,"type"=>"service",
    "title"=>"[LAYOUT TEST] Stage9 Synthetic Saved Service",
    "description"=>"Disposable linked QA-only service.","status"=>"published"
  ]);
  \App\Models\SavedListing::create([
    "user_id"=>$customer->id,"marketplace_listing_id"=>$linked->id
  ]);
  $jobs::create([
    "client_id"=>$customer->id,
    "title"=>"[LAYOUT TEST] Stage9 Synthetic Clean Job",
    "description"=>"Disposable unlinked QA-only job.",
    "category"=>"development","status"=>"published"
  ]);
  $job=$jobs::create([
    "client_id"=>$customer->id,
    "title"=>"[LAYOUT TEST] Stage9 Synthetic Applicant Job",
    "description"=>"Disposable linked QA-only job.",
    "category"=>"development","status"=>"published"
  ]);
  \App\Models\JobProposal::create([
    "marketplace_job_id"=>$job->id,"freelancer_id"=>$owner->id,
    "cover_message"=>"Synthetic QA proposal for relationship checks.",
    "status"=>"submitted"
  ]);
});
if($users::count()!==6||$listings::count()!==2||$jobs::count()!==2||
  \App\Models\SavedListing::count()!==1||
  \App\Models\JobProposal::count()!==1||
  \App\Models\PreviewVisibilityAction::count()!==0)exit(5);
echo "SYNTHETIC_ROLES=6\n";
echo "SYNTHETIC_LISTINGS=2\n";
echo "SYNTHETIC_JOBS=2\n";
echo "CUSTOMER_LINKED_LISTING=1\n";
echo "PROPOSAL_LINKED_JOB=1\n";
echo "PREVIEW_HISTORY_ACTIONS=0\n";
echo "WRITE_SWITCH=OFF\n";
PHP
)"
if ! (cd "$APP" && runuser -u www -- env -i \
  HOME="$APP" PATH=/usr/bin:/bin:/usr/local/bin \
  "$PHP" -r "$SEED" "$DB" "$USER" < "$OUT/accounts.json") \
  > "$OUT/seed.stdout" 2> "$OUT/seed.stderr"; then
  echo "PARTIAL_SYNTHETIC_DATABASE=$DB"
  echo "PRIVATE_EVIDENCE=$OUT"
  fail 'QA synthetic seed failed; never rerun into partial schema'
fi
cat "$OUT/seed.stdout"
[[ "$(test_mysql -e 'SELECT COUNT(*) FROM users')" == 6 &&
   "$(test_mysql -e 'SELECT COUNT(*) FROM marketplace_listings')" == 2 &&
   "$(test_mysql -e 'SELECT COUNT(*) FROM marketplace_jobs')" == 2 &&
   "$(test_mysql -e 'SELECT COUNT(*) FROM preview_visibility_actions')" == 0 ]] ||
  fail 'Fixture counts mismatched'
inventory qa "$TMP/qa-after"
inventory recovery "$TMP/rec-after"
cmp -s "$TMP/qa-before" "$TMP/qa-after" ||
  fail 'Source QA database changed unexpectedly'
cmp -s "$TMP/rec-before" "$TMP/rec-after" ||
  fail 'Recovered QA database changed unexpectedly'
echo "SYNTHETIC_QA_DB=$DB"
echo "SYNTHETIC_QA_APP=$APP"
echo "PRIVATE_ROLE_CREDENTIALS=$OUT/accounts.json"
echo "EVIDENCE_DIR=$OUT"
echo 'ORIGINAL_QA_AND_RECOVERY=UNCHANGED'
echo 'PRODUCTION=UNTOUCHED_BY_SCRIPT'
echo 'AUTHENTICATED_HTTP=NOT_TESTED'
echo 'WITHDRAWAL_ACTIONS=NOT_TESTED'
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE'
echo 'RESULT=DISPOSABLE_SYNTHETIC_QA_WORKSPACE_READY'
