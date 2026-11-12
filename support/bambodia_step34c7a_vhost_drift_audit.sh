#!/usr/bin/env bash
# Step 34C.7A: inspect the EXISTING OAuth-QA Nginx vhost drift.
# READ ONLY: no replacements, config reload, database access, or keys read.
# Outputs only hashes, metadata, and directive NAMES; never raw values.
set -Eeuo pipefail
set +x
umask 077

SITE="/www/wwwroot/oauth-qa.bambodia.com/index.html"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
BACKUP="/var/backups/bambodia-oauth-qa-preserve.6JJJAhXJ"
OLD_VHOST_HASH="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
OLD_SITE_HASH="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
APP="/var/lib/bambodia-oauth-public-qa"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"

echo '========== VERIFY ROOT-ONLY READ-ONLY AUDIT =========='
test "$(id -u)" -eq 0
for cmd in sha256sum stat python3 git; do
  command -v "$cmd" >/dev/null
done
test -d "$BACKUP" && test ! -L "$BACKUP"
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700"
for file in nginx-vhost.conf site.tar public-cert-sha256.txt RESTORE_NOTES.txt SHA256SUMS; do
  test -f "$BACKUP/$file" && test ! -L "$BACKUP/$file"
  test "$(stat -c '%u:%a' "$BACKUP/$file")" = '0:600'
done
( cd "$BACKUP" && sha256sum --status -c SHA256SUMS )
test "$(sha256sum "$BACKUP/nginx-vhost.conf" | awk '{print $1}')" = "$OLD_VHOST_HASH"
echo 'PRESERVED_VHOST_AND_ARCHIVE_CHECKSUMS=PASS'
test -f "$VHOST" && test ! -L "$VHOST"
test -f "$SITE" && test ! -L "$SITE"
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$OLD_SITE_HASH"
echo 'ORIGINAL_PUBLIC_INDEX=UNCHANGED'
test -d "$QA/.git" && test ! -L "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -d "$APP" && test ! -L "$APP"
test "$(stat -c '%u:%a' "$APP")" = "0:700"
test -f "$APP/.env" && test ! -L "$APP/.env"
test "$(stat -c '%u:%a' "$APP/.env")" = "0:600"
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock"
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA"
test -f "$APP/vendor/autoload.php"
for file in oauth-private.key oauth-public.key; do
  test ! -e "$APP/storage/$file" && test ! -L "$APP/storage/$file"
done
echo 'FROZEN_QA_AND_PRIVATE_APP_BOUNDARIES=PASS'
echo 'PASSPORT_KEYS=STILL_ABSENT'

echo '========== INSPECT REDACTED VHOST DIFF METADATA =========='
python3 - "$BACKUP/nginx-vhost.conf" "$VHOST" <<'PY'
import collections
import difflib
import hashlib
from pathlib import Path
import re
import stat
import sys

original = Path(sys.argv[1])
current = Path(sys.argv[2])
for label, path in [('PRESERVED', original), ('CURRENT', current)]:
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_size > 1024 * 1024:
        raise SystemExit('VHOST_AUDIT=BLOCKED_UNEXPECTED_FILE_TYPE_OR_SIZE')
    data = path.read_bytes()
    print(label + '_VHOST_SHA256=' + hashlib.sha256(data).hexdigest())
    print(label + '_VHOST_SIZE_BYTES=' + str(len(data)))
    print(label + '_VHOST_OWNER_UID=' + str(st.st_uid))
    print(label + '_VHOST_MODE=' + oct(stat.S_IMODE(st.st_mode)))
    print(label + '_VHOST_MTIME_UTC=' +
          __import__('datetime').datetime.fromtimestamp(
              st.st_mtime, __import__('datetime').timezone.utc
          ).isoformat(timespec='seconds'))

before = original.read_text('utf-8', errors='replace').splitlines()
after = current.read_text('utf-8', errors='replace').splitlines()
matcher = difflib.SequenceMatcher(a=before,b=after,autojunk=False)
changes = [op for op in matcher.get_opcodes() if op[0] != 'equal']
added = sum(end-start for _,_,_,start,end in changes)
removed = sum(end-start for _,start,end,_,_ in changes)

def classify(line):
    val = line.strip()
    if not val:
        return 'BLANK'
    if val.startswith('#'):
        return 'COMMENT'
    if val in ('{', '}', '};'):
        return 'BLOCK_BOUNDARY'
    first = re.split(r'[\s{;]+', val, maxsplit=1)[0].lower()
    if not re.fullmatch(r'[a-z0-9_-]{1,64}', first):
        return 'OTHER_SYNTAX'
    return first

before_directives = collections.Counter()
after_directives = collections.Counter()
for tag,a0,a1,b0,b1 in changes:
    before_directives.update(classify(s) for s in before[a0:a1])
    after_directives.update(classify(s) for s in after[b0:b1])
changed = sorted(set(before_directives) | set(after_directives))
print('DIFF_CHANGE_HUNKS=' + str(len(changes)))
print('DIFF_REMOVED_LINES=' + str(removed))
print('DIFF_ADDED_LINES=' + str(added))
print('DIFF_CHANGED_DIRECTIVE_NAMES=' + (','.join(changed) or 'NONE'))
# Only a *textual* classification: not a full Nginx semantic parser.
meaningful = sorted(set(changed)-{'BLANK','COMMENT'})
print('DIFF_NONCOMMENT_DIRECTIVES_CHANGED=' + ('YES' if meaningful else 'NO'))
critical = {'server_name','root','alias','proxy_pass','fastcgi_pass',
            'listen','ssl_certificate','ssl_certificate_key','return',
            'rewrite','include','auth_basic','allow','deny',
            'access_log','error_log','location'}
print('DIFF_SECURITY_OR_ROUTING_DIRECTIVE_NAMES=' +
      (','.join(sorted(set(changed) & critical)) or 'NONE'))
print('DIFF_RAW_LINES_OR_VALUES_DISCLOSED=NO')
print('VHOST_CONTENT_EQUAL_TO_BACKUP=' +
      ('YES' if not changes else 'NO'))
PY

echo '========== VERIFY NO CHANGE TO ORIGINALS AFTER AUDIT =========='
test "$(sha256sum "$SITE" | awk '{print $1}')" = "$OLD_SITE_HASH"
test "$(sha256sum "$BACKUP/nginx-vhost.conf" | awk '{print $1}')" = "$OLD_VHOST_HASH"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_SHA"
echo 'ORIGINAL_SITE_BACKUP_AND_PRIVATE_RELEASE=NOT_MODIFIED_BY_AUDIT'
echo 'NGINX_RELOAD_OR_CONFIG_WRITE=NONE'
echo 'DATABASE_OR_PASSPORT_WRITES=NONE'
echo 'DNS_TLS_GITHUB_MAIN_AND_PRODUCTION_WRITES=NONE'
echo 'STEP34C7A_READ_ONLY_VHOST_DRIFT_INVENTORY=COMPLETE'
echo 'VHOST_CHANGE_APPROVAL=NOT_GRANTED'
echo 'STEP34C7_INITIALIZATION=NOT_PERFORMED'
