#!/usr/bin/env bash
# Bambodia Site Identity: pinned, backup-first, file-only aaPanel upgrade.
# No migrations, database mutations, Nginx changes or existing asset deletion.
set -Eeuo pipefail
set +x
umask 077
MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ]] || {
    echo 'Usage: --check|--apply' >&2
    exit 2
}
fail(){ echo "BLOCKED=$*" >&2; exit 2; }

REPO=bambodia168-sys/bambodialaravel
MAIN=133f82a3e8681f3efbc8de9424a83dc3306e15d5
REVIEW=6d255f9fcb08a434fc008a6ddfa0f2138f50eb0a
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
PHP=/www/server/php/85/bin/php
BACKUPS=/root/Bambodia-SiteIdentity-Upgrade-Backups

[[ "$(id -u)" == 0 ]] || fail 'Root terminal required'
for bin in gh git sha256sum python3 stat realpath mktemp; do
    command -v "$bin" >/dev/null || fail "Missing $bin"
done
[[ -x "$PHP" && -f "$APP/artisan" && -d "$APP/vendor" ]] ||
    fail 'Wrong Laravel app or PHP binary'
for p in /www /www/wwwroot "$APP" "$APP/public" \
    "$APP/resources" "$APP/resources/views" "$APP/public/js"; do
    [[ ! -L "$p" ]] || fail "Linked path: $p"
done
[[ "$(realpath -e "$APP")" == "$APP" && -f "$VHOST" && ! -L "$VHOST" ]] ||
    fail 'Unexpected live app/vhost'
grep -Fq "root $APP/public;" "$VHOST" ||
    fail 'Nginx is not serving reviewed app root'
[[ -f "$APP/.env" && ! -L "$APP/.env" ]] ||
    fail 'Laravel environment missing'
[[ -f "$APP/public/favicon.png" && -f "$APP/public/favicon-clean.png" &&
   ! -L "$APP/public/favicon.png" && ! -L "$APP/public/favicon-clean.png" ]] ||
    fail 'Original or repaired favicon missing'
[[ "$(sha256sum "$APP/public/favicon.png" | cut -d' ' -f1)" == \
    77f5b96b55a73c040bfb35adc134488849b5b724652b7f9aa86d93d7ee9e23be ]] ||
    fail 'Original favicon changed since verified repair'
[[ "$(sha256sum "$APP/public/favicon-clean.png" | cut -d' ' -f1)" == \
    a1fc144045c045f28623d29008d3fac0f4d9c4a17ebdff4bf65e9d43ed365cb6 ]] ||
    fail 'Transparent favicon changed since verified repair'

"$PHP" -r '
$i=imagecreatefrompng($argv[1]);
if (!$i || imagesx($i)!==64 || imagesy($i)!==64) exit(2);
foreach ([[0,0],[63,0],[0,63],[63,63]] as [$x,$y]) {
 $c=imagecolorsforindex($i,imagecolorat($i,$x,$y));
 if ($c["alpha"]<100) exit(3);
}
' "$APP/public/favicon-clean.png" ||
    fail 'Transparent favicon alpha/dimensions invalid'

[[ "$(gh api "repos/$REPO/commits/main" --jq '.sha')" == "$MAIN" ]] ||
    fail 'GitHub main differs from reviewed base, reconcile first'
[[ "$(gh api "repos/$REPO/pulls/39" --jq '.head.ref')" == "feat/site-identity-asset-specs-transparent-icons-20261112" &&
   "$(gh api "repos/$REPO/pulls/39" --jq '.base.ref')" == main &&
   "$(gh api "repos/$REPO/pulls/39" --jq '.draft')" == true ]] ||
    fail 'PR #39 branch, base or draft state changed'
for run in 43166946138 43166945958 43166946026 43166945950 43166946059; do
    [[ "$(gh api "repos/$REPO/actions/runs/$run" --jq '.head_sha')" == "$REVIEW" &&
       "$(gh api "repos/$REPO/actions/runs/$run" --jq '.conclusion')" == success ]] ||
        fail "Pinned release CI not accepted: $run"
done
echo 'PR39_APPROVED_SOURCE_CI=5_OF_5_PASS'
echo 'FAVICON_CLEAN=64x64_ALPHA_VERIFIED'

TMP="$(mktemp -d /root/bambodia-siteid.XXXXXXXX)"
cleanup() {
    local rc=$?
    trap - EXIT
    if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-siteid.* ]]; then
        rm -rf -- "$TMP"
    fi
    exit "$rc"
}
trap cleanup EXIT
mkdir -p "$TMP/staged"
while IFS= read -r REL; do
    [[ -n "$REL" ]] || continue
    TARGET="$APP/$REL"
    [[ ! -L "$TARGET" ]] || fail "Linked target: $REL"
    if [[ "$REL" == public/js/site-identity-assets.js ]]; then
        [[ ! -e "$TARGET" ]] || fail 'New upload script is already installed'
    else
        [[ -f "$TARGET" ]] || fail "Expected live baseline missing: $REL"
        BASE="$(gh api "repos/$REPO/contents/$REL?ref=$MAIN" --jq '.sha')"
        [[ "$(git hash-object "$TARGET")" == "$BASE" ]] ||
            fail "LIVE_FILE_DIVERGED=$REL"
    fi
    mkdir -p "$TMP/staged/$(dirname "$REL")"
    gh api -H 'Accept: application/vnd.github.raw+json' \
        "repos/$REPO/contents/$REL?ref=$REVIEW" \
        > "$TMP/staged/$REL"
    EXPECTED="$(gh api "repos/$REPO/contents/$REL?ref=$REVIEW" --jq '.sha')"
    [[ -s "$TMP/staged/$REL" &&
       "$(git hash-object "$TMP/staged/$REL")" == "$EXPECTED" ]] ||
        fail "PR39_FILE_INTEGRITY_FAILED=$REL"
    case "$REL" in
      *.php) "$PHP" -l "$TMP/staged/$REL" >/dev/null ||
        fail "PHP syntax invalid: $REL" ;;
    esac
    echo "SOURCE_VERIFIED=$REL"
done <<'FILES'
app/Models/SiteBrandAsset.php
app/Http/Controllers/Dashboard/SiteBrandingController.php
app/Http/Controllers/SiteManifestController.php
resources/views/dashboard/site-identity.blade.php
resources/views/layouts/partials/site-brand-head.blade.php
public/js/site-identity-assets.js
FILES
echo 'DEPLOYMENT_SCOPE=FIVE_EXISTING_FILES_PLUS_ONE_NEW_JS'
echo 'MYSQL_NGINX_PHP_FPM_AND_ORIGINAL_ASSETS=UNTOUCHED'
if [[ "$MODE" == --check ]]; then
    echo 'DEPLOYMENT=NOT_PERFORMED'
    echo 'RESULT=READY_FOR_GUARDED_PR39_SITE_IDENTITY_UPGRADE'
    exit 0
fi

[[ ! -L "$BACKUPS" ]] || fail 'Linked backup directory'
mkdir -p -m 0700 "$BACKUPS"
[[ "$(stat -c %U:%G "$BACKUPS")" == root:root ]] ||
    fail 'Backup directory must be owned by root'
chmod 0700 "$BACKUPS"
OUT="$(mktemp -d "$BACKUPS/upgrade-$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
chmod 0700 "$OUT"
python3 - "$APP" "$TMP/staged" "$OUT" <<'PY'
import hashlib
import json
import os
import shutil
import stat
import sys
import tempfile
from pathlib import Path
app,staged,out=map(Path,sys.argv[1:])
paths=[
'app/Models/SiteBrandAsset.php',
'app/Http/Controllers/Dashboard/SiteBrandingController.php',
'app/Http/Controllers/SiteManifestController.php',
'resources/views/dashboard/site-identity.blade.php',
'resources/views/layouts/partials/site-brand-head.blade.php',
'public/js/site-identity-assets.js',
]
metadata={}
backup=out/'previous'
backup.mkdir(mode=0o700)
for rel in paths:
    target=app/rel
    src=staged/rel
    if target.is_symlink() or src.is_symlink() or not src.is_file():
        raise SystemExit('BLOCKED=untrusted branding target')
    if target.exists():
        destination=backup/rel
        destination.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(target,destination)
        st=target.stat()
        metadata[rel]=[st.st_uid,st.st_gid,stat.S_IMODE(st.st_mode)]
        if hashlib.sha256(target.read_bytes()).digest()!=hashlib.sha256(destination.read_bytes()).digest():
            raise SystemExit('BLOCKED=backup verification failed')
    else:
        metadata[rel]=None
(out/'manifest.json').write_text(json.dumps(metadata,indent=2)+'\n')
os.chmod(out/'manifest.json',0o600)
print('EXISTING_SITE_IDENTITY_FILES=BACKED_UP_AND_VERIFIED')
changed=[]
try:
    for rel in paths:
        target=app/rel
        src=staged/rel
        fd,temporary=tempfile.mkstemp(prefix='.siteid-',dir=target.parent)
        try:
            with os.fdopen(fd,'wb') as f, src.open('rb') as input:
                shutil.copyfileobj(input,f)
                f.flush()
                os.fsync(f.fileno())
            m=metadata[rel]
            if m is not None:
                os.chown(temporary,m[0],m[1])
                os.chmod(temporary,m[2])
            else:
                os.chown(temporary,0,0)
                os.chmod(temporary,0o644)
            os.replace(temporary,target)
            changed.append(rel)
        finally:
            if os.path.lexists(temporary):
                os.unlink(temporary)
    for rel in paths:
        if hashlib.sha256((app/rel).read_bytes()).digest()!=hashlib.sha256((staged/rel).read_bytes()).digest():
            raise RuntimeError('Post-install digest mismatch: '+rel)
except BaseException:
    for rel in reversed(changed):
        target=app/rel
        m=metadata[rel]
        if m is None:
            target.unlink(missing_ok=True)
        else:
            source=backup/rel
            shutil.copy2(source,target)
            os.chown(target,m[0],m[1])
            os.chmod(target,m[2])
    print('INSTALL_FAILED_BACKUP_RESTORE_ATTEMPTED')
    raise
print('SIX_TARGET_FILES=VERIFIED_INSTALLED')
PY

echo "PRIVATE_BACKUP_DIR=$OUT"
echo 'PRODUCTION_DATABASE_AND_NGINX=UNMODIFIED_BY_HELPER'
echo 'ORIGINAL_FAVICON_AND_APPLE_ICON=UNCHANGED'
echo 'RESULT=PR39_SITE_IDENTITY_FILE_ONLY_DEPLOYMENT_APPLIED'
