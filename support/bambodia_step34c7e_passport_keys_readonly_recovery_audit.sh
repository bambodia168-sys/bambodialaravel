#!/usr/bin/env bash
# Step 34C.7E: READ-ONLY inspection of partially completed Passport key init.
# No key generation, chmod, migrations, mail, vhost or GitHub changes.
# Never print a key, DB password, APP_KEY or raw key certificate material.
set -Eeuo pipefail
set +x
umask 077

APP="/var/lib/bambodia-oauth-public-qa"
QA="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="06806f2913c3150cf430482124a23f760dfb60d3c47dc587700762d32b2a4a6a"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"

fail() { echo "STEP34C7E_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY PRESERVED ENVIRONMENT =========='
test "$(id -u)" -eq 0 || fail NOT_ROOT
for bin in git stat sha256sum python3 openssl; do
  command -v "$bin" >/dev/null || fail TOOL_MISSING
done
test -x "$MYSQL" || fail MYSQL_NOT_FOUND
test -d "$APP" && test ! -L "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
  fail PRIVATE_APP_ROOT_CHANGED
test -f "$APP/.env" && test ! -L "$APP/.env" &&
  test "$(stat -c '%u:%a' "$APP/.env")" = '0:600' ||
  fail PRIVATE_APP_ENV_CHANGED
test -f "$APP/vendor/autoload.php" &&
  test -f "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail PRIVATE_APP_VENDOR_OR_LOCK_CHANGED
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_QA_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail OCCUPIED_VHOST_CHANGED
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_SITE_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail PRESERVED_BACKUP_UNSAFE
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail PRESERVED_BACKUP_CHANGED
for cnf in "$PUB_CNF" "$QA_CNF"; do
  test -f "$cnf" && test ! -L "$cnf" &&
    test "$(stat -c '%u:%a' "$cnf")" = '0:600' ||
    fail MYSQL_CREDENTIAL_FILE_UNSAFE
done
echo 'PRIVATE_APP_AND_FROZEN_QA=PASS'
echo 'OCCUPIED_VHOST_AND_ORIGINAL_SITE=UNCHANGED'
echo 'PRESERVATION_BACKUP=PASS'
echo 'PUBLIC_HOSTNAME_APPROVAL=NOT_GRANTED'

echo '========== READ-ONLY VERIFY ISOLATED MIGRATIONS =========='
PUB_MYSQL() {
  "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"
}
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP --host=127.0.0.1 \
    --batch --skip-column-names bambodia_oauthqa_browser "$@"
}
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' ||
  fail ISOLATED_MYSQL_ACCOUNT_CHANGED
test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail ISOLATED_DB_CHANGED
for table in migrations users oauth_clients oauth_auth_codes \
  oauth_access_tokens oauth_refresh_tokens; do
  test "$(PUB_MYSQL -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_public' AND TABLE_NAME='$table'")" = 1 ||
    fail ISOLATED_SCHEMA_REQUIRED_TABLE_MISSING
done
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM users')" = 0 ||
  fail ISOLATED_QA_USERS_UNEXPECTED
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 0 ||
  fail ISOLATED_QA_CLIENTS_UNEXPECTED
NEW_MIGRATIONS="$(PUB_MYSQL -e 'SELECT COUNT(*) FROM migrations')" ||
  fail ISOLATED_MIGRATION_INVENTORY_FAILED
test "$NEW_MIGRATIONS" -gt 0 || fail NO_MIGRATION_ENTRIES
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail ORIGINAL_QA_ACCOUNT_BASELINE_CHANGED
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0 ||
    fail FROZEN_ACTIVE_OAUTH_GRANTS
done
echo "ISOLATED_QA_MIGRATION_COUNT=$NEW_MIGRATIONS"
echo 'ISOLATED_QA_REQUIRED_TABLES=PASS'
echo 'ISOLATED_QA_USERS_AND_CLIENTS=ZERO'
echo 'ORIGINAL_QA_ACCOUNT_BASELINE=PASS'
echo 'ACTIVE_OAUTH_GRANTS_IN_FROZEN_QA=ZERO'

echo '========== INSPECT KEY METADATA AND MATHEMATICAL PAIRING =========='
python3 - "$APP/storage" <<'PY'
from pathlib import Path
import subprocess,sys,stat,hashlib
root=Path(sys.argv[1])
status={}
for filename,tag in [('oauth-private.key','PRIVATE'),('oauth-public.key','PUBLIC')]:
    path=root/filename
    try:
        s=path.lstat()
    except FileNotFoundError:
        print(tag+'_KEY_FILE=ABSENT')
        status[tag]=False
        continue
    if stat.S_ISLNK(s.st_mode):
        print(tag+'_KEY_FILE=SYMLINK_BLOCKED')
        status[tag]=False
        continue
    if not stat.S_ISREG(s.st_mode):
        print(tag+'_KEY_FILE=NOT_REGULAR_BLOCKED')
        status[tag]=False
        continue
    print(tag+'_KEY_FILE=REGULAR')
    print(tag+'_KEY_OWNER_UID='+str(s.st_uid))
    print(tag+'_KEY_MODE='+oct(stat.S_IMODE(s.st_mode)))
    print(tag+'_KEY_SIZE_BYTES='+str(s.st_size))
    # Public-key mode 0644 is not intrinsically insecure, since the key is public.
    # Private-key exposure is an independent, strict security failure.
    secure_owner=(s.st_uid==0)
    secure_private=(stat.S_IMODE(s.st_mode)&0o077)==0
    secure_public=(stat.S_IMODE(s.st_mode)&0o022)==0
    print(tag+'_OWNER_ROOT='+('YES' if secure_owner else 'NO'))
    print(tag+'_MODE_ACCEPTABLE='+(
        'YES' if (secure_private if tag=='PRIVATE' else secure_public) else 'NO'))
    print(tag+'_NONEMPTY='+('YES' if s.st_size>0 else 'NO'))
    status[tag]=secure_owner and s.st_size>0 and (
        secure_private if tag=='PRIVATE' else secure_public)
if not all(status.get(key) for key in ('PRIVATE','PUBLIC')):
    print('PASSPORT_KEY_SECURITY=REVIEW_REQUIRED')
else:
    print('PASSPORT_KEY_SECURITY=PASS')
private=root/'oauth-private.key'
public=root/'oauth-public.key'
if not (private.is_file() and public.is_file() and
        not private.is_symlink() and not public.is_symlink()):
    print('PASSPORT_KEYPAIR_DER_MATCH=NOT_CHECKED_MISSING_OR_UNSAFE_TYPE')
else:
    def run(args):
        try:
            p=subprocess.run(args,stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL,timeout=15,check=False)
            return p.stdout if p.returncode==0 else None
        except (OSError,subprocess.TimeoutExpired):
            return None
    private_pub=run(['openssl','pkey','-in',str(private),'-pubout','-outform','DER'])
    public_pub=run(['openssl','pkey','-pubin','-in',str(public),'-pubout','-outform','DER'])
    print('PRIVATE_RSA_PARSE='+('PASS' if private_pub else 'FAIL'))
    print('PUBLIC_KEY_PARSE='+('PASS' if public_pub else 'FAIL'))
    print('PASSPORT_KEYPAIR_DER_MATCH='+(
          'PASS' if private_pub and public_pub and private_pub==public_pub
          else 'FAIL_OR_NOT_VERIFIED'))
print('RAW_PRIVATE_KEY_OR_PUBLIC_KEY_MATERIAL_PRINTED=NO')
PY

echo '========== VERIFY READ-ONLY INSPECTION BOUNDARIES =========='
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail VHOST_CHANGED_DURING_KEY_AUDIT
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail SITE_CHANGED_DURING_KEY_AUDIT
test "$(git -C "$QA" rev-parse HEAD)" = "$SHA" ||
  fail FROZEN_QA_CHANGED_DURING_KEY_AUDIT
echo 'STEP34C7E_READ_ONLY_KEY_AND_DB_INVENTORY=COMPLETE'
echo 'KEY_REPAIR_OR_REGENERATION=NOT_PERFORMED'
echo 'MIGRATION_REPLAY=NOT_PERFORMED'
echo 'PUBLIC_SERVER_AND_DATABASE_WRITES=NONE'
echo 'DNS_NGINX_TLS_AND_GITHUB_MAIN_WRITES=NONE'
