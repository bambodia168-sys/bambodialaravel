#!/usr/bin/env bash
# Phase 4D.2E.3B: controller-only recovery. Never activate without separate authorization.
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
RELEASE=c1db97f9798cdd0e9291fb943d5a1a697c2b9f48
TREE=fab3c6c0611b8f54955673cd2e110ef23994bcf7
HEAD=f8855a6f7aeaea2086cc566ff9ad056181fea37b
PACKAGE=/root/bambodia-phase4d2-preparation.2PmfQTGk
MANIFEST=95e0bc0309565982a0544b769178805a2979b71a2832c66193a0ff51dea4ef90
STAGE=/var/tmp/bambodia-phase4-review.H49cOiGy
STAGE_MANIFEST=608457a44dcdf87cb5438881d6fb102b151ce8408cd7adcac72637b51e43d434
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
FPM=/www/server/php/85/sbin/php-fpm
MYSQL=/www/server/mysql/bin/mysql
CTRL=app/Http/Controllers/Api/BambodiaMcpController.php
MIGRATION=database/migrations/2026_11_28_100000_create_mcp_project_operations.php
OLD=832c692c12ef23e3584cc820512619383822c406
NEW=a302fa364f1f52b6320b77688b7595b91894ca13
fail(){ echo "PHASE4D2E_BLOCKED=$*" >&2; exit 1; }
MODE=$1
[[ $# == 3 && ( "$MODE" == --preflight || "$MODE" == --activate-controller ) ]] || fail MODE_REQUIRES_MIGRATED_BACKUP_AND_MANIFEST
BACKUP=$2
BACKUP_HASH=$3
[[ $(id -u) == 0 && -x "$PHP" && -x "$MYSQL" && -x "$FPM" ]] || fail REQUIRED_RUNTIME
[[ "$BACKUP" =~ ^/root/bambodia-phase4d2-migrated-backup\.[A-Za-z0-9]+$ && "$BACKUP_HASH" =~ ^[a-f0-9]{64}$ ]] || fail INVALID_BACKUP_ARGS
[[ -d "$SITE" && ! -L "$SITE" && $(realpath "$SITE") == "$SITE" ]] || fail SITE_UNSAFE
for command in git curl python3 sha256sum gzip flock runuser stat realpath find cmp awk; do command -v "$command" >/dev/null || fail MISSING_TOOL; done
exec 9>/run/lock/bambodia-mcp-operational-release.lock
flock -n 9 || fail RELEASE_LOCK_BUSY
exec 8>/run/lock/bambodia-account-overview.lock
flock -n 8 || fail ACCOUNT_RELEASE_LOCK_BUSY
exec 7>/run/lock/bambodia-mcp-phase3c.lock
flock -n 7 || fail PHASE3_LOCK_BUSY
exec 6>/run/lock/bambodia-mcp-phase4d2.lock
flock -n 6 || fail PHASE4_LOCK_BUSY
WORK=$(mktemp -d /root/bambodia-phase4d2e-private.XXXXXXXX)
APPLYING=0
JOURNAL=""
restore_controller(){
  local hash tmp
  [[ -f "$SITE/$CTRL" && ! -L "$SITE/$CTRL" && $(realpath "$(dirname "$SITE/$CTRL")") == "$(dirname "$SITE/$CTRL")" ]] || return 1
  hash=$(git hash-object "$SITE/$CTRL") || return 1
  if [[ "$hash" == "$NEW" ]]; then
    [[ $(git hash-object "$BACKUP/controller.original.php") == "$OLD" ]] || return 1
    tmp=$(mktemp "$(dirname "$SITE/$CTRL")/.phase4rollback.XXXXXXXX") || return 1
    if ! cp --preserve=mode,ownership,timestamps -- "$BACKUP/controller.original.php" "$tmp" || ! mv -fT -- "$tmp" "$SITE/$CTRL"; then rm -f -- "$tmp"; return 1; fi
  elif [[ "$hash" != "$OLD" ]]; then return 1; fi
  [[ $(git hash-object "$SITE/$CTRL") == "$OLD" ]] || return 1
  echo "CONTROLLER_DISK_ROLLBACK=PASS"
  echo "ADDITIVE_MIGRATION_AND_SERVICE_FILES_RETAINED=YES"
  echo "RUNTIME_ROLLBACK_ACCEPTANCE=REQUIRED"
  echo "PRODUCTION_DATABASE_RESTORE=NOT_PERFORMED"
}
finish(){
  local rc=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "APPLY_FAILED_RESTORE_CONTROLLER_ONLY" >&2
    restore_controller || { echo "CONTROLLER_ROLLBACK_FAILED_MANUAL_RECOVERY_REQUIRED" >&2; rc=1; }
    [[ -z "$JOURNAL" ]] || printf 'FAILED_OR_BLOCKED\n' >"$JOURNAL/RESULT"
    echo "DO_NOT_RETRY_OR_RUN_DOWN_MIGRATION_UNTIL_REVIEWED" >&2
  fi
  # Keep private, restricted failure logs; never preserve connection options.
  rm -f -- "$WORK/mysql.cnf" "$WORK/config.json"
  if [[ "$rc" == 0 ]]; then rm -rf --one-file-system -- "$WORK"; else echo "PRIVATE_DIAGNOSTICS=$WORK" >&2; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

RELEASE=c1db97f9798cdd0e9291fb943d5a1a697c2b9f48
PRIOR=/root/bambodia-phase4d2-backup.rt7qWepD
PRIOR_HASH=5ddc106a0cdf49a5a0acb5acc47372dd2e61aed5a1c87751e2bdbfa3459b08b7
FAILED_JOURNAL=/root/bambodia-phase4d2e-deployment.eUVvj7FW
HELPER="$(dirname "$(realpath "$0")")/bambodia-phase4-manifest-verify.py"
HELPER_BLOB=0d1a4345264e9d5ecda4ad067de1886d5c3b79be
verify_helper(){
  [[ -f "$HELPER" && ! -L "$HELPER" && $(git hash-object "$HELPER") == "$HELPER_BLOB" ]] || fail MANIFEST_HELPER_DRIFT
  python3 "$HELPER" --self-test || fail MANIFEST_REGRESSION
  echo "MANIFEST_REGRESSION_TESTS=PASS tests=9"
}
verify_prior(){
  [[ -d "$PRIOR" && ! -L "$PRIOR" && $(stat -c '%u:%a' "$PRIOR") == 0:700 ]] || fail PRIOR_BACKUP_UNSAFE
  [[ -z $(find "$PRIOR" -type l -print -quit) && $(sha256sum "$PRIOR/backup.sha256"|cut -d' ' -f1) == "$PRIOR_HASH" ]] || fail PRIOR_BACKUP_DRIFT
  (cd "$PRIOR"; sha256sum --check --status backup.sha256) || fail PRIOR_BACKUP_CONTENT_DRIFT
  [[ $(cat "$PRIOR/RECOVERY_PASS") == "$HEAD" && $(cat "$PRIOR/evidence/release-package-manifest.txt") == "$MANIFEST" && $(git hash-object "$PRIOR/controller.original.php") == "$OLD" ]] || fail PRIOR_BACKUP_RELEASE_DRIFT
  gzip -t "$PRIOR/database.sql.gz" || fail PRIOR_BACKUP_GZIP
  [[ -d "$FAILED_JOURNAL" && ! -L "$FAILED_JOURNAL" && $(stat -c '%u:%a' "$FAILED_JOURNAL") == 0:700 && $(cat "$FAILED_JOURNAL/RESULT") == FAILED_OR_BLOCKED && ! -e "$FAILED_JOURNAL/DISK_APPLY_PASS" ]] || fail FAILED_JOURNAL_DRIFT
  printf '%s\n' "$PRIOR" "$PRIOR_HASH" "$RELEASE" | cmp -s - "$FAILED_JOURNAL/release-reference.txt" || fail FAILED_JOURNAL_RELEASE_DRIFT
  echo "ORIGINAL_BACKUP_AND_FAILED_JOURNAL=PASS"
}
verify_retained(){
  local phase=$1 blob path n=0
  [[ $(stat -c 'CONTROLLER_OWNER=%u:%g MODE=%a' "$SITE/$CTRL") == "$(cat "$PRIOR/evidence/controller-metadata.txt")" ]] || fail CONTROLLER_METADATA_DRIFT
  while read -r blob path; do
    [[ -f "$PACKAGE/source/$path" && ! -L "$PACKAGE/source/$path" && $(git hash-object "$PACKAGE/source/$path") == "$blob" ]] || fail CANDIDATE_BLOB_DRIFT
    [[ -f "$SITE/$path" && ! -L "$SITE/$path" && $(realpath "$(dirname "$SITE/$path")") == "$(dirname "$SITE/$path")" ]] || fail RETAINED_FILE_PATH_DRIFT
    "$PHP" -n -l "$PACKAGE/source/$path" >/dev/null || fail CANDIDATE_SYNTAX
    if [[ "$path" == "$CTRL" ]]; then
      [[ "$blob" == a302fa364f1f52b6320b77688b7595b91894ca13 ]] || fail CANDIDATE_CONTROLLER_DRIFT
      if [[ "$phase" == before ]]; then
        [[ $(git hash-object "$SITE/$path") == "$OLD" ]] || fail CONTROLLER_BASELINE_DRIFT
      else
        [[ $(git hash-object "$SITE/$path") == "$blob" ]] || fail CONTROLLER_CANDIDATE_DRIFT
      fi
    else
      [[ $(git hash-object "$SITE/$path") == "$blob" && $(stat -c '%U:%G:%a' "$SITE/$path") == www:www:644 ]] || fail RETAINED_FILE_DRIFT
      n=$((n+1))
    fi
  done <"$PACKAGE/source-blobs.txt"
  [[ "$n" == 5 && $(wc -l <"$PACKAGE/source-blobs.txt") == 6 ]] || fail OVERLAY_INVENTORY_DRIFT
  sort "$FAILED_JOURNAL/installed-paths.txt" >"$WORK/journal-paths.txt"
  awk '{print $2}' "$PACKAGE/source-blobs.txt" | sort | cmp -s - "$WORK/journal-paths.txt" || fail FAILED_JOURNAL_INVENTORY_DRIFT
  echo "RETAINED_FIVE_FILES_AND_CANDIDATE_PACKAGE=PASS"
}

# Use the existing root GitHub CLI session; never print or export its token.
# Keep the original GET endpoints, JSON validation and fail-closed gates.
api(){
  local endpoint=$1 target=$2
  command -v gh >/dev/null && command -v timeout >/dev/null || fail GITHUB_CLI_RUNTIME_MISSING
  if ! timeout 60s env -u GH_DEBUG -u DEBUG GH_PROMPT_DISABLED=1 GH_PAGER=cat GH_HOST=github.com \
    gh api --hostname github.com --method GET "repos/$REPO/$endpoint" \
    >"$target.partial" 2>>"$WORK/github-api.private.log"; then
    rm -f -- "$target.partial"
    echo "GITHUB_API_FAILED_ENDPOINT=$endpoint" >&2
    fail GITHUB_AUTHENTICATED_GET_FAILED
  fi
  [[ -s "$target.partial" ]] || fail GITHUB_EMPTY_RESPONSE
  mv -fT -- "$target.partial" "$target"
}
repo_gate(){
  api commits/main "$WORK/main.json"
  api "actions/runs?head_sha=$RELEASE&event=push" "$WORK/ci.json"
  for n in 211 212 213; do api "pulls/$n" "$WORK/pr-$n.json"; done
  for branch in feature/mcp-phase4b-commerce-read-20261128 feature/mcp-phase4c-project-read-20261128 feature/mcp-phase4c-project-write-20261128; do api "commits/$branch" "$WORK/$(basename "$branch").json"; done
  api commits/afab2ac340f2540e16d678ae85f79948685e943f "$WORK/merge-211.json"
  api commits/a01bcba2f552309ce14921d57115cf0bb0ff40b3 "$WORK/merge-212.json"
  python3 - "$WORK" <<'PY'
import json,pathlib,sys
p=pathlib.Path(sys.argv[1]); read=lambda n:json.loads((p/n).read_text())
def gate(ok,msg):
    if not ok: raise SystemExit('PHASE4D2E_BLOCKED='+msg)
seq=[
(211,'bd662887a7408698c2903f8e9baebc723e477336','afab2ac340f2540e16d678ae85f79948685e943f','7b895041c6e8c847616661b2fed58ffcdb8967a5','49b7e8380effee8ea91f91adc4ffca8516c7f07d','mcp-phase4b-commerce-read-20261128'),
(212,'7f46cbd6f8a86e31efdce42cd3796ca38523a0bc','a01bcba2f552309ce14921d57115cf0bb0ff40b3','afab2ac340f2540e16d678ae85f79948685e943f','fda3ef2481445a1bdfe69ac168615c1dc3ff6695','mcp-phase4c-project-read-20261128'),
(213,'f8855a6f7aeaea2086cc566ff9ad056181fea37b','c1db97f9798cdd0e9291fb943d5a1a697c2b9f48','a01bcba2f552309ce14921d57115cf0bb0ff40b3','fab3c6c0611b8f54955673cd2e110ef23994bcf7','mcp-phase4c-project-write-20261128')]
for n,h,m,b,t,branch in seq:
    pr=read(f'pr-{n}.json'); commit=read('main.json' if n==213 else f'merge-{n}.json')
    gate(pr['merged'] and pr['head']['sha']==h and pr['head']['ref']=='feature/'+branch and pr['base']['ref']=='main' and pr['merge_commit_sha']==m,'PR_DRIFT_'+str(n))
    gate(commit['sha']==m and commit['commit']['tree']['sha']==t and [x['sha'] for x in commit['parents']]==[b,h],'MERGE_DRIFT_'+str(n))
    gate(read(branch+'.json')['sha']==h,'FEATURE_BRANCH_DRIFT_'+str(n))
runs=read('ci.json')['workflow_runs']; expected={46132893142:'Bambodia Quality',46132893168:'Bambodia MySQL Installer Acceptance',46132893148:'Bambodia Operational Acceptance',46132893110:'Bambodia Browser Emulation Acceptance',46132893454:'Bambodia Isolated Authenticated Journeys',46132893144:'Build Bambodia Installer'}
gate(len(runs)==6 and {r['id'] for r in runs}==set(expected),'CI_INVENTORY_DRIFT')
for r in runs:
    gate(r['name']==expected[r['id']] and r['head_sha']==seq[-1][2] and r['event']=='push' and r['status']=='completed' and r['conclusion']=='success','CI_NOT_ACCEPTED_'+str(r['id']))
print('FINAL_MAIN_TREE_PARENTS_BRANCHES_AND_SIX_CI=PASS')
PY
}
verify_backup(){
  [[ -d "$BACKUP" && ! -L "$BACKUP" && $(stat -c '%u:%a' "$BACKUP") == 0:700 ]] || fail BACKUP_UNSAFE
  [[ -z $(find "$BACKUP" -type l -print -quit) ]] || fail BACKUP_SYMLINK
  [[ $(sha256sum "$BACKUP/backup.sha256"|cut -d' ' -f1) == "$BACKUP_HASH" ]] || fail BACKUP_MANIFEST_DRIFT
  (cd "$BACKUP"; sha256sum --check --status backup.sha256) || fail BACKUP_CONTENT_DRIFT
  [[ $(cat "$BACKUP/RECOVERY_PASS") == "$HEAD" && $(cat "$BACKUP/evidence/release-package-manifest.txt") == "$MANIFEST" ]] || fail BACKUP_RELEASE_MISMATCH
  [[ $(git hash-object "$BACKUP/controller.original.php") == "$OLD" && $(stat -c '%u:%a' "$BACKUP/database.sql.gz") == 0:600 ]] || fail BACKUP_FILE_DRIFT
  cmp -s "$BACKUP/evidence/restored-counts.tsv" "$BACKUP/evidence/dump-row-counts.tsv" || fail BACKUP_RESTORED_COUNTS
  cmp -s "$BACKUP/evidence/restored-schema.tsv" "$BACKUP/evidence/schema-before.tsv" || fail BACKUP_RESTORED_SCHEMA
  cmp -s "$BACKUP/evidence/restored-gate.tsv" "$BACKUP/evidence/gate.tsv" || fail BACKUP_RESTORED_GATE
  [[ $(cat "$BACKUP/evidence/prior-backup-manifest.txt") == "$PRIOR_HASH" && $(cat "$BACKUP/evidence/migrated-release.txt") == "$RELEASE" ]] || fail MIGRATED_BACKUP_IDENTITY
  [[ $(cat "$BACKUP/evidence/gate.tsv") == $'0\t1\t1\t2\t1' ]] || fail MIGRATED_BACKUP_GATE
  [[ $(awk -F'\t' '$1=="mcp_project_operations"{print $2}' "$BACKUP/evidence/restored-counts.tsv") == 0 ]] || fail MIGRATED_BACKUP_LEDGER_NOT_EMPTY
  gzip -t "$BACKUP/database.sql.gz" || fail BACKUP_GZIP
  python3 - "$BACKUP" <<'PY'
from datetime import datetime,timezone
import pathlib,sys
p=pathlib.Path(sys.argv[1])
def parse_utc(name):
    # Recovery operator emits date -u +%FT%TZ. strptime also supports Python 3.8.
    try:
        return datetime.strptime((p/'evidence'/name).read_text().strip(),'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
    except (ValueError,OSError):
        raise SystemExit('PHASE4D2E_BLOCKED=BACKUP_TIMESTAMP_INVALID')
start=parse_utc('backup-started.txt')
end=parse_utc('backup-completed.txt')
now=datetime.now(timezone.utc)
if not start<=end<=now or (now-start).total_seconds()>=7200: raise SystemExit('PHASE4D2E_BLOCKED=BACKUP_EXPIRED_OR_CLOCK_DRIFT')
PY
  echo "FRESH_RESTORE_VERIFIED_BACKUP=PASS"
}
verify_source(){
  local phase=$1
  [[ -d "$PACKAGE" && ! -L "$PACKAGE" && $(stat -c '%u:%a' "$PACKAGE") == 0:700 ]] || fail PACKAGE_UNSAFE
  [[ -d "$STAGE" && ! -L "$STAGE" && $(stat -c '%u:%a' "$STAGE") == 0:700 ]] || fail STAGE_UNSAFE
  [[ -z $(find "$PACKAGE" "$STAGE/source" "$STAGE/candidate" "$STAGE/verified" -type l -print -quit) ]] || fail SOURCE_SYMLINK
  [[ $(sha256sum "$PACKAGE/package.sha256"|cut -d' ' -f1) == "$MANIFEST" ]] || fail PACKAGE_MANIFEST_DRIFT
  (cd "$PACKAGE"; sha256sum --check --status package.sha256) || fail PACKAGE_CONTENT_DRIFT
  [[ $(cat "$PACKAGE/PREPARE_PASS") == "$HEAD" ]] || fail PACKAGE_RELEASE_DRIFT
  [[ $(sha256sum "$STAGE/manifest.sha256"|cut -d' ' -f1) == "$STAGE_MANIFEST" ]] || fail STAGE_MANIFEST_DRIFT
  (cd "$STAGE"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum | cmp -s - manifest.sha256) || fail STAGE_CONTENT_DRIFT
  python3 - "$SITE" "$STAGE/verified/live-source.sha256" "$PACKAGE/evidence/protected.sha256" <<'PY'
import pathlib,sys
root=pathlib.Path(sys.argv[1])
for manifest in sys.argv[2:]:
    for line in pathlib.Path(manifest).read_text().splitlines():
        _,rel=line.split('  ',1)
        p=root/rel
        if not p.is_file() or any(x.is_symlink() for x in [p,*p.parents]):
            raise SystemExit('PHASE4D2B_BLOCKED=LIVE_SOURCE_PATH_DRIFT')
PY
  python3 "$HELPER" "$SITE" "$STAGE/verified/live-source.sha256" "$phase"
  (cd "$SITE"; sha256sum --check --status "$PACKAGE/evidence/protected.sha256") || fail PROTECTED_SOURCE_DRIFT
  sha256sum --check --status "$PACKAGE/evidence/vhost.sha256" || fail VHOST_DRIFT
  while read -r file state; do
    [[ "$state" == absent && ! -e "$SITE/$file" && ! -L "$SITE/$file" ]] || fail PROTECTED_PRESENCE_DRIFT
  done <"$PACKAGE/evidence/protected-absent.txt"
  verify_retained "$phase"
  echo "RELEASE_PACKAGE_AND_LIVE_SOURCE_INTEGRITY=PASS"
}
db_gate(){
  local phase=$1
# Load the application's environment and config only. Do not bootstrap
# providers, routes or a production application request.
(
  cd "$SITE"
  runuser -u www -- env -i PATH=/usr/bin:/bin "$PHP" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->bootstrapWith([
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);
if (!app()->environment('production') || config('mcp.production_enabled')!==true ||
    config('mcp.production_writes_enabled')!==true || rtrim(config('app.url'),'/')!=='https://bambodia.com') exit(8);
$name=config('database.default');
$raw=config('database.connections.'.$name);
if (!is_array($raw)) exit(9);
$cfg=(new \Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($raw);
if (($cfg['driver']??null)!=='mysql' || isset($cfg['read']) || isset($cfg['write'])) exit(10);
// Open the exact Laravel PDO target without booting service providers.
// Only a read-only identity query; no model, session or application request.
$pdo=(new \Illuminate\Database\Connectors\MySqlConnector)->connect($cfg);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$identity=$pdo->query('SELECT DATABASE(), @@server_uuid, @@version, @@datadir, @@socket')->fetch(\PDO::FETCH_NUM);
$pdo=null;
$out=array_intersect_key($cfg,array_flip(['host','port','unix_socket','database','username','password']));
$out['pdo_identity']=$identity;
echo json_encode($out,JSON_THROW_ON_ERROR);
PHP
) >"$WORK/config.json" 2>"$WORK/config.private.log" || fail EFFECTIVE_CONFIG_PROBE
python3 - "$WORK" <<'PY'
import hashlib,json,pathlib,re,sys
w=pathlib.Path(sys.argv[1]); d=json.loads((w/'config.json').read_text())
def require(ok,msg):
    if not ok: raise SystemExit('PHASE4D2E_BLOCKED='+msg)
host=d.get('host'); socket=d.get('unix_socket') or ''; db=d.get('database'); port=d.get('port') or 3306
require(isinstance(db,str) and re.fullmatch(r'[A-Za-z0-9_]+',db) and db.lower() not in ('mysql','sys','information_schema','performance_schema'),'UNSAFE_DATABASE_NAME')
require(isinstance(d.get('username'),str) and bool(d['username']),'INVALID_DB_USER')
require(isinstance(d.get('password'),(str,type(None))),'INVALID_DB_PASSWORD')
require(isinstance(socket,str) and (not socket or socket.startswith('/')),'INVALID_DB_SOCKET')
require(bool(socket) or host in ('localhost','127.0.0.1','::1'),'NONLOCAL_DB_TARGET')
require(str(port).isdigit() and 0<int(port)<65536,'INVALID_DB_PORT')
identity_row=d.get('pdo_identity')
require(isinstance(identity_row,list) and len(identity_row)==5 and all(isinstance(v,str) and '\t' not in v and '\n' not in v for v in identity_row),'INVALID_PDO_IDENTITY')
(w/'pdo-server.tsv').write_text('\t'.join(identity_row)+'\n')
def quote(v):
    v=str(v or '')
    require(not any(ord(c)<32 or ord(c)==127 for c in v),'CONTROL_CHAR_IN_DB_OPTION')
    return '"'+v.replace('\\','\\\\').replace('"','\\"')+'"'
lines=['[client]','user='+quote(d['username']),'password='+quote(d.get('password'))]
if socket: lines+=['protocol=SOCKET','socket='+quote(socket)]
else: lines+=['protocol=TCP','host='+quote(host),'port='+quote(port)]
(w/'mysql.cnf').write_text('\n'.join(lines)+'\n')
(w/'db-name').write_text(db)
identity={'host':host,'port':port,'socket':socket,'database':db,'username':d['username']}
(w/'db-target.sha256').write_text(hashlib.sha256(json.dumps(identity,sort_keys=True).encode()).hexdigest()+'\n')
PY
rm -- "$WORK/config.json"
DB=$(cat "$WORK/db-name")
prod_mysql(){
  env -i PATH=/usr/bin:/bin HOME="$WORK" "$MYSQL" --defaults-file="$WORK/mysql.cnf" --batch --skip-column-names --database="$DB" --connect-timeout=10 "$@"
}
# Every explicit production SQL statement is a SELECT in a read-only session.
prod_query(){ prod_mysql --init-command='SET SESSION TRANSACTION READ ONLY' "$@"; }
prod_query -e 'SELECT DATABASE(), @@server_uuid, @@version, @@datadir, @@socket' >"$WORK/server.tsv" 2>"$WORK/mysql.private.log" || fail DATABASE_CONNECTION
cmp -s "$WORK/pdo-server.tsv" "$WORK/server.tsv" || fail LARAVEL_AND_CLI_DATABASE_TARGET_MISMATCH
python3 - "$WORK" <<'PY'
import hashlib,pathlib,re,sys
w=pathlib.Path(sys.argv[1]); row=(w/'server.tsv').read_text().strip().split('\t')
if len(row)!=5 or row[0]!=(w/'db-name').read_text() or not re.fullmatch(r'[0-9a-fA-F-]{36}',row[1]) or not re.match(r'^8\.0\.\d+',row[2]): raise SystemExit('PHASE4D2E_BLOCKED=DB_IDENTITY_MISMATCH')
data=pathlib.Path(row[3]).resolve()
# Fail closed on an unfamiliar installation; never mask arbitrary host paths.
if data not in [pathlib.Path('/www/server/data'),pathlib.Path('/www/server/mysql/data'),pathlib.Path('/var/lib/mysql')]: raise SystemExit('PHASE4D2E_BLOCKED=UNREVIEWED_MYSQL_DATADIR')
socket=pathlib.Path(row[4])
if not socket.is_absolute(): socket=data/socket
socket=socket.resolve()
masked=[pathlib.Path(x) for x in ('/tmp','/run','/www/server/data','/www/server/mysql/data','/var/lib/mysql','/www/wwwroot','/root')]
if not any(p in socket.parents for p in masked): raise SystemExit('PHASE4D2E_BLOCKED=UNREVIEWED_MYSQL_SOCKET')
(w/'live-datadir').write_text(str(data))
(w/'live-socket').write_text(str(socket))
(w/'server-version').write_text(row[2])
(w/'server-identity.sha256').write_text(hashlib.sha256(('\t'.join(row)).encode()).hexdigest()+'\n')
PY
echo "LARAVEL_PDO_AND_CLI_DATABASE_IDENTITY=PASS"
echo "LOCAL_MYSQL_TARGET_AND_MCP_CONFIG=PASS"
cat >"$WORK/schema.sql" <<'SQL'
SELECT 'TABLE',TABLE_NAME,TABLE_TYPE,COALESCE(ENGINE,''),COALESCE(TABLE_COLLATION,'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT 'COLUMN',TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COALESCE(COLLATION_NAME,''),GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT 'INDEX',TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
SELECT 'FK',TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION;
SELECT 'FK_RULE',TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME;
SELECT 'VIEW',TABLE_NAME,CHECK_OPTION,IS_UPDATABLE,SECURITY_TYPE,VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT 'TRIGGER',TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME;
SELECT 'ROUTINE',ROUTINE_NAME,ROUTINE_TYPE,DTD_IDENTIFIER,SECURITY_TYPE,ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME,ROUTINE_TYPE;
SELECT 'EVENT',EVENT_NAME,EVENT_TYPE,STATUS,EVENT_DEFINITION FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME;
SQL
cat >"$WORK/gate.sql" <<'SQL'
SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND (ENGINE IS NULL OR ENGINE<>'InnoDB')) AS non_innodb,
(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_project_operations') AS new_table,
(SELECT COUNT(*) FROM migrations WHERE migration='2026_11_28_100000_create_mcp_project_operations') AS new_migration,
(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','audit_events') AND COLUMN_NAME='id' AND COLUMN_TYPE='bigint unsigned' AND COLUMN_KEY='PRI') AS required_ids,
(SELECT COUNT(*) FROM migrations WHERE migration='2026_11_28_080000_add_description_to_media_assets') AS phase3_migration;
SQL

  cmp -s "$WORK/db-target.sha256" "$BACKUP/evidence/db-target.sha256" || fail BACKUP_DATABASE_TARGET_DRIFT
  cmp -s "$WORK/server-identity.sha256" "$BACKUP/evidence/server-identity.sha256" || fail BACKUP_MYSQL_SERVER_DRIFT
  prod_query <"$WORK/gate.sql" >"$WORK/current-gate.tsv" 2>>"$WORK/mysql.private.log" || fail SCHEMA_GATE_QUERY
  prod_query <"$WORK/schema.sql" >"$WORK/current-schema.tsv" 2>>"$WORK/mysql.private.log" || fail SCHEMA_CAPTURE
  [[ "$phase" == retained || "$phase" == active ]] || fail INVALID_DB_PHASE
  [[ $(cat "$WORK/current-gate.tsv") == $'0\t1\t1\t2\t1' ]] || fail MIGRATED_SCHEMA_GATE
  cmp -s "$WORK/db-target.sha256" "$PRIOR/evidence/db-target.sha256" || fail PRIOR_DATABASE_TARGET_DRIFT
  cmp -s "$WORK/server-identity.sha256" "$PRIOR/evidence/server-identity.sha256" || fail PRIOR_SERVER_DRIFT
  cmp -s "$WORK/current-schema.tsv" "$BACKUP/evidence/schema-before.tsv" || fail MIGRATED_BACKUP_SCHEMA_DRIFT
  awk -F'\t' '$2 != "mcp_project_operations"' "$WORK/current-schema.tsv" >"$WORK/unchanged-schema.tsv"
  cmp -s "$WORK/unchanged-schema.tsv" "$PRIOR/evidence/schema-before.tsv" || fail UNEXPECTED_SCHEMA_CHANGE
    python3 - "$WORK/current-schema.tsv" <<'PY'
import pathlib,sys
rows=[l.split('\t') for l in pathlib.Path(sys.argv[1]).read_text().splitlines()]
r=[x for x in rows if len(x)>1 and x[1]=='mcp_project_operations']
def gate(ok,msg):
    if not ok: raise SystemExit('PHASE4D2E_BLOCKED='+msg)
cols={x[3]:(x[4],x[5]) for x in r if x[0]=='COLUMN'}
expected={'id':('bigint unsigned','NO'),'actor_id':('bigint unsigned','NO'),'request_id':('char(36)','NO'),'tool':('varchar(64)','NO'),'payload_digest':('char(64)','NO'),'resource_reference':('varchar(10)','NO'),'audit_event_id':('bigint unsigned','NO'),'result':('json','NO'),'created_at':('timestamp','NO')}
gate(cols==expected,'LEDGER_COLUMNS')
gate(any(x[0]=='COLUMN' and x[3]=='id' and 'auto_increment' in x[7] for x in r),'LEDGER_AUTO_INCREMENT')
idx=[x for x in r if x[0]=='INDEX']
gate([(x[4],x[5],x[3]) for x in idx if x[2]=='mcp_project_actor_request_unique']==[('1','actor_id','0'),('2','request_id','0')],'LEDGER_UNIQUE_INDEX')
gate([(x[4],x[5],x[3]) for x in idx if x[2]=='PRIMARY']==[('1','id','0')],'LEDGER_PRIMARY_KEY')
fk=[(x[4],x[5],x[6]) for x in r if x[0]=='FK']
gate(set(fk)=={('actor_id','users','id'),('audit_event_id','audit_events','id')} and len(fk)==2,'LEDGER_FKS')
rules=[x for x in r if x[0]=='FK_RULE']
gate(len(rules)==2 and all(x[4]=='RESTRICT' for x in rules),'LEDGER_DELETE_RULE')
print('ADDITIVE_LEDGER_SCHEMA_AND_CONSTRAINTS=PASS')
PY
  if [[ "$phase" == retained ]]; then
    [[ $(prod_query -e 'SELECT COUNT(*) FROM mcp_project_operations' 2>>"$WORK/mysql.private.log") == 0 ]] || fail LEDGER_NOT_EMPTY_BEFORE_ACTIVATION
    echo "LEDGER_EMPTY_BEFORE_ACTIVATION=PASS"
  fi
  rm -f -- "$WORK/mysql.cnf" "$WORK/db-name"
  echo "DATABASE_TARGET_AND_SCHEMA_$phase=PASS"
}
health(){
  local code
  code=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' https://bambodia.com/) || fail HOME_HTTP
  [[ "$code" == 200 ]] || fail HOME_NOT_200
  curl -fLsS --max-time 30 https://bambodia.com/.well-known/oauth-protected-resource/mcp -o "$WORK/resource.json" || fail OAUTH_METADATA_HTTP
  python3 - "$WORK/resource.json" <<'PY'
import json,sys
d=json.load(open(sys.argv[1]))
if d['resource']!='https://bambodia.com/mcp' or d['authorization_servers']!=['https://bambodia.com']: raise SystemExit('PHASE4D2E_BLOCKED=OAUTH_RESOURCE_DRIFT')
PY
  code=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' https://bambodia.com/mcp) || fail MCP_HTTP
  [[ "$code" == 401 ]] || fail UNAUTHENTICATED_MCP_NOT_401
  echo "PUBLIC_HOME_OAUTH_METADATA_AND_UNAUTHENTICATED_GATE=PASS"
}
fpm_gate(){
  # Read only. No reload/restart, no webroot diagnostic endpoint.
  "$FPM" -i >"$WORK/fpm-info.private.log" 2>&1 || fail FPM_INFO
  "$FPM" -tt >"$WORK/fpm-config.private.log" 2>&1 || fail FPM_CONFIG
  python3 - "$WORK" <<'PY'
import pathlib,re,sys
p=pathlib.Path(sys.argv[1]); info=(p/'fpm-info.private.log').read_text(errors='replace'); config=(p/'fpm-config.private.log').read_text(errors='replace')
settings={}
for line in info.splitlines():
    if line.startswith('opcache.') and ' => ' in line:
        k,*v=line.split(' => ');settings[k]=v
def gate(ok,msg):
    if not ok: raise SystemExit('PHASE4D2E_BLOCKED='+msg)
gate(settings.get('opcache.validate_timestamps')==['On','On'],'FPM_OPCACHE_TIMESTAMP_CONFIG')
v=settings.get('opcache.revalidate_freq',[])
gate(len(v)==2 and all(x.isdigit() and int(x)<=2 for x in v),'FPM_OPCACHE_REVALIDATE_CONFIG')
gate(not re.search(r'php_(?:admin_)?(?:value|flag)\[opcache\.(?:validate_timestamps|revalidate_freq)\]',config,re.I),'FPM_OPCACHE_POOL_OVERRIDE')
print('FPM_DISK_CONFIG_TIMESTAMP_REVALIDATION=PASS')
PY
}
verify_helper
verify_prior
repo_gate
verify_backup
verify_source before
db_gate retained
fpm_gate
health
echo "CONTROLLER_RECOVERY_PREFLIGHT=PASS"
if [[ "$MODE" == --preflight ]]; then
  echo "CONTROLLER_REACTIVATED=NO"
  echo "MIGRATION_REEXECUTED=NO"
  exit 0
fi
# Same lock scope; recheck immediately before the only authorized file change.
repo_gate
verify_prior
verify_backup
verify_source before
db_gate retained
JOURNAL=$(mktemp -d /root/bambodia-phase4d2e-controller-recovery.XXXXXXXX)
printf '%s\n' "$BACKUP" "$BACKUP_HASH" "$RELEASE" >"$JOURNAL/release-reference.txt"
echo "CONTROLLER_RECOVERY_JOURNAL=$JOURNAL"
APPLYING=1
tmp=$(mktemp "$(dirname "$SITE/$CTRL")/.phase4recovery.XXXXXXXX")
if ! cp -- "$PACKAGE/source/$CTRL" "$tmp" || ! chown --reference="$PRIOR/controller.original.php" "$tmp" || ! chmod --reference="$PRIOR/controller.original.php" "$tmp" || [[ $(git hash-object "$tmp") != "$NEW" ]] || [[ $(git hash-object "$SITE/$CTRL") != "$OLD" ]] || ! mv -fT -- "$tmp" "$SITE/$CTRL"; then
  rm -f -- "$tmp"
  fail CONTROLLER_ACTIVATION
fi
printf '%s\n' "$CTRL" >"$JOURNAL/installed-paths.txt"
verify_source after
db_gate active
sleep 3
health
verify_source after
printf '%s\n' "$RELEASE" >"$JOURNAL/CONTROLLER_DISK_APPLY_PASS"
APPLYING=0
echo "CONTROLLER_ONLY_DISK_APPLY=PASS"
echo "MIGRATION_REEXECUTED=NO"
echo "FIVE_RETAINED_FILES_REINSTALLED=NO"
echo "PRODUCTION_SERVICE_RESTARTED=NO"
echo "PRODUCTION_DATABASE_RESTORE=NOT_PERFORMED"
echo "EXPECTED_MCP_REGISTRY=38 READ=25 WRITE=13"
echo "AUTHENTICATED_LIVE_RUNTIME_ACCEPTANCE=PENDING"
