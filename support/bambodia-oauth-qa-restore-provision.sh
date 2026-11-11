#!/usr/bin/env bash
# QA-only restore schema preparation. Read-only --check; additive --prepare-restore.
# Never restores SQL, runs migrations, rotates keys, or touches production.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in --check|--prepare-restore) ;; *) echo 'Usage: script --check|--prepare-restore' >&2; exit 64;; esac

APP="/var/lib/bambodia-oauth-qa-acceptance"
QA_DB="bambodia_oauthqa_acceptance"
QA_USER="bambodiaqa_acceptance"
RESTORE_DB="bambodia_oauthqa_restore_acceptance"
CNF="/root/bambodia-oauth-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
QA_PHP="/www/server/php/84/bin/php"
BACKUP="/root/bambodia-oauth-qa-snapshots/run.l0IsKwRj/before.sql"
EXPECTED_BACKUP_SHA="3498997c561f5941d8f095e10b50203396ae79b5dd7ae80e6c9673de26d533a0"
EXPECTED_QA_SHA="${BAMBODIA_QA_CHECKOUT_SHA:-}"

die() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || die 'Root operator required.'
[[ "$EXPECTED_QA_SHA" =~ ^[0-9a-f]{40}$ ]] || die 'Specify reviewed full 40-character QA SHA.'
[[ -d "$APP/.git" && ! -L "$APP" ]] || die 'QA checkout missing/symlinked.'
[[ "$(realpath -e "$APP")" == "$APP" ]] || die 'QA checkout resolves unexpectedly.'
[[ "$(git -C "$APP" rev-parse HEAD)" == "$EXPECTED_QA_SHA" ]] || die 'QA SHA mismatch.'
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] || die 'QA tracked source modified.'
[[ -x "$MYSQL" && -x "$QA_PHP" ]] || die 'Expected aaPanel binaries missing.'
[[ -f "$CNF" && ! -L "$CNF" && "$(stat -c '%u:%a' "$CNF")" == "0:600" ]] ||
  die 'QA credentials missing or unsafe.'
[[ -f "$APP/.env" && ! -L "$APP/.env" ]] || die 'QA environment missing/symlinked.'
[[ -s "$APP/storage/oauth-private.key" && -s "$APP/storage/oauth-public.key" ]] ||
  die 'QA Passport rehearsal keys missing.'
[[ ! -e "$APP/bootstrap/cache/config.php" ]] || die 'QA cached configuration found.'
[[ "$QA_DB" != "$RESTORE_DB" ]] || die 'QA restore DB equals rehearsal DB.'
[[ -d "$(dirname "$BACKUP")" && ! -L "$(dirname "$BACKUP")" ]] ||
  die 'Snapshot folder missing/symlinked.'
[[ "$(stat -c '%u:%a' "$(dirname "$BACKUP")")" == "0:700" ]] ||
  die 'Snapshot folder not root-only.'
[[ -f "$BACKUP" && ! -L "$BACKUP" && -s "$BACKUP" ]] ||
  die 'Pre-migration snapshot missing/symlinked/empty.'
[[ "$(realpath -e "$BACKUP")" == "$BACKUP" ]] || die 'Snapshot path redirects elsewhere.'
[[ -f "$BACKUP.sha256" && ! -L "$BACKUP.sha256" ]] ||
  die 'Snapshot checksum manifest missing.'
sha256sum --status --check "$BACKUP.sha256" || die 'Snapshot checksum manifest invalid.'
[[ "$(sha256sum "$BACKUP" | cut -d ' ' -f 1)" == "$EXPECTED_BACKUP_SHA" ]] ||
  die 'Snapshot differs from operator-reported checksum.'
[[ -f "$(dirname "$BACKUP")/table-count.txt" ]] || die 'Table-count manifest missing.'
[[ "$(cat "$(dirname "$BACKUP")/table-count.txt")" == 0 ]] ||
  die 'This QA snapshot must be the empty pre-OAuth database.'
[[ -f "$(dirname "$BACKUP")/core-counts.txt" ]] || die 'Core-counts manifest missing.'
[[ -c /dev/tty ]] || die 'Interactive root terminal required.'

QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP --host=127.0.0.1 \
    --batch --skip-column-names "$@"
}
ADMIN() {
  "$MYSQL" --no-defaults --protocol=SOCKET --user=root --password \
    --batch --skip-column-names "$@"
}

export PATH="/www/server/mysql/bin:$PATH"
export BAMBODIA_QA_APP="$APP"
export BAMBODIA_QA_DB="$QA_DB"
export BAMBODIA_QA_MYSQL_CNF="$CNF"
export BAMBODIA_QA_EXPECTED_SHA="$EXPECTED_QA_SHA"
export BAMBODIA_QA_PHP="$QA_PHP"

echo '========== REVERIFY ORIGINAL QA DATABASE =========='
bash "$APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --check
oauth_count="$(QA_MYSQL information_schema -e \
  "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$QA_DB' AND TABLE_NAME IN ('oauth_auth_codes','oauth_access_tokens','oauth_refresh_tokens','oauth_clients','oauth_device_codes')")"
[[ "$oauth_count" == 5 ]] || die 'Expected 5 OAuth tables in rehearsal database.'

echo '========== VERIFY DISTINCT EMPTY RESTORE TARGET =========='
echo 'BACKUP_INTEGRITY=PASS'
echo "RESTORE_DB=$RESTORE_DB"
echo 'DBA_AUTHENTICATION=INTERACTIVE_PASSWORD_PROMPT'
counts="$(ADMIN -e "
SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$RESTORE_DB'),
       (SELECT COUNT(*) FROM mysql.user WHERE user='$QA_USER' AND host='127.0.0.1');
")" || die 'DBA authentication or metadata lookup failed.'
read -r exists user_exists <<< "$counts"
[[ "$exists" =~ ^[0-9]+$ && "$user_exists" =~ ^[0-9]+$ ]] ||
  die 'Failed to read QA schema and account metadata.'
echo "RESTORE_DB_EXISTING_COUNT=$exists"
echo "EXPECTED_QA_USER_COUNT=$user_exists"
[[ "$exists" == 0 ]] || die 'Restore target already exists. No overwrite.'
[[ "$user_exists" == 1 ]] || die 'Expected QA user missing or ambiguous.'

if [[ "$MODE" == --check ]]; then
  echo 'RESTORE_PRECHECK=PASS'
  echo 'READ_ONLY=YES'
  exit 0
fi

echo 'Type PREPARE-RESTORE to create ONLY the second QA schema and grant scoped access:'
IFS= read -r CONFIRM < /dev/tty
[[ "$CONFIRM" == PREPARE-RESTORE ]] || die 'Operator declined QA restore preparation.'

echo '========== CREATE QA-ONLY RESTORE SCHEMA AND GRANTS =========='
# A second password prompt is intentional; no DBA credentials are stored.
ADMIN <<SQL
CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP,
      REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER, LOCK TABLES
ON \`$RESTORE_DB\`.* TO '$QA_USER'@'127.0.0.1';
SQL

# Existing OAuth preflight verifies ALL grants are still restricted to QA only.
bash "$APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --check
tables="$(QA_MYSQL information_schema -e \
  "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$RESTORE_DB'")"
[[ "$tables" == 0 ]] || die 'Restore target has tables unexpectedly.'
QA_MYSQL "$RESTORE_DB" -e 'SELECT 1' >/dev/null || die 'QA account lacks restore DB access.'
echo 'RESTORE_SCHEMA_PROVISION=PASS'
echo 'RESTORE_SCHEMA_TABLES=0'
echo 'RESTORE_NOT_EXECUTED=YES'
echo 'PRODUCTION_SCHEMA_NOT_TOUCHED=YES'
