#!/usr/bin/env bash
# Step 26C: inspect reserved HTTPS QA deployment boundaries, READ-ONLY.
# No file copies, vhost edits, DB creation/migration, credentials, or reload.
set -Eeuo pipefail
umask 077

HOST='oauth-qa.bambodia.com'
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
WEBROOT="/www/wwwroot/$HOST"
SOURCE='/var/lib/bambodia-oauth-browser-qa'
SOURCE_SHA='34e86fbbdfcbe56e5e28e0286a47ba2309e48455'
REQUIRED_LOCK='01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3'
NEW_DB='bambodia_oauthqa_https'
NEW_USER='bambodiaqa_https'
PHP='/www/server/php/85/bin/php'
NGINX='/www/server/nginx/sbin/nginx'
die(){ printf 'STEP26C_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || die ROOT_REQUIRED
for bin in git getent curl python3 realpath sha256sum stat; do
  command -v "$bin" >/dev/null || die "MISSING_$bin"
done
[[ -x "$PHP" && -x "$NGINX" ]] || die PHP_OR_NGINX_MISSING
[[ "$HOST" != bambodia.com && "$HOST" != www.bambodia.com ]] ||
  die PRODUCTION_HOST_FORBIDDEN
[[ "$WEBROOT" == /www/wwwroot/oauth-qa.bambodia.com ]] ||
  die UNEXPECTED_WEBROOT
[[ -d "$WEBROOT" && ! -L "$WEBROOT" &&
   "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] ||
  die WEBROOT_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" ]] || die VHOST_UNSAFE
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
   "$(realpath -e "$SOURCE")" == "$SOURCE" ]] ||
  die APPROVED_SOURCE_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SOURCE_SHA" ]] ||
  die APPROVED_SOURCE_SHA_MISMATCH
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  die APPROVED_SOURCE_TRACKED_CHANGES
[[ -s "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" ]] ||
  die ACCEPTED_LOCK_UNAVAILABLE
[[ "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$REQUIRED_LOCK" ]] ||
  die ACCEPTED_LOCK_HASH_CHANGED
[[ "$NEW_DB" == bambodia_oauthqa_https &&
   "$NEW_USER" == bambodiaqa_https ]] ||
  die ISOLATED_QA_NAMES_REQUIRED
id www >/dev/null || die PHP_SERVICE_ACCOUNT_MISSING

echo '========== APPROVED CODE / HOST BOUNDARIES =========='
echo 'APPROVED_SOURCE=PINNED_AND_CLEAN'
echo 'ACCEPTED_LOCK=VERIFIED'
echo "NEW_QA_HOST=$HOST"
echo "NEW_QA_DATABASE_NAME_RESERVED=$NEW_DB"
echo "NEW_QA_DATABASE_USER_NAME_RESERVED=$NEW_USER"
echo 'DATABASE_EXISTENCE=NOT_CHECKED'
echo 'DB_CREDENTIALS=NOT_ACCESSED'

echo '========== PHP AND NGINX READINESS =========='
"$PHP" -r 'echo "PHP_VERSION=".PHP_VERSION.PHP_EOL;'
if "$NGINX" -t >/dev/null 2>&1; then
  echo 'NGINX_SYNTAX=PASS'
else
  echo 'NGINX_SYNTAX=REVIEW_REQUIRED'
fi
if [[ -S /tmp/php-cgi-85.sock ]]; then
  echo 'PHP85_SOCKET=/tmp/php-cgi-85.sock'
elif [[ -S /www/server/php/85/var/run/php-fpm.sock ]]; then
  echo 'PHP85_SOCKET=/www/server/php/85/var/run/php-fpm.sock'
else
  echo 'PHP85_SOCKET=NOT_FOUND'
fi
if getent ahostsv4 "$HOST" >/dev/null 2>&1; then
  echo 'DNS=RESOLVES'
else
  echo 'DNS=UNAVAILABLE'
fi

echo '========== SAFETY INVENTORY: WEBROOT =========='
python3 - "$VHOST" "$WEBROOT" "$HOST" <<'PY'
import pathlib,re,sys
vhost,root,host=pathlib.Path(sys.argv[1]),pathlib.Path(sys.argv[2]),sys.argv[3]
raw=vhost.read_text(errors='replace')
names=re.findall(r'^\s*server_name\s+([^;]+);',raw,re.M)
if not any(host in s.split() for s in names):
    raise SystemExit('BLOCKED: EXACT_VHOST_HOSTNAME_MISSING')
roots=re.findall(r'^\s*root\s+([^;]+);',raw,re.M)
if len(roots)!=1 or roots[0].strip()!=str(root):
    raise SystemExit('BLOCKED: EXPECTED_ORIGINAL_EMPTY_WEBROOT_NOT_CONFIGURED')
print('EXACT_VHOST_NAME=VERIFIED')
print('CURRENT_VHOST_ROOT=EXPECTED')
print('WEBROOT_CHILD_COUNT='+str(sum(1 for _ in root.iterdir())))
for name in ('index.php','public/index.php','artisan','.env',
             'vendor/autoload.php','storage/oauth-private.key',
             'storage/oauth-public.key'):
    path=root/name
    print('WEBROOT_'+name.upper().replace('/','_').replace('.','_')+'='+
          ('SYMLINK_REVIEW_REQUIRED' if path.is_symlink() else
           'PRESENT_REVIEW_REQUIRED' if path.exists() else 'ABSENT'))
print('VHOST_CONFIG=NOT_DISCLOSED')
PY

echo '========== HTTPS REACHABILITY =========='
status="$(curl --silent --show-error --proto '=https' \
  --connect-timeout 5 --max-time 12 --output /dev/null \
  --write-out '%{http_code}' \
  "https://$HOST/.well-known/oauth-authorization-server")" ||
  die HTTPS_TLS_REQUEST_FAILED
echo "DISCOVERY_HTTP_STATUS=$status"
if [[ "$status" == 404 ]]; then
  echo 'OAUTH_HTTPS_STATUS=APPLICATION_NOT_YET_DEPLOYED'
elif [[ "$status" == 200 ]]; then
  echo 'OAUTH_HTTPS_STATUS=ALREADY_PRESENT_REVIEW_BEFORE_DEPLOY'
else
  echo 'OAUTH_HTTPS_STATUS=REVIEW_REQUIRED'
fi

echo '========== FINAL STEP 26C STATUS =========='
echo 'STEP26C_BOUNDARY_INVENTORY=COMPLETE'
echo 'QA_DEPLOYMENT=NOT_STARTED'
echo 'WEBROOT_WRITES=NONE'
echo 'DATABASE_WRITES=NONE'
echo 'PASSPORT_KEYS=UNCHANGED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
