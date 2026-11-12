#!/usr/bin/env bash
# Bambodia Issue #35: backup-first migrations on the exact EMPTY isolated QA database only.
# No production paths, DROP, migration rollback, external storage, user seeding or write activation.
set -Eeuo pipefail
set +x
umask 077

MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--check" || "$MODE" == "--apply" ) ]] || {
  echo 'Usage: --check|--apply' >&2; exit 2;
}
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

QA=/www/wwwroot/bambodia-preview-qa
PROD=/www/wwwroot/bambodia.com
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
DB=bambodia_pvqa_29c824170b10
DBUSER=b_pvqa_29c824170b10
PHP=/www/server/php/85/bin/php
MYSQL=/www/server/mysql/bin/mysql
MYSQLDUMP=/www/server/mysql/bin/mysqldump
BACKUP_ROOT=/root/Bambodia-PreviewQA-Migration-Backups

[[ "$(id -u)" == 0 ]] || fail 'Run from authorized aaPanel root shell'
for bin in git python3 realpath sha256sum gzip tar stat mktemp runuser find; do
  command -v "$bin" >/dev/null || fail "Missing binary: $bin"
done
[[ -x "$PHP" && -x "$MYSQL" && -x "$MYSQLDUMP" ]] ||
  fail 'aaPanel PHP/MySQL/mysqldump binaries missing'
[[ ! -L /www && ! -L /www/wwwroot && ! -L "$QA" && ! -L "$PROD" ]] ||
  fail 'Symlink in protected application paths'
[[ -f "$PROD/artisan" && -f "$QA/artisan" && -d "$QA/.git" ]] ||
  fail 'Expected production or isolated QA source missing'
[[ "$(realpath -e "$QA")" != "$(realpath -e "$PROD")" ]] ||
  fail 'QA path resolves to production'
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" ]] ||
  fail 'QA Git head differs from approved main merge'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'QA tracked source has local edits'
[[ -f "$QA/.env" && ! -L "$QA/.env" &&
   "$(stat -c %a "$QA/.env")" == 600 &&
   "$(stat -c %U:%G "$QA/.env")" == www:www ]] ||
  fail 'Missing or unsafe dedicated QA .env'
for rel in vendor/autoload.php composer.lock package-lock.json \
  public/build/manifest.json config/preview_visibility.php \
  database/migrations/2026_11_11_000020_create_preview_visibility_actions_table.php; do
  [[ -f "$QA/$rel" && ! -L "$QA/$rel" ]] ||
    fail "Required verified QA runtime source missing: $rel"
done
[[ ! -e "$QA/public/hot" && ! -e "$QA/bootstrap/cache/config.php" &&
   ! -e "$QA/storage/app/installed.lock" ]] ||
  fail 'Dev server, config cache, or installer state present'
[[ ! -L "$QA/storage" && ! -L "$QA/storage/app" &&
   ! -L "$QA/storage/app/private" ]] ||
  fail 'QA private storage must not be symlinked'
[[ -d "$QA/storage/app/private" ]] || fail 'Private QA media directory missing'
if [[ -d /www/server/panel/vhost/nginx ]] &&
  grep -rFq -- "$QA" /www/server/panel/vhost/nginx; then
  fail 'QA HTTP exposure before independently accepted vhost review'
fi
id www >/dev/null || fail 'aaPanel www service account absent'
"$PHP" -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' ||
  fail 'PHP pdo_mysql unavailable'

echo '========== BAMBODIA STAGE 6 QA BACKUP-FIRST MIGRATIONS =========='
echo "MODE=$MODE"
echo "SOURCE_SHA=$SHA"
echo "QA_DATABASE=$DB"
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE'
echo 'PRODUCTION_TARGET=NOT_ALLOWED'

TMP="$(mktemp -d /root/bambodia-pvqa-migrate.XXXXXXXX)"
OUT=""
cleanup(){
  rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-pvqa-migrate.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT

# Independently validate the private environment and generate a TEMP root-only
# MySQL option file (password never appears in argv or stdout).
python3 - "$QA/.env" "$TMP/qa.cnf" "$DB" "$DBUSER" <<'PY'
import os,re,stat,sys
from pathlib import Path
envfile, cnf, expected_db, expected_user = sys.argv[1:]
kv={}
for line in Path(envfile).read_text().splitlines():
    if not line or line.lstrip().startswith("#") or "=" not in line:
        continue
    k,v=line.split("=",1)
    kv[k.strip()]=v.strip().strip('"').strip("'")
expected={
    "APP_ENV":"staging","APP_DEBUG":"false","APP_URL":"http://127.0.0.1",
    "DB_CONNECTION":"mysql","DB_HOST":"127.0.0.1","DB_PORT":"3306",
    "DB_DATABASE":expected_db,"DB_USERNAME":expected_user,
    "SESSION_DRIVER":"file","CACHE_STORE":"file","QUEUE_CONNECTION":"sync",
    "MAIL_MAILER":"array","FILESYSTEM_DISK":"local",
    "MEDIA_LIBRARY_DISK":"local","PREVIEW_VISIBILITY_WRITES_ENABLED":"false"
}
for key,want in expected.items():
    if kv.get(key)!=want: raise SystemExit("BLOCKED=unsafe QA env: "+key)
if any(k in kv and kv[k] not in ("","null") for k in (
    "DB_URL","DB_SOCKET","SESSION_CONNECTION","DB_CACHE_CONNECTION",
    "REDIS_URL","REDIS_HOST","AWS_ACCESS_KEY_ID","R2_ACCESS_KEY_ID")):
    raise SystemExit("BLOCKED=unexpected external connection setting")
if not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=",kv.get("APP_KEY","")):
    raise SystemExit("BLOCKED=invalid separate QA APP_KEY")
if not re.fullmatch(r"bambodia_pvqa_[a-f0-9]{12}",kv.get("SESSION_COOKIE","")):
    raise SystemExit("BLOCKED=QA session namespace mismatch")
if not re.fullmatch(r"bambodia_pvqa_[a-f0-9]{12}",kv.get("CACHE_PREFIX","")):
    raise SystemExit("BLOCKED=QA cache namespace mismatch")
pwd=kv.get("DB_PASSWORD","")
if not re.fullmatch(r"[A-Za-z0-9_-]{32,100}",pwd):
    raise SystemExit("BLOCKED=unrecognized QA DB password format")
fd=os.open(cnf,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
with os.fdopen(fd,"w") as out:
    out.write("[client]\nuser="+expected_user+"\npassword=\""+pwd+
        "\"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n")
print("QA_ENV_AND_PRIVATE_DB_CREDENTIALS=VALIDATED")
PY

mysql_qa(){ "$MYSQL" "--defaults-extra-file=$TMP/qa.cnf" --batch --raw --skip-column-names "$DB" "$@"; }
dump_qa(){
  "$MYSQLDUMP" "--defaults-extra-file=$TMP/qa.cnf" \
    --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
    --triggers --hex-blob "$DB"
}
mysql_qa -e 'SELECT 1' >/dev/null || fail 'QA MySQL login failed'
ACTUAL_DB="$(mysql_qa -e 'SELECT DATABASE()')"
ACTUAL_USER="$(mysql_qa -e 'SELECT SUBSTRING_INDEX(CURRENT_USER(), "@", 1)')"
[[ "$ACTUAL_DB" == "$DB" && "$ACTUAL_USER" == "$DBUSER" ]] ||
  fail 'QA database or MySQL account identity mismatch'
# information_schema reads cannot create schema or tables
NUM_TABLES="$(mysql_qa -e \
  'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')" ||
  fail 'Unable to inspect QA table count'
[[ "$NUM_TABLES" == 0 ]] ||
  fail "QA already has $NUM_TABLES tables; never migrate twice or overwrite"
MIGRATION_FILES="$(find "$QA/database/migrations" -maxdepth 1 -type f -name '*.php' | wc -l)"
[[ "$MIGRATION_FILES" == 62 ]] ||
  fail 'Expected 62 reviewed migration source files'
mysql_qa -e 'SHOW GRANTS' > "$TMP/qa-grants"
python3 - "$TMP/qa-grants" "$DB" "$DBUSER" <<'PY'
import sys
grants=open(sys.argv[1]).read().splitlines()
dbname,username=sys.argv[2:]
if not any(("ON \`"+dbname+"\`.*") in x or ("ON "+dbname+".*") in x for x in grants):
    raise SystemExit("BLOCKED=QA account missing schema-scoped grant")
for grant in grants:
    # USAGE on *.* is normal; elevated global privileges are not.
    if "ON *.*" in grant and "GRANT USAGE" not in grant:
        raise SystemExit("BLOCKED=QA account unexpectedly has global privileges")
print("QA_DB_PRIVILEGES=SCHEMA_SCOPED")
PY
echo 'QA_DATABASE_IDENTITY=PASS'
echo 'QA_SCHEMA=EMPTY'
echo 'MIGRATION_SOURCE_FILES=62'
echo 'MIGRATION_EXECUTION=NOT_STARTED'

if [[ "$MODE" == "--check" ]]; then
  echo 'QA_SCHEMA_CHANGES=NONE'
  echo 'RESULT=READY_FOR_QA_BACKUP_FIRST_MIGRATIONS'
  exit 0
fi

[[ -n "$BACKUP_ROOT" && "$BACKUP_ROOT" == /root/* ]] ||
  fail 'Backup evidence directory must be private and root-owned'
mkdir -p -m 0700 "$BACKUP_ROOT"
[[ ! -L "$BACKUP_ROOT" && "$(stat -c %u "$BACKUP_ROOT")" == 0 ]] ||
  fail 'Backup root unexpectedly linked or not root-owned'
chmod 0700 "$BACKUP_ROOT"
OUT="$(mktemp -d "$BACKUP_ROOT/qa-migration-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$OUT"

echo '========== SNAPSHOT QA EMPTY SCHEMA + SECRET CONFIG =========='
if ! dump_qa | gzip -c > "$OUT/qa-before-schema.sql.gz"; then
  fail 'QA before-migration mysqldump failed; migrations not started'
fi
gzip -t "$OUT/qa-before-schema.sql.gz" ||
  fail 'QA before-migration dump corrupt; migrations not started'
tar -czf "$OUT/qa-env-and-private-media.tar.gz" \
  -C "$QA" .env storage/app/private ||
  fail 'QA environment/private-media archive failed; migrations not started'
tar -tzf "$OUT/qa-env-and-private-media.tar.gz" > "$TMP/private-archive-list" ||
  fail 'QA private-media archive unreadable; migrations not started'
sha256sum "$OUT/qa-before-schema.sql.gz" \
  "$OUT/qa-env-and-private-media.tar.gz" > "$OUT/pre-migration-checksums.sha256"
sha256sum --check --status "$OUT/pre-migration-checksums.sha256" ||
  fail 'QA backup hash check failed; migrations not started'
[[ "$(mysql_qa -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')" == 0 ]] ||
  fail 'QA schema changed between backup and migrations'
echo "QA_BACKUP_EVIDENCE=$OUT"
echo 'BACKUP_VERIFIED=GZIP_TAR_SHA256'
echo 'BACKUP_CONTAINS_PRIVATE_QA_SECRETS=YES_ROOT_ONLY'

echo '========== RUN MIGRATIONS ONLY IN FRESH QA SCHEMA =========='
if ! ( cd "$QA" && runuser -u www -- env -i HOME="$QA" \
    PATH=/usr/bin:/bin:/usr/local/bin "$PHP" artisan migrate \
    --force --no-interaction ) > "$OUT/qa-migration.stdout" 2> "$OUT/qa-migration.stderr"; then
  echo "QA_MIGRATION_LOG=$OUT/qa-migration.stdout"
  echo 'QA_SCHEMA=PARTIALLY_MIGRATED_POSSIBLE'
  echo 'RECOVERY=MANUAL_ONLY_NO_AUTOMATIC_ROLLBACK'
  fail 'QA Artisan migrations failed: inspect private log and retain backups'
fi

[[ "$(mysql_qa -e 'SELECT COUNT(*) FROM migrations')" == 62 ]] ||
  fail 'Migration ledger count differs from reviewed 62 migrations'
REQUIRED_TABLES='users marketplace_listings marketplace_jobs preview_visibility_actions'
for table in $REQUIRED_TABLES; do
  [[ "$(mysql_qa -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")" == 1 ]] ||
    fail "QA migration required table missing: $table"
  [[ "$(mysql_qa -e "SELECT COUNT(*) FROM $table")" == 0 ]] ||
    fail "QA required table contains unexpected data: $table"
done
for col in subject_kind subject_id subject_public_id actor_id action from_status to_status review_fingerprint owner_confirmation_reference backup_reference rights_review_reference reason created_at; do
  [[ "$(mysql_qa -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='preview_visibility_actions' AND COLUMN_NAME='$col'")" == 1 ]] ||
    fail "QA visibility history audit column missing: $col"
done
echo 'MIGRATIONS_APPLIED=62'
echo 'VISIBILITY_HISTORY_TABLE_AND_COLUMNS=PASS'
echo 'QA_USERS_LISTINGS_JOBS_HISTORY=ALL_EMPTY'

echo '========== SNAPSHOT QA POST-MIGRATION SCHEMA =========='
if ! dump_qa | gzip -c > "$OUT/qa-after-schema.sql.gz"; then
  fail 'QA post-migration snapshot failed; do not mark Stage 6 complete'
fi
gzip -t "$OUT/qa-after-schema.sql.gz" || fail 'QA post-migration backup corrupt'
sha256sum "$OUT/qa-after-schema.sql.gz" > "$OUT/post-migration-checksum.sha256"
sha256sum --check --status "$OUT/post-migration-checksum.sha256" ||
  fail 'QA post-migration hash check failed'
echo 'POST_MIGRATION_BACKUP=SHA256_VERIFIED'
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE'
echo 'PRODUCTION=UNTOUCHED_BY_SCRIPT'
echo 'QA_RESTORE=NOT_YET_TESTED'
echo "BACKUP_EVIDENCE_DIR=$OUT"
echo 'RESULT=QA_62_MIGRATIONS_APPLIED_BACKUP_VERIFIED'
echo 'NEXT=RESTORE_POST_MIGRATION_SNAPSHOT_TO_SEPARATE_EMPTY_QA_SCHEMA'
