#!/usr/bin/env bash
# Bambodia Issue #35 - isolated QA DB & APP_KEY; never runs migrations or touches production.
set -Eeuo pipefail
set +x
umask 077
PROD=/www/wwwroot/bambodia.com
QA=/www/wwwroot/bambodia-preview-qa
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
PHP=/www/server/php/85/bin/php
MYSQL=/www/server/mysql/bin/mysql
MODE=--check
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == --check || "$MODE" == --initialize ) ]] || {
  echo 'Usage: --check|--initialize' >&2; exit 2;
}
fail(){ echo "BLOCKED=$*" >&2; exit 2; }
[[ "$(id -u)" == 0 ]] || fail 'root required'
for bin in git python3 stat mktemp realpath; do command -v "$bin" >/dev/null || fail "Missing $bin"; done
[[ -x "$PHP" && -x "$MYSQL" ]] || fail 'Expected aaPanel PHP/MySQL binaries absent'
[[ ! -L /www && ! -L /www/wwwroot && ! -L "$PROD" && ! -L "$QA" ]] || fail 'Symlink in application paths'
[[ -f "$PROD/artisan" && -f "$QA/artisan" && -d "$QA/.git" ]] || fail 'Expected sources absent'
[[ "$(realpath -e "$PROD")" != "$(realpath -e "$QA")" ]] || fail 'QA resolves to production'
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" ]] || fail 'QA source commit differs'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail 'Modified tracked QA source'
[[ -f "$QA/config/preview_visibility.php" &&
   -f "$QA/database/migrations/2026_11_11_000020_create_preview_visibility_actions_table.php" ]] ||
  fail 'QA feature source incomplete'
[[ ! -e "$QA/.env" && ! -L "$QA/.env" ]] || fail 'Existing QA environment: refuse overwrite'
[[ ! -e "$QA/bootstrap/cache/config.php" ]] || fail 'QA config cache already exists'
[[ ! -e "$QA/public/storage" && ! -L "$QA/public/storage" ]] || fail 'Unexpected QA public storage'
[[ -d "$QA/storage/framework/sessions" && ! -L "$QA/storage/framework/sessions" ]] || fail 'QA session path invalid'
"$PHP" -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' || fail 'PHP pdo_mysql unavailable'
id www >/dev/null || fail 'www user absent'
echo '========== BAMBODIA INDEPENDENT QA MYSQL / APP KEY =========='
echo "MODE=$MODE"
echo 'SOURCE_COMMIT=VERIFIED'
echo 'PRODUCTION=NO_CHANGES'
echo 'MIGRATIONS=NONE'
echo 'WRITE_SWITCH=OFF'
if [[ -f "$QA/composer.lock" && -f "$QA/package-lock.json" ]]; then
  echo 'DEPENDENCY_LOCKFILES=PRESENT'
else
  echo 'DEPENDENCY_BUILD=BLOCKED_MISSING_LOCKFILES'
fi
TMP="$(mktemp -d /root/bambodia-pvqa-db.XXXXXXXX)"
cleanup(){
  rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-pvqa-db.* ]]; then rm -rf -- "$TMP"; fi
  exit "$rc"
}
trap cleanup EXIT
ADMIN_CNF=''
if [[ -v MYSQL_ADMIN_CNF ]]; then ADMIN_CNF="$MYSQL_ADMIN_CNF"; fi
if [[ -n "$ADMIN_CNF" ]]; then
  [[ "$ADMIN_CNF" == /root/* && -f "$ADMIN_CNF" && ! -L "$ADMIN_CNF" ]] ||
    fail 'MYSQL_ADMIN_CNF must be a regular root-only file under /root'
  [[ "$(stat -c %u "$ADMIN_CNF")" == 0 ]] || fail 'Credential file must be root-owned'
  PERM="$(stat -c %a "$ADMIN_CNF")"
  (( (8#$PERM & 077) == 0 )) || fail 'Credential file must not be group/world accessible'
fi
admin_mysql(){
  if [[ -n "$ADMIN_CNF" ]]; then
    "$MYSQL" --defaults-extra-file="$ADMIN_CNF" --protocol=socket --user=root \
      --connect-timeout=5 --batch --raw --skip-column-names "$@"
  else
    "$MYSQL" --protocol=socket --user=root --connect-timeout=5 \
      --batch --raw --skip-column-names "$@"
  fi
}
if ! admin_mysql -e 'SELECT 1' >/dev/null 2>&1; then
  if [[ "$MODE" == --check ]]; then
    echo 'MYSQL_ADMIN_ACCESS=PRIVATE_AUTH_REQUIRED'
    echo 'RESULT=STOP_BEFORE_DATABASE_INITIALIZATION'
    exit 0
  fi
  [[ -r /dev/tty ]] || fail 'Interactive terminal needed for hidden MySQL root password'
  printf 'MySQL root password (hidden; do not share): ' > /dev/tty
  IFS= read -rs ADMIN_PASSWORD < /dev/tty || fail 'Password read failed'
  printf '\n' > /dev/tty
  [[ -n "$ADMIN_PASSWORD" ]] || fail 'Blank administrator password'
  printf '%s' "$ADMIN_PASSWORD" | python3 -c '
import sys
p=sys.stdin.read().replace("\\","\\\\").replace("\"","\\\"")
with open(sys.argv[1],"w") as f: f.write("[client]\nuser=root\npassword=\""+p+"\"\n")
' "$TMP/admin.cnf"
  chmod 0600 "$TMP/admin.cnf"
  unset ADMIN_PASSWORD
  ADMIN_CNF="$TMP/admin.cnf"
  admin_mysql -e 'SELECT 1' >/dev/null 2>&1 || fail 'MySQL admin authentication failed; no DB created'
fi
echo 'MYSQL_ADMIN_ACCESS=PASS'
if [[ "$MODE" == --check ]]; then
  echo 'RESULT=READY_FOR_QA_DB_INITIALIZATION'
  exit 0
fi
[[ ! -e "$QA/.env" && ! -L "$QA/.env" ]] || fail 'QA env appeared during preflight'
SUFFIX="$(python3 -c 'import secrets;print(secrets.token_hex(6))')"
DB="bambodia_pvqa_$SUFFIX"
DBUSER="b_pvqa_$SUFFIX"
DBPASS="$(python3 -c 'import secrets;print(secrets.token_urlsafe(48))')"
APPKEY="$(python3 -c 'import secrets,base64;print("base64:"+base64.b64encode(secrets.token_bytes(32)).decode())')"
[[ "$DB" =~ ^[a-z0-9_]+$ && "$DBUSER" =~ ^[a-z0-9_]+$ && "$DBPASS" =~ ^[A-Za-z0-9_-]+$ ]] ||
  fail 'Unsafe random account generation'
[[ "$(printf %s "$DBUSER" | wc -c)" -le 32 ]] || fail 'MySQL username length exceeds limit'
NDB="$(admin_mysql -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")" ||
  fail 'Cannot prove QA DB absent'
NUSER="$(admin_mysql -e "SELECT COUNT(*) FROM mysql.user WHERE User='$DBUSER'")" ||
  fail 'Cannot prove QA DB user absent'
[[ "$NDB" == 0 && "$NUSER" == 0 ]] || fail 'Proposed QA account or DB already exists'
echo 'QA_DB_AND_USER=NEW_AND_ABSENT_BEFORE_INITIALIZATION'
# Limited only to the randomly generated, nonexistent QA names. No DROP operations.
if ! admin_mysql > "$TMP/mysql.stdout" 2> "$TMP/mysql.stderr" <<SQL
CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';
CREATE USER '$DBUSER'@'127.0.0.1' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON $DB.* TO '$DBUSER'@'localhost';
GRANT ALL PRIVILEGES ON $DB.* TO '$DBUSER'@'127.0.0.1';
SQL
then
  echo "PARTIAL_QA_DB=$DB"
  echo "PARTIAL_QA_USER=$DBUSER"
  fail 'Provisioning incomplete; inspect manually; no automatic deletion'
fi
printf '[client]\nuser=%s\npassword="%s"\nhost=127.0.0.1\nport=3306\nprotocol=tcp\n' \
  "$DBUSER" "$DBPASS" > "$TMP/qa.cnf"
chmod 0600 "$TMP/qa.cnf"
CONNECTED_DB="$("$MYSQL" --defaults-extra-file="$TMP/qa.cnf" \
  --batch --raw --skip-column-names "$DB" -e 'SELECT DATABASE()' 2>/dev/null)" ||
  fail 'QA user cannot authenticate over TCP'
[[ "$CONNECTED_DB" == "$DB" ]] || fail 'QA database connection mismatch'
N_TABLES="$("$MYSQL" --defaults-extra-file="$TMP/qa.cnf" \
  --batch --raw --skip-column-names "$DB" \
  -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()' 2>/dev/null)" ||
  fail 'Unable to inspect new QA DB'
[[ "$N_TABLES" == 0 ]] || fail 'New QA DB contains unexpected tables'
cat > "$TMP/qa.env" <<EOF
APP_NAME=BambodiaPreviewQA
APP_ENV=staging
APP_DEBUG=false
APP_URL=http://127.0.0.1
APP_KEY=$APPKEY
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=single
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=$DBUSER
DB_PASSWORD=$DBPASS
SESSION_DRIVER=file
SESSION_COOKIE=bambodia_pvqa_$SUFFIX
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
CACHE_STORE=file
CACHE_PREFIX=bambodia_pvqa_$SUFFIX
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MEDIA_LIBRARY_DISK=local
MAIL_MAILER=array
PREVIEW_VISIBILITY_WRITES_ENABLED=false
EOF
python3 - "$TMP/qa.env" "$QA/.env" <<'PY'
import os, sys
src, dst = sys.argv[1:]
flags=os.O_WRONLY | os.O_CREAT | os.O_EXCL
if hasattr(os,"O_NOFOLLOW"): flags |= os.O_NOFOLLOW
fd=os.open(dst,flags,0o600)
with os.fdopen(fd,"wb") as output, open(src,"rb") as source:
    output.write(source.read())
    output.flush()
    os.fsync(output.fileno())
PY
chown www:www "$QA/.env"
chmod 0600 "$QA/.env"
unset DBPASS APPKEY
[[ -f "$QA/.env" && ! -L "$QA/.env" && "$(stat -c %a "$QA/.env")" == 600 ]] ||
  fail 'QA environment permissions invalid'
echo "QA_DATABASE_NAME=$DB"
echo "QA_DATABASE_USER=$DBUSER"
echo 'QA_DATABASE=EMPTY_SEPARATE_VERIFIED'
echo 'QA_APP_KEY=UNIQUE_PRIVATE_0600'
echo 'QA_SESSIONS=FILE_PRIVATE_UNIQUE_COOKIE'
echo 'QA_CACHE=FILE_PRIVATE_UNIQUE_PREFIX'
echo 'QA_MAIL=ARRAY_NO_EXTERNAL_SEND'
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE'
echo 'MIGRATIONS=NOT_EXECUTED'
echo 'RESULT=QA_DATABASE_AND_APP_KEY_INITIALIZED'
echo 'NEXT=DEPENDENCY_BUILD_MIGRATION_AND_RESTORE_ACCEPTANCE'
