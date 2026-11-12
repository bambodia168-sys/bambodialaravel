#!/usr/bin/env bash
# Bambodia Issue #35: read-only diagnosis after Stage11 stopped at write-switch guard.
# No PHP HTTP server, no DB writes, no backup restore, no .env changes or production.
set -Eeuo pipefail
set +x
umask 077
[[ $# == 1 && "$1" == "--check" ]] || {
  echo 'Usage: --check' >&2
  exit 2
}
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

PROD=/www/wwwroot/bambodia.com
QA=/www/wwwroot/bambodia-preview-qa
REC=/var/lib/bambodia-pvr-9e5847bc21b8
APP=/var/lib/bambodia-pvt-53309a26f4cd
DB=bambodia_pvt_53309a26f4cd
DBUSER=b_pvt_53309a26f4cd
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
EVIDENCE=/root/Bambodia-PreviewQA-Synthetic-Stage9/fixture-53309a26f4cd.YWeq6qDu
PHP=/www/server/php/85/bin/php

[[ "$(id -u)" == 0 ]] || fail 'Run as authorized aaPanel root user'
for bin in git stat realpath runuser python3 gzip sha256sum; do
  command -v "$bin" >/dev/null || fail "Missing $bin"
done
[[ -x "$PHP" ]] || fail 'aaPanel PHP 8.5 unavailable'
for path in /www /www/wwwroot /var /var/lib "$PROD" "$QA" "$REC" "$APP" "$EVIDENCE"; do
  [[ ! -L "$path" ]] || fail "Linked path not permitted: $path"
done
for path in "$PROD" "$QA" "$REC" "$APP"; do
  [[ -f "$path/artisan" && "$(realpath -e "$path")" == "$path" ]] ||
    fail "Unexpected app location: $path"
done
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'Original QA checkout not at clean reviewed source SHA'
[[ ! -e "$APP/storage/app/installed.lock" &&
   ! -L "$APP/storage/app/installed.lock" ]] ||
  fail 'Temporary Stage11 installed marker still exists: stop for manual repair'
[[ ! -e "$APP/bootstrap/cache/config.php" &&
   ! -e "$APP/.git" ]] ||
  fail 'Synthetic app config cached or source unexpected'
for path in "$QA/.env" "$REC/.env" "$APP/.env"; do
  [[ -f "$path" && ! -L "$path" &&
     "$(stat -c %a "$path")" == 600 &&
     "$(stat -c %U:%G "$path")" == www:www ]] ||
    fail 'Protected QA env ownership or permission mismatch'
done
[[ -d "$EVIDENCE" && ! -L "$EVIDENCE" &&
   "$(stat -c %U:%G "$EVIDENCE")" == root:root &&
   "$(stat -c %a "$EVIDENCE")" == 700 ]] ||
  fail 'Root-owned Stage9 evidence missing or unsafe'
[[ -f "$EVIDENCE/accounts.json" && ! -L "$EVIDENCE/accounts.json" &&
   "$(stat -c %U:%G "$EVIDENCE/accounts.json")" == root:root &&
   "$(stat -c %a "$EVIDENCE/accounts.json")" == 600 ]] ||
  fail 'Stage9 private synthetic accounts missing or unsafe'

echo '========== VERIFY PARTIAL STAGE11 EVIDENCE WITHOUT PRINTING SECRETS =========='
python3 - "$EVIDENCE" "$APP/.env" <<'PY'
from pathlib import Path
import json
import re
import sys
import gzip
import hashlib

root, envfile = Path(sys.argv[1]), Path(sys.argv[2])
candidates = sorted(root.glob("stage11-lifecycle-*"))
if len(candidates)!=1:
    raise SystemExit("BLOCKED=expected exactly one Stage11 evidence directory, found "+str(len(candidates)))
e = candidates[0]
if not e.is_dir() or e.is_symlink():
    raise SystemExit("BLOCKED=Stage11 evidence path invalid")
if e.stat().st_mode & 0o077:
    raise SystemExit("BLOCKED=Stage11 evidence is not root-private")
for name in ("synthetic-before.sql.gz","synthetic-before.sha256","fixtures.json","server.log"):
    f=e/name
    if not f.is_file() or f.is_symlink() or f.stat().st_size==0:
        raise SystemExit("BLOCKED=Stage11 private evidence incomplete: "+name)
snapshot=e/"synthetic-before.sql.gz"
if not snapshot.read_bytes().startswith(b"\x1f\x8b"):
    raise SystemExit("BLOCKED=Stage11 gzip magic mismatch")
with gzip.open(snapshot,"rb") as stream:
    while stream.read(1024*1024):
        pass
raw=(e/"synthetic-before.sha256").read_text()
m=re.fullmatch(r"([a-f0-9]{64})\s+(.+)\n?",raw)
if not m:
    raise SystemExit("BLOCKED=Stage11 backup manifest malformed")
expected=hashlib.sha256(snapshot.read_bytes()).hexdigest()
if m.group(1)!=expected or Path(m.group(2))!=snapshot:
    raise SystemExit("BLOCKED=Stage11 backup digest mismatch")
if (e/"lifecycle-results.json").exists():
    events=json.loads((e/"lifecycle-results.json").read_text())
    if events!=[]:
        raise SystemExit("BLOCKED=Stage11 lifecycle HTTP operations already recorded")
fixtures=json.loads((e/"fixtures.json").read_text())
if len(fixtures)!=4 or len({(x["type"],x["linked"]) for x in fixtures})!=4:
    raise SystemExit("BLOCKED=Stage11 fixture inventory invalid")
values={}
for line in envfile.read_text().splitlines():
    if line and not line.lstrip().startswith("#") and "=" in line:
        key,value=line.split("=",1)
        values[key.strip()]=value.strip().strip('"').strip("'")
for key,want in {
    "APP_ENV":"staging", "APP_DEBUG":"false", "DB_CONNECTION":"mysql",
    "DB_HOST":"127.0.0.1", "DB_DATABASE":"bambodia_pvt_53309a26f4cd",
    "DB_USERNAME":"b_pvt_53309a26f4cd",
    "PREVIEW_VISIBILITY_WRITES_ENABLED":"false",
    "MAIL_MAILER":"array", "SESSION_DRIVER":"file",
    "CACHE_STORE":"file"
}.items():
    if values.get(key)!=want:
        raise SystemExit("BLOCKED=synthetic env safety failed: "+key)
print("STAGE11_PREWRITE_BACKUP=HASH_AND_GZIP_PASS")
print("STAGE11_EVIDENCE_DIR="+str(e))
print("STAGE11_RECORDED_WITHDRAW_RESTORE_EVENTS=ZERO")
print("SAVED_SYNTHETIC_WRITE_SWITCH=OFF")
PY

echo '========== ISOLATED PHP ENVIRONMENT DIAGNOSIS (NO HTTP OR WRITES) =========='
CHECK="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("database.default")!=="mysql" ||
   config("database.connections.mysql.database")!==$argv[1] ||
   config("database.connections.mysql.username")!==$argv[2]) exit(2);
if(config("mail.default")!=="array" ||
   config("cache.default")!=="file" ||
   config("session.driver")!=="file") exit(3);
$key="PREVIEW_VISIBILITY_WRITES_ENABLED";
$raw=getenv($key);
$env=\Illuminate\Support\Env::get($key);
$on=config("preview_visibility.enabled")===true;
echo "PROCESS_GETENV=".($raw==="true"?"TRUE":($raw===false?"ABSENT":"OTHER"))."\n";
echo "PHP_ENV_SUPERGLOBAL=".(isset($_ENV[$key])?"PRESENT":"ABSENT")."\n";
echo "PHP_SERVER_SUPERGLOBAL=".(isset($_SERVER[$key])?"PRESENT":"ABSENT")."\n";
echo "LARAVEL_ENV_VALUE=".($env===true?"TRUE":($env===false?"FALSE":"OTHER"))."\n";
echo "EFFECTIVE_PREVIEW_CONFIG=".($on?"ON":"OFF")."\n";
$pdo=\Illuminate\Support\Facades\DB::connection()->getPdo();
if($pdo->query("SELECT DATABASE()")->fetchColumn()!==$argv[1]) exit(4);
$counts=[
"users"=>6,
"marketplace_listings"=>2,
"marketplace_jobs"=>2,
"saved_listings"=>1,
"job_proposals"=>1,
"preview_visibility_actions"=>0,
"account_notifications"=>0
];
foreach($counts as $table=>$expected){
 if((int)$pdo->query("SELECT COUNT(*) FROM ".$table)->fetchColumn()!==$expected) exit(5);
}
foreach(["marketplace_listings","marketplace_jobs"] as $table){
 if((int)$pdo->query("SELECT COUNT(*) FROM ".$table." WHERE status='published'")->fetchColumn()!==2)exit(6);
}
$n=(int)$pdo->query("SELECT COUNT(*) FROM audit_events WHERE event='auth.login.success'")->fetchColumn();
$total=(int)$pdo->query("SELECT COUNT(*) FROM audit_events")->fetchColumn();
echo "SYNTHETIC_SUCCESSFUL_LOGIN_AUDITS=".$n."\n";
echo "SYNTHETIC_TOTAL_AUDIT_EVENTS=".$total."\n";
echo "SYNTHETIC_VISIBILITY_HISTORY=ZERO\n";
echo "SYNTHETIC_RECORD_STATUSES=FOUR_PUBLISHED\n";
PHP
)"
for order in DEFAULT EGPCS; do
  echo "========== PHP VARIABLES_ORDER=$order =========="
  if [[ "$order" == DEFAULT ]]; then
    (cd "$APP" && runuser -u www -- env -i \
      HOME="$APP" PATH=/usr/bin:/bin:/usr/local/bin \
      PREVIEW_VISIBILITY_WRITES_ENABLED=true "$PHP" -r "$CHECK" "$DB" "$DBUSER") ||
      fail 'Default PHP environment diagnostic failed'
  else
    (cd "$APP" && runuser -u www -- env -i \
      HOME="$APP" PATH=/usr/bin:/bin:/usr/local/bin \
      PREVIEW_VISIBILITY_WRITES_ENABLED=true "$PHP" -d variables_order=EGPCS \
      -r "$CHECK" "$DB" "$DBUSER") ||
      fail 'EGPCS environment diagnostic failed'
  fi
done
echo 'PRODUCTION=NOT_MODIFIED'
echo 'ORIGINAL_QA_AND_RECOVERY=NOT_MODIFIED'
echo 'DISPOSABLE_DATABASE=READ_ONLY_CHECKS_ONLY'
echo 'HTTP_REQUESTS=NONE'
echo 'RESULT=STAGE11_FAILURE_DIAGNOSIS_READ_ONLY_COMPLETE'
