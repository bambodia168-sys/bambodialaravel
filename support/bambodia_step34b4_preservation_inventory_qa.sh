#!/usr/bin/env bash
# Bambodia Step 34B.4: read-only site occupancy & preservation inventory.
# Does not create a backup or modify DNS, TLS, Nginx, code or database.
set -Eeuo pipefail
set +x
umask 077

HOST='oauth-qa.bambodia.com'
SITE="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
CERTDIR="/www/server/panel/vhost/cert/$HOST"
QA='/var/lib/bambodia-oauth-browser-qa'
QA_SHA='34e86fbbdfcbe56e5e28e0286a47ba2309e48455'
CNF='/root/bambodia-oauth-browser-qa.cnf'
DB='bambodia_oauthqa_browser'
MYSQL='/www/server/mysql/bin/mysql'

echo '========== VERIFY FROZEN QA AND OCCUPIED HOST =========='
test "$(id -u)" -eq 0
test -d "$SITE" && test ! -L "$SITE"
test "$(realpath -e "$SITE")" = "$SITE"
test -f "$VHOST" && test ! -L "$VHOST"
test -d "$CERTDIR" && test ! -L "$CERTDIR"
test -d "$QA/.git" && test ! -L "$QA"
test "$(realpath -e "$QA")" = "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -f "$QA/.env" && test ! -L "$QA/.env"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env"
test -s "$QA/public/build/manifest.json"
test "$SITE" != "$QA" && test "$SITE" != "$QA/public"
test -f "$CNF" && test ! -L "$CNF"
test "$(stat -c '%u:%a' "$CNF")" = '0:600'
test -x "$MYSQL"
command -v python3 >/dev/null
command -v sha256sum >/dev/null

QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
echo 'QA_SOURCE_ISOLATION=PASS'
echo 'QA_DATABASE_BASELINE=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== EXISTING SITE CONTENT INVENTORY =========='
python3 - "$SITE" "$VHOST" "$CERTDIR" <<'PY'
import hashlib
import html
from pathlib import Path
import re
import stat
import sys

root, vhost, certdir = map(Path, sys.argv[1:])
items = sorted(root.iterdir(), key=lambda p: p.name)
print("EXISTING_ROOT_OWNER_UID=" + str(root.stat().st_uid))
print("EXISTING_ROOT_OWNER_GID=" + str(root.stat().st_gid))
print("EXISTING_ROOT_MODE=" + oct(stat.S_IMODE(root.stat().st_mode)))
print("EXISTING_ROOT_TOP_LEVEL_ENTRIES=" + str(len(items)))
print("EXISTING_SITE_OCCUPIED=" + ("YES" if items else "NO"))
for item in items:
    name = item.name
    safe_name = (
        name if re.fullmatch(r"[A-Za-z0-9_-][A-Za-z0-9_.-]{0,100}", name)
        and not name.startswith(".")
        else "<hidden-or-unusual-name>"
    )
    mode = item.lstat().st_mode
    kind = ("SYMLINK" if stat.S_ISLNK(mode)
            else "DIRECTORY" if stat.S_ISDIR(mode)
            else "REGULAR_FILE" if stat.S_ISREG(mode) else "OTHER")
    print("SITE_ENTRY name=" + safe_name + " type=" + kind)
print("EXISTING_ROOT_SYMLINK_COUNT=" + str(sum(p.is_symlink() for p in items)))
print("EXISTING_ROOT_DIRECTORY_COUNT=" + str(sum(p.is_dir() and not p.is_symlink() for p in items)))
print("EXISTING_ROOT_REGULAR_FILE_COUNT=" + str(sum(p.is_file() and not p.is_symlink() for p in items)))

index = root / "index.html"
if index.is_file() and not index.is_symlink():
    size = index.stat().st_size
    print("STATIC_INDEX_SIZE_BYTES=" + str(size))
    digest = hashlib.sha256()
    with index.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    print("STATIC_INDEX_SHA256=" + digest.hexdigest())
    if size <= 262144:
        data = index.read_text(encoding="utf-8", errors="replace")
        match = re.search(r"<title\b[^>]*>(.*?)</title\s*>", data, re.I | re.S)
        if match:
            title = re.sub(r"\s+", " ", html.unescape(re.sub(
                r"<[^>]+>", "", match.group(1)))).strip()[:100]
            if title and all(ord(ch) >= 32 for ch in title):
                print("STATIC_INDEX_TITLE=" + title)
            else:
                print("STATIC_INDEX_TITLE=NONPRINTABLE_OR_EMPTY")
        else:
            print("STATIC_INDEX_TITLE=NOT_PRESENT")
    else:
        print("STATIC_INDEX_TITLE=NOT_READ_SIZE_LIMIT")
else:
    print("STATIC_INDEX_PRESENT=NO")

print("VHOST_FILE_SHA256=" + hashlib.sha256(vhost.read_bytes()).hexdigest())
print("CERT_DIRECTORY_ENTRY_COUNT=" + str(sum(1 for _ in certdir.iterdir())))
print("EXISTING_ROOT_CONTENT_REVIEW=COMPLETE")
PY

echo '========== BACKUP READINESS WITHOUT A BACKUP =========='
for tool in tar gzip df stat; do
  name="$(printf '%s' "$tool" | tr '[:lower:]' '[:upper:]')"
  if command -v "$tool" >/dev/null 2>&1; then
    printf 'BACKUP_TOOL_%s=AVAILABLE\n' "$name"
  else
    printf 'BACKUP_TOOL_%s=NOT_AVAILABLE\n' "$name"
  fi
done
if command -v du >/dev/null 2>&1; then
  SIZE="$(du -sB1 -- "$SITE" 2>/dev/null | awk '{print $1}')"
  if [[ "$SIZE" =~ ^[0-9]+$ ]]; then
    echo "EXISTING_SITE_ALLOCATED_BYTES=$SIZE"
  else
    echo 'EXISTING_SITE_ALLOCATED_BYTES=UNAVAILABLE'
  fi
fi
if command -v df >/dev/null 2>&1; then
  df -Pk -- "$SITE" | awk 'NR==2 {print "SITE_FILESYSTEM_AVAILABLE_KIB="$4}'
fi

echo '========== PUBLIC QA DEPLOYMENT SAFETY GATE =========='
echo 'EXISTING_SITE_REUSE=REQUIRES_EXPLICIT_OWNER_APPROVAL'
echo 'EXISTING_CONTENT_BACKUP=NOT_CREATED'
echo 'SEPARATE_PUBLIC_QA_RELEASE_AND_DB=RECOMMENDED'
echo 'PUBLIC_APP_URL_AND_OAUTH_ISSUER=NOT_CONFIGURED'
echo 'CLOUDFLARE_ORIGIN_MODE=NOT_VERIFIED'
echo 'REAL_BROWSER_AND_CHATGPT_ACCEPTANCE=NOT_TESTED'
echo 'DNS_CHANGES=NONE'
echo 'NGINX_CHANGES=NONE'
echo 'TLS_CHANGES=NONE'
echo 'QA_DATABASE_WRITES=NONE'
echo 'FROZEN_QA_CODE_CHANGES=NONE'
echo 'PRODUCTION_DEPLOYMENT=NONE'
echo 'STEP34B4_PRESERVATION_INVENTORY=COMPLETE'
