#!/usr/bin/env bash
# Bambodia MCP Gate 1.2: permissions metadata ONLY. No media bytes or filenames printed.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--inspect" ]] || {
  echo "Usage: bash script.sh --inspect" >&2; exit 64;
}
fail() { printf 'MCP_PRIVATE_MODE_AUDIT_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for binary in python3 git sha256sum; do
  command -v "$binary" >/dev/null || fail REQUIRED_BINARY_MISSING
done
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
   -f "$APP/storage/app/installed.lock" ]] || fail LIVE_APP_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail VHOST_CHANGED
[[ "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] || fail VHOST_HASH_CHANGED
[[ "$(git hash-object "$APP/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] || fail COMPOSER_CHANGED
[[ "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] || fail LOCK_CHANGED
echo "MCP_PRIVATE_MODE_LIVE_IDENTITY=PASS"

python3 - "$APP" <<'PY'
from pathlib import Path
import os
import stat
import sys

app=Path(sys.argv[1])
target=app/"storage/app/private"
chain=[Path("/"),Path("/www"),Path("/www/wwwroot"),app,app/"storage",app/"storage/app",target]

def emit(key, value):
    print(key+"="+str(value), flush=True)

def failure(category):
    emit("MCP_PRIVATE_MODE_AUDIT",category)
    sys.exit(1)

try:
    chain_info=[]
    for directory in chain:
        if directory.is_symlink():
            failure("BLOCKED_ANCESTOR_SYMLINK")
        mode=directory.lstat().st_mode
        if not stat.S_ISDIR(mode):
            failure("BLOCKED_ANCESTOR_NOT_DIRECTORY")
        chain_info.append(mode)
    ancestor_traverse=all(bool(m & stat.S_IXOTH) for m in chain_info)
    emit("MCP_PRIVATE_CHAIN_OTHER_TRAVERSABLE", "YES" if ancestor_traverse else "NO")
    emit("MCP_PRIVATE_ROOT_OTHER_READ", "YES" if chain_info[-1] & stat.S_IROTH else "NO")
    emit("MCP_PRIVATE_ROOT_OTHER_WRITE", "YES" if chain_info[-1] & stat.S_IWOTH else "NO")
    emit("MCP_PRIVATE_ROOT_OTHER_EXECUTE", "YES" if chain_info[-1] & stat.S_IXOTH else "NO")
    totals={"files":0,"directories":0,"links":0,"other_read":0,
            "other_read_traverse":0,"other_write":0,"group_read":0,
            "world_writable_dirs":0,"not_regular":0}
    truncated=False
    # Track whether every nested directory is traversable by a local "other" account.
    queue=[(target,ancestor_traverse)]
    while queue:
        directory,can_traverse=queue.pop()
        totals["directories"]+=1
        if totals["directories"]>5000:
            truncated=True
            break
        if directory.lstat().st_mode & stat.S_IWOTH:
            totals["world_writable_dirs"]+=1
        with os.scandir(directory) as entries:
            for entry in entries:
                info=entry.stat(follow_symlinks=False)
                mode=info.st_mode
                if stat.S_ISLNK(mode):
                    totals["links"]+=1
                    continue
                if stat.S_ISDIR(mode):
                    next_traverse=can_traverse and bool(mode & stat.S_IXOTH)
                    queue.append((Path(entry.path),next_traverse))
                    continue
                if not stat.S_ISREG(mode):
                    totals["not_regular"]+=1
                    continue
                totals["files"]+=1
                if mode & stat.S_IROTH:
                    totals["other_read"]+=1
                    if can_traverse:
                        totals["other_read_traverse"]+=1
                if mode & stat.S_IWOTH:
                    totals["other_write"]+=1
                if mode & stat.S_IRGRP:
                    totals["group_read"]+=1
                if totals["files"]>50000:
                    truncated=True
                    break
        if truncated:
            break
    emit("MCP_PRIVATE_FILE_COUNT_METADATA_ONLY",totals["files"])
    emit("MCP_PRIVATE_SUBDIR_COUNT_METADATA_ONLY",totals["directories"])
    emit("MCP_PRIVATE_OTHER_READABLE_FILES",totals["other_read"])
    emit("MCP_PRIVATE_OTHER_READABLE_WITH_OTHER_TRAVERSAL",totals["other_read_traverse"])
    emit("MCP_PRIVATE_OTHER_WRITABLE_FILES",totals["other_write"])
    emit("MCP_PRIVATE_GROUP_READABLE_FILES",totals["group_read"])
    emit("MCP_PRIVATE_OTHER_WRITABLE_DIRECTORIES",totals["world_writable_dirs"])
    emit("MCP_PRIVATE_LINKS_SKIPPED",totals["links"])
    emit("MCP_PRIVATE_OTHER_OBJECT_TYPES",totals["not_regular"])
    emit("MCP_PRIVATE_SCAN_TRUNCATED","YES" if truncated else "NO")
    emit("MCP_PRIVATE_ACL_AND_WEB_EXPOSURE","NOT_VERIFIED")
    emit("MCP_PRIVATE_MEDIA_CONTENT","NOT_READ")
    emit("MCP_BACKUP_CREATED","NO")
    emit("MCP_PRODUCTION_RELEASE","BLOCKED")
    emit("MCP_PRIVATE_MODE_AUDIT","READ_ONLY_COMPLETED")
except OSError:
    failure("BLOCKED_FILESYSTEM_METADATA_ERROR")
PY
echo "MCP_PRODUCTION_FILES_DATABASE_NGINX=UNCHANGED"
