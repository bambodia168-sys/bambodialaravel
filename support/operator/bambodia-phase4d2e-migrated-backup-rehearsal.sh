#!/usr/bin/env bash
# Phase 4D.2E.3B: migrated-state backup and isolated restore; no production writes.
set -Eeuo pipefail
set +x
umask 077
PACKAGE=/root/bambodia-phase4d2-preparation.2PmfQTGk
MANIFEST=95e0bc0309565982a0544b769178805a2979b71a2832c66193a0ff51dea4ef90
STAGE=/var/tmp/bambodia-phase4-review.H49cOiGy
STAGE_MANIFEST=608457a44dcdf87cb5438881d6fb102b151ce8408cd7adcac72637b51e43d434
HEAD=f8855a6f7aeaea2086cc566ff9ad056181fea37b
SITE=/www/wwwroot/bambodia-staging-qa
CTRL=app/Http/Controllers/Api/BambodiaMcpController.php
OLD=832c692c12ef23e3584cc820512619383822c406
PHP=/www/server/php/85/bin/php
MYSQLBASE=/www/server/mysql
MYSQL=$MYSQLBASE/bin/mysql
DUMP=$MYSQLBASE/bin/mysqldump
DAEMON=$MYSQLBASE/bin/mysqld
fail(){ echo "PHASE4D2B_BLOCKED=$*" >&2; exit 1; }
[[ $# == 1 && "$1" == --backup-and-rehearse ]] || fail INVALID_MODE
[[ $(id -u) == 0 ]] || fail ROOT_REQUIRED
for cmd in git sha256sum python3 gzip flock runuser unshare mount setpriv getent df stat cmp realpath timeout findmnt; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
for path in "$PHP" "$MYSQL" "$DUMP" "$DAEMON"; do [[ -x "$path" ]] || fail REQUIRED_BINARY_MISSING; done
[[ -d "$SITE" && ! -L "$SITE" && $(realpath "$SITE") == "$SITE" ]] || fail SITE_UNSAFE
exec 9>/run/lock/bambodia-mcp-operational-release.lock
flock -n 9 || fail RELEASE_LOCK_BUSY
exec 8>/run/lock/bambodia-account-overview.lock
flock -n 8 || fail ACCOUNT_RELEASE_LOCK_BUSY
exec 7>/run/lock/bambodia-mcp-phase3c.lock
flock -n 7 || fail PHASE3_LOCK_BUSY
exec 6>/run/lock/bambodia-mcp-phase4d2.lock
flock -n 6 || fail PHASE4_LOCK_BUSY
WORK=$(mktemp -d /root/bambodia-phase4d2-secret.XXXXXXXX)
BACKUP=""
RUNTIME=""
cleanup(){
  local rc=$?
  trap - EXIT
  # Preserve only private error logs on failure, never credentials or SQL dump
  # contents in terminal output. A failed backup cannot have a readiness seal.
  if [[ "$rc" != 0 ]]; then
    local diag
    diag=$(mktemp -d /root/bambodia-phase4d2-diagnostics.XXXXXXXX) || diag=""
    if [[ -n "$diag" ]]; then
      for f in config.private.log mysql.private.log dump.private.log restore.private.log; do
        if [[ -f "$WORK/$f" && ! -L "$WORK/$f" ]]; then install -m 600 "$WORK/$f" "$diag/$f" || true; fi
      done
      if [[ -n "$RUNTIME" && -d "$RUNTIME" && ! -L "$RUNTIME" ]]; then
        for f in init.log init.stdout server.log server.stdout restore.stderr; do
          if [[ -f "$RUNTIME/$f" && ! -L "$RUNTIME/$f" ]]; then install -m 600 "$RUNTIME/$f" "$diag/$f" || true; fi
        done
      fi
      echo "PRIVATE_DIAGNOSTICS=$diag" >&2
    fi
  fi
  if [[ -n "$RUNTIME" ]]; then
    if [[ "$RUNTIME" =~ ^/var/tmp/bambodia-phase4-mysql-rehearsal\.[A-Za-z0-9]+$ && ! -L "$RUNTIME" ]]; then
      rm -rf --one-file-system -- "$RUNTIME" || rc=1
      [[ ! -e "$RUNTIME" && ! -L "$RUNTIME" ]] || rc=1
    else rc=1; fi
  fi
  if [[ "$WORK" =~ ^/root/bambodia-phase4d2-secret\.[A-Za-z0-9]+$ && ! -L "$WORK" ]]; then
    rm -rf --one-file-system -- "$WORK" || rc=1
    [[ ! -e "$WORK" && ! -L "$WORK" ]] || rc=1
  else rc=1; fi
  if [[ "$rc" != 0 ]]; then
    if [[ -n "$BACKUP" && -d "$BACKUP" && ! -L "$BACKUP" ]]; then
      rm -f -- "$BACKUP/RECOVERY_PASS" "$BACKUP/backup.sha256"
    fi
    echo "PHASE4D2B_RECOVERY_READINESS=FAILED_OR_BLOCKED" >&2
  else
    echo "TEMPORARY_CREDENTIAL_FILES_AND_RESTORE_RUNTIME_REMOVED=YES"
  fi
  exit "$rc"
}
trap cleanup EXIT
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

verify_helper
verify_prior
verify_source(){
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
  (cd "$SITE"; sha256sum --check --status "$STAGE/verified/live-source.sha256"; sha256sum --check --status "$PACKAGE/evidence/protected.sha256") || fail LIVE_SOURCE_DRIFT
  sha256sum --check --status "$PACKAGE/evidence/vhost.sha256" || fail VHOST_DRIFT
  while read -r file state; do
    [[ "$state" == absent && ! -e "$SITE/$file" && ! -L "$SITE/$file" ]] || fail PROTECTED_PRESENCE_DRIFT
  done <"$PACKAGE/evidence/protected-absent.txt"
  [[ $(git hash-object "$SITE/$CTRL") == "$OLD" ]] || fail CONTROLLER_DRIFT
  python3 "$HELPER" "$SITE" "$STAGE/verified/live-source.sha256" before
  verify_retained before
  echo "RELEASE_PACKAGE_AND_LIVE_SOURCE_INTEGRITY=PASS"
}
verify_source
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
    if not ok: raise SystemExit('PHASE4D2B_BLOCKED='+msg)
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
if len(row)!=5 or row[0]!=(w/'db-name').read_text() or not re.fullmatch(r'[0-9a-fA-F-]{36}',row[1]) or not re.match(r'^8\.0\.\d+',row[2]): raise SystemExit('PHASE4D2B_BLOCKED=DB_IDENTITY_MISMATCH')
data=pathlib.Path(row[3]).resolve()
# Fail closed on an unfamiliar installation; never mask arbitrary host paths.
if data not in [pathlib.Path('/www/server/data'),pathlib.Path('/www/server/mysql/data'),pathlib.Path('/var/lib/mysql')]: raise SystemExit('PHASE4D2B_BLOCKED=UNREVIEWED_MYSQL_DATADIR')
socket=pathlib.Path(row[4])
if not socket.is_absolute(): socket=data/socket
socket=socket.resolve()
masked=[pathlib.Path(x) for x in ('/tmp','/run','/www/server/data','/www/server/mysql/data','/var/lib/mysql','/www/wwwroot','/root')]
if not any(p in socket.parents for p in masked): raise SystemExit('PHASE4D2B_BLOCKED=UNREVIEWED_MYSQL_SOCKET')
(w/'live-datadir').write_text(str(data))
(w/'live-socket').write_text(str(socket))
(w/'server-version').write_text(row[2])
(w/'server-identity.sha256').write_text(hashlib.sha256(('\t'.join(row)).encode()).hexdigest()+'\n')
PY
echo "LARAVEL_PDO_AND_CLI_DATABASE_IDENTITY=PASS"
echo "LOCAL_MYSQL_TARGET_AND_MCP_CONFIG=PASS"
# Stable metadata, excluding live cardinality, auto-increment and row estimates.
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
prod_query <"$WORK/gate.sql" >"$WORK/gate.tsv" 2>>"$WORK/mysql.private.log" || fail PRODUCTION_SCHEMA_QUERY
[[ $(cat "$WORK/gate.tsv") == $'0\t1\t1\t2\t1' ]] || fail MIGRATED_SCHEMA_PRECONDITIONS
prod_query <"$WORK/schema.sql" >"$WORK/schema-before.tsv" 2>>"$WORK/mysql.private.log" || fail PRODUCTION_SCHEMA_CAPTURE
cmp -s "$WORK/db-target.sha256" "$PRIOR/evidence/db-target.sha256" || fail PRIOR_DATABASE_TARGET_DRIFT
cmp -s "$WORK/server-identity.sha256" "$PRIOR/evidence/server-identity.sha256" || fail PRIOR_MYSQL_SERVER_DRIFT
awk -F'\t' '$2 != "mcp_project_operations"' "$WORK/schema-before.tsv" >"$WORK/unchanged-schema.tsv"
cmp -s "$WORK/unchanged-schema.tsv" "$PRIOR/evidence/schema-before.tsv" || fail UNEXPECTED_SCHEMA_CHANGE
    python3 - "$WORK/schema-before.tsv" <<'PY'
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
[[ $(prod_query -e 'SELECT COUNT(*) FROM mcp_project_operations' 2>>"$WORK/mysql.private.log") == 0 ]] || fail LEDGER_NOT_EMPTY
echo "MIGRATED_SCHEMA_AND_EMPTY_LEDGER=PASS"
prod_query -e "SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'" >"$WORK/size" 2>>"$WORK/mysql.private.log" || fail DATABASE_SIZE_QUERY
echo "PRODUCTION_SCHEMA_PRECONDITIONS=PASS"
python3 - "$WORK" <<'PY'
import pathlib,shutil,sys
w=pathlib.Path(sys.argv[1]); size=int((w/'size').read_text())
need=max(1024**3, 4*size+512*1024**2)
if any(shutil.disk_usage(p).free<need for p in ('/root','/var/tmp')): raise SystemExit('PHASE4D2B_BLOCKED=INSUFFICIENT_DISK')
mem=next(int(l.split()[1])*1024 for l in pathlib.Path('/proc/meminfo').read_text().splitlines() if l.startswith('MemAvailable:'))
if mem<512*1024**2: raise SystemExit('PHASE4D2B_BLOCKED=INSUFFICIENT_AVAILABLE_MEMORY')
print('BACKUP_AND_RESTORE_CAPACITY=PASS')
print('ESTIMATED_DATABASE_BYTES='+str(size))
PY
# Test isolation availability before collecting the sensitive backup.
unshare --mount --net --pid --fork --kill-child=SIGKILL --mount-proc bash -c 'mount --make-rprivate /; test -r /proc/self/status' >/dev/null 2>&1 || fail NAMESPACE_PREFLIGHT
# Separate numeric UID, without creating users or groups on the host.
uid=62000
while getent passwd "$uid" >/dev/null || getent group "$uid" >/dev/null; do uid=$((uid+1)); [[ "$uid" -lt 63000 ]] || fail NO_UNUSED_UID; done
BACKUP=$(mktemp -d /root/bambodia-phase4d2-migrated-backup.XXXXXXXX)
chmod 700 "$BACKUP"
echo "BACKUP=$BACKUP"
mkdir "$BACKUP/evidence"
cp -a "$PACKAGE/rollback/controller.original.php" "$BACKUP/controller.original.php"
cp "$PACKAGE/rollback/controller-metadata.txt" "$BACKUP/evidence/"
cp "$PACKAGE/evidence/protected.sha256" "$PACKAGE/evidence/protected-absent.txt" "$PACKAGE/evidence/vhost.sha256" "$BACKUP/evidence/"
cp "$WORK/db-target.sha256" "$WORK/server-identity.sha256" "$WORK/schema-before.tsv" "$WORK/gate.tsv" "$WORK/server-version" "$BACKUP/evidence/"
printf '%s\n' "$MANIFEST" >"$BACKUP/evidence/release-package-manifest.txt"
printf '%s\n' "$PRIOR_HASH" >"$BACKUP/evidence/prior-backup-manifest.txt"
printf '%s\n' "$RELEASE" >"$BACKUP/evidence/migrated-release.txt"
date -u +%FT%TZ >"$BACKUP/evidence/backup-started.txt"
echo "=== Consistent private logical backup ==="
env -i PATH=/usr/bin:/bin HOME="$WORK" "$DUMP" --defaults-file="$WORK/mysql.cnf" \
  --single-transaction --quick --skip-lock-tables --skip-add-locks --no-tablespaces \
  --set-gtid-purged=OFF --routines --events --triggers --hex-blob --skip-extended-insert \
  --databases "$DB" 2>"$WORK/dump.private.log" | gzip -1 >"$BACKUP/database.sql.gz" || fail LOGICAL_BACKUP
gzip -t "$BACKUP/database.sql.gz" || fail GZIP_INTEGRITY
[[ -s "$BACKUP/database.sql.gz" ]] || fail EMPTY_BACKUP
# Compare schema before/after dump; no global lock or production mutations.
prod_query <"$WORK/schema.sql" >"$WORK/schema-after.tsv" 2>>"$WORK/mysql.private.log" || fail PRODUCTION_SCHEMA_RECHECK
cmp -s "$WORK/schema-before.tsv" "$WORK/schema-after.tsv" || fail CONCURRENT_SCHEMA_DRIFT
prod_query <"$WORK/gate.sql" >"$WORK/gate-after.tsv" 2>>"$WORK/mysql.private.log" || fail PRODUCTION_GATE_RECHECK
cmp -s "$WORK/gate.tsv" "$WORK/gate-after.tsv" || fail PRODUCTION_SCHEMA_GATE_DRIFT
prod_query -e 'SELECT DATABASE(), @@server_uuid, @@version, @@datadir, @@socket' >"$WORK/server-after.tsv" 2>>"$WORK/mysql.private.log" || fail DATABASE_TARGET_RECHECK
cmp -s "$WORK/server.tsv" "$WORK/server-after.tsv" || fail DATABASE_SERVER_DRIFT
[[ $(prod_query -e 'SELECT COUNT(*) FROM mcp_project_operations' 2>>"$WORK/mysql.private.log") == 0 ]] || fail LEDGER_CHANGED_DURING_BACKUP
date -u +%FT%TZ >"$BACKUP/evidence/backup-completed.txt"
# Remove the production connection option file before starting isolated restore.
rm -- "$WORK/mysql.cnf"
echo "PRODUCTION_BACKUP_AND_SCHEMA_STABILITY=PASS"
echo "DATABASE_BACKUP_BYTES=$(stat -c '%s' "$BACKUP/database.sql.gz")"
# With skip-extended-insert, each emitted INSERT is exactly one row.
# Validate dump completion, all table names, and build expected row counts.
python3 - "$BACKUP" "$WORK" <<'PY'
import gzip,pathlib,re,sys
b,w=map(pathlib.Path,sys.argv[1:])
tables={}
for line in (w/'schema-before.tsv').read_text().splitlines():
    c=line.split('\t')
    if c[0]=='TABLE' and c[2]=='BASE TABLE':
        if not re.fullmatch(r'[A-Za-z0-9_]+',c[1]): raise SystemExit('PHASE4D2B_BLOCKED=UNSAFE_TABLE_NAME')
        tables[c[1]]=0
if not tables: raise SystemExit('PHASE4D2B_BLOCKED=EMPTY_SCHEMA')
complete=False
with gzip.open(b/'database.sql.gz','rb') as fh:
    for line in fh:
        if line.startswith(b'INSERT INTO '):
            m=re.match(rb'INSERT INTO \x60([A-Za-z0-9_]+)\x60 VALUES \(',line)
            if not m or not line.rstrip().endswith(b');'): raise SystemExit('PHASE4D2B_BLOCKED=UNEXPECTED_INSERT_FORMAT')
            table=m.group(1).decode()
            if table not in tables: raise SystemExit('PHASE4D2B_BLOCKED=UNEXPECTED_DUMP_TABLE')
            tables[table]+=1
        if line.startswith(b'-- Dump completed on '): complete=True
if not complete: raise SystemExit('PHASE4D2B_BLOCKED=DUMP_COMPLETION_MISSING')
if tables.get('mcp_project_operations') != 0: raise SystemExit('PHASE4D2B_BLOCKED=DUMP_LEDGER_NOT_EMPTY')
(b/'evidence/dump-row-counts.tsv').write_text(''.join(f'{t}\t{n}\n' for t,n in sorted(tables.items())))
(w/'counts.sql').write_text(''.join(f"SELECT '{t}', COUNT(*) FROM \x60{t}\x60;\n" for t in sorted(tables)))
print('DUMP_ROW_INVENTORY=PASS tables='+str(len(tables)))
PY
RUNTIME=$(mktemp -d /var/tmp/bambodia-phase4-mysql-rehearsal.XXXXXXXX)
chmod 700 "$RUNTIME"
cp "$WORK/schema.sql" "$WORK/gate.sql" "$WORK/counts.sql" "$RUNTIME/"
cp "$WORK/server-version" "$RUNTIME/expected-version"
cat >"$RUNTIME/restore-worker.sh" <<'WORKER'
#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
runtime=$1; base=$2; db=$3
cd "$runtime"
mkdir data tmp
mysql=$base/bin/mysql
daemon=$base/bin/mysqld
# --no-defaults, private datadir/socket, no TCP or event scheduler.
"$daemon" --no-defaults --initialize-insecure --basedir="$base" --datadir="$runtime/data" --log-error="$runtime/init.log" </dev/null >"$runtime/init.stdout" 2>&1
"$daemon" --no-defaults --basedir="$base" --datadir="$runtime/data" \
  --socket="$runtime/mysql.sock" --pid-file="$runtime/mysql.pid" --log-error="$runtime/server.log" \
  --tmpdir="$runtime/tmp" --skip-networking --mysqlx=OFF --event-scheduler=OFF \
  --skip-log-bin --skip-replica-start --local-infile=0 --secure-file-priv=NULL \
  --innodb-buffer-pool-size=64M --performance-schema=OFF --max-connections=10 \
  </dev/null >"$runtime/server.stdout" 2>&1 &
pid=$!
finish(){ local rc=$?; trap - EXIT; kill -TERM "$pid" 2>/dev/null || true; wait "$pid" || true; exit "$rc"; }
trap finish EXIT
cli(){ "$mysql" --no-defaults --protocol=SOCKET --socket="$runtime/mysql.sock" --user=root --batch --skip-column-names --binary-mode=1 --local-infile=0 "$@"; }
ready=0
for i in $(seq 1 90); do
  if cli -e 'SELECT 1' </dev/null >/dev/null 2>&1; then ready=1; break; fi
  kill -0 "$pid" || exit 21
  sleep 1
done
[[ "$ready" == 1 ]] || exit 22
cli -e 'SELECT @@version, @@skip_networking, @@event_scheduler, @@local_infile, @@datadir, @@socket' </dev/null > "$runtime/identity.tsv"
python3 - "$runtime" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1]); a=(p/'identity.tsv').read_text().strip().split('\t')
assert a==[(p/'expected-version').read_text(),'1','OFF','0',str(p/'data')+'/',str(p/'mysql.sock')], 'ISOLATED_MYSQL_IDENTITY_MISMATCH'
PY
# Input is the verified dump streamed from the parent. No production option file.
cli >"$runtime/restore.stdout" 2>"$runtime/restore.stderr"
cli --database="$db" <"$runtime/schema.sql" >"$runtime/restored-schema.tsv"
cli --database="$db" <"$runtime/gate.sql" >"$runtime/restored-gate.tsv"
cli --database="$db" <"$runtime/counts.sql" >"$runtime/restored-counts.tsv"
echo "ISOLATED_MYSQL_RESTORE=PASS"
WORKER
chmod 700 "$RUNTIME/restore-worker.sh"
chown -hR "$uid:$uid" "$RUNTIME"
echo "=== Restore into network- and PID-isolated MySQL ==="
# Mask live webroot, database files, sockets, root backups and temporary paths.
# The only dump access is stdin; no production credentials are supplied.
gzip -dc "$BACKUP/database.sql.gz" | timeout --signal=TERM --kill-after=15s 1800 \
  unshare --mount --net --pid --fork --kill-child=SIGKILL --mount-proc bash -c '
    set -Eeuo pipefail
    runtime=$1; base=$2; db=$3; uid=$4; live_data=$5; live_socket=$6
    mount --make-rprivate /
    for target in /www/wwwroot /www/server/data /www/server/mysql/data /var/lib/mysql /run /tmp /root; do
      if [[ -d "$target" && ! -L "$target" ]]; then
        mount -t tmpfs -o mode=0700,nosuid,nodev,noexec tmpfs "$target"
      fi
    done
    # Actual resolved production datadir must be masked by the above list.
    [[ "$live_data" == /www/server/data || "$live_data" == /www/server/mysql/data || "$live_data" == /var/lib/mysql ]]
    [[ $(findmnt -n -o FSTYPE --target "$live_data") == tmpfs ]]
    [[ ! -S "$live_socket" && ! -e "$live_socket" ]]
    exec setpriv --reuid="$uid" --regid="$uid" --clear-groups --no-new-privs --bounding-set=-all --inh-caps=-all --ambient-caps=-all \
      env -i PATH=/usr/bin:/bin HOME="$runtime" TMPDIR="$runtime/tmp" LC_ALL=C \
      bash "$runtime/restore-worker.sh" "$runtime" "$base" "$db"
  ' _ "$RUNTIME" "$MYSQLBASE" "$DB" "$uid" "$(cat "$WORK/live-datadir")" "$(cat "$WORK/live-socket")" >"$WORK/restore.private.log" 2>&1 || fail ISOLATED_RESTORE_EXECUTION
grep -Fxq 'ISOLATED_MYSQL_RESTORE=PASS' "$WORK/restore.private.log" || fail RESTORE_MARKER
# Verify exact schema and every row count independently of current live activity.
cmp -s "$WORK/schema-before.tsv" "$RUNTIME/restored-schema.tsv" || fail RESTORED_SCHEMA_MISMATCH
cmp -s "$WORK/gate.tsv" "$RUNTIME/restored-gate.tsv" || fail RESTORED_SCHEMA_GATE_MISMATCH
cmp -s "$BACKUP/evidence/dump-row-counts.tsv" "$RUNTIME/restored-counts.tsv" || fail RESTORED_ROW_COUNT_MISMATCH
cp "$RUNTIME/restored-counts.tsv" "$RUNTIME/restored-schema.tsv" "$RUNTIME/restored-gate.tsv" "$BACKUP/evidence/"
echo "ISOLATED_MYSQL_RESTORE=PASS"
echo "RESTORED_SCHEMA_AND_ALL_TABLE_ROW_COUNTS=PASS"
echo "RESTORED_TABLE_COUNT=$(wc -l <"$BACKUP/evidence/restored-counts.tsv")"
echo "RESTORED_ROW_COUNT=$(awk -F'\t' '{s+=$2} END {printf "%.0f\n",s}' "$BACKUP/evidence/restored-counts.tsv")"
# Remove the entire restored private database before recording acceptance.
rm -rf --one-file-system -- "$RUNTIME"
[[ ! -e "$RUNTIME" && ! -L "$RUNTIME" ]] || fail RESTORE_CLEANUP
RUNTIME=""
verify_source
verify_prior
gzip -t "$BACKUP/database.sql.gz"
# No raw logs, database names, row contents, secrets or SQL are emitted.
printf '%s\n' "$HEAD" >"$BACKUP/RECOVERY_PASS"
(cd "$BACKUP"; find evidence -type f -print0 | sort -z | xargs -0 sha256sum > backup.sha256; sha256sum controller.original.php database.sql.gz RECOVERY_PASS >> backup.sha256; sha256sum --check --status backup.sha256)
echo "BACKUP_MANIFEST_SHA256=$(sha256sum "$BACKUP/backup.sha256"|cut -d' ' -f1)"
echo "BACKUP_INTEGRITY=PASS"
echo "RESTORE_REHEARSAL=PASS"
echo "MIGRATED_BACKUP_RECOVERY_READINESS=PASS"
echo "PRODUCTION_DATABASE_READ_ONLY=YES"
echo "PRODUCTION_DATABASE_RECORDS_MODIFIED=0"
echo "PRODUCTION_FILES_MODIFIED=0"
echo "PRODUCTION_SERVICES_RESTARTED=NO"
echo "MERGE=NOT_PERFORMED"
echo "PRODUCTION_DEPLOYMENT=NOT_STARTED"
