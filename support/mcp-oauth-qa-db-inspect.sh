#!/usr/bin/env bash
# Read-only isolated QA environment and MySQL readiness inspection.
set -Eeuo pipefail
umask 077

[[ $# -eq 1 && "$1" == "--check" ]] || { echo 'Usage: script --check' >&2; exit 64; }
APP="/var/lib/bambia-oauth-qa-acceptance"
# Use only this approved, exact OAuth QA checkout path.
APP="/var/lib/bambodia-oauth-qa-acceptance"
DB="bambodia_oauthqa_acceptance"
DB_USER="bambodiaqa_acceptance"
CNF="/root/bambodia-oauth-qa.cnf"
: "$BAMBODIA_QA_CHECKOUT_SHA"
EXPECTED_SHA="$BAMBODIA_QA_CHECKOUT_SHA"

die() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || die 'Run as root.'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || die 'Reviewed SHA must contain 40 hex digits.'
[[ -d "$APP/.git" && ! -L "$APP" ]] || die 'Missing or symlinked QA checkout.'
[[ "$(realpath -e "$APP")" == "$APP" ]] || die 'QA directory resolves outside the expected path.'
[[ "$(git -C "$APP" rev-parse HEAD)" == "$EXPECTED_SHA" ]] || die 'QA source SHA mismatch.'
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] || die 'Tracked QA source modified.'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/composer.lock" ]] || die 'QA dependencies/lockfile missing.'
[[ ! -e "$APP/storage/oauth-private.key" && ! -L "$APP/storage/oauth-private.key" ]] || die 'Passport private key already exists.'
[[ ! -e "$APP/storage/oauth-public.key" && ! -L "$APP/storage/oauth-public.key" ]] || die 'Passport public key already exists.'
[[ ! -e "$APP/bootstrap/cache/config.php" ]] || die 'QA config cache must not exist.'
echo "QA_SOURCE=PASS"
echo "QA_DEPENDENCIES=PRESENT"
echo "QA_PASSPORT_KEYS=ABSENT"

[[ -f "$APP/.env" && ! -L "$APP/.env" ]] || die 'QA .env must exist as a regular file.'
python3 - "$APP/.env" "$DB" "$DB_USER" <<'PY'
import stat, sys
from pathlib import Path
p=Path(sys.argv[1]); db,user=sys.argv[2:]
s=p.stat()
if s.st_uid != 0 or stat.S_IMODE(s.st_mode) not in (0o600,0o640):
    raise SystemExit('BLOCKED: QA .env must be root-owned, mode 600/640.')
data={}
duplicate=set()
for line in p.read_text().splitlines():
    if not line or line.lstrip().startswith('#') or '=' not in line: continue
    k,v=line.split('=',1); k=k.strip(); v=v.strip().strip("\"'")
    if k in data: duplicate.add(k)
    data[k]=v
expected={
'APP_ENV':'staging','APP_DEBUG':'false','APP_URL':'https://oauth-qa.invalid',
'DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_DATABASE':db,
'DB_USERNAME':user,'MAIL_MAILER':'log','QUEUE_CONNECTION':'database',
'SESSION_DRIVER':'file','CACHE_STORE':'file',
'SESSION_COOKIE':'bambodia_oauthqa_acceptance'
}
valid = all(data.get(k)==v for k,v in expected.items())
valid &= data.get('SESSION_DOMAIN','null') in ('','null')
valid &= data.get('APP_KEY','').startswith('base64:')
valid &= not duplicate
valid &= not any(k in data for k in ('PASSPORT_PRIVATE_KEY','PASSPORT_PUBLIC_KEY','DB_URL','DATABASE_URL','DB_SOCKET'))
print('QA_ENV_NON_SECRET_SETTINGS=' + ('PASS' if valid else 'REVIEW_REQUIRED'))
if not valid: raise SystemExit(1)
pw=data.get('DB_PASSWORD','')
print('QA_DB_PASSWORD=' + ('PLACEHOLDER' if pw == 'NOT_CONFIGURED' else 'CONFIGURED' if pw else 'MISSING'))
print('ENV_VALUES=REDACTED')
PY

if [[ -e "$CNF" || -L "$CNF" ]]; then
  [[ -f "$CNF" && ! -L "$CNF" && "$(stat -c '%u:%a' "$CNF")" == "0:600" ]] ||
    die 'Existing QA MySQL CNF has unsafe type, owner or mode.'
  echo "QA_MYSQL_CNF=PRESENT"
else
  echo "QA_MYSQL_CNF=MISSING"
fi

MYSQL="$(command -v mysql || true)"
[[ -n "$MYSQL" ]] || die 'mysql CLI unavailable.'
# Only local administrator availability and QA schema/user name are read.
if "$MYSQL" --protocol=SOCKET --user=root -N -B -e 'SELECT 1' >/dev/null 2>&1; then
  echo 'MYSQL_LOCAL_ADMIN_ACCESS=AVAILABLE'
  db_exists="$("$MYSQL" --protocol=SOCKET --user=root -N -B -e \
    "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")"
  user_exists="$("$MYSQL" --protocol=SOCKET --user=root -N -B -e \
    "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER'")"
  echo "QA_SCHEMA=$([[ "$db_exists" == 0 ]] && echo ABSENT || echo EXISTS)"
  echo "QA_USER=$([[ "$user_exists" == 0 ]] && echo ABSENT || echo EXISTS)"
  if [[ "$db_exists" != 0 || "$user_exists" != 0 ]]; then
    echo 'QA_PROVISION_STATUS=REVIEW_EXISTING_RESOURCES'
  else
    echo 'QA_PROVISION_STATUS=NEW_SCHEMA_AND_USER_CAN_BE_PREPARED'
  fi
else
  echo 'MYSQL_LOCAL_ADMIN_ACCESS=NOT_AVAILABLE'
  echo 'QA_PROVISION_STATUS=DBA_CREDENTIAL_SETUP_REQUIRED'
fi

echo 'READ_ONLY=YES'
echo 'SQL_WRITES=NONE'
echo 'OAUTH_MIGRATIONS=NOT_RUN'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'PRODUCTION_APP=UNTOUCHED'
