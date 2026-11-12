#!/usr/bin/env bash
# Step 26I-R: read-only investigation of a failed shallow Git clone.
# NO clone retry, git fetch/repair, source/index writes, deletions or DB changes.
set -Eeuo pipefail
set +x
umask 077

SOURCE="/var/lib/bambodia-oauth-browser-qa"
DEST="/var/lib/bambodia-oauth-https-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
MISSING_OBJECT="a1a923f833deb02605bdd851ad10fd470c4c8c62"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
fail(){ printf 'STEP26IR_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in git stat realpath sha256sum python3; do
    command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done

# Prevent Git's partial-clone object hydration and index refresh when inspecting.
export GIT_NO_LAZY_FETCH=1
export GIT_OPTIONAL_LOCKS=0
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && ! -L "$SOURCE/.git" &&
   "$(realpath -e "$SOURCE")" == "$SOURCE" ]] ||
    fail ACCEPTED_SOURCE_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] ||
    fail ACCEPTED_SOURCE_COMMIT_CHANGED
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
    fail SOURCE_TRACKED_WORKTREE_CHANGED
[[ -s "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" &&
   "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
    fail ACCEPTED_LOCK_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
    fail NGINX_VHOST_CHANGED

echo '========== SOURCE GIT OBJECT DIAGNOSTICS =========='
echo 'APPROVED_SOURCE_SHA_AND_LOCK=VERIFIED'
echo 'CURRENT_NGINX_VHOST_SHA=UNCHANGED'
echo "SOURCE_IS_SHALLOW=$(git -C "$SOURCE" rev-parse --is-shallow-repository)"
if git -C "$SOURCE" cat-file -e "$SHA^{commit}" 2>/dev/null; then
    echo 'APPROVED_COMMIT_OBJECT=LOCAL'
else
    echo 'APPROVED_COMMIT_OBJECT=NOT_ACCESSIBLE_LOCALLY'
fi
if git -C "$SOURCE" cat-file -e "$SHA^{tree}" 2>/dev/null; then
    echo 'APPROVED_ROOT_TREE=LOCAL'
else
    echo 'APPROVED_ROOT_TREE=NOT_ACCESSIBLE_LOCALLY'
fi
if git -C "$SOURCE" cat-file -e "$MISSING_OBJECT^{object}" 2>/dev/null; then
    echo 'ERROR_OBJECT=ACCESSIBLE_LOCALLY'
else
    echo 'ERROR_OBJECT=NOT_ACCESSIBLE_LOCALLY'
fi
if [[ -f "$SOURCE/.git/shallow" && ! -L "$SOURCE/.git/shallow" ]]; then
    echo 'SHALLOW_BOUNDARY_FILE=PRESENT'
else
    echo 'SHALLOW_BOUNDARY_FILE=ABSENT'
fi

echo '========== INCOMPLETE TARGET INSPECTION =========='
python3 - "$DEST" <<'PY'
from pathlib import Path
import os,stat,sys
path=Path(sys.argv[1])
if path.is_symlink():
    print("DEST_STATE=SYMLINK_REVIEW_REQUIRED")
elif not path.exists():
    print("DEST_STATE=ABSENT")
elif path.is_dir():
    info=path.lstat()
    print("DEST_STATE=PRESENT_DIRECTORY_DO_NOT_DELETE")
    print("DEST_UID_GID={}:{}".format(info.st_uid,info.st_gid))
    print("DEST_MODE={:04o}".format(stat.S_IMODE(info.st_mode)))
    entries=list(path.iterdir())
    print("DEST_TOP_LEVEL_ENTRY_COUNT="+str(len(entries)))
    git=path/'.git'
    print("DEST_GIT_DIRECTORY="+("PRESENT" if git.is_dir() and not git.is_symlink() else
                                "UNSAFE_SYMLINK" if git.is_symlink() else "ABSENT"))
    for f in ('.env','composer.lock','artisan','public/index.php','storage/oauth-private.key'):
        entry=path/f
        print("DEST_"+f.upper().replace('/','_').replace('.','_')+"="+
              ("SYMLINK_REVIEW_REQUIRED" if entry.is_symlink() else
               "PRESENT" if entry.exists() else "ABSENT"))
else:
    print("DEST_STATE=UNEXPECTED_NON_DIRECTORY_REVIEW_REQUIRED")
print("DEST_CONTENTS=NOT_READ")
PY
if [[ -d "$DEST/.git" && ! -L "$DEST/.git" ]]; then
    if DEST_SHA="$(git -C "$DEST" rev-parse --verify HEAD 2>/dev/null)"; then
        if [[ "$DEST_SHA" == "$SHA" ]]; then
            echo 'DEST_GIT_HEAD=APPROVED_SHA'
        else
            echo 'DEST_GIT_HEAD=OTHER_OR_INCOMPLETE'
        fi
    else
        echo 'DEST_GIT_HEAD=UNAVAILABLE_IN_INCOMPLETE_CLONE'
    fi
fi

echo '========== FINAL NO-WRITE RESULT =========='
echo 'STEP26IR_CLONE_FAILURE_INVENTORY=COMPLETE'
echo 'SOURCE_GIT_FETCH_OR_REPAIR=NOT_RUN'
echo 'INCOMPLETE_TARGET=NOT_DELETED'
echo 'MYSQL=NOT_CONNECTED'
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
