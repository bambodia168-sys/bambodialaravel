#!/usr/bin/env bash
# Bambodia OAuth Step 26G: READ-ONLY reserved QA MySQL identity audit.
# --check verifies files/paths without connecting; --audit runs 2 SELECTs
# against local MySQL with an interactive, hidden root password prompt.
# No DB creation, GRANT, migrations or runtime changes.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in --check|--audit) ;; *)
  echo 'Usage: bash script --check|--audit' >&2; exit 64 ;;
esac
APP="/var/lib/bambodia-oauth-browser-qa"
APP_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
QA_ROOT="/var/lib/bambodia-oauth-https-qa"
QA_DB="bambodia_oauthqa_https"
QA_USER="bambodiaqa_https"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
fail(){ printf 'STEP26G_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_OPERATOR_REQUIRED
for cmd in git sha256sum realpath python3; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ "$QA_ROOT" == /var/lib/bambodia-oauth-https-qa &&
   ! -e "$QA_ROOT" && ! -L "$QA_ROOT" ]] ||
  fail PRIVATE_QA_TARGET_NO_LONGER_AVAILABLE
[[ -d "$APP/.git" && ! -L "$APP" && ! -L "$APP/.git" &&
   "$(realpath -e "$APP")" == "$APP" ]] || fail SOURCE_PATH_UNSAFE
[[ "$(git -C "$APP" rev-parse HEAD)" == "$APP_SHA" ]] ||
  fail SOURCE_SHA_CHANGED
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] ||
  fail TRACKED_SOURCE_CHANGED
[[ -s "$APP/composer.lock" && ! -L "$APP/composer.lock" &&
   "$(sha256sum "$APP/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail ACCEPTED_LOCK_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail VHOST_CHANGED_SINCE_STEP26E
[[ "$QA_DB" =~ ^bambodia_oauthqa_[a-z0-9_]+$ &&
   "$QA_USER" =~ ^bambodiaqa_[a-z0-9_]+$ ]] ||
  fail RESERVED_IDENTITY_INVALID

MYSQL="/www/server/mysql/bin/mysql"
if [[ ! -x "$MYSQL" ]]; then MYSQL="$(command -v mysql || true)"; fi
[[ -n "$MYSQL" && -x "$MYSQL" ]] || fail MYSQL_CLIENT_UNAVAILABLE

echo '========== RESERVED QA MYSQL IDENTITIES =========='
echo "QA_DATABASE_NAME=$QA_DB"
echo "QA_MYSQL_ACCOUNT_NAME=$QA_USER"
echo "QA_APPLICATION_PATH=$QA_ROOT"
echo 'SOURCE_AND_VHOST=PINNED'
echo 'MYSQL_TARGET=127.0.0.1:3306'
echo 'MYSQL_METADATA_QUERIES=SELECT_ONLY'
echo 'MYSQL_SECRETS=NOT_PRINTED'
if [[ "$MODE" == --check ]]; then
  echo 'STEP26G_PREFLIGHT=PASS'
  echo 'MYSQL_CONNECTED=NO'
  echo 'DATABASE_EXISTENCE=UNVERIFIED'
  echo 'ACCOUNT_EXISTENCE=UNVERIFIED'
  echo 'MYSQL_WRITES=NONE'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

# --no-defaults ignores potentially connected credentials; root MySQL password
# is entered interactively, never stored in a shell variable, file or argv.
[[ -c /dev/tty ]] || fail INTERACTIVE_PASSWORD_TERMINAL_REQUIRED
echo '========== LOCAL MYSQL READ-ONLY METADATA QUERY =========='
echo 'Enter the LOCAL MySQL root password at the hidden prompt.'
echo 'Never paste passwords into chat or GitHub.'
SQL="START TRANSACTION READ ONLY;
SELECT CONCAT('SCHEMA_COUNT=',COUNT(*))
  FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${QA_DB}';
SELECT CONCAT('ACCOUNT_HOST_COUNT=',COUNT(*))
  FROM mysql.user WHERE User='${QA_USER}';
COMMIT;"
set +e
RESULT="$(
  env -i HOME=/root PATH=/usr/bin:/bin \
  "$MYSQL" --no-defaults --protocol=TCP \
    --host=127.0.0.1 --port=3306 --user=root --password \
    --batch --skip-column-names --silent --connect-timeout=8 \
    --execute "$SQL"
)"
RET=$?
set -e
[[ "$RET" == 0 ]] || fail MYSQL_ADMIN_METADATA_READ_UNAVAILABLE
echo 'MYSQL_LOCAL_ADMIN_METADATA=ACCESSIBLE'
python3 - "$RESULT" <<'PY'
import re,sys
rows=sys.argv[1].splitlines()
if len(rows)!=2:
    raise SystemExit("STEP26G_BLOCKED=UNEXPECTED_MYSQL_METADATA_FORMAT")
values={}
for line in rows:
    match=re.fullmatch(r'(SCHEMA_COUNT|ACCOUNT_HOST_COUNT)=([0-9]+)',line)
    if not match or match.group(1) in values:
        raise SystemExit("STEP26G_BLOCKED=UNEXPECTED_MYSQL_METADATA")
    values[match.group(1)]=int(match.group(2))
if set(values)!={"SCHEMA_COUNT","ACCOUNT_HOST_COUNT"}:
    raise SystemExit("STEP26G_BLOCKED=INCOMPLETE_MYSQL_METADATA")
print("RESERVED_QA_DATABASE_COUNT="+str(values["SCHEMA_COUNT"]))
print("RESERVED_QA_ACCOUNT_HOST_COUNT="+str(values["ACCOUNT_HOST_COUNT"]))
if values["SCHEMA_COUNT"]==0 and values["ACCOUNT_HOST_COUNT"]==0:
    print("QA_MYSQL_IDENTITIES=AVAILABLE_FOR_SEPARATE_APPROVED_PROVISIONING")
else:
    print("QA_MYSQL_IDENTITIES=EXISTING_OBJECT_REVIEW_REQUIRED")
print("QA_MYSQL_GRANTS=NOT_YET_AUDITED")
PY
echo '========== FINAL READ-ONLY STATUS =========='
echo 'STEP26G_MYSQL_METADATA_AUDIT=COMPLETE'
echo 'MYSQL_METADATA_READ_ONLY=YES'
echo 'APPLICATION_DEPLOYMENT=NOT_STARTED'
echo 'DATABASE_WRITES=NONE'
echo 'MYSQL_GRANTS_MODIFIED=NO'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
