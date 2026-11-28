#!/usr/bin/env bash
# Phase 3C: exact accepted overlay only. No checkout, OAuth changes or DB restore.
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=45fcb968809be8c7abbdaa0d9d4bc46c9a2f64ff
BASE=8f045bb6672de5ae6cc8000e789f8c3d3ecdfb2b
TREE=a0ee81267ada605bff6e36d6cbbb7f9fadc82069
SITE=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
PHP=/www/server/php/85/bin/php
STAGE=/var/tmp/bambodia-phase3-review.LnLW0mGV
MANIFEST=4b8a2e3ea315f6f04d53c15249fcd9d79bce6d111079e69a25498c18b51e9b95
CTRL=app/Http/Controllers/Api/BambodiaMcpController.php
SERVICE=app/Support/Mcp/AdvancedContentTools.php
MIGRATION=database/migrations/2026_11_28_080000_add_description_to_media_assets.php
OLD=766d8dbafbcd8c2ab4c742916488b217587da4bf
NEW=832c692c12ef23e3584cc820512619383822c406
SERVICE_BLOB=96ba1240af97e557d9345847b3b58d1ecf2b788a
MIGRATION_BLOB=b96cedca6729eaf36c1d30d0a598c589410955be
fail(){ echo "PHASE3C_BLOCKED=$*" >&2; exit 1; }
MODE=${1:-}
[[ "$MODE" == --preflight || "$MODE" == --apply || "$MODE" == --rollback ]] || fail INVALID_MODE
[[ $(id -u) == 0 && -x "$PHP" ]] || fail ROOT_OR_PHP_REQUIRED
for cmd in git curl python3 sha256sum gzip flock runuser stat realpath find cmp; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
[[ -d "$SITE" && ! -L "$SITE" && $(realpath "$SITE") == "$SITE" && -f "$SITE/artisan" ]] || fail SITE_CHANGED
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' "$VHOST" || fail VHOST_CHANGED
exec 9>/run/lock/bambodia-mcp-operational-release.lock
flock -n 9 || fail RELEASE_LOCK_BUSY
exec 8>/run/lock/bambodia-account-overview.lock
flock -n 8 || fail ACCOUNT_RELEASE_LOCK_BUSY
exec 7>/run/lock/bambodia-mcp-phase3c.lock
flock -n 7 || fail PHASE3C_LOCK_BUSY
WORK=$(mktemp -d /root/bambodia-phase3c-work.XXXXXXXX)
BACKUP=""
APPLYING=0
blob(){ [[ -f "$1" && ! -L "$1" && $(git hash-object "$1") == "$2" ]] || fail "BLOB_MISMATCH_$1"; }
verify_backup(){
  [[ "$BACKUP" =~ ^/root/bambodia-phase3c-backup\.[A-Za-z0-9]+$ && -d "$BACKUP" && ! -L "$BACKUP" && $(stat -c '%u:%a' "$BACKUP") == 0:700 ]] || fail UNSAFE_BACKUP
  [[ -f "$BACKUP/backup.sha256" && ! -L "$BACKUP/backup.sha256" && -f "$BACKUP/PREFLIGHT_PASS" && ! -L "$BACKUP/PREFLIGHT_PASS" ]] || fail BACKUP_NOT_COMPLETE
  (cd "$BACKUP"; sha256sum --check --status backup.sha256) || fail BACKUP_INTEGRITY
  blob "$BACKUP/controller.original.php" "$OLD"
  [[ $(cat "$BACKUP/PREFLIGHT_PASS") == "$HEAD" ]] || fail BACKUP_RELEASE_MISMATCH
  gzip -t "$BACKUP/database.sql.gz" || fail DB_ARCHIVE_INVALID
}
atomic(){
  local source=$1 target=$2 tmp
  [[ -d $(dirname "$target") && ! -L $(dirname "$target") && ! -L "$target" ]] || return 1
  tmp=$(mktemp "$(dirname "$target")/.phase3c.XXXXXXXX") || return 1
  if ! cp --preserve=mode,ownership,timestamps -- "$source" "$tmp" || ! mv -fT -- "$tmp" "$target"; then rm -f -- "$tmp"; return 1; fi
}
new_file(){
  local source=$1 target=$2 tmp
  [[ ! -e "$target" && ! -L "$target" && -d $(dirname "$target") && ! -L $(dirname "$target") ]] || return 1
  tmp=$(mktemp "$(dirname "$target")/.phase3c.XXXXXXXX") || return 1
  if ! install -o www -g www -m 0644 "$source" "$tmp" || ! mv -fT -- "$tmp" "$target"; then rm -f -- "$tmp"; return 1; fi
}
restore_code(){
  local hash
  [[ -f "$SITE/$CTRL" && ! -L "$SITE/$CTRL" ]] || return 1
  hash=$(git hash-object "$SITE/$CTRL") || return 1
  if [[ "$hash" == "$NEW" ]]; then
    atomic "$BACKUP/controller.original.php" "$SITE/$CTRL" || return 1
  elif [[ "$hash" != "$OLD" ]]; then return 1; fi
  if [[ -e "$SITE/$SERVICE" || -L "$SITE/$SERVICE" ]]; then
    [[ -f "$SITE/$SERVICE" && ! -L "$SITE/$SERVICE" && $(git hash-object "$SITE/$SERVICE") == "$SERVICE_BLOB" ]] || return 1
    rm -- "$SITE/$SERVICE" || return 1
  fi
  [[ $(git hash-object "$SITE/$CTRL") == "$OLD" ]] || return 1
  echo "CODE_ROLLBACK=PASS"
  echo "ADDITIVE_MIGRATION_FILE_AND_COLUMN_RETAINED_IF_PRESENT=YES"
  echo "PRODUCTION_DATABASE_RESTORE=NOT_PERFORMED"
}
finish(){
  local rc=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "APPLY_FAILED_RESTORING_CODE" >&2
    restore_code || { echo "CODE_ROLLBACK_FAILED_BACKUP=$BACKUP" >&2; rc=1; }
  fi
  rm -rf --one-file-system -- "$WORK"
  exit "$rc"
}
trap finish EXIT
repo_gate(){
  local mode=$1 merged=${2:-}
  local api="https://api.github.com/repos/$REPO"
  curl -fLsS --retry 2 --max-time 60 "$api/pulls/210" -o "$WORK/pr.json"
  curl -fLsS --retry 2 --max-time 60 "$api/commits/main" -o "$WORK/main.json"
  for run in 46090616586 46090616589 46090616590 46090616577 46090616718; do
    curl -fLsS --retry 2 --max-time 60 "$api/actions/runs/$run" -o "$WORK/$run.json"
  done
  python3 - "$WORK" "$mode" "$HEAD" "$BASE" "$TREE" "$merged" <<'PY'
import json,pathlib,sys
w=pathlib.Path(sys.argv[1]); mode,head,base,tree,merged=sys.argv[2:]
p=json.loads((w/'pr.json').read_text()); m=json.loads((w/'main.json').read_text())
def gate(ok,msg):
    if not ok: raise SystemExit("PHASE3C_BLOCKED="+msg)
gate(p['head']['sha']==head and p['base']['ref']=='main','PR_HEAD_OR_BASE_CHANGED')
if mode=='--preflight':
    gate(not p['merged'] and p['state']=='open' and p['base']['sha']==base and m['sha']==base,'PR_OR_MAIN_DRIFT')
else:
    gate(p['merged'] and p['merge_commit_sha']==merged and m['sha']==merged and m['commit']['tree']['sha']==tree,'MERGE_OR_TREE_NOT_APPROVED')
expected={46090616586:'Bambodia Quality',46090616589:'Bambodia MySQL Installer Acceptance',46090616590:'Bambodia Operational Acceptance',46090616577:'Bambodia Browser Emulation Acceptance',46090616718:'Bambodia Isolated Authenticated Journeys'}
for run,name in expected.items():
    j=json.loads((w/(str(run)+'.json')).read_text())
    gate(j['id']==run and j['name']==name and j['head_sha']==head and j['event']=='pull_request' and j['status']=='completed' and j['conclusion']=='success','CI_GATE_'+str(run))
    print(name+': PASS')
print('GITHUB_RELEASE_GATE=PASS')
PY
}
stage_gate(){
  [[ -d "$STAGE" && ! -L "$STAGE" && $(stat -c '%u:%a' "$STAGE") == 0:700 ]] || fail STAGE_UNSAFE
  [[ $(sha256sum "$STAGE/manifest.sha256"|cut -d' ' -f1) == "$MANIFEST" ]] || fail QA_MANIFEST_DRIFT
  [[ -z $(find "$STAGE/source" "$STAGE/candidate" "$STAGE/verified" -type l -print -quit) ]] || fail STAGE_SYMLINK
  (cd "$STAGE"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum | cmp -s - manifest.sha256) || fail QA_INVENTORY_DRIFT
  grep -F 'OK (1 test, 30 assertions)' "$STAGE/baseline-qa.log"
  grep -F 'OK (16 tests, 374 assertions)' "$STAGE/candidate-qa.log"
  grep -F 'OK (1 test, 29 assertions)' "$STAGE/rollback-qa.log"
  blob "$STAGE/source/$CTRL" "$OLD"
  blob "$STAGE/candidate/$CTRL" "$NEW"
  blob "$STAGE/candidate/$SERVICE" "$SERVICE_BLOB"
  blob "$STAGE/candidate/$MIGRATION" "$MIGRATION_BLOB"
  for f in "$CTRL" "$SERVICE" "$MIGRATION"; do "$PHP" -l "$STAGE/candidate/$f"; done
  echo "ISOLATED_ACCEPTED_BYTES=PASS"
}
source_gate(){
  local phase=$1
  python3 - "$STAGE/source" "$SITE" "$phase" "$CTRL" <<'PY'
import hashlib,pathlib,sys
stage,live=map(pathlib.Path,sys.argv[1:3]); phase,ctrl=sys.argv[3:]
n=0
for f in sorted(stage.rglob('*')):
    if not f.is_file(): continue
    r=f.relative_to(stage)
    if r.parts[0]=='tests' or str(r)=='phpunit.xml': continue
    if phase=='after' and str(r)==ctrl: continue
    p=live/r
    if not p.is_file() or any(x.is_symlink() for x in [p,*p.parents]):
        raise SystemExit("PHASE3C_BLOCKED=SOURCE_PATH_DRIFT_"+str(r))
    if hashlib.sha256(f.read_bytes()).digest()!=hashlib.sha256(p.read_bytes()).digest():
        raise SystemExit("PHASE3C_BLOCKED=SOURCE_BYTES_DRIFT_"+str(r))
    n+=1
print("QA_LIVE_SOURCE_FILES_MATCH="+str(n))
PY
  if [[ "$phase" == before ]]; then
    blob "$SITE/$CTRL" "$OLD"
    [[ ! -e "$SITE/$SERVICE" && ! -L "$SITE/$SERVICE" && ! -e "$SITE/$MIGRATION" && ! -L "$SITE/$MIGRATION" ]] || fail PHASE3_ALREADY_PRESENT
  else
    blob "$SITE/$CTRL" "$NEW"
    blob "$SITE/$SERVICE" "$SERVICE_BLOB"
    blob "$SITE/$MIGRATION" "$MIGRATION_BLOB"
  fi
}
db_gate(){
  local phase=$1
  (cd "$SITE"; runuser -u www -- "$PHP" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('production') || config('mcp.production_enabled')!==true || config('mcp.production_writes_enabled')!==true || \App\Support\Mcp\McpResource::uri()!=='https://bambodia.com/mcp') exit(8);
$c=\Illuminate\Support\Facades\DB::connection();
if ($c->getDriverName()!=='mysql') exit(9);
$cfg=$c->getConfig();
$row=$c->selectOne('SELECT DATABASE() AS name');
$tables=$c->selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')");
if ((int)$tables->n!==0 || $row->name!==$cfg['database']) exit(10);
$col=$c->selectOne("SELECT DATA_TYPE AS kind, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_assets' AND COLUMN_NAME='description'");
$count=$c->table('migrations')->where('migration','2026_11_28_080000_add_description_to_media_assets')->count();
if (!$c->getSchemaBuilder()->hasTable('media_assets')) exit(11);
echo json_encode(['host'=>$cfg['host']??null,'port'=>$cfg['port']??3306,'socket'=>$cfg['unix_socket']??'','database'=>$cfg['database'],'username'=>$cfg['username'],'password'=>$cfg['password'],'column'=>$col,'migration_count'=>$count],JSON_THROW_ON_ERROR);
PHP
  ) >"$WORK/db.json" 2>"$WORK/db-probe.private.log" || fail DB_OR_MCP_CONFIG_GATE
  python3 - "$WORK" "$phase" <<'PY'
import hashlib,json,pathlib,re,sys
w=pathlib.Path(sys.argv[1]); phase=sys.argv[2]; d=json.loads((w/'db.json').read_text())
if phase=='before':
    assert d['column'] is None and d['migration_count']==0,'PHASE3_SCHEMA_ALREADY_PRESENT'
else:
    assert d['column']=={'kind':'text','nullable':'YES'} and d['migration_count']==1,'PHASE3_SCHEMA_NOT_VERIFIED'
assert d['host'] in ('localhost','127.0.0.1','::1') or (isinstance(d['socket'],str) and d['socket'].startswith('/')),'UNEXPECTED_DB_HOST'
assert re.fullmatch(r'[A-Za-z0-9_-]+',d['database']),'UNSAFE_DB_NAME'
def q(v):
    return '"'+str(v or '').replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r')+'"'
lines=['[client]','user='+q(d['username']),'password='+q(d['password'])]
if d['socket']: lines+=['protocol=SOCKET','socket='+q(d['socket'])]
else: lines+=['host='+q(d['host']),'port='+q(d['port'])]
(w/'mysql.cnf').write_text('\n'.join(lines)+'\n')
(w/'db-name').write_text(d['database'])
(w/'db-target.sha256').write_text(hashlib.sha256(json.dumps({k:d[k] for k in ('host','port','socket','database','username')},sort_keys=True).encode()).hexdigest()+'\n')
print('MYSQL_SCHEMA_AND_MCP_CONFIG=PASS')
PY
  rm -f -- "$WORK/db.json"
}
health(){
  local code
  code=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' https://bambodia.com/)
  [[ "$code" == 200 ]] || fail "HOMEPAGE_HTTP_$code"
  curl -fLsS --max-time 30 https://bambodia.com/.well-known/oauth-protected-resource/mcp -o "$WORK/resource.json"
  python3 - "$WORK/resource.json" <<'PY'
import json,sys
d=json.load(open(sys.argv[1]))
assert d['resource']=='https://bambodia.com/mcp' and d['authorization_servers']==['https://bambodia.com']
PY
  code=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' https://bambodia.com/mcp)
  [[ "$code" == 401 ]] || fail "UNAUTHENTICATED_MCP_HTTP_$code"
  echo "PUBLIC_HOME_OAUTH_METADATA_AND_UNAUTHENTICATED_GATE=PASS"
}
protected_capture(){
  (cd "$SITE"; 
    for f in .env storage/oauth-private.key storage/oauth-public.key bootstrap/cache/config.php routes/web.php bootstrap/app.php app/Providers/AppServiceProvider.php config/mcp.php composer.json composer.lock; do
      if [[ -e "$f" || -L "$f" ]]; then
        [[ -f "$f" && ! -L "$f" ]] || exit 1
        sha256sum "$f"
      elif [[ "$f" != storage/oauth-private.key && "$f" != storage/oauth-public.key && "$f" != bootstrap/cache/config.php ]]; then exit 1; fi
    done
  ) > "$BACKUP/protected.sha256" || fail PROTECTED_FILE_CAPTURE
  sha256sum "$VHOST" >"$BACKUP/vhost.sha256"
  for f in storage/oauth-private.key storage/oauth-public.key bootstrap/cache/config.php; do
    if [[ -e "$SITE/$f" ]]; then echo "$f present"; else echo "$f absent"; fi
  done >"$BACKUP/key-presence.txt"
}
protected_verify(){
  (cd "$SITE"; sha256sum --check --status "$BACKUP/protected.sha256") || fail PROTECTED_FILE_DRIFT
  sha256sum --check --status "$BACKUP/vhost.sha256" || fail VHOST_DRIFT
  for f in storage/oauth-private.key storage/oauth-public.key bootstrap/cache/config.php; do
    if [[ -e "$SITE/$f" ]]; then echo "$f present"; else echo "$f absent"; fi
  done | cmp -s - "$BACKUP/key-presence.txt" || fail KEY_PRESENCE_DRIFT
  echo "OAUTH_KEYS_ENV_AND_PROTECTED_SOURCE_UNCHANGED=PASS"
}
if [[ "$MODE" == --preflight ]]; then
  [[ $# == 1 ]] || fail INVALID_ARGS
  repo_gate --preflight
  stage_gate
  source_gate before
  db_gate before
  health
  BACKUP=$(mktemp -d /root/bambodia-phase3c-backup.XXXXXXXX)
  chmod 700 "$BACKUP"
  echo "BACKUP=$BACKUP"
  cp -a -- "$SITE/$CTRL" "$BACKUP/controller.original.php"
  blob "$BACKUP/controller.original.php" "$OLD"
  protected_capture
  cp -- "$WORK/db-target.sha256" "$BACKUP/db-target.sha256"
  DUMP=/www/server/mysql/bin/mysqldump
  if [[ ! -x "$DUMP" ]]; then DUMP=$(command -v mysqldump) || fail MYSQLDUMP_NOT_AVAILABLE; fi
  DB=$(cat "$WORK/db-name")
  echo "=== Create consistent private MySQL logical backup ==="
  "$DUMP" --defaults-file="$WORK/mysql.cnf" --single-transaction --quick --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF --routines --events --triggers --databases "$DB" 2>"$BACKUP/dump.private.log" | gzip -1 > "$BACKUP/database.sql.gz" || fail DATABASE_BACKUP_FAILED
  rm -f -- "$WORK/mysql.cnf" "$WORK/db-name"
  chmod 600 "$BACKUP/database.sql.gz"
  gzip -t "$BACKUP/database.sql.gz"
  gzip -dc "$BACKUP/database.sql.gz" | tail -n 15 | grep -q '^-- Dump completed on ' || fail INCOMPLETE_SQL_DUMP
  [[ -s "$BACKUP/database.sql.gz" ]] || fail EMPTY_BACKUP
  source_gate before
  protected_verify
  printf '%s\n' "$HEAD" > "$BACKUP/PREFLIGHT_PASS"
  (cd "$BACKUP"; sha256sum controller.original.php database.sql.gz protected.sha256 vhost.sha256 key-presence.txt db-target.sha256 PREFLIGHT_PASS > backup.sha256)
  verify_backup
  echo "DATABASE_BACKUP_BYTES=$(stat -c '%s' "$BACKUP/database.sql.gz")"
  echo "BACKUP_MANIFEST_SHA256=$(sha256sum "$BACKUP/backup.sha256"|cut -d' ' -f1)"
  echo "BACKUP_INTEGRITY=PASS"
  echo "BACKUP_RESTORE_REHEARSAL=NOT_RUN"
  echo "PHASE3C_PREFLIGHT=PASS"
  echo "PRODUCTION_FILES_MODIFIED=0"
  echo "PRODUCTION_DEPLOYMENT=NOT_STARTED"
elif [[ "$MODE" == --apply ]]; then
  [[ $# == 4 ]] || fail APPLY_REQUIRES_BACKUP_MANIFEST_MERGED_SHA
  BACKUP=$2
  [[ "$3" =~ ^[a-f0-9]{64}$ && "$4" =~ ^[a-f0-9]{40}$ ]] || fail INVALID_RELEASE_ARGS
  verify_backup
  [[ $(sha256sum "$BACKUP/backup.sha256"|cut -d' ' -f1) == "$3" ]] || fail BACKUP_MANIFEST_DRIFT
  [[ $(($(date +%s)-$(stat -c '%Y' "$BACKUP/PREFLIGHT_PASS"))) -lt 7200 ]] || fail BACKUP_TOO_OLD
  repo_gate --apply "$4"
  stage_gate
  source_gate before
  protected_verify
  db_gate before
  cmp -s "$WORK/db-target.sha256" "$BACKUP/db-target.sha256" || fail BACKUP_DATABASE_TARGET_DRIFT
  rm -f -- "$WORK/mysql.cnf" "$WORK/db-name"
  health
  APPLYING=1
  new_file "$STAGE/candidate/$MIGRATION" "$SITE/$MIGRATION" || fail MIGRATION_INSTALL_FAILED
  (cd "$SITE"; runuser -u www -- "$PHP" artisan migrate --path="$MIGRATION" --force --no-interaction --no-ansi) >"$BACKUP/migration.private.log" 2>&1 || fail MIGRATION_FAILED
  db_gate after
  cmp -s "$WORK/db-target.sha256" "$BACKUP/db-target.sha256" || fail DATABASE_TARGET_CHANGED
  rm -f -- "$WORK/mysql.cnf" "$WORK/db-name"
  new_file "$STAGE/candidate/$SERVICE" "$SITE/$SERVICE" || fail SERVICE_INSTALL_FAILED
  cp -- "$STAGE/candidate/$CTRL" "$WORK/controller.new.php"
  chown --reference="$BACKUP/controller.original.php" "$WORK/controller.new.php"
  chmod --reference="$BACKUP/controller.original.php" "$WORK/controller.new.php"
  atomic "$WORK/controller.new.php" "$SITE/$CTRL" || fail CONTROLLER_INSTALL_FAILED
  source_gate after
  protected_verify
  health
  APPLYING=0
  echo "PHASE3C_DEPLOYMENT=PASS"
  echo "MERGED_RELEASE=$4"
  echo "EXPECTED_MCP_INVENTORY=24"
  echo "AUTHENTICATED_LIVE_ACCEPTANCE=PENDING"
  echo "BACKUP=$BACKUP"
else
  [[ $# == 3 ]] || fail ROLLBACK_REQUIRES_BACKUP_AND_MANIFEST
  BACKUP=$2
  verify_backup
  [[ $(sha256sum "$BACKUP/backup.sha256"|cut -d' ' -f1) == "$3" ]] || fail BACKUP_MANIFEST_DRIFT
  protected_verify
  restore_code || fail ROLLBACK_FAILED
  health
  echo "PHASE3C_CODE_ROLLBACK=PASS"
fi
