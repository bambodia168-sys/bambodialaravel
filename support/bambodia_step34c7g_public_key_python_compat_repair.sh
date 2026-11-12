#!/usr/bin/env bash
# Step 34C.7G - Python 3.8-compatible recovery of a single public-key mode.
# Supersedes Step 34C.7F, which failed on unavailable hashlib.file_digest
# BEFORE fchmod. Hash with os.pread so byte ranges remain correct after chmod.
# Never modify key contents, rerun migrations, or touch Nginx.
set -Eeuo pipefail
set +x
umask 077

MODE="$1"
case "$MODE" in --check|--repair) ;; *)
  echo 'Usage: script --check|--repair' >&2; exit 64 ;;
esac
APP="/var/lib/bambodia-oauth-public-qa"
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
PRIVATE="$APP/storage/oauth-private.key"
PUBLIC="$APP/storage/oauth-public.key"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="06806f2913c3150cf430482124a23f760dfb60d3c47dc587700762d32b2a4a6a"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
PUB_CNF="/root/bambodia-oauth-public-qa.cnf"
QA_CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"
fail(){ echo "STEP34C7G_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY PRIVATE APP AND EXISTING HOST =========='
test "$(id -u)" = 0 || fail ROOT_REQUIRED
for bin in git stat sha256sum python3 openssl; do
  command -v "$bin" >/dev/null || fail REQUIRED_TOOL_MISSING
done
test -x "$MYSQL" || fail MYSQL_CLIENT_MISSING
test -d "$APP" && test ! -L "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = '0:700' ||
  fail PRIVATE_RELEASE_ROOT_CHANGED
test -f "$APP/.env" && test ! -L "$APP/.env" &&
  test "$(stat -c '%u:%a' "$APP/.env")" = '0:600' ||
  fail PRIVATE_ENV_CHANGED
test -f "$APP/vendor/autoload.php" &&
  test -f "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail VENDOR_OR_LOCK_CHANGED
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_QA_CHANGED
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" ||
  fail UNAPPROVED_VHOST_CHANGED
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_SITE_CHANGED
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail OFFLINE_BACKUP_CHANGED
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail OFFLINE_BACKUP_HASH_MISMATCH
for cnf in "$PUB_CNF" "$QA_CNF"; do
  test -f "$cnf" && test ! -L "$cnf" &&
    test "$(stat -c '%u:%a' "$cnf")" = '0:600' ||
    fail MYSQL_CREDENTIAL_FILE_CHANGED
done
echo 'FROZEN_QA_PRIVATE_RELEASE_AND_BACKUP=PASS'
echo 'CURRENT_OCCUPIED_VHOST=UNCHANGED'
echo 'PUBLIC_HOSTNAME_AUTHORIZATION=NOT_GRANTED'

echo '========== READ-ONLY MIGRATION AND ACCOUNT VERIFICATION =========='
PUB_MYSQL(){ "$MYSQL" --defaults-extra-file="$PUB_CNF" --batch --skip-column-names "$@"; }
QA_MYSQL(){ "$MYSQL" --defaults-extra-file="$QA_CNF" --protocol=TCP \
  --host=127.0.0.1 --batch --skip-column-names bambodia_oauthqa_browser "$@"; }
test "$(PUB_MYSQL -e 'SELECT CURRENT_USER()')" = 'bambodiaqa_public@127.0.0.1' &&
  test "$(PUB_MYSQL -e 'SELECT DATABASE()')" = 'bambodia_oauthqa_public' ||
  fail NEW_QA_DB_IDENTITY_CHANGED
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM migrations')" = 67 ||
  fail ISOLATED_MIGRATION_COUNT_CHANGED
test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM users')" = 0 &&
  test "$(PUB_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 0 ||
  fail ISOLATED_USERS_OR_CLIENTS_CHANGED
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1 &&
  test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1 ||
  fail FROZEN_QA_ACCOUNT_BASELINE_CHANGED
for t in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $t WHERE revoked=0")" = 0 ||
    fail FROZEN_QA_ACTIVE_OAUTH_GRANTS
done
echo 'ISOLATED_QA_MIGRATIONS=67'
echo 'ISOLATED_QA_USERS_AND_CLIENTS=ZERO'
echo 'FROZEN_QA_ACCOUNT_BASELINE=PASS'

echo '========== VERIFY EXACT KEY METADATA AND MATH =========='
python3 - "$PRIVATE" "$PUBLIC" <<'PY'
from pathlib import Path
import stat, subprocess, sys
private,public=map(Path,sys.argv[1:])
for path,label,mode,size in (
        (private,'PRIVATE',0o600,3322),
        (public,'PUBLIC',0o660,812)):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or \
            stat.S_IMODE(st.st_mode)!=mode or st.st_size!=size:
        raise SystemExit('STEP34C7G_BLOCKED='+label+'_KEY_METADATA_DRIFT')
    print(label+'_KEY_METADATA=EXPECTED')
def derive(path, is_public):
    args=['openssl','pkey']
    if is_public: args+=['-pubin']
    args+=['-in',str(path),'-pubout','-outform','DER']
    p=subprocess.run(args,capture_output=True,timeout=15,check=False)
    if p.returncode or not p.stdout:
        raise SystemExit('STEP34C7G_BLOCKED=KEY_OPENSSL_PARSE_FAILED')
    return p.stdout
if derive(private,False)!=derive(public,True):
    raise SystemExit('STEP34C7G_BLOCKED=PUBLIC_PRIVATE_KEY_MISMATCH')
print('PASSPORT_KEYPAIR_MATHEMATICALLY_MATCHED=PASS')
print('KEY_MATERIAL_PRINTED=NO')
PY

if [ "$MODE" = '--check' ]; then
  echo 'STEP34C7G_READ_ONLY_PREFLIGHT=PASS'
  echo 'KEY_PERMISSIONS_CHANGED=NO'
  exit 0
fi

echo '========== APPROVE ONLY PUBLIC KEY MODE RESTRICTION =========='
test -c /dev/tty || fail TERMINAL_REQUIRED
echo 'Only the existing public key mode will change 0660 -> 0600.'
echo 'Private key, keys contents, APP_KEY, database and web routing untouched.'
printf 'Type REPAIR-PASSPORT-PUBLIC-KEY-PYTHON-COMPAT: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'REPAIR-PASSPORT-PUBLIC-KEY-PYTHON-COMPAT' ||
  fail OPERATOR_DECLINED
echo 'STEP34C7G_EXPLICIT_PERMISSION_REPAIR_APPROVAL=PASS'

echo '========== RESTRICT EXISTING PUBLIC KEY VIA FILE DESCRIPTOR =========='
python3 - "$PRIVATE" "$PUBLIC" <<'PY'
from pathlib import Path
import hashlib,os,stat,subprocess,sys
private,public=map(Path,sys.argv[1:])
s=private.lstat()
if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or \
   stat.S_IMODE(s.st_mode)!=0o600 or s.st_size!=3322:
    raise SystemExit('STEP34C7G_BLOCKED=PRIVATE_KEY_CHANGED')
fd=os.open(public,os.O_RDONLY|os.O_CLOEXEC|os.O_NOFOLLOW)
try:
    before=os.fstat(fd)
    if not stat.S_ISREG(before.st_mode) or before.st_uid!=0 or \
       stat.S_IMODE(before.st_mode)!=0o660 or before.st_size!=812:
        raise SystemExit('STEP34C7G_BLOCKED=PUBLIC_KEY_CHANGED')
    if before.st_nlink!=1:
        raise SystemExit('STEP34C7G_BLOCKED=PUBLIC_KEY_HARDLINK_UNEXPECTED')
    def hash_fd(handle):
        digest=hashlib.sha256()
        offset=0
        while True:
            data=os.pread(handle,65536,offset)
            if not data:break
            digest.update(data)
            offset+=len(data)
        return digest.digest()
    private_before=hashlib.sha256(private.read_bytes()).digest()
    digest_before=hash_fd(fd)
    os.fchmod(fd,0o600)
    after=os.fstat(fd)
    path_after=os.lstat(public)
    if before.st_dev!=after.st_dev or before.st_ino!=after.st_ino or \
       after.st_dev!=path_after.st_dev or after.st_ino!=path_after.st_ino or \
       after.st_uid!=0 or after.st_nlink!=1 or \
       stat.S_IMODE(after.st_mode)!=0o600 or \
       not stat.S_ISREG(after.st_mode) or after.st_size!=812:
        raise SystemExit('STEP34C7G_BLOCKED=PUBLIC_KEY_CHMOD_VERIFICATION')
    digest_after=hash_fd(fd)
    if digest_before!=digest_after:
        raise SystemExit('STEP34C7G_BLOCKED=PUBLIC_KEY_CONTENT_CHANGED')
    if hashlib.sha256(private.read_bytes()).digest()!=private_before:
        raise SystemExit('STEP34C7G_BLOCKED=PRIVATE_KEY_CONTENT_CHANGED')
finally:
    os.close(fd)
def derive(path,pub):
    args=['openssl','pkey']
    if pub: args.append('-pubin')
    args+=['-in',str(path),'-pubout','-outform','DER']
    p=subprocess.run(args,capture_output=True,timeout=15,check=False)
    if p.returncode or not p.stdout:
        raise SystemExit('STEP34C7G_BLOCKED=OPENSSL_VALIDATION_FAILED')
    return p.stdout
if derive(private,False)!=derive(public,True):
    raise SystemExit('STEP34C7G_BLOCKED=KEYPAIR_CHANGED')
print('PASSPORT_PRIVATE_KEY=ROOT_0600_UNCHANGED')
print('PASSPORT_PUBLIC_KEY=ROOT_0600_RESTRICTED')
print('PASSPORT_PUBLIC_KEY_CONTENT=BYTE_IDENTICAL')
print('PASSPORT_KEYPAIR_DER_MATCH=PASS')
print('PASSPORT_KEY_REGENERATION=NONE')
PY
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$VHOST_SHA" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" ||
  fail SITE_OR_FROZEN_QA_CHANGED_POST_REPAIR

echo 'STEP34C7G_PUBLIC_KEY_PERMISSIONS_RECOVERY=PASS'
echo 'MIGRATIONS_REPLAYED=NO'
echo 'APP_KEY_AND_PRIVATE_SIGNING_KEY_UNCHANGED=YES'
echo 'PUBLIC_OAUTH_ISSUER=NOT_ACTIVATED'
echo 'NGINX_DNS_TLS_AND_PRODUCTION_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PR_MERGE=UNCHANGED'
