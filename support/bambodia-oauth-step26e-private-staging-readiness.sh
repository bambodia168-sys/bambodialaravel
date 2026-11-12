#!/usr/bin/env bash
# Bambodia OAuth Step 26E: READ-ONLY QA staging/DB metadata preflight.
# A private application tree must be staged OUTSIDE the currently served webroot.
# No provisioning, file writes, MySQL mutations, Nginx changes, or key generation.
set -Eeuo pipefail
umask 077

HOST="oauth-qa.bambodia.com"
WEBROOT="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
SRC="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
STAGING="/var/lib/bambodia-oauth-https-qa"
NEW_DB="bambodia_oauthqa_https"
NEW_USER="bambodiaqa_https"
PHP="/www/server/php/85/bin/php"
NGINX="/www/server/nginx/sbin/nginx"

fail() { printf 'STEP26E_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for bin in python3 git sha256sum realpath stat; do
  command -v "$bin" >/dev/null || fail "MISSING_$bin"
done
[[ -d /var/lib && ! -L /var/lib && "$(realpath -e /var/lib)" == /var/lib ]] ||
  fail UNSAFE_PRIVATE_STAGING_PARENT
[[ ! -e "$STAGING" && ! -L "$STAGING" ]] ||
  fail PRIVATE_STAGING_PATH_ALREADY_OCCUPIED
[[ "$STAGING" == /var/lib/bambodia-oauth-https-qa ]] ||
  fail UNEXPECTED_PRIVATE_STAGING_PATH
[[ "$STAGING" != "$SRC" && "$STAGING" != "$WEBROOT" ]] ||
  fail STAGING_COLLISION
[[ -d "$WEBROOT" && ! -L "$WEBROOT" &&
   "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] ||
  fail EXISTING_WEBROOT_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" ]] ||
  fail EXISTING_VHOST_UNSAFE
[[ -d "$SRC/.git" && ! -L "$SRC" && ! -L "$SRC/.git" ]] ||
  fail ACCEPTED_SOURCE_UNSAFE
[[ "$(realpath -e "$SRC")" == "$SRC" ]] ||
  fail ACCEPTED_SOURCE_REDIRECTED
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] ||
  fail ACCEPTED_SOURCE_SHA_CHANGED
[[ -z "$(git -C "$SRC" status --porcelain --untracked-files=no)" ]] ||
  fail ACCEPTED_SOURCE_TRACKED_CHANGES
[[ -s "$SRC/composer.lock" && ! -L "$SRC/composer.lock" ]] ||
  fail ACCEPTED_COMPOSER_LOCK_MISSING
[[ "$(sha256sum "$SRC/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail ACCEPTED_COMPOSER_LOCK_CHANGED
[[ -x "$PHP" && -x "$NGINX" ]] || fail PHP_OR_NGINX_UNAVAILABLE
id www >/dev/null || fail WWW_RUNTIME_USER_UNAVAILABLE

echo "========== VERIFY EXISTING HTTPS ROOT =========="
python3 - "$WEBROOT" "$VHOST" "$HOST" <<'PY'
from pathlib import Path
import hashlib, re, stat, sys
root, conf, host = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
raw=conf.read_text(errors="replace")
names=re.findall(r'^\s*server_name\s+([^;]+);', raw, re.M)
roots=re.findall(r'^\s*root\s+([^;]+);', raw, re.M)
if not any(host in s.split() for s in names):
    raise SystemExit("STEP26E_BLOCKED=VHOST_NAME_CHANGED")
if len(roots)!=1 or roots[0].strip()!=str(root):
    raise SystemExit("STEP26E_BLOCKED=VHOST_ROOT_CHANGED")
print("EXISTING_NGINX_ROOT=UNCHANGED")
print("VHOST_SHA256="+hashlib.sha256(conf.read_bytes()).hexdigest())
print("WEBROOT_CHILD_COUNT="+str(sum(1 for _ in root.iterdir())))
ui=root/".user.ini"
if ui.is_symlink(): raise SystemExit("STEP26E_BLOCKED=SYMLINK_USER_INI")
if ui.is_file():
    lines=ui.read_text(errors="replace").splitlines()
    defs=[s.strip() for s in lines if s.strip() and not s.lstrip().startswith(";")]
    values=[s.split("=",1)[1].strip() for s in defs if s.lower().startswith("open_basedir=")]
    print("USER_INI_PRESENT=YES")
    print("OPEN_BASEDIR_SETTING="+("FOUND" if values else "NOT_FOUND"))
    if values:
        print("OPEN_BASEDIR_REFERENCES_EXISTING_ROOT="+
              ("YES" if str(root) in values[0] else "NO"))
        print("OPEN_BASEDIR_REFERENCES_PRIVATE_STAGING="+
              ("YES" if "/var/lib/bambodia-oauth-https-qa" in values[0] else "NO"))
else:
    print("USER_INI_PRESENT=NO")
well=root/".well-known"
if well.is_symlink(): raise SystemExit("STEP26E_BLOCKED=SYMLINK_WELL_KNOWN")
if well.is_dir():
    # Do not read validation files or show ACME challenge tokens.
    files=dirs=links=other=0
    for base, children, names in __import__('os').walk(well,followlinks=False):
        for name in children+names:
            p=Path(base)/name
            mode=p.lstat().st_mode
            if stat.S_ISLNK(mode): links+=1
            elif stat.S_ISDIR(mode): dirs+=1
            elif stat.S_ISREG(mode): files+=1
            else: other+=1
    print("WELL_KNOWN_DIRS="+str(dirs))
    print("WELL_KNOWN_FILES="+str(files))
    print("WELL_KNOWN_SYMLINKS="+str(links))
    print("WELL_KNOWN_SPECIAL_OBJECTS="+str(other))
else:
    print("WELL_KNOWN=ABSENT")
print("WEBROOT_FILE_CONTENTS=NOT_PRINTED")
PY

echo "========== PRIVATE STAGING PATH =========="
echo "PRIVATE_STAGING_PATH=$STAGING"
echo "PRIVATE_STAGING_STATE=ABSENT"
echo "PRIVATE_STAGING_ACTION=RESERVE_ONLY"
echo "ACCEPTED_CODE_AND_LOCK=VERIFIED"

echo "========== PHP-FPM READINESS =========="
"$PHP" -r 'echo "PHP_VERSION=".PHP_VERSION.PHP_EOL;'
if [[ -S /tmp/php-cgi-85.sock ]]; then
  echo "PHP85_SOCKET=PRESENT"
else
  echo "PHP85_SOCKET=NOT_AT_PREVIOUSLY_VERIFIED_PATH"
fi
if "$NGINX" -t >/dev/null 2>&1; then
  echo "NGINX_SYNTAX=PASS"
else
  echo "NGINX_SYNTAX=REVIEW_REQUIRED"
fi

echo "========== QA DATABASE METADATA (OPTIONAL READ-ONLY) =========="
MYSQL="/www/server/mysql/bin/mysql"
if [[ ! -x "$MYSQL" ]]; then
  MYSQL="$(command -v mysql || true)"
fi
if [[ -n "$MYSQL" && -x "$MYSQL" ]]; then
  # No password flag, no prompt, no secrets printed; use existing local defaults.
  SQL_DB="SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$NEW_DB';"
  if DB_COUNT="$("$MYSQL" --batch --skip-column-names --connect-timeout=3 \
    --execute "$SQL_DB" 2>/dev/null)" && [[ "$DB_COUNT" =~ ^[01]$ ]]; then
    echo "RESERVED_QA_DATABASE_EXISTS=$DB_COUNT"
  else
    echo "RESERVED_QA_DATABASE_EXISTENCE=UNVERIFIED_NO_CREDENTIALS_REQUESTED"
  fi
  SQL_USER="SELECT COUNT(*) FROM mysql.user WHERE User='$NEW_USER';"
  if USER_COUNT="$("$MYSQL" --batch --skip-column-names --connect-timeout=3 \
    --execute "$SQL_USER" 2>/dev/null)" && [[ "$USER_COUNT" =~ ^[0-9]+$ ]]; then
    echo "RESERVED_QA_USER_ENTRY_COUNT=$USER_COUNT"
  else
    echo "RESERVED_QA_USER_EXISTENCE=UNVERIFIED_OR_UNAUTHORIZED"
  fi
else
  echo "MYSQL_CLIENT=UNAVAILABLE"
  echo "RESERVED_QA_DATABASE_EXISTENCE=UNVERIFIED"
  echo "RESERVED_QA_USER_EXISTENCE=UNVERIFIED"
fi
echo "QA_MYSQL_GRANTS=NOT_VERIFIED"
echo "DB_CREDENTIALS=NOT_READ_OR_DISCLOSED"

echo "========== FINAL READ-ONLY STATUS =========="
echo "STEP26E_PRIVATE_STAGING_PREFLIGHT=COMPLETE"
echo "QA_APPLICATION_DEPLOYMENT=NOT_STARTED"
echo "WEBROOT_FILES=UNCHANGED"
echo "DATABASE_WRITES=NONE"
echo "PASSPORT_KEYS=UNCHANGED"
echo "NGINX=UNCHANGED"
echo "PRODUCTION=UNCHANGED"
