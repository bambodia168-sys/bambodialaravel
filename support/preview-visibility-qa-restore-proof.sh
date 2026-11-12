#!/usr/bin/env bash
# QA ONLY: verify an isolated Bambodia MySQL dump really restores into a
# separate, empty schema, including synthetic private-media file hashes.
# Never operates on production database names or changes the source QA DB.
set -Eeuo pipefail
umask 077

fail(){ printf 'BLOCKED: %s\n' "$*" >&2; exit 2; }
[[ $# -eq 6 ]] || fail "Usage: $0 --check|--apply QA_APP SOURCE_DB RESTORE_DB SOURCE_CNF RESTORE_CNF"
MODE="$1"; APP="$2"; SRC="$3"; DEST="$4"; SCNF="$5"; DCNF="$6"
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail "Unexpected mode"
[[ "$SRC" =~ ^bambodia_previewqa_[a-z0-9_]{4,30}$ ]] || fail "Source database not a dedicated preview QA database"
[[ "$DEST" =~ ^bambodia_previewrestore_[a-z0-9_]{4,30}$ ]] || fail "Restore database not a dedicated preview restore database"
[[ "$SRC" != "$DEST" ]] || fail "Source and restore must differ"

APP="$(realpath -e -- "$APP")" || fail "Source application path cannot be resolved"
[[ -d "$APP" && -f "$APP/artisan" && -f "$APP/.env" && ! -L "$APP/.env" ]] || fail "No normal QA Laravel app at source"
for bad in '/www/wwwroot/bambodia-staging-qa' '/www/wwwroot/bambodia.com'; do
  [[ "$APP" != "$bad" && "$APP" != "$bad/"* ]] || fail "Protected installed application path"
done
for f in "$SCNF" "$DCNF"; do
  [[ -f "$f" && ! -L "$f" && "$(stat -c %a "$f")" == '600' ]] || fail "MySQL CNF must be a private 0600 regular file"
done
[[ "$(realpath -e -- "$SCNF")" != "$(realpath -e -- "$DCNF")" ]] || fail "Use separate source/restore credentials"
for bin in gzip tar sha256sum cmp mktemp find sort xargs awk; do
  command -v "$bin" >/dev/null || fail "Missing $bin"
done
MYSQL="${BAMBODIA_PREVIEW_QA_MYSQL:-$(command -v mysql || true)}"
MYSQLDUMP="${BAMBODIA_PREVIEW_QA_MYSQLDUMP:-$(command -v mysqldump || true)}"
[[ -x "$MYSQL" ]] || MYSQL="/www/server/mysql/bin/mysql"
[[ -x "$MYSQLDUMP" ]] || MYSQLDUMP="/www/server/mysql/bin/mysqldump"
[[ -x "$MYSQL" && -x "$MYSQLDUMP" ]] || fail "MySQL command binaries unavailable"
PHP="${BAMBODIA_PREVIEW_QA_PHP:-$(command -v php)}"
[[ -x "$PHP" ]] || fail "PHP CLI unavailable"
[[ "${BAMBODIA_PREVIEW_QA_ISOLATED:-}" == '1' ]] || fail "QA isolation opt-in not set"
[[ -n "${BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN:-}" ]] || fail "QA origin not supplied"

# Laravel guard checks the actual URL, DB user, storage roots, synthetic
# titles, and default-off withdrawal configuration.
"$PHP" "$APP/support/preview-visibility-qa-preflight.php" --audit >/dev/null ||
  fail "QA application preflight rejected the environment"

ACTUAL_DB="$(cd "$APP" && "$PHP" -r '
require getcwd()."/vendor/autoload.php";
$a=require getcwd()."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) config("database.connections.mysql.database");')" ||
  fail "Cannot verify Laravel database identity"
[[ "$ACTUAL_DB" == "$SRC" ]] || fail "Source DB does not match verified Laravel QA DB"

m_src=("$MYSQL" "--defaults-extra-file=$SCNF" --batch --skip-column-names --raw)
m_dst=("$MYSQL" "--defaults-extra-file=$DCNF" --batch --skip-column-names --raw)
dump=("$MYSQLDUMP" "--defaults-extra-file=$SCNF" --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --triggers --hex-blob)
"${m_src[@]}" "$SRC" -e 'SELECT 1' >/dev/null || fail "Source connection denied"
"${m_dst[@]}" "$DEST" -e 'SELECT 1' >/dev/null || fail "Destination connection denied"
[[ "$("${m_src[@]}" "$SRC" -e 'SELECT DATABASE()')" == "$SRC" ]] || fail "Source session DB mismatch"
[[ "$("${m_dst[@]}" "$DEST" -e 'SELECT DATABASE()')" == "$DEST" ]] || fail "Restore session DB mismatch"
SRC_SERVER="$("${m_src[@]}" "$SRC" -e 'SELECT @@server_uuid')"
DST_SERVER="$("${m_dst[@]}" "$DEST" -e 'SELECT @@server_uuid')"
[[ "$SRC_SERVER" == "$DST_SERVER" ]] || fail "Restore target must be on the same isolated QA MySQL server"

TMP="$(mktemp -d /tmp/bambodia-preview-restore.XXXXXXXX)"
trap 'rm -rf -- "$TMP"' EXIT
list_tables(){
  local -n c="$1"; local db="$2"; local file="$3"
  "${c[@]}" "$db" -e "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME" > "$file"
}
list_tables m_src "$SRC" "$TMP/source-tables"
[[ -s "$TMP/source-tables" ]] || fail "Source has no schema"
grep -Fxq 'preview_visibility_actions' "$TMP/source-tables" || fail "Missing audit table"
list_tables m_dst "$DEST" "$TMP/restore-tables"
[[ ! -s "$TMP/restore-tables" ]] || fail "Restore target must have ZERO existing tables"
[[ -d "$APP/storage/app/private" && -d "$APP/storage/app/public" ]] ||
  fail "QA storage paths missing"
[[ -z "$(find "$APP/storage/app/private" "$APP/storage/app/public" -type l -print -quit)" ]] ||
  fail "Symlink inside QA media storage rejected"

inventory(){
  local -n client="$1"; local db="$2"; local file="$3"
  : > "$file"
  local table rowcount check
  while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail "Unsupported table identifier"
    rowcount="$("${client[@]}" "$db" -e "SELECT COUNT(*) FROM $table")"
    [[ "$rowcount" =~ ^[0-9]+$ ]] || fail "Count failed"
    check="$("${client[@]}" "$db" -e "CHECKSUM TABLE $table EXTENDED" | awk '{print $NF}')"
    [[ "$check" =~ ^[0-9]+$ ]] || fail "Extended table checksum failed"
    printf '%s|%s|%s\n' "$table" "$rowcount" "$check" >> "$file"
  done < "$TMP/source-tables"
}
inventory m_src "$SRC" "$TMP/source-before"
if [[ "$MODE" == '--check' ]]; then
  echo "QA_RESTORE_CHECK=PASS"
  echo "SOURCE_QA_SCHEMA=VERIFIED"
  echo "RESTORE_TARGET=EMPTY"
  echo "SOURCE_SWITCH=DISABLED"
  echo "SOURCE_DATA=NOT_MODIFIED"
  echo "RESTORATION=NOT_EXECUTED"
  exit 0
fi

[[ "${BAMBODIA_PREVIEW_QA_RESTORE_CONFIRM:-}" == "$DEST" ]] ||
  fail "Explicit confirmation required for the named empty QA restore database"
BACKUP_ROOT="${BAMBODIA_PREVIEW_QA_BACKUP_ROOT:-/root/Bambodia-PreviewQA-Restore-Proof}"
mkdir -p -m 0700 "$BACKUP_ROOT"
BACKUP_ROOT="$(realpath -e "$BACKUP_ROOT")"
[[ "$BACKUP_ROOT" != "$APP" && "$BACKUP_ROOT" != "$APP/"* &&
   "$BACKUP_ROOT" != '/www/wwwroot' && "$BACKUP_ROOT" != '/www/wwwroot/'* ]] ||
  fail "Backup location may not be under the Laravel or a public web tree"
OUT="$(mktemp -d "$BACKUP_ROOT/restore-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$OUT"
echo "========== SNAPSHOT ISOLATED QA DATA =========="
if ! "${dump[@]}" "$SRC" | gzip -c > "$OUT/qa.sql.gz"; then
  rm -f "$OUT/qa.sql.gz"
  fail "Source dump failed (restore schema unchanged)"
fi
gzip -t "$OUT/qa.sql.gz" || fail "Dump integrity failed"
tar -czf "$OUT/qa-media.tar.gz" -C "$APP" storage/app/private storage/app/public ||
  fail "QA private-media archive failed"
tar -tzf "$OUT/qa-media.tar.gz" >/dev/null || fail "Media archive unreadable"
(cd "$APP" && find storage/app/private storage/app/public -type f -print0 |
  LC_ALL=C sort -z | xargs -0 -r sha256sum) > "$OUT/qa-media.sha256"
inventory m_src "$SRC" "$TMP/source-after"
cmp -s "$TMP/source-before" "$TMP/source-after" ||
  fail "QA source data changed during snapshot; no restoration attempted"

# Recheck destination immediately before the ONLY DB mutation. No DROP, DELETE,
# TRUNCATE or CREATE DATABASE is used; restore into a manually precreated empty DB.
list_tables m_dst "$DEST" "$TMP/restore-tables"
[[ ! -s "$TMP/restore-tables" ]] || fail "Restore target changed after preflight"
echo "========== RESTORE INTO A DIFFERENT EMPTY QA SCHEMA =========="
gzip -dc "$OUT/qa.sql.gz" | "${m_dst[@]}" "$DEST" ||
  fail "Restore failed; preserve destination for manual QA diagnosis"
list_tables m_dst "$DEST" "$TMP/restored-tables"
cmp -s "$TMP/source-tables" "$TMP/restored-tables" ||
  fail "Restored table inventory mismatch"
inventory m_dst "$DEST" "$TMP/restored-counts"
cmp -s "$TMP/source-before" "$TMP/restored-counts" ||
  fail "Restored row counts or extended checksums differ"

mkdir -p -m 0700 "$OUT/restored-files"
tar -xzf "$OUT/qa-media.tar.gz" -C "$OUT/restored-files" ||
  fail "Media extraction failed"
(cd "$OUT/restored-files" && sha256sum --check --status "$OUT/qa-media.sha256") ||
  fail "Restored media SHA256 mismatch"
sha256sum "$OUT/qa.sql.gz" "$OUT/qa-media.tar.gz" > "$OUT/archive-checksums.sha256"
echo "QA_DB_RESTORE=PASS"
echo "QA_MEDIA_RESTORE=PASS"
echo "SOURCE_DATA=UNCHANGED"
echo "SOURCE_SWITCH=DISABLED"
echo "RESTORED_APP_BOOT=NOT_TESTED"
echo "REAL_AAPANEL_HOST_ACCEPTANCE=NOT_COMPLETE"
echo "BACKUP_EVIDENCE_DIR=$OUT"
