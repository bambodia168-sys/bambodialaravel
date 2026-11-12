#!/usr/bin/env bash
# Step 34C.7C - READ-ONLY scoped Nginx root and PHP target inventory.
# No hostname cutover, site modification, DB writes, or key reading.
set -Eeuo pipefail
set +x
umask 077

VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
CURRENT_SHA="06806f2913c3150cf430482124a23f760dfb60d3c47dc587700762d32b2a4a6a"
ORIGINAL_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
SITE_SHA="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
PRIVATE_QA="/var/lib/bambodia-oauth-public-qa"
FROZEN_QA="/var/lib/bambodia-oauth-browser-qa"
FROZEN_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
fail(){ echo "STEP34C7C_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY UNCHANGED FILES AND PRIVATE BOUNDARIES =========='
test "$(id -u)" = 0 || fail ROOT_REQUIRED
for tool in git stat sha256sum python3; do
  command -v "$tool" >/dev/null || fail TOOL_MISSING
done
test -f "$VHOST" && test ! -L "$VHOST" &&
  test "$(stat -c '%u:%a' "$VHOST")" = '0:600' ||
  fail CURRENT_VHOST_UNSAFE
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$CURRENT_SHA" ||
  fail VHOST_CHANGED_SINCE_STEP34C7A
test -d "$BACKUP" && test ! -L "$BACKUP" &&
  test "$(stat -c '%u:%a' "$BACKUP")" = '0:700' ||
  fail BACKUP_UNSAFE
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS ) ||
  fail BACKUP_CHANGED
test "$(sha256sum "$BACKUP/nginx-vhost.conf" | awk '{print $1}')" = "$ORIGINAL_SHA" ||
  fail ORIGINAL_VHOST_HASH_MISMATCH
test -f "$SITE" && test ! -L "$SITE" &&
  test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail SITE_INDEX_CHANGED
test -d "$FROZEN_QA/.git" && test ! -L "$FROZEN_QA" &&
  test "$(git -C "$FROZEN_QA" rev-parse HEAD)" = "$FROZEN_SHA" &&
  test -z "$(git -C "$FROZEN_QA" status --porcelain --untracked-files=no)" ||
  fail FROZEN_QA_SOURCE_CHANGED
test -d "$PRIVATE_QA" && test ! -L "$PRIVATE_QA" &&
  test "$(stat -c '%u:%a' "$PRIVATE_QA")" = '0:700' &&
  test -f "$PRIVATE_QA/.env" && test ! -L "$PRIVATE_QA/.env" &&
  test "$(stat -c '%u:%a' "$PRIVATE_QA/.env")" = '0:600' &&
  test -f "$PRIVATE_QA/vendor/autoload.php" &&
  test "$(sha256sum "$PRIVATE_QA/composer.lock" | awk '{print $1}')" = "$LOCK_SHA" ||
  fail PRIVATE_QA_BOUNDARY_CHANGED
for key in oauth-private.key oauth-public.key; do
  test ! -e "$PRIVATE_QA/storage/$key" &&
    test ! -L "$PRIVATE_QA/storage/$key" ||
    fail UNEXPECTED_PASSPORT_KEYS
done
echo 'CURRENT_AND_PRESERVED_VHOST_HASHES=PASS'
echo 'ORIGINAL_SITE_AND_FROZEN_QA=PASS'
echo 'PRIVATE_QA_APP_AND_VENDOR=PASS'
echo 'PASSPORT_KEYS=ABSENT'

echo '========== REVIEW SCOPED NGINX TARGETS WITHOUT RAW CONFIG VALUES =========='
python3 - "$BACKUP/nginx-vhost.conf" "$VHOST" <<'PY'
from pathlib import Path
import hashlib, os, re, stat, sys
original, current = (Path(p) for p in sys.argv[1:3])
class ParseError(Exception): pass

def tokens(src):
    # Treat ';', '{', '}' as structure only outside quote/comment.
    out=[]; item=[]; quote=None; escape=False; comment=False
    for c in src:
        if comment:
            if c=='\n': comment=False; item.append(' ')
            continue
        if escape: item.append(c); escape=False; continue
        if c=='\\': item.append(c); escape=True; continue
        if quote:
            item.append(c)
            if c==quote: quote=None
            continue
        if c in ("'",'"'): quote=c; item.append(c); continue
        if c=='#': comment=True; continue
        if c in ';{}':
            val=''.join(item).strip()
            if val: out.append(('text',val))
            out.append((c,c)); item=[]
            continue
        item.append(c)
    if quote: raise ParseError('UNTERMINATED_QUOTE')
    if ''.join(item).strip(): raise ParseError('TRAILING_INCOMPLETE_DIRECTIVE')
    return out

def parse(src):
    stream=tokens(src)
    stack=[]; collected=[]; pending=None
    for tag,val in stream:
        if tag=='text':
            if pending is not None: raise ParseError('CONSECUTIVE_TEXT')
            pending=val
        elif tag=='{':
            if not pending: raise ParseError('UNNAMED_BLOCK')
            name=pending.split(None,1)[0].lower()
            # Scope labels only; never print location regex/path.
            kind='LOCATION' if name=='location' else ('SERVER' if name=='server' else name.upper())
            stack.append(kind)
            pending=None
        elif tag=='}':
            if pending or not stack: raise ParseError('UNBALANCED_BLOCK')
            stack.pop()
        elif tag==';':
            if not pending: raise ParseError('EMPTY_DIRECTIVE')
            parts=pending.split(None,1)
            name=parts[0].lower()
            val=parts[1].strip() if len(parts)==2 else ''
            collected.append((name,val,tuple(stack)))
            pending=None
    if pending or stack: raise ParseError('INCOMPLETE_BLOCK')
    return collected

try:
    old=parse(original.read_text('utf-8',errors='replace'))
    new=parse(current.read_text('utf-8',errors='replace'))
except ParseError as ex:
    raise SystemExit('STEP34C7C_BLOCKED=NGINX_SCOPE_PARSER_'+str(ex))

def scope_name(scope):
    return '_'.join(scope) if scope else 'GLOBAL'

def unquote(x): return x.strip().strip('"').strip("'").rstrip('/')
def category(value):
    v=unquote(value)
    if not v: return 'EMPTY'
    if '$' in v: return 'VARIABLE_TARGET'
    if not v.startswith('/'): return 'RELATIVE_OR_NONPATH'
    real=os.path.realpath(v)
    known=[
       ('ORIGINAL_WEBSITE','/www/wwwroot/oauth-qa.bambodia.com'),
       ('PRIVATE_PUBLIC_QA','/var/lib/bambodia-oauth-public-qa'),
       ('FROZEN_INTERNAL_QA','/var/lib/bambodia-oauth-browser-qa'),
       ('PRODUCTION_MARKETPLACE','/www/wwwroot/bambodia.com'),
    ]
    for name,prefix in known:
        if real==prefix or real.startswith(prefix+'/'):
            return name
    if real.startswith('/www/wwwroot/'): return 'OTHER_AAPANEL_WEBROOT'
    if real.startswith('/var/lib/'): return 'OTHER_PRIVATE_VAR_LIB_APP'
    if real.startswith('/www/'): return 'OTHER_WWW_TREE'
    return 'OTHER_OR_OUTSIDE_MANAGED_TREE'

roots_before=[(v,s) for n,v,s in old if n=='root']
roots_after=[(v,s) for n,v,s in new if n=='root']
php_params=[(v,s) for n,v,s in new if n=='fastcgi_param' and v.startswith('SCRIPT_FILENAME')]
print('OLD_ROOT_DIRECTIVE_COUNT='+str(len(roots_before)))
print('CURRENT_ROOT_DIRECTIVE_COUNT='+str(len(roots_after)))
for i,(v,scope) in enumerate(roots_after,1):
    raw=unquote(v)
    classification=category(raw)
    previously_present=any(raw==unquote(prev) and scope==old_scope for prev,old_scope in roots_before)
    print('ROOT_'+str(i)+'_SCOPE='+scope_name(scope))
    print('ROOT_'+str(i)+'_CATEGORY='+classification)
    print('ROOT_'+str(i)+'_IN_BACKUP_SAME_SCOPE='+('YES' if previously_present else 'NO'))
    print('ROOT_'+str(i)+'_TARGET_ID='+hashlib.sha256(raw.encode()).hexdigest()[:16])
    if '$' in raw or not raw.startswith('/'):
        print('ROOT_'+str(i)+'_TARGET_PATH_STATE=VARIABLE_OR_NONABSOLUTE')
    else:
        target=Path(raw)
        real=Path(os.path.realpath(raw))
        if target.exists():
            mode=target.lstat().st_mode
            print('ROOT_'+str(i)+'_TARGET_PATH_STATE='+
                  ('SYMLINK' if stat.S_ISLNK(mode) else
                   ('EXISTING_DIRECTORY' if stat.S_ISDIR(mode) else 'OTHER_EXISTING_FILE')))
        else:
            print('ROOT_'+str(i)+'_TARGET_PATH_STATE=ABSENT')
        if real.is_dir():
            print('ROOT_'+str(i)+'_INDEX_PHP_PRESENT='+('YES' if (real/'index.php').is_file() else 'NO'))
            print('ROOT_'+str(i)+'_INDEX_HTML_PRESENT='+('YES' if (real/'index.html').is_file() else 'NO'))
for i,(v,scope) in enumerate(php_params,1):
    words=v.split(None,1)
    value=words[1] if len(words)>1 else ''
    if '$document_root' in value or '$realpath_root' in value:
        kind='DERIVED_FROM_ACTIVE_ROOT'
    elif '/var/lib/bambia-oauth-public-qa/' in value:
        kind='PRIVATE_QA_LITERAL'
    elif '$' in value: kind='OTHER_NGINX_VARIABLE'
    elif value.startswith('/'): kind=category(value)
    else: kind='OTHER_OR_RELATIVE'
    print('SCRIPT_FILENAME_'+str(i)+'_SCOPE='+scope_name(scope))
    print('SCRIPT_FILENAME_'+str(i)+'_CATEGORY='+kind)
    print('SCRIPT_FILENAME_'+str(i)+'_VALUE_ID='+hashlib.sha256(value.encode()).hexdigest()[:16])
print('FASTCGI_SCRIPT_FILENAME_COUNT='+str(len(php_params)))
critical=('PRIVATE_PUBLIC_QA','PRODUCTION_MARKETPLACE','FROZEN_INTERNAL_QA')
if any(category(v) in critical for v,s in roots_after):
    print('TARGET_REVIEW_PRIORITY=HIGH_NO_AUTOMATIC_RELEASE')
elif any(category(v).startswith('OTHER_') or category(v)=='VARIABLE_TARGET' for v,s in roots_after):
    print('TARGET_REVIEW_PRIORITY=UNKNOWN_OWNER_REVIEW_REQUIRED')
else:
    print('TARGET_REVIEW_PRIORITY=NEEDS_OWNER_ACCEPTANCE')
print('RAW_TARGET_PATHS_OR_VALUES_PRINTED=NO')
print('SCOPED_PARSER_IS_NOT_EFFECTIVE_NGINX_ROUTING_PROOF=YES')
PY

echo '========== CONFIRM NO CHANGES =========='
test "$(sha256sum "$VHOST" | awk '{print $1}')" = "$CURRENT_SHA" ||
  fail CURRENT_VHOST_CHANGED_DURING_AUDIT
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$SITE_SHA" ||
  fail EXISTING_PUBLIC_SITE_CHANGED_DURING_AUDIT
test "$(git -C "$FROZEN_QA" rev-parse HEAD)" = "$FROZEN_SHA" ||
  fail FROZEN_QA_CHANGED_DURING_AUDIT
echo 'STEP34C7C_SCOPED_ROUTING_AUDIT=COMPLETE'
echo 'PUBLIC_HOSTNAME_REBASELINE_OR_REUSE=NOT_AUTHORIZED'
echo 'MIGRATIONS_OR_PASSPORT_KEYS=NOT_EXECUTED'
echo 'DATABASE_NGINX_DNS_TLS_AND_PRODUCTION_WRITES=NONE'
