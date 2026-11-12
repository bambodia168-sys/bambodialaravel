#!/usr/bin/env bash
# Bambodia #35 Stage 7: validate a Stage 6 QA snapshot; restore to NEW, empty,
# separately credentialed schema; compare every table and boot a separate CLI app.
# Never runs migrate/rollback, modifies production or QA source, or enables writes.
set -Eeuo pipefail
set +x
umask 077

MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--check" || "$MODE" == "--restore" ) ]] || {
  echo 'Usage: --check|--restore' >&2; exit 2;
}
fail(){ echo "BLOCKED=$*" >&2; exit 2; }

QA=/www/wwwroot/bambodia-preview-qa
PROD=/www/wwwroot/bambodia.com
SOURCE_DB=bambodia_pvqa_29c824170b10
SOURCE_USER=b_pvqa_29c824170b10
SOURCE_SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
BACKUP=/root/Bambodia-PreviewQA-Migration-Backups/qa-migration-20261112T030423Z.7QncYRhO
EVIDENCE_ROOT=/root/Bambodia-PreviewQA-Restore-Proofs
PHP=/www/server/php/85/bin/php
MYSQL=/www/server/mysql/bin/mysql

[[ "$(id -u)" == 0 ]] || fail 'Authorized aaPanel root terminal required'
for cmd in git python3 realpath mktemp sha256sum gzip tar stat runuser awk cmp grep wc; do
  command -v "$cmd" >/dev/null || fail "Missing: $cmd"
done
[[ -x "$MYSQL" && -x "$PHP" ]] || fail 'aaPanel MySQL/PHP binaries missing'
[[ ! -L /www && ! -L /www/wwwroot && ! -L "$QA" &&
   ! -L "$PROD" && ! -L "$BACKUP" ]] || fail 'Symlink in protected paths'
[[ -f "$PROD/artisan" && -f "$QA/artisan" && -d "$QA/.git" ]] ||
  fail 'Original production or QA checkout missing'
[[ "$(realpath -e "$PROD")" != "$(realpath -e "$QA")" ]] ||
  fail 'Source QA resolves to production'
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SOURCE_SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'Original QA source is not clean and exact-approved'
[[ -f "$QA/.env" && ! -L "$QA/.env" &&
   "$(stat -c %U:%G "$QA/.env")" == www:www &&
   "$(stat -c %a "$QA/.env")" == 600 ]] ||
  fail 'QA env owner/permissions invalid'
[[ -f "$QA/vendor/autoload.php" && -f "$QA/public/build/manifest.json" ]] ||
  fail 'Verified QA runtime missing'
[[ ! -e "$QA/bootstrap/cache/config.php" &&
   ! -e "$QA/storage/app/installed.lock" ]] ||
  fail 'QA config cache or installation lock exists'
if [[ -d /www/server/panel/vhost/nginx ]] &&
  grep -rFq -- "$QA" /www/server/panel/vhost/nginx; then
  fail 'QA unexpected live vhost'
fi
id www >/dev/null || fail 'aaPanel www user missing'

[[ -d "$BACKUP" && "$(stat -c %U:%G "$BACKUP")" == root:root &&
   "$(stat -c %a "$BACKUP")" == 700 ]] ||
  fail 'Stage 6 backup directory missing/permissions unsafe'
[[ "$(realpath -e "$BACKUP")" == "$BACKUP" ]] ||
  fail 'Backup directory resolves unexpectedly'
for name in qa-before-schema.sql.gz qa-after-schema.sql.gz \
  qa-env-and-private-media.tar.gz pre-migration-checksums.sha256 \
  post-migration-checksum.sha256; do
  [[ -s "$BACKUP/$name" && -f "$BACKUP/$name" && ! -L "$BACKUP/$name" ]] ||
    fail "Missing or linked Stage 6 backup evidence: $name"
done
(cd "$BACKUP" && sha256sum --check --status pre-migration-checksums.sha256 &&
  sha256sum --check --status post-migration-checksum.sha256) ||
  fail 'Stage 6 backup checksums invalid'
gzip -t "$BACKUP/qa-before-schema.sql.gz" || fail 'Pre-migration gzip invalid'
gzip -t "$BACKUP/qa-after-schema.sql.gz" || fail 'Post-migration gzip invalid'
tar -tzf "$BACKUP/qa-env-and-private-media.tar.gz" >/dev/null ||
  fail 'Stage 6 private-media backup invalid'

TMP="$(mktemp -d /root/bambodia-pvqa-restore.XXXXXXXX)"
cleanup(){
  rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-pvqa-restore.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT
python3 - "$QA/.env" "$BACKUP/qa-env-and-private-media.tar.gz" \
  "$TMP/source.cnf" "$SOURCE_DB" "$SOURCE_USER" <<'PY'
import os,re,sys,tarfile
from pathlib import Path
env_path, backup_path, cnf, db, user = sys.argv[1:]
def parse(data):
    ret={}
    for line in data.splitlines():
        if line.strip() and not line.lstrip().startswith("#") and "=" in line:
            key,val=line.split("=",1)
            ret[key.strip()]=val.strip().strip('"').strip("'")
    return ret
live=parse(Path(env_path).read_text())
with tarfile.open(backup_path,"r:gz") as t:
    for m in t:
        name=m.name.removeprefix("./")
        if name not in (".env","storage","storage/app","storage/app/private") and not name.startswith("storage/app/private/"):
            raise SystemExit("BLOCKED=unexpected path inside QA secrets/media archive")
        if not(m.isfile() or m.isdir()):
            raise SystemExit("BLOCKED=symlink or special media archive entry")
    member=t.extractfile(".env")
    if member is None:
        raise SystemExit("BLOCKED=QA key backup missing")
    saved=parse(member.read().decode())
for key,val in {
    "APP_ENV":"staging","APP_DEBUG":"false","APP_URL":"http://127.0.0.1",
    "DB_CONNECTION":"mysql","DB_HOST":"127.0.0.1","DB_PORT":"3306",
    "DB_DATABASE":db,"DB_USERNAME":user,"SESSION_DRIVER":"file",
    "CACHE_STORE":"file","MAIL_MAILER":"array","QUEUE_CONNECTION":"sync",
    "PREVIEW_VISIBILITY_WRITES_ENABLED":"false","FILESYSTEM_DISK":"local",
    "MEDIA_LIBRARY_DISK":"local"
}.items():
    if live.get(key)!=val or saved.get(key)!=val:
        raise SystemExit("BLOCKED=live/backup QA environment mismatch: "+key)
for key in ("APP_KEY","DB_PASSWORD","SESSION_COOKIE","CACHE_PREFIX"):
    if not live.get(key) or live.get(key)!=saved.get(key):
        raise SystemExit("BLOCKED=live/backup private setting mismatch: "+key)
if not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=", live["APP_KEY"]):
    raise SystemExit("BLOCKED=invalid archived Laravel APP_KEY")
if not re.fullmatch(r"[A-Za-z0-9_-]{32,100}",live["DB_PASSWORD"]):
    raise SystemExit("BLOCKED=unsupported DB password pattern")
for key in ("DB_URL","DB_SOCKET","SESSION_CONNECTION","REDIS_URL"):
    if live.get(key,"").lower() not in ("","null"):
        raise SystemExit("BLOCKED=unsafe source connection override")
with os.fdopen(os.open(cnf,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),"w") as f:
    f.write("[client]\nuser="+user+"\npassword=\""+live["DB_PASSWORD"]+
            "\"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n")
print("SOURCE_ENV_AND_BACKUP_APP_KEY=MATCH")
PY

src_mysql(){ "$MYSQL" "--defaults-extra-file=$TMP/source.cnf" --batch --raw --skip-column-names "$SOURCE_DB" "$@"; }
[[ "$(src_mysql -e 'SELECT DATABASE()')" == "$SOURCE_DB" ]] ||
  fail 'Source QA login or schema identity mismatch'
[[ "$(src_mysql -e 'SELECT SUBSTRING_INDEX(CURRENT_USER(), "@", 1)')" == "$SOURCE_USER" ]] ||
  fail 'Unexpected source QA database account'
[[ "$(src_mysql -e 'SELECT COUNT(*) FROM migrations')" == 62 ]] ||
  fail 'Expected 62 migration ledger rows'
for table in users marketplace_listings marketplace_jobs preview_visibility_actions; do
  [[ "$(src_mysql -e "SELECT COUNT(*) FROM $table")" == 0 ]] ||
    fail "Source QA user/content/history unexpectedly nonempty: $table"
done

echo '========== BAMBODIA STAGE 7 QA RESTORE PREFLIGHT =========='
echo "MODE=$MODE"
echo "SOURCE_DATABASE=$SOURCE_DB"
echo "SOURCE_SHA=$SOURCE_SHA"
echo 'STAGE6_BACKUPS=SHA256_GZIP_TAR_VERIFIED'
echo 'SOURCE_ENV=BACKUP_KEY_MATCH'
echo 'SOURCE_MIGRATIONS=62'
echo 'SOURCE_CUSTOMER_AND_HISTORY_ROWS=ZERO'
echo 'PREVIEW_VISIBILITY_WRITES=OFF'
echo 'PRODUCTION=NOT_A_TARGET'

# Generate trusted inventory of every SOURCE base table, with exact row counts
# and extended MySQL checksums. No SQL statements write to source.
list_tables(){
  src_mysql -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
}
list_tables > "$TMP/tables"
[[ -s "$TMP/tables" ]] || fail 'Source QA has no tables'
grep -Fxq preview_visibility_actions "$TMP/tables" ||
  fail 'Source missing immutable preview history table'
inventory(){
  local role="$1" db="$2" file="$3" table rowcnt sum
  : > "$file"
  while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail 'Unsupported SQL identifier'
    if [[ "$role" == source ]]; then
      rowcnt="$(src_mysql -e "SELECT COUNT(*) FROM $table")" || fail 'Source count failed'
      sum="$(src_mysql -e "CHECKSUM TABLE $table EXTENDED" | awk '{print $NF}')" ||
        fail 'Source extended checksum failed'
    else
      rowcnt="$(dst_mysql -e "SELECT COUNT(*) FROM $table")" || fail 'Restore count failed'
      sum="$(dst_mysql -e "CHECKSUM TABLE $table EXTENDED" | awk '{print $NF}')" ||
        fail 'Restore extended checksum failed'
    fi
    [[ "$rowcnt" =~ ^[0-9]+$ && "$sum" =~ ^[0-9]+$ ]] ||
      fail 'Unsupported MySQL checksum or row count'
    printf '%s|%s|%s\n' "$table" "$rowcnt" "$sum" >> "$file"
  done < "$TMP/tables"
}
inventory source "$SOURCE_DB" "$TMP/source-before"
echo "SOURCE_TABLE_COUNT=$(wc -l < "$TMP/tables")"
echo 'SOURCE_INVENTORY=ROW_COUNTS_AND_EXTENDED_CHECKSUMS_RECORDED'

if [[ "$MODE" == --check ]]; then
  echo 'RESTORE_DATABASE=NOT_CREATED'
  echo 'RECOVERY_APP=NOT_CREATED'
  echo 'SOURCE_QA_SCHEMA_WRITES=NONE'
  echo 'RESULT=READY_FOR_SEPARATE_QA_RESTORE'
  exit 0
fi

# Fresh restore names: never reuse source/production credentials or schemas.
SUFFIX="$(python3 -c 'import secrets;print(secrets.token_hex(6))')"
DEST="bambodia_pvr_$SUFFIX"
DEST_USER="b_pvr_$SUFFIX"
DEST_PASS="$(python3 -c 'import secrets;print(secrets.token_urlsafe(48))')"
RECOVERY_APP="/var/lib/bambodia-pvr-$SUFFIX"
[[ "$DEST" =~ ^bambodia_pvr_[0-9a-f]{12}$ &&
   "$DEST_USER" =~ ^b_pvr_[0-9a-f]{12}$ &&
   ! -e "$RECOVERY_APP" && ! -L "$RECOVERY_APP" ]] ||
  fail 'Invalid or occupied new QA recovery name'

[[ ! -L "$EVIDENCE_ROOT" ]] || fail 'QA evidence root may not be linked'
mkdir -p -m 0700 "$EVIDENCE_ROOT"
[[ "$(stat -c %U:%G "$EVIDENCE_ROOT")" == root:root ]] ||
  fail 'Recovery evidence root must be root-owned'
chmod 0700 "$EVIDENCE_ROOT"
OUT="$(mktemp -d "$EVIDENCE_ROOT/recovery-$SUFFIX.XXXXXXXX")"
chmod 0700 "$OUT"
printf '%s\n' "$DEST" > "$OUT/recovery-database-name"
printf '%s\n' "$DEST_USER" > "$OUT/recovery-database-user"
printf '%s\n' "$RECOVERY_APP" > "$OUT/recovery-app-path"

ADMIN_CNF=''
if [[ -v MYSQL_ADMIN_CNF ]]; then ADMIN_CNF="$MYSQL_ADMIN_CNF"; fi
if [[ -n "$ADMIN_CNF" ]]; then
  [[ "$ADMIN_CNF" == /root/* && -f "$ADMIN_CNF" && ! -L "$ADMIN_CNF" &&
     "$(stat -c %u "$ADMIN_CNF")" == 0 ]] ||
    fail 'MYSQL_ADMIN_CNF must be a root-owned regular file under /root'
  PERM="$(stat -c %a "$ADMIN_CNF")"
  (( (8#$PERM & 077) == 0 )) ||
    fail 'MYSQL_ADMIN_CNF must not be group/world readable'
fi
admin_mysql(){
  if [[ -n "$ADMIN_CNF" ]]; then
    "$MYSQL" "--defaults-extra-file=$ADMIN_CNF" --protocol=socket --user=root \
      --batch --raw --skip-column-names "$@"
  else
    "$MYSQL" --protocol=socket --user=root --batch --raw --skip-column-names "$@"
  fi
}
if ! admin_mysql -e 'SELECT 1' >/dev/null 2>&1; then
  [[ -r /dev/tty ]] || fail 'Terminal needed for private MySQL admin authentication'
  printf 'MySQL administrator password (hidden; do not share): ' > /dev/tty
  IFS= read -rs MYSQL_ADMIN_PASSWORD < /dev/tty ||
    fail 'MySQL administrator password read failed'
  printf '\n' > /dev/tty
  [[ -n "$MYSQL_ADMIN_PASSWORD" ]] || fail 'Blank MySQL administrator password'
  printf '%s' "$MYSQL_ADMIN_PASSWORD" | python3 -c '
import sys
p=sys.stdin.read().replace("\\","\\\\").replace("\"","\\\"")
with open(sys.argv[1],"w") as f: f.write("[client]\nuser=root\npassword=\""+p+"\"\n")
' "$TMP/admin.cnf"
  chmod 0600 "$TMP/admin.cnf"
  unset MYSQL_ADMIN_PASSWORD
  ADMIN_CNF="$TMP/admin.cnf"
  admin_mysql -e 'SELECT 1' >/dev/null 2>&1 ||
    fail 'MySQL administrator login rejected; no restoration attempted'
fi
[[ "$(admin_mysql -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DEST'")" == 0 ]] ||
  fail 'Restore database name already exists'
[[ "$(admin_mysql -e "SELECT COUNT(*) FROM mysql.user WHERE User='$DEST_USER'")" == 0 ]] ||
  fail 'Restore MySQL username already exists'
echo 'RESTORE_SCHEMA_AND_ACCOUNT=CONFIRMED_NEW_AND_ABSENT'

# The only admin writes are CREATE fresh QA schema/user and schema-scoped grants.
# No DROP, DELETE, TRUNCATE, or change to a preexisting database.
if ! admin_mysql > "$OUT/mysql-create.stdout" 2>"$OUT/mysql-create.stderr" <<SQL
CREATE DATABASE $DEST CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DEST_USER'@'localhost' IDENTIFIED BY '$DEST_PASS';
CREATE USER '$DEST_USER'@'127.0.0.1' IDENTIFIED BY '$DEST_PASS';
GRANT ALL PRIVILEGES ON $DEST.* TO '$DEST_USER'@'localhost';
GRANT ALL PRIVILEGES ON $DEST.* TO '$DEST_USER'@'127.0.0.1';
SQL
then
  echo "PARTIAL_RESTORE_SCHEMA=$DEST"
  echo "RESTORE_EVIDENCE=$OUT"
  fail 'Restore account provisioning incomplete: manual review only'
fi
printf '[client]\nuser=%s\npassword="%s"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n' \
  "$DEST_USER" "$DEST_PASS" > "$TMP/dest.cnf"
chmod 0600 "$TMP/dest.cnf"
dst_mysql(){ "$MYSQL" "--defaults-extra-file=$TMP/dest.cnf" \
  --batch --raw --skip-column-names "$DEST" "$@"; }
[[ "$(dst_mysql -e 'SELECT DATABASE()')" == "$DEST" &&
   "$(dst_mysql -e 'SELECT SUBSTRING_INDEX(CURRENT_USER(), "@", 1)')" == "$DEST_USER" ]] ||
  fail 'Restore account or schema mismatch; manual inspection required'
[[ "$(dst_mysql -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')" == 0 ]] ||
  fail 'Restore schema not empty; refuse import'

echo '========== IMPORT VERIFIED SNAPSHOT INTO FRESH RESTORE SCHEMA =========='
# The dump was created from the empty migrated QA app, with a pinned SHA and
# a newly scoped account. Even qualified SQL targeting other schemas is denied
# by the restored account's schema-only privileges.
if ! (gzip -dc "$BACKUP/qa-after-schema.sql.gz" | \
  "$MYSQL" "--defaults-extra-file=$TMP/dest.cnf" \
    --database="$DEST" --batch --raw) \
   > "$OUT/mysql-import.stdout" 2>"$OUT/mysql-import.stderr"; then
  echo "PARTIAL_RESTORE_SCHEMA=$DEST"
  echo "RESTORE_EVIDENCE=$OUT"
  fail 'Restored SQL import failed; preserve evidence for manual diagnosis'
fi

dst_mysql -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" \
  > "$TMP/restored-tables" || fail 'Restored table listing failed'
cmp -s "$TMP/tables" "$TMP/restored-tables" ||
  fail 'Table names do not match source QA'
inventory restore "$DEST" "$TMP/restored-inventory"
cmp -s "$TMP/source-before" "$TMP/restored-inventory" ||
  fail 'Restore row count or extended checksum mismatch'
inventory source "$SOURCE_DB" "$TMP/source-after"
cmp -s "$TMP/source-before" "$TMP/source-after" ||
  fail 'Source QA database changed during restoration'
[[ "$(dst_mysql -e 'SELECT COUNT(*) FROM migrations')" == 62 &&
   "$(dst_mysql -e 'SELECT COUNT(*) FROM preview_visibility_actions')" == 0 ]] ||
  fail 'Restored migrations or immutable history state mismatch'
cp "$TMP/source-before" "$OUT/source-inventory.txt"
cp "$TMP/restored-inventory" "$OUT/restored-inventory.txt"
sha256sum "$OUT/source-inventory.txt" "$OUT/restored-inventory.txt" > "$OUT/inventory-checksums.sha256"
echo 'QA_DATABASE_RESTORE=TABLES_COUNTS_CHECKSUMS_MATCH'
echo 'SOURCE_DATABASE=UNCHANGED'

echo '========== ASSEMBLE INDEPENDENT PRIVATE RECOVERY APP =========='
# New app outside all aaPanel public roots. No source .env, .git, private
# media, logs, sessions, or installer locks are copied from the active QA app.
python3 - "$QA" "$RECOVERY_APP" "$BACKUP/qa-env-and-private-media.tar.gz" \
  "$TMP/dest.cnf" "$DEST" "$DEST_USER" "$SUFFIX" <<'PY'
from pathlib import Path, PurePosixPath
import grp
import hashlib
import os
import re
import shutil
import stat
import sys
import tarfile
src, dst, tarpath, client_file, db, dbuser, suffix = sys.argv[1:]
src, dst = Path(src), Path(dst)
if dst.exists() or dst.is_symlink() or str(dst.parent) != "/var/lib":
    raise SystemExit("BLOCKED=recovery app destination unsafe")
def exclude(folder, names):
    banned={".git",".env","storage"} if Path(folder)==src else set()
    if Path(folder).name=="bootstrap": banned.add("cache")
    return banned.intersection(names)
if Path("/var/lib").is_symlink():
    raise SystemExit("BLOCKED=restore application parent must not be a symlink")
space=os.statvfs("/var/lib")
if space.f_bavail*space.f_frsize < 400*1024*1024:
    raise SystemExit("BLOCKED=less than 400 MiB free for isolated recovery runtime")
for parent, dirs, files in os.walk(src):
    if Path(parent)==src:
        dirs[:]=[x for x in dirs if x not in (".git","storage")]
    if Path(parent).name=="bootstrap":
        dirs[:]=[x for x in dirs if x!="cache"]
    for name in dirs+files:
        if (Path(parent)/name).is_symlink():
            raise SystemExit("BLOCKED=symlink in QA runtime, refusing copy")
shutil.copytree(src,dst,symlinks=False,ignore=exclude)
www_gid=grp.getgrnam("www").gr_gid
www_uid=__import__("pwd").getpwnam("www").pw_uid
for parent, dirs, files in os.walk(dst):
    if os.path.islink(parent): raise SystemExit("BLOCKED=symlink in recovery runtime")
    os.chown(parent,0,www_gid)
    os.chmod(parent,0o750)
    for item in files:
        f=Path(parent)/item
        if f.is_symlink() or not f.is_file():
            raise SystemExit("BLOCKED=unexpected recovery runtime file")
        was_x=(f.stat().st_mode&0o111)!=0
        os.chown(f,0,www_gid)
        os.chmod(f,0o750 if was_x else 0o640)

for rel in (
    "storage","storage/app","storage/app/private","storage/app/public",
    "storage/framework","storage/framework/cache","storage/framework/cache/data",
    "storage/framework/cache/locks","storage/framework/sessions",
    "storage/framework/views","storage/logs","bootstrap/cache"
):
    p=dst/rel
    p.mkdir(parents=True,exist_ok=True)
    os.chown(p,www_uid,www_gid)
    os.chmod(p,0o700)
with tarfile.open(tarpath,"r:gz") as tar:
    key=None
    count=0
    for member in tar:
        path=PurePosixPath(member.name.removeprefix("./"))
        if str(path)==".env":
            f=tar.extractfile(member)
            if f is None: raise SystemExit("BLOCKED=archived env missing")
            for line in f.read().decode().splitlines():
                if line.startswith("APP_KEY="): key=line.split("=",1)[1].strip()
            continue
        if not str(path).startswith("storage/app/private"):
            if str(path) in ("storage","storage/app"): continue
            raise SystemExit("BLOCKED=unexpected archived media path")
        if ".." in path.parts or path.is_absolute() or not (member.isfile() or member.isdir()):
            raise SystemExit("BLOCKED=archived media unsafe entry")
        target=dst.joinpath(*path.parts)
        if member.isdir():
            target.mkdir(parents=True,exist_ok=True)
            os.chown(target,www_uid,www_gid)
            os.chmod(target,0o700)
        else:
            target.parent.mkdir(parents=True,exist_ok=True)
            data=tar.extractfile(member)
            if data is None: raise SystemExit("BLOCKED=media archive entry unreadable")
            expected=hashlib.sha256()
            with open(target,"xb") as outfile:
                for chunk in iter(lambda: data.read(1024*1024),b""):
                    expected.update(chunk)
                    outfile.write(chunk)
            actual=hashlib.sha256()
            with open(target,"rb") as reread:
                for chunk in iter(lambda: reread.read(1024*1024),b""):
                    actual.update(chunk)
            if actual.digest()!=expected.digest():
                raise SystemExit("BLOCKED=recovered private-media SHA256 mismatch")
            os.chown(target,www_uid,www_gid)
            os.chmod(target,0o600)
            count+=1
if not key or not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=",key):
    raise SystemExit("BLOCKED=restored QA encryption key absent or invalid")
client=Path(client_file).read_text().splitlines()
pwd=[x.removeprefix('password="').removesuffix('"') for x in client if x.startswith('password="')]
if len(pwd)!=1 or not re.fullmatch(r"[A-Za-z0-9_-]{32,100}",pwd[0]):
    raise SystemExit("BLOCKED=invalid recovery password")
env="\n".join([
 "APP_NAME=BambodiaPreviewRecovery", "APP_ENV=staging","APP_DEBUG=false",
 "APP_URL=http://127.0.0.1","APP_KEY="+key,"APP_LOCALE=en",
 "APP_FALLBACK_LOCALE=en","LOG_CHANNEL=single","LOG_LEVEL=warning",
 "DB_CONNECTION=mysql","DB_HOST=127.0.0.1","DB_PORT=3306",
 "DB_DATABASE="+db,"DB_USERNAME="+dbuser,"DB_PASSWORD="+pwd[0],
 "SESSION_DRIVER=file","SESSION_COOKIE=bambodia_pvr_"+suffix,
 "SESSION_DOMAIN=null","SESSION_SECURE_COOKIE=false",
 "CACHE_STORE=file","CACHE_PREFIX=bambodia_pvr_"+suffix,
 "QUEUE_CONNECTION=sync","FILESYSTEM_DISK=local",
 "MEDIA_LIBRARY_DISK=local","MAIL_MAILER=array",
 "PREVIEW_VISIBILITY_WRITES_ENABLED=false",""
])
flags=os.O_WRONLY|os.O_CREAT|os.O_EXCL
if hasattr(os,"O_NOFOLLOW"): flags|=os.O_NOFOLLOW
fd=os.open(dst/".env",flags,0o600)
with os.fdopen(fd,"w") as outfile:
    outfile.write(env)
    outfile.flush()
    os.fsync(outfile.fileno())
os.chown(dst/".env",www_uid,www_gid)
os.chmod(dst/".env",0o600)
print("RECOVERY_APP_PRIVATE_MEDIA_FILES="+str(count))
print("RECOVERY_APP_PRIVATE_MEDIA_SHA256=PASS")
print("RECOVERY_APP_SEPARATE_STORAGE=PASS")
print("RESTORED_ENCRYPTION_KEY=FROM_VERIFIED_PRIVATE_QA_BACKUP")
PY

echo '========== BOOT SEPARATE RESTORED LARAVEL APPLICATION =========='
# No web daemon or public Nginx vhost is started. Isolated CLI bootstrap.
BOOT="$(cat <<'PHP'
$dir=getcwd();
require $dir."/vendor/autoload.php";
$app=require $dir."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false) exit(2);
if(config("database.default")!=="mysql") exit(3);
if(config("database.connections.mysql.database")!==$argv[1]) exit(4);
if(config("database.connections.mysql.username")!==$argv[2]) exit(5);
if(config("session.driver")!=="file" || config("cache.default")!=="file") exit(6);
if(config("mail.default")!=="array" || config("queue.default")!=="sync") exit(7);
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS selected_db");
if(($db->selected_db??"")!==$argv[1]) exit(8);
if(\Illuminate\Support\Facades\DB::table("migrations")->count()!==62) exit(9);
if(\Illuminate\Support\Facades\DB::table("preview_visibility_actions")->count()!==0) exit(10);
echo "RESTORED_LARAVEL_CLI_BOOT=PASS\n";
echo "RESTORED_DATABASE_IDENTITY=EXACT_MATCH\n";
echo "RESTORED_PREVIEW_WRITES=OFF\n";
PHP
)"
(cd "$RECOVERY_APP" && runuser -u www -- env -i \
  HOME="$RECOVERY_APP" PATH=/usr/bin:/bin:/usr/local/bin \
  "$PHP" -r "$BOOT" "$DEST" "$DEST_USER") > "$OUT/restored-app-boot.stdout" 2>"$OUT/restored-app-boot.stderr" ||
    fail 'Independent restored Laravel CLI boot failed; inspect private evidence'
cat "$OUT/restored-app-boot.stdout"

[[ "$(dst_mysql -e 'SELECT COUNT(*) FROM preview_visibility_actions')" == 0 ]] ||
  fail 'Unexpected restore history writes'
inventory source "$SOURCE_DB" "$TMP/source-final"
cmp -s "$TMP/source-before" "$TMP/source-final" ||
  fail 'Source QA rows changed during restore app boot'
echo "RESTORED_DATABASE=$DEST"
echo "RESTORED_APP_DIRECTORY=$RECOVERY_APP"
echo "RESTORE_EVIDENCE_DIR=$OUT"
echo 'SOURCE_QA_DATABASE=UNCHANGED'
echo 'PRODUCTION=UNTOUCHED_BY_SCRIPT'
echo 'QA_PUBLIC_HTTP_BOOT=NOT_TESTED'
echo 'PHYSICAL_DEVICE_ACCEPTANCE=NOT_TESTED'
echo 'RESULT=QA_DATABASE_RESTORE_AND_INDEPENDENT_CLI_BOOT_PASS'
