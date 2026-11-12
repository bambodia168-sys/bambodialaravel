#!/usr/bin/env bash
# Step 34C.7B: READ-ONLY classification of the current occupied OAuth-QA
# Nginx vhost routing, effective root targets and FastCGI settings.
# Does not print Nginx values, paths to other apps, secrets or TLS keys.
# Does not reload Nginx, write databases, install source or change routing.
set -Eeuo pipefail
set +x
umask 077

VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
CURRENT_SHA="06806f2913c3150cf430482124a23f760dfb60d3c47dc587700762d32b2a4a6a"
ORIGINAL_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
APP="/var/lib/bambodia-oauth-public-qa"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"

fail() {
  echo "STEP34C7B_BLOCKED=$1" >&2
  exit 34
}
echo '========== VERIFY ORIGINAL BACKUP AND CURRENT VHOST =========='
test "$(id -u)" = 0 || fail ROOT_REQUIRED
for name in git stat sha256sum python3; do
  command -v "$name" >/dev/null || fail TOOL_MISSING
done
test -f "$VHOST" && test ! -L "$VHOST" || fail VHOST_MISSING_OR_SYMLINK
test "$(stat -c '%u:%a' "$VHOST")" = "0:600" || fail CURRENT_VHOST_MODE
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$CURRENT_SHA" ||
  fail CURRENT_VHOST_CHANGED_SINCE_STEP34C7A
test -d "$BACKUP" && test ! -L "$BACKUP" ||
  fail PRESERVATION_BACKUP_MISSING
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700" ||
  fail PRESERVATION_BACKUP_PERMISSIONS
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail BACKUP_CONTENT_CHANGED
test "$(sha256sum "$BACKUP/nginx-vhost.conf" | awk '{print $1}')" = "$ORIGINAL_SHA" ||
  fail BACKUP_ORIGINAL_VHOST_CHANGED
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_PUBLIC_SITE_CHANGED
echo 'PRESERVED_VHOST_AND_BACKUP=PASS'
echo 'CURRENT_VHOST_STEP34C7A_HASH=UNCHANGED'
echo 'ORIGINAL_PUBLIC_INDEX=UNCHANGED'

echo '========== VERIFY FROZEN AND NEW PRIVATE QA BOUNDARIES =========='
test -d "$QA/.git" && test ! -L "$QA" &&
  test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" &&
  test -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_QA_SOURCE_CHANGED
test -d "$APP" && test ! -L "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = "0:700" ||
  fail NEW_PRIVATE_QA_ROOT_PERMISSIONS
test -f "$APP/.env" && test ! -L "$APP/.env" &&
  test "$(stat -c '%u:%a' "$APP/.env")" = "0:600" ||
  fail NEW_PRIVATE_QA_ENV_MISSING_OR_UNSAFE
test -f "$APP/vendor/autoload.php" &&
  test -f "$APP/composer.lock" &&
  test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail NEW_PRIVATE_QA_VENDOR_OR_LOCK_CHANGED
for f in oauth-private.key oauth-public.key; do
  test ! -e "$APP/storage/$f" && test ! -L "$APP/storage/$f" ||
    fail UNEXPECTED_PASSPORT_KEY_CREATED
done
echo 'FROZEN_QA_AND_PRIVATE_RELEASE_BOUNDARIES=PASS'
echo 'PASSPORT_KEYS=ABSENT'

echo '========== CLASSIFY ACTIVE VHOST WITHOUT PRINTING TARGET VALUES =========='
python3 - "$VHOST" <<'PY'
import collections
import os
from pathlib import Path
import re
import stat
import sys

p = Path(sys.argv[1])
raw = p.read_text('utf-8', errors='replace')
if len(raw) > 1024 * 1024:
    raise SystemExit('STEP34C7B_BLOCKED=VHOST_UNEXPECTED_SIZE')
# A conservative line-oriented classifier, NOT a full Nginx config parser.
# Never print raw directive values, URLs, certificate paths or app paths.
d = collections.defaultdict(list)
for line in raw.splitlines():
    value = line.strip()
    if not value or value.startswith('#'):
        continue
    # Remove trailing comments only outside simple quoted strings.
    value = re.sub(r'\s+#.*$', '', value)
    m = re.match(r'^([a-z_][a-z0-9_]*)\s+(.+?)(?:\s*;|\s*\{)?$', value)
    if m:
        d[m.group(1)].append(m.group(2).strip().rstrip(';').strip())

expected_site = '/www/wwwroot/oauth-qa.bambodia.com'
expected_app = '/var/lib/bambodia-oauth-public-qa/public'
def unquote(v):
    return v.strip().strip('\"').strip("'").rstrip('/')
def root_kind(v):
    v = unquote(v)
    if v == expected_site:
        return 'ORIGINAL_PLACEHOLDER_ROOT'
    if v == expected_site + '/public':
        return 'ORIGINAL_SITE_PUBLIC_SUBDIRECTORY'
    if v == expected_app:
        return 'PRIVATE_OAUTH_QA_APP_PUBLIC_UNAUTHORIZED'
    if v.startswith('/var/lib/bambodia-oauth-public-qa/'):
        return 'PRIVATE_QA_OTHER_PATH_UNAUTHORIZED'
    if v == '/www/wwwroot/bambodia.com/public':
        return 'PRODUCTION_MARKETPLACE_PATH_HIGH_RISK'
    return 'OTHER_OR_VARIABLE_ROOT_REQUIRES_REVIEW'

root_counts = collections.Counter(root_kind(v) for v in d.get('root', []))
def fastcgi_kind(v):
    v = unquote(v)
    if v.startswith('unix:'):
        if 'php-cgi-85.sock' in v or '/php/85/' in v:
            return 'AAPANEL_PHP85_SOCKET'
        if 'php-cgi-84.sock' in v or '/php/84/' in v:
            return 'AAPANEL_PHP84_SOCKET'
        return 'OTHER_UNIX_SOCKET'
    if v.startswith('127.0.0.1:') or v.startswith('localhost:'):
        return 'LOOPBACK_FASTCGI'
    if '$' in v:
        return 'VARIABLE_FASTCGI_TARGET'
    return 'OTHER_FASTCGI_TARGET_REQUIRES_REVIEW'

fcgi_counts = collections.Counter(fastcgi_kind(v) for v in d.get('fastcgi_pass', []))

def include_kind(v):
    v = unquote(v)
    if 'fastcgi_params' in v or 'fastcgi.conf' in v:
        return 'FASTCGI_PARAMETERS'
    if 'enable-php-' in v:
        return 'AAPANEL_PHP_INCLUDE'
    if 'rewrite' in v:
        return 'REWRITE_INCLUDE'
    if 'well-known' in v or 'acme' in v:
        return 'ACME_INCLUDE'
    return 'OTHER_INCLUDE_REQUIRES_REVIEW'
include_counts = collections.Counter(include_kind(v) for v in d.get('include', []))
names = [unquote(x) for x in d.get('server_name',[])]
host_is_present = any('oauth-qa.bambodia.com' in name.split() for name in names)
def script_file_kind(v):
    if 'SCRIPT_FILENAME' not in v:
        return 'OTHER_FASTCGI_PARAM'
    if '$document_root' in v or '$realpath_root' in v:
        return 'DOCUMENT_ROOT_DERIVED_SCRIPT'
    if '/var/lib/bambodia-oauth-public-qa/' in v:
        return 'PRIVATE_QA_EXPLICIT_SCRIPT_PATH'
    if '$' in v:
        return 'OTHER_VARIABLE_SCRIPT_PATH'
    return 'ABSOLUTE_OR_OTHER_SCRIPT_PATH_REVIEW'
script_counts = collections.Counter(script_file_kind(v) for v in d.get('fastcgi_param',[]) if 'SCRIPT_FILENAME' in v)
locs = d.get('location', [])
location_php = sum(1 for v in locs if 'php' in v.lower())
location_root = sum(1 for v in locs if unquote(v) == '/')
location_deny_dot = sum(1 for v in locs if '/\\.' in v or r'/\.' in v)
print('SERVER_NAME_EXPECTED_HOST_PRESENT=' + ('YES' if host_is_present else 'NO'))
print('SERVER_NAME_DECLARATION_COUNT=' + str(len(names)))
print('ROOT_DIRECTIVE_COUNT=' + str(len(d.get('root',[]))))
for kind,count in sorted(root_counts.items()):
    print('ROOT_KIND_' + kind + '=' + str(count))
print('FASTCGI_PASS_COUNT=' + str(len(d.get('fastcgi_pass',[]))))
for kind,count in sorted(fcgi_counts.items()):
    print('FASTCGI_KIND_' + kind + '=' + str(count))
print('FASTCGI_SCRIPT_FILENAME_COUNT=' + str(sum(script_counts.values())))
for kind,count in sorted(script_counts.items()):
    print('FASTCGI_SCRIPT_KIND_' + kind + '=' + str(count))
print('INCLUDE_DIRECTIVE_COUNT=' + str(len(d.get('include',[]))))
for kind,count in sorted(include_counts.items()):
    print('INCLUDE_KIND_' + kind + '=' + str(count))
print('LOCATION_COUNT=' + str(len(locs)))
print('LOCATION_PHP_PATTERN_COUNT=' + str(location_php))
print('LOCATION_SLASH_PATTERN_COUNT=' + str(location_root))
print('LOCATION_DOTFILE_PATTERN_COUNT=' + str(location_deny_dot))
print('TRY_FILES_DIRECTIVE_COUNT=' + str(len(d.get('try_files',[]))))
print('PROXY_PASS_DIRECTIVE_COUNT=' + str(len(d.get('proxy_pass',[]))))
print('RETURN_DIRECTIVE_COUNT=' + str(len(d.get('return',[]))))
print('ROUTING_TARGET_VALUES_DISCLOSED=NO')
print('READ_ONLY_NGINX_CLASSIFICATION_NOT_SEMANTIC_VALIDATION=YES')
sensitive = any(x.startswith('PRIVATE_') or x.startswith('PRODUCTION_')
                for x in root_counts)
if sensitive or not host_is_present or not root_counts:
    print('ROUTING_REVIEW_PRIORITY=HIGH')
elif root_counts.get('OTHER_OR_VARIABLE_ROOT_REQUIRES_REVIEW'):
    print('ROUTING_REVIEW_PRIORITY=UNKNOWN_REVIEW_REQUIRED')
else:
    print('ROUTING_REVIEW_PRIORITY=STANDARD_REVIEW_REQUIRED')
PY

echo '========== VERIFY NGINX CONFIGURATION SYNTAX WITHOUT RELOAD =========='
if [ -x /www/server/nginx/sbin/nginx ]; then
  if /www/server/nginx/sbin/nginx -t >/dev/null 2>&1; then
    echo 'CURRENT_NGINX_SYNTAX=PASS'
  else
    echo 'CURRENT_NGINX_SYNTAX=FAILED_OR_UNVERIFIED'
  fi
else
  echo 'CURRENT_NGINX_SYNTAX=NGINX_BINARY_UNAVAILABLE'
fi

echo '========== RECONFIRM NO CONFIGURATION CHANGES =========='
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$CURRENT_SHA" ||
  fail VHOST_CHANGED_DURING_AUDIT
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail ORIGINAL_PUBLIC_SITE_CHANGED_DURING_AUDIT
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA" ||
  fail FROZEN_QA_CHANGED_DURING_AUDIT
echo 'STEP34C7B_READ_ONLY_ROUTING_PROVENANCE=COMPLETE'
echo 'CURRENT_VHOST_REBASELINED=NO'
echo 'HOSTNAME_REUSE_OR_ROUTING_APPROVAL=NOT_GRANTED'
echo 'STEP34C7_MIGRATIONS_AND_PASSPORT_KEYS=NOT_EXECUTED'
echo 'NGINX_RELOAD_OR_VHOST_WRITES=NONE'
echo 'DATABASE_OR_PRODUCTION_WRITES=NONE'
