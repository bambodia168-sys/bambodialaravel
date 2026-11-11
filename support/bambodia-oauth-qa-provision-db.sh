#!/usr/bin/env bash
# Operator-run, isolated OAuth QA database provisioning.
# Run --check first; --provision requires typed DBA password on /dev/tty.
# No production DB credentials or data are read or reused.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in --check|--provision) ;; *) echo 'Usage: bash script --check|--provision' >&2; exit 64 ;; esac
APP="/var/lib/bambodia-oauth-qa-acceptance"
DB="bambodia_oauthqa_acceptance"
USER="bambodiaqa_acceptance"
CNF="/root/bambodia-oauth-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
QA_SHA="${BAMBODIA_QA_CHECKOUT_SHA:?Set the known-good QA checkout SHA}"
fail() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail 'Run as root on the authorized aaPanel host.'
[[ "$QA_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'QA SHA must be 40 hex characters.'
[[ -x "$MYSQL" ]] || fail 'aaPanel MySQL executable unavailable.'
[[ -d "$APP/.git" && ! -L "$APP" ]] || fail 'Isolated QA checkout missing or symlinked.'
[[ "$(realpath -e "$APP")" == "$APP" ]] || fail 'QA path resolves outside approved location.'
[[ "$(git -C "$APP" rev-parse HEAD)" == "$QA_SHA" ]] || fail 'QA checkout revision mismatch.'
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] || fail 'QA tracked files changed.'
[[ -f "$APP/.env" && ! -L "$APP/.env" && -f "$APP/vendor/autoload.php" ]] ||
  fail 'QA application recovery is incomplete.'
[[ ! -e "$APP/storage/oauth-private.key" && ! -e "$APP/storage/oauth-public.key" ]] ||
  fail 'OAuth keys already exist; stop to inspect before provisioning.'
[[ ! -e "$APP/bootstrap/cache/config.php" ]] || fail 'Cached QA config found.'
[[ ! -e "$CNF" && ! -L "$CNF" ]] || fail 'QA credential file already exists.'
[[ "$(stat -c '%a' "$APP/.env")" == 640 ]] || fail 'QA .env must be mode 640.'
[[ "$(grep -c '^DB_PASSWORD=NOT_CONFIGURED$' "$APP/.env" || true)" == 1 ]] ||
  fail 'QA database password placeholder is not in expected original state.'
grep -qx 'DB_DATABASE=bambodia_oauthqa_acceptance' "$APP/.env" ||
  fail 'QA database target does not match.'
grep -qx 'DB_USERNAME=bambodiaqa_acceptance' "$APP/.env" ||
  fail 'QA user target does not match.'
grep -qx 'DB_HOST=127.0.0.1' "$APP/.env" ||
  fail 'QA database host must be loopback.'
grep -qx 'APP_URL=https://oauth-qa.invalid' "$APP/.env" ||
  fail 'QA URL must remain non-routable during this phase.'
command -v openssl >/dev/null || fail 'OpenSSL unavailable.'
command -v python3 >/dev/null || fail 'Python3 unavailable.'

# The DBA password is requested by the MySQL client interactively via /dev/tty.
# No -pSECRET, no MYSQL_PWD, no root credential files or shared secrets.
admin() { "$MYSQL" --no-defaults --protocol=SOCKET --user=root --password --batch --skip-column-names "$@"; }

echo '========== QA DATABASE SAFETY PREFLIGHT =========='
echo "QA_CHECKOUT_SHA=$QA_SHA"
echo "QA_DB=$DB"
echo "QA_DB_USER=$USER"
echo 'DBA_AUTHENTICATION=INTERACTIVE_PASSWORD_PROMPT'

# Requires only a scoped metadata lookup, never reads application records.
COUNTS="$(admin -e "
SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'),
       (SELECT COUNT(*) FROM mysql.user WHERE user='$USER');
")" || fail 'DBA login failed or lacks privilege to check database/user existence.'
read -r DB_EXISTS USER_EXISTS <<< "$COUNTS"
[[ "$DB_EXISTS" =~ ^[0-9]+$ && "$USER_EXISTS" =~ ^[0-9]+$ ]] ||
  fail 'Could not parse MySQL metadata; refusing changes.'
echo 'DBA_AUTHENTICATION=PASS'
echo "QA_DATABASE_EXISTS=$DB_EXISTS"
echo "QA_USER_EXISTS=$USER_EXISTS"
[[ "$DB_EXISTS" == 0 && "$USER_EXISTS" == 0 ]] ||
  fail 'QA DB or user already exists. Never overwrite an existing resource.'

if [[ "$MODE" == --check ]]; then
  echo 'QA_DB_PRECHECK=PASS'
  echo 'DB_CHANGES=NONE'
  echo 'NEXT=Review output, then run --provision only if this is the authorized isolated host.'
  exit 0
fi

[[ -c /dev/tty ]] || fail 'Interactive terminal unavailable; never automate DBA password entry.'
echo 'Type PROVISION to create ONLY the isolated QA schema and user:'
IFS= read -r CONFIRM </dev/tty
[[ "$CONFIRM" == PROVISION ]] || fail 'Operator did not confirm isolated provisioning.'

QA_DB_PASSWORD="$(openssl rand -hex 32)"
[[ "$QA_DB_PASSWORD" =~ ^[0-9a-f]{64}$ ]] || fail 'Secure QA password generation failed.'
export QA_DB_PASSWORD
echo '========== CREATE RESTRICTED QA SCHEMA AND USER =========='
# A second DBA password prompt is intentional. Never cache DBA credentials.
admin <<SQL
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$USER'@'127.0.0.1' IDENTIFIED BY '$QA_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP,
      REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER, LOCK TABLES
ON \`$DB\`.* TO '$USER'@'127.0.0.1';
SQL

# Create private QA-only credentials without including the password in args/logs.
(
  set -C
  cat > "$CNF" <<EOF
[client]
user=$USER
password=$QA_DB_PASSWORD
host=127.0.0.1
protocol=TCP
EOF
)
chown root:root "$CNF"
chmod 600 "$CNF"

[[ -s "$CNF" && ! -L "$CNF" ]] || fail 'Restricted QA credential file was not created.'
"$MYSQL" --defaults-extra-file="$CNF" --batch --skip-column-names "$DB" -e 'SELECT 1' >/dev/null ||
  fail 'Isolated QA database account cannot connect; stop before changing Laravel config.'

# Atomic replace of the ONE synthetic placeholder; no other .env keys changed.
python3 - "$APP/.env" <<'PY'
import os, sys, tempfile, grp
from pathlib import Path
env = Path(sys.argv[1])
content = env.read_text()
old = 'DB_PASSWORD=NOT_CONFIGURED\n'
assert content.count(old) == 1, "Unexpected QA .env password placeholder"
secret = os.environ['QA_DB_PASSWORD']
assert len(secret) == 64 and all(c in '0123456789abcdef' for c in secret)
fd, temp = tempfile.mkstemp(prefix='.bambodia-oauth-qa-env-', dir=env.parent)
try:
    os.fchmod(fd, 0o640)
    os.fchown(fd, 0, grp.getgrnam('www').gr_gid)
    with os.fdopen(fd, 'w') as stream:
        stream.write(content.replace(old, 'DB_PASSWORD=' + secret + '\n'))
    os.replace(temp, env)
finally:
    if os.path.exists(temp):
        os.unlink(temp)
PY
unset QA_DB_PASSWORD

export PATH="/www/server/mysql/bin:$PATH"
export BAMBODIA_QA_APP="$APP"
export BAMBODIA_QA_DB="$DB"
export BAMBODIA_QA_MYSQL_CNF="$CNF"
export BAMBODIA_QA_EXPECTED_SHA="$QA_SHA"
export BAMBODIA_QA_PHP="/www/server/php/84/bin/php"

echo '========== RUN READ-ONLY OAUTH QA PREFLIGHT =========='
bash "$APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --check

echo 'QA_DB_PROVISION=PASS'
echo 'QA_SCHEMA=NEW_AND_EMPTY'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'OAUTH_MIGRATIONS=NOT_RUN'
echo 'PRODUCTION_SCHEMA=UNMODIFIED_BY_THIS_SCRIPT'
