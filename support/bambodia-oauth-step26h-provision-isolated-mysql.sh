#!/usr/bin/env bash
# Bambodia OAuth Step 26H — create ONLY a brand-new isolated QA MySQL
# schema and localhost account. No Laravel, migrations, Nginx or other DB writes.
# --check is read-only and never connects. --provision explicitly confirmed.
set -Eeuo pipefail
set +x
umask 077
MODE="${1:-}"
case "$MODE" in --check|--provision) ;; *)
  echo 'Usage: bash script --check|--provision' >&2; exit 64 ;;
esac
SOURCE="/var/lib/bambodia-oauth-browser-qa"
SOURCE_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
STAGING="/var/lib/bambodia-oauth-https-qa"
WEBROOT="/www/wwwroot/oauth-qa.bambodia.com"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
DB="bambodia_oauthqa_https"
USERNAME="bambodiaqa_https"
DBHOST="127.0.0.1"
CNF="/root/bambodia-oauth-https-mysql.cnf"
fail(){ printf 'STEP26H_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_OPERATOR_REQUIRED
for cmd in git sha256sum realpath python3 openssl; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d /root && ! -L /root && "$(stat -c %u /root)" == 0 ]] ||
  fail ROOT_PRIVATE_DIRECTORY_UNSAFE
[[ ! -e "$CNF" && ! -L "$CNF" ]] ||
  fail PRIVATE_CREDENTIAL_FILE_ALREADY_PRESENT
[[ -d /var/lib && ! -L /var/lib && "$(realpath -e /var/lib)" == /var/lib ]] ||
  fail STAGING_PARENT_UNSAFE
[[ ! -e "$STAGING" && ! -L "$STAGING" ]] ||
  fail PRIVATE_QA_APP_PATH_ALREADY_OCCUPIED
[[ -d "$WEBROOT" && ! -L "$WEBROOT" &&
   "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] ||
  fail EXISTING_WEBROOT_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail NGINX_VHOST_CHANGED
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && ! -L "$SOURCE/.git" &&
   "$(realpath -e "$SOURCE")" == "$SOURCE" ]] ||
  fail APPROVED_SOURCE_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SOURCE_SHA" ]] ||
  fail SOURCE_COMMIT_CHANGED
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail SOURCE_TRACKED_CHANGE
[[ -s "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" &&
   "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail LOCKFILE_INTEGRITY_CHANGED
[[ "$DB" =~ ^bambodia_oauthqa_[a-z0-9_]+$ &&
   "$USERNAME" =~ ^bambodiaqa_[a-z0-9_]+$ &&
   "$DBHOST" == 127.0.0.1 ]] ||
  fail QA_IDENTITY_SCOPE_INVALID
MYSQL="/www/server/mysql/bin/mysql"
if [[ ! -x "$MYSQL" ]]; then MYSQL="$(command -v mysql || true)"; fi
[[ -n "$MYSQL" && -x "$MYSQL" ]] || fail MYSQL_CLIENT_UNAVAILABLE

echo '========== STEP 26H SCOPE / SAFETY =========='
echo "QA_SCHEMA=$DB"
echo "QA_ACCOUNT=$USERNAME@$DBHOST"
echo "PRIVATE_CLIENT_CREDENTIAL_PATH=$CNF"
echo 'ACCEPTED_SOURCE_AND_NGINX=VERIFIED'
echo 'PRIVATE_APP_PATH=NOT_CREATED'
echo 'PRODUCTION_AND_PRIOR_QA_SCHEMAS=FORBIDDEN'

if [[ "$MODE" == --check ]]; then
  echo 'STEP26H_PREFLIGHT=PASS'
  echo 'MYSQL_CONNECTED=NO'
  echo 'SCHEMA_AND_USER_CREATION=NOT_STARTED'
  echo 'CREDENTIAL_FILE_WRITES=NONE'
  echo 'MYSQL_WRITES=NONE'
  echo 'NGINX=UNCHANGED'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

[[ -c /dev/tty ]] || fail INTERACTIVE_TERMINAL_REQUIRED
printf 'Type PROVISION-STEP26H to create ONE dedicated QA schema and local account: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == PROVISION-STEP26H ]] || fail OPERATOR_DECLINED
unset APPROVAL

# First session: remote password prompt is hidden by mysql CLI. Read exactly
# two metadata counts. Never use existing Laravel or QA MySQL credentials.
echo '========== RECHECK LOCAL MYSQL RESERVATION BEFORE CREATE =========='
echo 'MySQL will prompt for the LOCAL administrative password (hidden).'
SQL="START TRANSACTION READ ONLY;
SELECT CONCAT('SCHEMA_COUNT=',COUNT(*))
FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB';
SELECT CONCAT('ACCOUNT_COUNT=',COUNT(*))
FROM mysql.user WHERE User='$USERNAME';
COMMIT;"
COUNTS="$(env -i HOME=/root PATH=/usr/bin:/bin \
  "$MYSQL" --no-defaults --protocol=TCP --host="$DBHOST" --port=3306 \
  --user=root --password --batch --skip-column-names --silent \
  --connect-timeout=8 --execute "$SQL")" ||
  fail MYSQL_ADMIN_PREFLIGHT_UNAVAILABLE
[[ "$COUNTS" == "$(printf 'SCHEMA_COUNT=0\nACCOUNT_COUNT=0')" ]] ||
  fail RESERVED_SCHEMA_OR_MYSQL_ACCOUNT_ALREADY_EXISTS
echo 'RESERVED_MYSQL_IDENTITIES=STILL_ABSENT'

# Generate a new 256-bit random password. Do not print, export, or add it to
# a process argv. Store it only in a root-owned mode-0600 MySQL options file.
QA_PASSWORD="$(openssl rand -hex 32)" || fail RANDOM_GENERATOR_FAILED
[[ "$QA_PASSWORD" =~ ^[0-9a-f]{64}$ ]] || fail RANDOM_PASSWORD_INVALID
( set -C; printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=3306\nprotocol=tcp\n' \
   "$USERNAME" "$QA_PASSWORD" "$DBHOST" > "$CNF" ) ||
  fail PRIVATE_CREDENTIAL_FILE_CREATE_FAILED
chmod 600 "$CNF"
[[ -f "$CNF" && ! -L "$CNF" && "$(stat -c %u "$CNF")" == 0 &&
   "$(stat -c %a "$CNF")" == 600 ]] ||
  fail PRIVATE_CREDENTIAL_FILE_INTEGRITY
echo 'NEW_PRIVATE_QA_CREDENTIAL=GENERATED_AND_PROTECTED'

# Explicit database-scoped grants. No wildcard hosts and no grant option.
# SQL travels only through stdin, never process command-line parameters.
echo '========== CREATE NEW QA SCHEMA AND RESTRICTED LOCAL ACCOUNT =========='
echo 'Enter the LOCAL MySQL administrative password again (hidden).'
if ! {
  printf 'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$DB"
  printf "CREATE USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$USERNAME" "$DBHOST" "$QA_PASSWORD"
  printf "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES ON \`%s\`.* TO '%s'@'%s';\n" "$DB" "$USERNAME" "$DBHOST"
} | env -i HOME=/root PATH=/usr/bin:/bin \
  "$MYSQL" --no-defaults --protocol=TCP --host="$DBHOST" --port=3306 \
  --user=root --password --batch --silent --connect-timeout=8; then
  unset QA_PASSWORD
  echo 'PARTIAL_PROVISION_POSSIBLE=YES'
  echo 'PRIVATE_CREDENTIAL_FILE=PRESERVED'
  fail MYSQL_QA_CREATE_OR_GRANT_FAILED_DO_NOT_RERUN_OR_DROP
fi
unset QA_PASSWORD

echo '========== VERIFY NEW QA LOGIN AND GRANT BOUNDARIES =========='
GRANTS="$("$MYSQL" --defaults-file="$CNF" --batch --skip-column-names \
  --silent --connect-timeout=8 --execute 'SHOW GRANTS FOR CURRENT_USER()')" ||
  fail QA_ACCOUNT_LOGIN_OR_GRANTS_UNAVAILABLE
python3 - "$GRANTS" <<'PY'
import re,sys
lines=sys.argv[1].splitlines()
if not lines: raise SystemExit('STEP26H_BLOCKED=EMPTY_GRANTS')
found_schema=False
for line in lines:
    if not line.startswith('GRANT ') or ' WITH GRANT OPTION' in line:
        raise SystemExit('STEP26H_BLOCKED=UNEXPECTED_GRANT')
    if re.search(r' ON \*\.\* TO ',line):
        if not re.match(r'^GRANT USAGE ON \*\.\* TO ',line):
            raise SystemExit('STEP26H_BLOCKED=GLOBAL_GRANT_DETECTED')
    elif re.search(r' ON \x60?bambodia_oauthqa_https\x60?\.\* TO ',line):
        found_schema=True
    else:
        raise SystemExit('STEP26H_BLOCKED=GRANT_OUTSIDE_NEW_QA_SCHEMA')
if not found_schema:
    raise SystemExit('STEP26H_BLOCKED=MISSING_SCHEMA_GRANT')
print('QA_MYSQL_GRANTS=RESTRICTED_TO_NEW_SCHEMA')
PY
"$MYSQL" --defaults-file="$CNF" --batch --skip-column-names --silent \
  --connect-timeout=8 "$DB" --execute 'SELECT 1' >/dev/null ||
  fail QA_SCHEMA_CONNECTIVITY_FAILED

echo '========== STEP 26H ACCEPTANCE =========='
echo 'QA_SCHEMA_AND_LOCAL_ACCOUNT=CREATED'
echo 'QA_DATABASE_CONNECTION=PASS'
echo 'QA_MYSQL_GRANTS=RESTRICTED'
echo "QA_PRIVATE_CREDENTIAL_FILE=$CNF"
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'QA_APPLICATION=NOT_STAGED'
echo 'EXISTING_QA_MYSQL_SCHEMAS=NOT_TARGETED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
