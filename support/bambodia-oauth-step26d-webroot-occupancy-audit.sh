#!/usr/bin/env bash
# Bambodia OAuth Step 26D: inspect existing HTTPS QA webroot without changes.
# Never delete, move, chown, copy, migrate or write webroot/QA/production files.
set -Eeuo pipefail
umask 077

HOST="oauth-qa.bambodia.com"
WEBROOT="/www/wwwroot/oauth-qa.bambodia.com"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
SOURCE="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"

fail() { printf 'STEP26D_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in git python3 realpath sha256sum; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done

[[ -d "$WEBROOT" && ! -L "$WEBROOT" ]] || fail UNSAFE_WEBROOT
[[ "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] || fail WEBROOT_REDIRECTED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail UNSAFE_VHOST
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && ! -L "$SOURCE/.git" ]] ||
  fail UNSAFE_APPROVED_SOURCE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] ||
  fail UNEXPECTED_SOURCE_COMMIT
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail TRACKED_SOURCE_MODIFIED
[[ -s "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" ]] ||
  fail APPROVED_SOURCE_LOCK_MISSING
[[ "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail APPROVED_SOURCE_LOCK_CHANGED

python3 - "$WEBROOT" "$VHOST" "$HOST" <<'PY'
from pathlib import Path
import json
import os
import re
import stat
import sys

webroot = Path(sys.argv[1])
vhost = Path(sys.argv[2])
hostname = sys.argv[3]
cfg = vhost.read_text(errors="replace")
names = re.findall(r"^\s*server_name\s+([^;]+);", cfg, re.M)
roots = re.findall(r"^\s*root\s+([^;]+);", cfg, re.M)
if not any(hostname in nameset.split() for nameset in names):
    raise SystemExit("STEP26D_BLOCKED=VHOST_HOST_MISMATCH")
if len(roots) != 1 or roots[0].strip() != str(webroot):
    raise SystemExit("STEP26D_BLOCKED=VHOST_ROOT_CHANGED")

root_stat = webroot.lstat()
print("VHOST_NAME=VERIFIED")
print("VHOST_ROOT=VERIFIED")
print("WEBROOT_UID=" + str(root_stat.st_uid))
print("WEBROOT_GID=" + str(root_stat.st_gid))
print("WEBROOT_MODE=" + format(stat.S_IMODE(root_stat.st_mode), "04o"))

entries = sorted(webroot.iterdir(), key=lambda p: p.name)
print("WEBROOT_ENTRY_COUNT=" + str(len(entries)))
unsafe = False
for i, path in enumerate(entries, start=1):
    info = path.lstat()
    if stat.S_ISLNK(info.st_mode):
        kind = "SYMLINK"
        unsafe = True
    elif stat.S_ISDIR(info.st_mode):
        kind = "DIRECTORY"
    elif stat.S_ISREG(info.st_mode):
        kind = "REGULAR_FILE"
    else:
        kind = "SPECIAL_OBJECT"
        unsafe = True
    print("ENTRY_{:02d}_NAME={}".format(i, json.dumps(path.name, ensure_ascii=True)))
    print("ENTRY_{:02d}_TYPE={}".format(i, kind))
    print("ENTRY_{:02d}_UID_GID={}:{}".format(i, info.st_uid, info.st_gid))
    print("ENTRY_{:02d}_MODE={:04o}".format(i, stat.S_IMODE(info.st_mode)))
    if kind == "REGULAR_FILE":
        print("ENTRY_{:02d}_SIZE_BYTES={}".format(i, info.st_size))

# This is a deliberate manual-approval gate. Even common aaPanel placeholder
# files must be assessed before a Laravel install targets this directory.
print("WEBROOT_OCCUPANCY=" +
      ("EMPTY" if not entries else "NONEMPTY_REVIEW_REQUIRED"))
print("WEBROOT_LINKS_OR_SPECIAL_OBJECTS=" +
      ("REVIEW_REQUIRED" if unsafe else "NONE_AT_TOP_LEVEL"))
print("EXISTING_CONTENT_READ=FILENAMES_AND_METADATA_ONLY")
print("FILE_CONTENTS=NOT_READ")
PY

echo "DATABASE_EXISTENCE=NOT_CHECKED"
echo "DATABASE_CREDENTIALS=NOT_ACCESSED"
echo "STEP26D_WEBROOT_INVENTORY=COMPLETE"
echo "APPLICATION_DEPLOYMENT=NONE"
echo "WEBROOT_WRITES=NONE"
echo "NGINX_CHANGES=NONE"
echo "MYSQL_CHANGES=NONE"
echo "PRODUCTION_CHANGES=NONE"
