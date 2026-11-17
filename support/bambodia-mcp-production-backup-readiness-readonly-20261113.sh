#!/usr/bin/env bash
# Bambodia MCP: read-only backup/restore and media inventory BEFORE change control.
# This checks only file metadata and available disk space. No content, DB access,
# backup creation, customer data restoration, Nginx reload or production writes.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--inspect" ]] || {
  echo "Usage: bash $0 --inspect" >&2; exit 64;
}
fail(){ printf 'BACKUP_READINESS_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for bin in python3 sha256sum stat git; do
  command -v "$bin" >/dev/null || fail REQUIRED_BINARY_MISSING
done

APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
   -f "$APP/storage/app/installed.lock" ]] || fail LIVE_APPLICATION_IDENTITY_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
   fail VHOST_IDENTITY_CHANGED
[[ "$(git hash-object "$APP/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
   fail PRODUCTION_COMPOSER_CHANGED
[[ "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
   fail PRODUCTION_LOCK_CHANGED

echo "BACKUP_READINESS_LIVE_IDENTITY=PASS"
python3 - "$APP" <<'PY'
from pathlib import Path
from datetime import datetime, timezone
import os
import stat
import sys

site=Path(sys.argv[1])
MAX_FILES=50000
MAX_BYTES=1 << 52

def flag(label,value):
    print(label+"="+value)
def regular_meta(path):
    try:
        mode=path.lstat().st_mode
        if stat.S_ISREG(mode):
            return path.stat(follow_symlinks=False)
    except OSError:
        pass
    return None
def scan(root,accepted=None):
    # Metadata-only inventory. Do NOT follow symbolic links, read file bytes,
    # print customer filenames or interpret existence as backup recoverability.
    stats={"files":0,"bytes":0,"links":0,"latest":None,"limit":False,"errors":0}
    if not root.is_dir() or root.is_symlink():
        return stats
    for directory, folders, filenames in os.walk(root,topdown=True,followlinks=False):
        valid_folders=[]
        for name in folders:
            p=Path(directory)/name
            try:
                if p.is_symlink():
                    stats["links"]+=1
                else:
                    valid_folders.append(name)
            except OSError:
                stats["errors"]+=1
        folders[:]=valid_folders
        for name in filenames:
            path=Path(directory)/name
            try:
                if path.is_symlink():
                    stats["links"]+=1
                    continue
                if accepted is not None and not accepted(name.lower()):
                    continue
                info=regular_meta(path)
                if info is None:
                    stats["errors"]+=1
                    continue
                stats["files"]+=1
                stats["bytes"]+=info.st_size
                if stats["latest"] is None or info.st_mtime>stats["latest"]:
                    stats["latest"]=info.st_mtime
                if stats["files"]>MAX_FILES or stats["bytes"]>MAX_BYTES:
                    stats["limit"]=True
                    break
            except OSError:
                stats["errors"]+=1
        if stats["limit"]:
            break
    return stats

def format_size(bytes_count):
    return str(round(bytes_count / (1024**3),2))
def availability(label,path):
    if path.is_symlink():
        flag(label+"_ROOT","SYMLINK_NOT_SCANNED")
        return False
    if not path.is_dir():
        flag(label+"_ROOT","NOT_FOUND_IN_EXPECTED_LOCATION")
        return False
    flag(label+"_ROOT","PRESENT")
    return True

# Known aaPanel backup directories, not necessarily where the operator stores
# encrypted/off-site backups. No live database connection is made.
backup_ext=(".sql",".sql.gz",".sql.zst",".sql.zip",".zip",".tar",
            ".tar.gz",".tgz",".tar.zst",".zst",".gz",".gpg",".age",".7z")
for name,path in (
    ("BACKUP_AAPANEL_DATABASE",Path("/www/backup/database")),
    ("BACKUP_AAPANEL_SITE",Path("/www/backup/site")),
):
    if not availability(name,path):
        continue
    meta=scan(path,lambda filename:filename.endswith(backup_ext))
    flag(name+"_POTENTIAL_ARCHIVES",str(meta["files"]))
    flag(name+"_TOTAL_GIB_METADATA_ONLY",format_size(meta["bytes"]))
    flag(name+"_LINKS_SKIPPED",str(meta["links"]))
    flag(name+"_SCAN_ERRORS",str(meta["errors"]))
    flag(name+"_SCAN_LIMIT_REACHED","YES" if meta["limit"] else "NO")
    if meta["latest"] is None:
        flag(name+"_MOST_RECENT_ARCHIVE_AGE_DAYS","NOT_FOUND")
    else:
        age=(datetime.now(timezone.utc).timestamp()-meta["latest"])/86400
        flag(name+"_MOST_RECENT_ARCHIVE_AGE_DAYS",str(round(max(0,age),1)))

# Only preselected generic media locations; no filenames or customer content.
# The definitive private-media roots require authorized app/DB review later.
for name,relative in (
    ("MEDIA_STORAGE_APP_PUBLIC","storage/app/public"),
    ("MEDIA_STORAGE_APP_PRIVATE","storage/app/private"),
    ("MEDIA_PUBLIC_UPLOADS","public/uploads"),
    ("MEDIA_PUBLIC_MEDIA","public/media"),
):
    path=site/relative
    if not availability(name,path):
        continue
    meta=scan(path)
    flag(name+"_FILES_METADATA_ONLY",str(meta["files"]))
    flag(name+"_TOTAL_GIB_METADATA_ONLY",format_size(meta["bytes"]))
    flag(name+"_LINKS_SKIPPED",str(meta["links"]))
    flag(name+"_SCAN_ERRORS",str(meta["errors"]))
    flag(name+"_SCAN_LIMIT_REACHED","YES" if meta["limit"] else "NO")

# Secret visibility: examine ONLY filesystem permissions, never .env contents.
env=site/".env"
info=regular_meta(env)
if info is None or env.is_symlink():
    flag("PRODUCTION_ENV_METADATA","NOT_REGULAR_OR_MISSING")
else:
    mode=stat.S_IMODE(info.st_mode)
    flag("PRODUCTION_ENV_OTHER_READ_WRITE",
         "BLOCKED_WORLD_ACCESS" if mode & (stat.S_IROTH|stat.S_IWOTH) else "NO_WORLD_ACCESS")

for name,root in (
    ("SPACE_LIVE_FILESYSTEM",site),
    ("SPACE_ROOT_PRIVATE_FILESYSTEM",Path("/root")),
):
    try:
        state=os.statvfs(root)
        flag(name+"_AVAILABLE_GIB",str(round(state.f_bavail*state.f_frsize/1024**3,2)))
    except OSError:
        flag(name+"_AVAILABLE_GIB","UNAVAILABLE")

# File existence, size, mtime, ownership and free disk do not establish usable
# backups, encryption, coverage, independent restoration or rollback safety.
flag("BACKUP_LOCATION_COVERAGE","EXPECTED_LOCATIONS_ONLY")
flag("PRODUCTION_DATABASE_CREDENTIALS","NOT_ACCESSED")
flag("PRODUCTION_DATABASE_RECORDS","NOT_ACCESSED")
flag("PRIVATE_CUSTOMER_MEDIA_CONTENT","NOT_ACCESSED")
flag("ENCRYPTED_OFFSITE_BACKUP","NOT_VERIFIED")
flag("DATABASE_MEDIA_CONSISTENT_BACKUP","NOT_CREATED")
flag("ISOLATED_BACKUP_RESTORE","NOT_PERFORMED")
flag("USER_PERMISSION_MEDIA_CHECKSUM_RECONCILIATION","NOT_VERIFIED")
flag("APPLICATION_SCHEMA_AND_CODE_ROLLBACK_REHEARSAL","NOT_PERFORMED")
flag("BACKUP_READINESS_AUDIT","READ_ONLY_COMPLETED")
flag("MCP_PRODUCTION_RELEASE_GATE","BLOCKED")
flag("PRODUCTION_SOURCE_DATABASE_SECRETS_NGINX","UNCHANGED_BY_AUDIT")
PY
