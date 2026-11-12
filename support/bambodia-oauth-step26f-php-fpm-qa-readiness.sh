#!/usr/bin/env bash
# Bambodia OAuth Step 26F: read-only PHP-FPM, open_basedir and private
# staging path review. No writes, application startup or DB connection.
set -Eeuo pipefail
umask 077

HOST="oauth-qa.bambodia.com"
WEBROOT="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
SRC="/var/lib/bambodia-oauth-browser-qa"
STAGING="/var/lib/bambodia-oauth-https-qa"
EXPECTED_SOURCE="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
EXPECTED_LOCK="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
EXPECTED_VHOST="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
PHP="/www/server/php/85/bin/php"
NGINX="/www/server/nginx/sbin/nginx"
fail(){ printf 'STEP26F_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_OPERATOR_REQUIRED
for cmd in git python3 realpath sha256sum stat runuser; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$WEBROOT" && ! -L "$WEBROOT" &&
   "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] ||
  fail EXISTING_WEBROOT_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail VHOST_UNSAFE
[[ "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$EXPECTED_VHOST" ]] ||
  fail VHOST_CHANGED_SINCE_STEP26E
[[ ! -e "$STAGING" && ! -L "$STAGING" ]] ||
  fail PRIVATE_STAGING_PATH_NOT_EMPTY
[[ -d /var/lib && ! -L /var/lib &&
   "$(realpath -e /var/lib)" == /var/lib ]] ||
  fail PRIVATE_STAGING_PARENT_UNSAFE
[[ -d "$SRC/.git" && ! -L "$SRC" && ! -L "$SRC/.git" ]] ||
  fail ACCEPTED_QA_SOURCE_UNSAFE
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$EXPECTED_SOURCE" ]] ||
  fail SOURCE_SHA_MISMATCH
[[ -z "$(git -C "$SRC" status --porcelain --untracked-files=no)" ]] ||
  fail ACCEPTED_SOURCE_CHANGED
[[ -s "$SRC/composer.lock" && ! -L "$SRC/composer.lock" ]] ||
  fail DEPENDENCY_LOCK_MISSING
[[ "$(sha256sum "$SRC/composer.lock" | cut -d ' ' -f1)" == "$EXPECTED_LOCK" ]] ||
  fail SOURCE_DEPENDENCIES_CHANGED
[[ -x "$PHP" && -x "$NGINX" ]] || fail PHP_OR_NGINX_MISSING
id www >/dev/null || fail WEB_USER_UNAVAILABLE

echo '========== HOST SAFETY GATES =========='
echo 'APPROVED_QA_SOURCE=PINNED_AND_CLEAN'
echo 'APPROVED_DEPENDENCIES=VERIFIED'
echo 'VHOST_CONFIG_SHA256=UNCHANGED_SINCE_STEP26E'
echo 'PRIVATE_STAGING_PATH=AVAILABLE_BUT_NOT_CREATED'
if "$NGINX" -t >/dev/null 2>&1; then
  echo 'NGINX_SYNTAX=PASS'
else
  echo 'NGINX_SYNTAX=REVIEW_REQUIRED'
fi
if runuser -u www -- test -x /var/lib; then
  echo 'WWW_CAN_TRAVERSE_VAR_LIB=YES'
else
  echo 'WWW_CAN_TRAVERSE_VAR_LIB=NO'
fi
if [[ -S /tmp/php-cgi-85.sock ]]; then
  echo 'EXPECTED_PHP85_SOCKET=PRESENT'
else
  echo 'EXPECTED_PHP85_SOCKET=ABSENT_REVIEW_REQUIRED'
fi

echo '========== PHP 8.5 CONFIGURATION DIRECTIVE METADATA =========='
python3 - "$WEBROOT" "$STAGING" "$VHOST" "$HOST" <<'PY'
import glob
import pathlib
import re
import stat
import sys

root,staging,vhost,host=map(pathlib.Path,sys.argv[1:])
cfg=vhost.read_text(errors='replace')
names=re.findall(r'^\s*server_name\s+([^;]+);',cfg,re.M)
roots=re.findall(r'^\s*root\s+([^;]+);',cfg,re.M)
if not any(str(host) in nameset.split() for nameset in names):
    raise SystemExit('STEP26F_BLOCKED=VHOST_NAME_MISMATCH')
if len(roots)!=1 or roots[0].strip()!=str(root):
    raise SystemExit('STEP26F_BLOCKED=VHOST_ROOT_MISMATCH')

# Read only explicitly scoped PHP 8.5 configuration locations.
etc=pathlib.Path('/www/server/php/85/etc')
candidates=[]
if etc.is_dir() and not etc.is_symlink():
    for pat in ('php.ini','php-fpm.conf','php-fpm.d/*.conf',
                'php-fpm.d/*.ini','php-fpm.conf.d/*.conf'):
        candidates.extend(pathlib.Path(x) for x in glob.glob(str(etc / pat)))
checked=0
fpm_restrictions=0
fpm_values_staging=0
socket_pool_matches=0
fpm_pool_directives=0
for path in sorted(set(candidates)):
    if path.is_symlink() or not path.is_file():
        continue
    checked+=1
    data=path.read_text(errors='replace')
    for raw in data.splitlines():
        s=raw.strip()
        if not s or s.startswith(('#',';')):
            continue
        if re.match(r'(?i)^(?:php_admin_value|php_value)\s*\[\s*open_basedir\s*\]\s*=',s):
            fpm_restrictions+=1
            if str(staging) in s: fpm_values_staging+=1
        if re.match(r'(?i)^open_basedir\s*=',s):
            fpm_restrictions+=1
            if str(staging) in s: fpm_values_staging+=1
        if re.match(r'(?i)^listen\s*=\s*/tmp/php-cgi-85\.sock',s):
            socket_pool_matches+=1
        if re.match(r'(?i)^(?:user|group|listen\.owner|listen\.group)\s*=',s):
            fpm_pool_directives+=1

site_ini=root/'.user.ini'
site_restrictions=0
if site_ini.is_symlink():
    raise SystemExit('STEP26F_BLOCKED=SITE_USER_INI_SYMLINK')
if site_ini.is_file():
    for raw in site_ini.read_text(errors='replace').splitlines():
        s=raw.strip()
        if s and not s.startswith((';','#')) and re.match(r'(?i)^open_basedir\s*=',s):
            site_restrictions+=1

print('PHP85_CONFIG_FILES_INSPECTED='+str(checked))
print('FPM_OPEN_BASEDIR_DIRECTIVE_COUNT='+str(fpm_restrictions))
print('FPM_OPEN_BASEDIR_REFERENCES_PRIVATE_STAGING='+
      ('YES' if fpm_values_staging else 'NOT_FOUND_IN_SCANNED_FILES'))
print('POOL_LISTEN_SOCKET_DIRECTIVE_MATCHES='+str(socket_pool_matches))
print('POOL_RUNTIME_USER_GROUP_DIRECTIVE_COUNT='+str(fpm_pool_directives))
print('CURRENT_WEBROOT_USER_INI_OPEN_BASEDIR_COUNT='+str(site_restrictions))
print('EFFECTIVE_FPM_OPEN_BASEDIR=NOT_ESTABLISHED_BY_FILE_SCAN')
print('PHP_CONFIGURATION_VALUES_AND_SECRETS=NOT_PRINTED')
PY

echo '========== DEPLOYMENT APPROVAL STATUS =========='
echo 'STEP26F_RUNTIME_PREFLIGHT=COMPLETE'
echo 'PRIVATE_APP_CREATED=NO'
echo 'DATABASE_EXISTENCE_AND_GRANTS=UNVERIFIED'
echo 'PHP_FPM_CONFIGURATION_CHANGES=NONE'
echo 'WEBROOT_CHANGES=NONE'
echo 'PASSPORT_KEYS=UNCHANGED'
echo 'MYSQL_CHANGES=NONE'
echo 'NGINX_CHANGES=NONE'
echo 'PRODUCTION_CHANGES=NONE'
