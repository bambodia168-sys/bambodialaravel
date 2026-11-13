#!/usr/bin/env bash
# Bambodia MCP: preserve TWO newly changed production Blade views in a NEW
# root-private release candidate. Do not modify live, prior private, or MySQL.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--prepare-private" ]] || {
  echo "Usage: bash $0 --prepare-private" >&2; exit 64;
}
fail(){ printf 'MCP_TWO_BLADE_REBASE_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for tool in python3 sha256sum stat cp mktemp df du git; do
  command -v "$tool" >/dev/null || fail MISSING_BINARY
done

LIVE=/www/wwwroot/bambodia-staging-qa
PRIOR=/root/Bambodia-MCP-Overlay-Refresh.hZDR51NJ
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
[[ -d "$LIVE" && ! -L "$LIVE" && -f "$LIVE/artisan" &&
   -f "$LIVE/storage/app/installed.lock" ]] || fail LIVE_ROOT_CHANGED
[[ -d "$PRIOR" && ! -L "$PRIOR" &&
   "$(stat -c '%u:%a' "$PRIOR")" == "0:700" &&
   -d "$PRIOR/source" && ! -L "$PRIOR/source" &&
   -d "$PRIOR/review" && ! -L "$PRIOR/review" ]] ||
   fail PRIVATE_CANDIDATE_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
   fail VHOST_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
 fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
 fail LIVE_LOCK_CHANGED
[[ "$(sha256sum "$PRIOR/source/composer.lock" | awk '{print $1}')" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] ||
 fail PRIVATE_LOCK_CHANGED
[[ -f "$PRIOR/source/vendor/autoload.php" && ! -L "$PRIOR/source/vendor" ]] ||
 fail PRIVATE_VENDOR_UNSAFE
for item in "$PRIOR/source/.env" "$PRIOR/source/.env.testing" \
 "$PRIOR/source/storage/oauth-private.key" "$PRIOR/source/storage/oauth-public.key" \
 "$PRIOR/source/bootstrap/cache/config.php"; do
 [[ ! -e "$item" && ! -L "$item" ]] || fail PRIVATE_SECRET_OR_CACHE_PRESENT
done

TMP="$(mktemp -d /root/Bambodia-MCP-TwoBlade-Review.XXXXXXXX)"
WORK=""
SUCCESS=0
cleanup(){
 if [[ "$TMP" == /root/Bambodia-MCP-TwoBlade-Review.* &&
       -d "$TMP" && ! -L "$TMP" ]]; then
   rm -rf --one-file-system -- "$TMP"
 fi
 if [[ "$SUCCESS" == 0 && "$WORK" == /root/Bambodia-MCP-Overlay-TwoBlade.* &&
       -d "$WORK" && ! -L "$WORK" ]]; then
   rm -rf --one-file-system -- "$WORK"
 fi
}
trap cleanup EXIT
cat > "$TMP/verify.py" <<'PY'
from pathlib import Path
import hashlib
import json
import os
import shutil
import stat
import sys
import tempfile

live, prior = map(Path, sys.argv[1:3])
mode = sys.argv[3]
new = Path(sys.argv[4]) if mode == "prepare" else None

two = {
    "resources/views/home.blade.php",
    "resources/views/layouts/main.blade.php",
}
six = {
    "bootstrap/app.php": "48b931f09aa0c21cc05705396a06272f85b2be8d",
    "app/Http/Middleware/RequireMcpOAuthResource.php": "ae8922d4a1a82a5dabf11df4877d5aab8fb8093d",
    "app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php": "641e625f4a12765a3e8da54916235ccb7b337835",
    "config/mcp.php": "215d9b15714e0a6b687191bdab44af54ace10450",
    "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php": "290f3d66998315235c9c6350e3b878d458344331",
    "tests/Feature/BambodiaMcpOAuthTest.php": "725a067ce74802cec15169eab87beab051c7e3ca",
}
critical = {
    "app/Http/Controllers/Api/BambodiaMcpController.php",
    "app/Http/Middleware/SetLocale.php",
    "app/Models/User.php",
    "app/Providers/AppServiceProvider.php",
    "app/Support/Mcp/BambodiaTools.php",
    "config/auth.php",
    "resources/views/developer/index.blade.php",
    "resources/views/layouts/workspace.blade.php",
    "routes/web.php",
}
def fail(code):
    print("MCP_TWO_BLADE_REBASE_BLOCKED=" + code)
    raise SystemExit(2)
def safe(path):
    for ancestor in (path, *path.parents):
        if ancestor == Path("/"):
            break
        if ancestor.is_symlink():
            fail("SOURCE_PATH_SYMLINK")
    try:
        mode = path.lstat().st_mode
    except OSError:
        fail("SOURCE_FILE_MISSING")
    if not stat.S_ISREG(mode):
        fail("SOURCE_SPECIAL_FILE")
    return path.read_bytes()
def digest(data):
    return hashlib.sha256(data).hexdigest()
def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + bytes([0]) + data).hexdigest()
def read_json(path):
    try:
        return json.loads(safe(path))
    except (ValueError, TypeError):
        fail("REVIEW_JSON_INVALID")
def inventory(root):
    if root.is_symlink() or not root.is_dir():
        fail("VITE_ROOT_UNSAFE")
    result={}
    for path in root.rglob("*"):
        if path.is_dir() and not path.is_symlink():
            continue
        if path.is_symlink() or not path.is_file():
            fail("VITE_FILE_UNSAFE")
        rel=path.relative_to(root).as_posix()
        if rel.startswith(".env") or path.suffix.lower()==".php":
            fail("VITE_NAME_UNSAFE")
        result[rel]=digest(safe(path))
        if len(result)>15000:
            fail("VITE_SIZE_UNSAFE")
    return result

refresh=read_json(prior/"review/20261113-refresh-evidence.json")
sync=read_json(prior/"review/mcp-oauth-security-sync-20261113.json")
paths=refresh.get("updated_live_paths",[])+refresh.get("added_live_paths",[])
if (len(paths)!=11 or len(set(paths))!=11
        or refresh.get("release_authorized") is not False
        or sync.get("github_oauth_security_sync")!="PASSED"
        or sync.get("source_git_commit")!="396614486e3b7e50a28cd42932af6b4aa6536cfd"
        or sync.get("private_candidate_deployable") is not False
        or sync.get("production_changed") is not False):
    fail("SOURCE_EVIDENCE_CHANGED")
expected=refresh.get("source_sha256")
if not isinstance(expected,dict) or set(expected)!=set(paths):
    fail("FRONTEND_EVIDENCE_INVALID")

new_hashes={}
for rel in paths:
    baseline=expected[rel]
    if not isinstance(baseline,str) or len(baseline)!=64:
        fail("BASELINE_DIGEST_INVALID")
    private_data=safe(prior/"source"/rel)
    live_data=safe(live/rel)
    if digest(private_data)!=baseline:
        fail("PRIVATE_FRONTEND_BASELINE_CHANGED")
    current=digest(live_data)
    if rel in two:
        if current==baseline:
            fail("EXPECTED_NEW_LIVE_BLADE_CHANGE_MISSING")
        new_hashes[rel]=current
    elif current!=baseline:
        fail("UNAPPROVED_FRONTEND_DRIFT")
print("MCP_TWO_BLADE_CHANGED_LIVE=2")
print("MCP_TWO_BLADE_OTHER_FRONTEND=9_UNCHANGED")

records=sync.get("source_files")
if not isinstance(records,dict) or set(records)!=set(six):
    fail("OAUTH_SYNC_INVENTORY_INVALID")
for rel,pin in six.items():
    if blob(safe(prior/"source"/rel))!=pin or records[rel].get("approved_git_blob")!=pin:
        fail("SIX_OAUTH_SECURITY_SOURCES_CHANGED")
print("MCP_TWO_BLADE_OAUTH_SOURCES=6_VERIFIED")

for rel in critical:
    if safe(prior/"review/live-critical"/rel)!=safe(live/rel):
        fail("CRITICAL_LIVE_SNAPSHOT_CHANGED")
print("MCP_TWO_BLADE_PROTECTED_LIVE=9_UNCHANGED")

assets=refresh.get("live_build_sha256")
if (not isinstance(assets,dict) or len(assets)!=23 or
    inventory(live/"public/build")!=assets or
    inventory(prior/"source/public/build")!=assets):
    fail("VITE_BUILD_CHANGED")
print("MCP_TWO_BLADE_VITE=23_UNCHANGED")

if mode=="inspect":
    print("MCP_TWO_BLADE_PREFLIGHT=PASS")
    print("PRODUCTION_AND_PRIOR_PRIVATE=UNCHANGED")
    raise SystemExit(0)
if mode!="prepare" or new is None:
    fail("INVALID_MODE")
if not new.is_dir() or new.is_symlink():
    fail("PRIVATE_DESTINATION_UNSAFE")
if not (new/"source").is_dir() or not (new/"review").is_dir():
    fail("PRIVATE_DESTINATION_INCOMPLETE")
for rel in paths:
    if digest(safe(new/"source"/rel))!=expected[rel]:
        fail("NEW_COPY_DIVERGED_BEFORE_EDIT")
for rel,pin in six.items():
    if blob(safe(new/"source"/rel))!=pin:
        fail("NEW_COPY_OAUTH_SECURITY_CHANGED")
if inventory(new/"source/public/build")!=assets:
    fail("NEW_COPY_VITE_DIVERGED")

old_record=new/"review/20261113-refresh-evidence.json"
prior_record=new/"review/20261113-initial-refresh-evidence.json"
if prior_record.exists() or prior_record.is_symlink():
    fail("INITIAL_REFRESH_BACKUP_COLLISION")
shutil.copy2(old_record,prior_record)
if safe(prior_record)!=safe(old_record):
    fail("ORIGINAL_REVIEW_RECORD_COPY_FAILED")
archive=new/"review/pre-two-blade-refresh"
if archive.exists() or archive.is_symlink():
    fail("TWO_BLADE_SNAPSHOT_COLLISION")

archive.mkdir(mode=0o700)
for rel in sorted(two):
    source=new/"source"/rel
    before=safe(source)
    snapshot=archive/rel
    snapshot.parent.mkdir(parents=True,exist_ok=True)
    snapshot.write_bytes(before)
    snapshot.chmod(0o600)
    if safe(snapshot)!=before:
        fail("TEMPLATE_SNAPSHOT_FAILED")
    live_now=safe(live/rel)
    if digest(live_now)!=new_hashes[rel]:
        fail("LIVE_TEMPLATE_CHANGED_DURING_REFRESH")
    fd,tmp=tempfile.mkstemp(prefix=".mcp-two-blade-",dir=source.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"wb") as stream:
            stream.write(live_now)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp,source)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)
    if digest(safe(source))!=new_hashes[rel]:
        fail("NEW_PRIVATE_TEMPLATE_COPY_MISMATCH")

for rel in paths:
    expected_current=new_hashes.get(rel,expected[rel])
    if (digest(safe(new/"source"/rel))!=expected_current
        or digest(safe(live/rel))!=expected_current):
        fail("POSTCOPY_FRONTEND_DRIFT")
for rel,pin in six.items():
    if blob(safe(new/"source"/rel))!=pin:
        fail("POSTCOPY_OAUTH_DRIFT")
if (inventory(new/"source/public/build")!=assets
    or inventory(live/"public/build")!=assets):
    fail("POSTCOPY_VITE_DRIFT")
for rel in critical:
    if safe(new/"review/live-critical"/rel)!=safe(live/rel):
        fail("POSTCOPY_CRITICAL_LIVE_DRIFT")

updated=dict(refresh)
updated["source_sha256"]=dict(expected)
updated["source_sha256"].update(new_hashes)
updated["two_blade_catchup_review"]="CURRENT_LIVE_ONLY_PRIVATE_COPY"
updated["two_blade_catchup_paths"]=sorted(two)
updated["prior_refresh_sha256"]=digest(safe(prior_record))
updated["release_authorized"]=False
updated["new_oauth_source_security_review"]="SIX_FILE_SYNC_VERIFIED_SEPARATELY"
updated["production_backup_restore_review"]="PENDING"

evidence={
    "operation":"private-only-two-template-rebase",
    "previous_candidate":str(prior),
    "new_candidate":str(new),
    "updated_paths":sorted(two),
    "prior_sha256":{rel:expected[rel] for rel in sorted(two)},
    "updated_sha256":new_hashes,
    "vite_files_unchanged":23,
    "oauth_security_files_unchanged":6,
    "protected_live_snapshots_unchanged":9,
    "production_modified":False,
    "prior_candidate_modified":False,
    "release_authorized":False,
    "independent_php85_http_acceptance":"PENDING",
    "independent_device_browser_acceptance":"PENDING",
    "independent_backup_restore_and_rollback":"PENDING",
}
def write_json_atomic(path,obj):
    data=(json.dumps(obj,sort_keys=True,indent=2)+"\n").encode()
    fd,tmp=tempfile.mkstemp(prefix=".mcp-review-",dir=path.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)
write_json_atomic(old_record,updated)
write_json_atomic(new/"review/20261113-two-blade-catchup.json",evidence)
if read_json(old_record).get("source_sha256")!=updated["source_sha256"]:
    fail("UPDATED_REVIEW_EVIDENCE_MISMATCH")
print("MCP_TWO_BLADE_NEW_PRIVATE_CANDIDATE=PASS")
print("MCP_TWO_BLADE_FILES_COPIED=2")
print("MCP_TWO_BLADE_PRIOR_CANDIDATE=UNCHANGED")
print("MCP_TWO_BLADE_CANDIDATE_DEPLOYABLE=NO")
print("MCP_TWO_BLADE_INDEPENDENT_HTTP_QA=PENDING")
print("MCP_TWO_BLADE_REVIEW_REPORT="+str(new/"review/20261113-two-blade-catchup.json"))
print("PRODUCTION_SOURCE_DATABASE_NGINX_KEYS=UNCHANGED")
PY

echo "========== READ-ONLY TWO-TEMPLATE PREFLIGHT =========="
python3 "$TMP/verify.py" "$LIVE" "$PRIOR" inspect
echo "========== CONFIRM PRIVATE-ONLY COPY =========="
[[ -c /dev/tty ]] || fail OPERATOR_TTY_REQUIRED
printf 'Type CATCHUP-TWO-BLADE-PRIVATE-ONLY to create a NEW private candidate: ' > /dev/tty
IFS= read -r RESPONSE < /dev/tty
[[ "$RESPONSE" == "CATCHUP-TWO-BLADE-PRIVATE-ONLY" ]] || fail OPERATOR_DECLINED
unset RESPONSE

USED="$(du -sk "$PRIOR" | awk '{print $1}')"
FREE="$(df -Pk /root | awk 'END {print $4}')"
[[ "$USED" =~ ^[0-9]+$ && "$FREE" =~ ^[0-9]+$ ]] || fail DISK_METRICS_INVALID
(( USED < 2400000 && FREE > USED + 524288 )) || fail DISK_CAPACITY_UNSAFE
WORK="$(mktemp -d /root/Bambodia-MCP-Overlay-TwoBlade.XXXXXXXX)"
chmod 700 "$WORK"
echo "MCP_TWO_BLADE_NEW_WORKSPACE=$WORK"
cp -a --reflink=auto -- "$PRIOR/." "$WORK/" || fail PRIVATE_CLONE_FAILED
python3 "$TMP/verify.py" "$LIVE" "$PRIOR" prepare "$WORK"
SUCCESS=1
echo "MCP_TWO_BLADE_PRODUCTION_DEPLOYMENT=NOT_STARTED"
echo "MCP_TWO_BLADE_MCP_ACTIVATION=NOT_STARTED"
