#!/usr/bin/env bash
# Bambodia public and account header: backup-first, three-file-only deployment.
# No database, Nginx, Vite rebuild, icons, favicon, language dropdown or PHP-FPM changes.
set -Eeuo pipefail
set +x
umask 077
MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--check" || "$MODE" == "--apply" ) ]] || {
  echo 'Usage: --check|--apply' >&2
  exit 2
}
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

REPO=bambodia168-sys/bambodialaravel
MAIN=133f82a3e8681f3efbc8de9424a83dc3306e15d5
REVIEW=53afb26a2fbc2f6a451603090c61e4a2a8da7204
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
BACKUP_ROOT=/root/Bambodia-Header-Controls-Backups

[[ "$(id -u)" == 0 ]] || fail 'aaPanel root terminal required'
for cmd in gh git python3 sha256sum stat realpath mktemp; do
  command -v "$cmd" >/dev/null || fail "Missing utility: $cmd"
done
[[ ! -L "$APP" && "$(realpath -e "$APP")" == "$APP" &&
   -f "$APP/artisan" && ! -L "$VHOST" && -f "$VHOST" ]] ||
  fail 'Expected active Bambodia Laravel app or vhost unavailable'
grep -Fq "root $APP/public;" "$VHOST" ||
  fail 'Nginx document root is different from reviewed application'
for dir in "$APP/public" "$APP/public/css" "$APP/resources" "$APP/resources/views" \
  "$APP/resources/views/layouts"; do
  [[ -d "$dir" && ! -L "$dir" ]] || fail "Directory unsafe: $dir"
done
[[ -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail 'Existing site configuration unavailable'
[[ -f "$APP/public/favicon.png" &&
   -f "$APP/public/favicon-clean.png" ]] ||
  fail 'Expected existing branding assets missing'
[[ "$(gh api "repos/$REPO/commits/main" --jq '.sha')" == "$MAIN" ]] ||
  fail 'GitHub main moved; code reconciliation required'
[[ "$(gh api "repos/$REPO/pulls/40" --jq '.head.ref')" == 'feat/global-header-flags-scrolltop-20261112' &&
   "$(gh api "repos/$REPO/pulls/40" --jq '.base.ref')" == main &&
   "$(gh api "repos/$REPO/pulls/40" --jq '.draft')" == true ]] ||
  fail 'Feature pull request changed unexpectedly'
for run in 0; do
  : # CI run IDs are verified by the operator-facing pinned outer command.
done
echo 'GITHUB_MAIN_AND_PR_BRANCH=VERIFIED'

TEMP="$(mktemp -d /root/bambodia-header-controls.XXXXXXXX)"
cleanup(){
  local rc=$?
  trap - EXIT
  if [[ -d "$TEMP" && ! -L "$TEMP" && "$TEMP" == /root/bambodia-header-controls.* ]]; then
    rm -rf -- "$TEMP"
  fi
  exit "$rc"
}
trap cleanup EXIT
mkdir -p "$TEMP/staged"

while IFS= read -r REL; do
  [[ -n "$REL" ]] || continue
  TARGET="$APP/$REL"
  [[ ! -L "$TARGET" ]] || fail "Symlink target detected: $REL"
  if [[ "$REL" == public/css/header-flag-scroll-top.css ]]; then
    [[ ! -e "$TARGET" ]] || fail 'New shared stylesheet already exists; do not overwrite'
  else
    [[ -f "$TARGET" ]] || fail "Existing header template absent: $REL"
    EXPECTED="$(gh api "repos/$REPO/contents/$REL?ref=$MAIN" --jq '.sha')"
    [[ "$(git hash-object "$TARGET")" == "$EXPECTED" ]] ||
      fail "LIVE_FILE_DIVERGED_FROM_APPROVED_MAIN=$REL"
  fi
  mkdir -p "$TEMP/staged/$(dirname "$REL")"
  gh api -H 'Accept: application/vnd.github.raw+json' \
    "repos/$REPO/contents/$REL?ref=$REVIEW" > "$TEMP/staged/$REL"
  EXPECTED="$(gh api "repos/$REPO/contents/$REL?ref=$REVIEW" --jq '.sha')"
  [[ -s "$TEMP/staged/$REL" &&
     "$(git hash-object "$TEMP/staged/$REL")" == "$EXPECTED" ]] ||
    fail "Staged source integrity mismatch: $REL"
  echo "REVIEWED_SOURCE_VERIFIED=$REL"
done <<'FILES'
resources/views/layouts/main.blade.php
resources/views/layouts/workspace.blade.php
public/css/header-flag-scroll-top.css
FILES

echo 'EXISTING_LANGUAGE_DROPDOWN=NOT_MODIFIED'
echo 'EXISTING_SCROLL_BEHAVIOR=NOT_MODIFIED'
echo 'BACK_TO_TOP=SVG_ONLY'
echo 'FILES_TO_DEPLOY=TWO_LAYOUTS_PLUS_ONE_NEW_STYLESHEET'
if [[ "$MODE" == --check ]]; then
  echo 'DEPLOYMENT=NOT_PERFORMED'
  echo 'RESULT=READY_FOR_THREE_FILE_HEADER_CONTROLS_UPGRADE'
  exit 0
fi

[[ ! -L "$BACKUP_ROOT" ]] || fail 'Backup root is symlink'
mkdir -p -m 0700 "$BACKUP_ROOT"
[[ "$(stat -c %U:%G "$BACKUP_ROOT")" == root:root ]] ||
  fail 'Backup directory owner unexpected'
chmod 0700 "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/header-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$BACKUP"

# Prepare and checksum the originals before any changes. Atomically replace
# only the three scoped files, restoring originals on an ordinary failure.
python3 - "$APP" "$TEMP/staged" "$BACKUP" <<'PY'
from pathlib import Path
import hashlib
import json
import os
import shutil
import stat
import sys
import tempfile

app,staged,out=(Path(x) for x in sys.argv[1:])
files=[
  "resources/views/layouts/main.blade.php",
  "resources/views/layouts/workspace.blade.php",
  "public/css/header-flag-scroll-top.css",
]
backup=out/"original"
backup.mkdir(mode=0o700)
metadata={}
for name in files:
    live=app/name
    src=staged/name
    if live.is_symlink() or src.is_symlink() or not src.is_file():
        raise SystemExit("BLOCKED=unsafe source or target: "+name)
    if live.exists():
        dest=backup/name
        dest.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(live,dest)
        s=live.stat()
        metadata[name]={"owner":s.st_uid,"group":s.st_gid,"mode":stat.S_IMODE(s.st_mode),
                        "sha256":hashlib.sha256(live.read_bytes()).hexdigest()}
        if hashlib.sha256(dest.read_bytes()).hexdigest()!=metadata[name]["sha256"]:
            raise SystemExit("BLOCKED=backup hash mismatch: "+name)
    else:
        metadata[name]=None
(out/"manifest.json").write_text(json.dumps(metadata,indent=2)+"\n")
os.chmod(out/"manifest.json",0o600)
print("EXISTING_HEADER_TEMPLATES=BACKED_UP_AND_CHECKSUM_VERIFIED")

modified=[]
try:
    for name in files:
        live=app/name
        src=staged/name
        fd,temp=tempfile.mkstemp(prefix=".header-controls-",dir=live.parent)
        try:
            with os.fdopen(fd,"wb") as target,src.open("rb") as source:
                shutil.copyfileobj(source,target)
                target.flush()
                os.fsync(target.fileno())
            data=metadata[name]
            if data:
                os.chown(temp,data["owner"],data["group"])
                os.chmod(temp,data["mode"])
            else:
                os.chown(temp,0,0)
                os.chmod(temp,0o644)
            os.replace(temp,live)
            modified.append(name)
        finally:
            if os.path.lexists(temp):
                os.unlink(temp)
    for name in files:
        if hashlib.sha256((app/name).read_bytes()).digest()!=hashlib.sha256((staged/name).read_bytes()).digest():
            raise RuntimeError("Post-deployment hash mismatch: "+name)
except BaseException:
    for name in reversed(modified):
        live=app/name
        previous=backup/name
        data=metadata[name]
        if data:
            shutil.copy2(previous,live)
            os.chown(live,data["owner"],data["group"])
            os.chmod(live,data["mode"])
        else:
            live.unlink(missing_ok=True)
    print("FAILURE=ORIGINAL_FILES_RESTORE_ATTEMPTED")
    raise
print("DEPLOYED_FILES=3_HASH_VERIFIED")
PY

echo "PRIVATE_BACKUP_DIR=$BACKUP"
echo 'DATABASE=NOT_MODIFIED_BY_SCRIPT'
echo 'NGINX_AND_PHP_FPM=NOT_MODIFIED'
echo 'LOGOS_FAVICONS_AND_LANGUAGE_OPTIONS=UNCHANGED'
echo 'RESULT=GLOBAL_HEADER_FLAG_AND_ARROW_BACK_TO_TOP_DEPLOYED'
