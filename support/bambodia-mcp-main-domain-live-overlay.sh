#!/usr/bin/env bash
# Private, live-first MCP merge preparation; NO live writes or app execution.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == --assemble ]] || { echo 'Usage: bash script --assemble' >&2; exit 64; }
APP=/www/wwwroot/bambodia-staging-qa
STAGE=/root/Bambodia-MCP-Main-Source.tpE9k1zN
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
BASE=133f82a3e8681f3efbc8de9424a83dc3306e15d5
fail(){ echo "MCP_OVERLAY_BLOCKED=$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for bin in git gh python3 sha256sum stat cp mktemp; do command -v "$bin" >/dev/null || fail "MISSING_$bin"; done
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" ]] || fail LIVE_APP_CHANGED
[[ -f "$APP/storage/app/installed.lock" ]] || fail LIVE_NOT_INSTALLED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == 5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116 ]] || fail LIVE_VHOST_CHANGED
[[ "$(git hash-object "$APP/composer.json")" == 457992551f4f234ad442d9db5ad73c8b54689e7b ]] || fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03 ]] || fail LIVE_LOCK_CHANGED
[[ -d "$STAGE" && ! -L "$STAGE" &&
  "$(stat -c '%u:%a' "$STAGE")" == '0:700' ]] || fail PRIVATE_STAGE_CHANGED
[[ -f "$STAGE/source/vendor/autoload.php" ]] || fail PRIVATE_VENDOR_MISSING
[[ "$(git hash-object "$STAGE/source/composer.json")" == fc687c2f0f82e034af38b6183b0c58502a0c7948 ]] || fail STAGED_COMPOSER_CHANGED
[[ "$(sha256sum "$STAGE/source/composer.lock" | cut -d' ' -f1)" == 4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8 ]] || fail STAGED_LOCK_CHANGED
[[ ! -e "$STAGE/source/public/build" ]] || fail ORIGINAL_STAGE_ASSETS_UNEXPECTED

[[ -c /dev/tty ]] || fail OPERATOR_TTY_REQUIRED
printf 'Type PREPARE-MCP-LIVE-OVERLAY to merge ONLY inside a private workspace: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == PREPARE-MCP-LIVE-OVERLAY ]] || fail OPERATOR_DECLINED
unset APPROVAL

WORK="$(mktemp -d /root/Bambodia-MCP-Main-Overlay.XXXXXXXX)"
chmod 700 "$WORK"
echo "PRIVATE_OVERLAY_WORKSPACE=$WORK"
cp -a -- "$STAGE/source" "$WORK/source" || fail COPY_PRIVATE_STAGE_FAILED
mkdir -m 700 "$WORK/review"

python3 - "$APP" "$STAGE/source" "$WORK" "$BASE" "$VHOST" <<'PY'
import hashlib, json, os, pathlib, re, shutil, stat, subprocess, sys
live,stage,work=map(pathlib.Path,sys.argv[1:4])
base_commit,vhost=sys.argv[4:6]
text="\n".join(s for s in pathlib.Path(vhost).read_text().splitlines()
               if not s.lstrip().startswith("#"))
roots=[s.strip() for s in re.findall(r"(?m)^\s*root\s+([^;]+);",text)]
if roots != ["/www/wwwroot/bambodia-staging-qa/public"]:
    raise SystemExit("MCP_OVERLAY_BLOCKED=LIVE_VHOST_ROOT_CHANGED")
protected={
 "app/Http/Controllers/Api/BambodiaMcpController.php",
 "app/Support/Mcp/BambodiaTools.php",
 "app/Http/Middleware/SetLocale.php",
 "app/Models/User.php",
 "app/Providers/AppServiceProvider.php",
 "config/auth.php",
 "routes/web.php",
 "resources/views/layouts/workspace.blade.php",
 "resources/views/developer/index.blade.php",
}
base_sha={
 "app/Http/Middleware/SetLocale.php":"654afb37c4a91bc5cba7cd551442257f5bbd42a3",
 "app/Models/User.php":"bb4b77f5e47e52afc5143d172cc1839266276e78",
 "app/Providers/AppServiceProvider.php":"ff31661bc41088eaf96cb1c2ab7ff6fd1e4863a6",
 "config/auth.php":"d7568ff194267ec424aa84e2e9c0d2cbea42b8f8",
 "routes/web.php":"8d609196893732d2e7a4d064f3209d58cd532114",
 "resources/views/layouts/workspace.blade.php":"58a26b782a9507530e0f7e6078da9c5d273b36f9",
 "resources/views/developer/index.blade.php":"8fbed9f19647c11136eb27842c82e8154719af8c",
}
scan=("app","config","routes","resources","database/migrations","public/css","public/js")
ignored={"vendor","node_modules",".git","storage","cache","build"}
def sha(p):
    h=hashlib.sha256()
    with p.open("rb") as f:
        for buf in iter(lambda:f.read(1024*1024),b""):h.update(buf)
    return h.hexdigest()
def inventory(root):
    data={}
    for section in scan:
        directory=root/section
        if not directory.exists():continue
        if directory.is_symlink():raise ValueError("symlink directory "+section)
        for p in directory.rglob("*"):
            rel=p.relative_to(root)
            if any(x in ignored or x.startswith(".env") for x in rel.parts):continue
            mode=p.lstat().st_mode
            if stat.S_ISDIR(mode):continue
            if not stat.S_ISREG(mode):raise ValueError("unexpected special file "+str(rel))
            data[rel.as_posix()]=sha(p)
    return data
try:
    a,b=inventory(live),inventory(stage)
except (OSError,ValueError) as exc:
    raise SystemExit("MCP_OVERLAY_BLOCKED=UNSAFE_FILE: "+str(exc))
changed={p for p in a.keys()&b.keys() if a[p]!=b[p]}
only_live=set(a)-set(b)
only_stage=set(b)-set(a)
print("DRIFT_CHANGED_SHARED="+str(len(changed)))
print("DRIFT_LIVE_ONLY="+str(len(only_live)))
print("DRIFT_STAGE_ONLY="+str(len(only_stage)))
if (len(changed),len(only_live),len(only_stage))!=(39,2,26):
    raise SystemExit("MCP_OVERLAY_BLOCKED=LIVE_SOURCE_DRIFT_CHANGED_SINCE_APPROVAL")
if protected!=protected&changed:
    raise SystemExit("MCP_OVERLAY_BLOCKED=MCP_MERGE_TOUCHPOINTS_CHANGED")
if only_live!={"public/css/header-flag-scroll-top.css","public/js/site-identity-assets.js"}:
    raise SystemExit("MCP_OVERLAY_BLOCKED=UNEXPECTED_LIVE_ONLY_FILES")
(work/"review/source-drift.json").write_text(json.dumps({
    "changed":sorted(changed),"live_only":sorted(only_live),"stage_only":sorted(only_stage)},indent=2))
for name in sorted(changed|only_live):
    target=(work/"review/live-critical"/name) if name in protected else (work/"source"/name)
    target.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(live/name,target)
print("LIVE_UNRELATED_UPDATES_PRESERVED_IN_PRIVATE_OVERLAY="+str(len(changed-protected)+len(only_live)))
print("MCP_CRITICAL_LIVE_FILE_COPIES=9")
conflicts=[]
accepted=[]
for name,blob in base_sha.items():
    dest=work/"review/base"/name
    dest.parent.mkdir(parents=True,exist_ok=True)
    cmd=["gh","api","-H","Accept: application/vnd.github.raw+json",
         "repos/bambodia168-sys/bambodialaravel/contents/"+name+"?ref="+base_commit]
    with dest.open("wb") as output:
        fetch=subprocess.run(cmd,stdout=output,stderr=subprocess.PIPE,check=False)
    if fetch.returncode:
        raise SystemExit("MCP_OVERLAY_BLOCKED=BASE_DOWNLOAD_FAILED_"+name)
    actual=subprocess.run(["git","hash-object",str(dest)],capture_output=True,text=True,check=True).stdout.strip()
    if actual!=blob:
        raise SystemExit("MCP_OVERLAY_BLOCKED=BASE_GIT_BLOB_MISMATCH_"+name)
    current=work/"source"/name
    original=work/"review/live-critical"/name
    run=subprocess.run(["git","merge-file","-p","--diff3",
                        "-L","LIVE","-L","ORIGINAL_MAIN","-L","MCP_RELEASE",
                        str(original),str(dest),str(current)],
                       capture_output=True,check=False)
    if run.returncode or not run.stdout:
        conflict=work/"review/merge-conflicts"/name
        conflict.parent.mkdir(parents=True,exist_ok=True)
        conflict.write_bytes(run.stdout)
        conflicts.append(name)
        continue
    current.write_bytes(run.stdout)
    accepted.append(name)
print("MCP_THREE_WAY_AUTO_MERGED="+str(len(accepted)))
for name in accepted:print("MCP_AUTO_MERGED_PATH="+name)
print("MCP_THREE_WAY_CONFLICTS="+str(len(conflicts)))
for name in conflicts:print("MCP_CONFLICT_PATH="+name)
for name in sorted(protected-set(base_sha)):
    print("MCP_PREEXISTING_LIVE_ADDITION_NEEDS_MANUAL_MERGE="+name)
print("PRIVATE_MERGE_REVIEW_DIRECTORY="+str(work/"review"))

# Compile assets are reused only inside private overlay. After preserving the
# live CSS, the overlay has the same source inputs as the existing Vite build.
for name in ("resources/css/app.css","resources/js/app.js","vite.config.js","package.json"):
    if sha(live/name)!=sha(work/"source"/name):
        raise SystemExit("MCP_OVERLAY_BLOCKED=PRIVATE_FRONTEND_INPUT_DIFFERS_"+name)
build=live/"public/build"
if not build.is_dir() or build.is_symlink():
    raise SystemExit("MCP_OVERLAY_BLOCKED=LIVE_BUILD_MISSING")
def assets(root):
    names={}
    for p in root.rglob("*"):
        mode=p.lstat().st_mode
        if stat.S_ISDIR(mode):continue
        if not stat.S_ISREG(mode):raise ValueError("symlink or special build file")
        rel=p.relative_to(root).as_posix()
        if p.name.startswith(".env") or p.suffix.lower()==".php":
            raise ValueError("sensitive/executable public build file")
        names[rel]=sha(p)
        if len(names)>15000:raise ValueError("build file count")
    return names
try:
    live_assets=assets(build)
    if sum(p.stat().st_size for p in build.rglob("*") if p.is_file())>800*1024*1024:
        raise ValueError("build size limit")
    manifest=json.loads((build/"manifest.json").read_text())
    for name in ("resources/css/app.css","resources/js/app.js"):
        if not isinstance(manifest.get(name),dict) or not manifest[name].get("isEntry"):
            raise ValueError("required Vite entry missing")
    for row in manifest.values():
        if not isinstance(row,dict):raise ValueError("invalid Vite manifest row")
        refs=([row["file"]] if isinstance(row.get("file"),str) else [])
        for field in ("css","assets"):
            if not isinstance(row.get(field,[]),list):raise ValueError("invalid manifest list")
            refs+=row.get(field,[])
        for ref in refs:
            if not isinstance(ref,str) or ref.startswith("/") or ".." in pathlib.PurePosixPath(ref).parts or ref not in live_assets:
                raise ValueError("manifest missing/unsafe asset")
    target=work/"source/public/build"
    if target.exists():raise ValueError("private build already exists")
    shutil.copytree(build,target,symlinks=False)
    if assets(target)!=live_assets:raise ValueError("copied Vite hashes mismatch")
except (ValueError,OSError,TypeError) as exc:
    raise SystemExit("MCP_OVERLAY_BLOCKED=LIVE_VITE_REUSE_FAILED: "+str(exc))
print("PRIVATE_VITE_ASSETS=EXACT_LIVE_SHA256_COPY")
print("PRIVATE_VITE_ASSET_FILES="+str(len(live_assets)))
print("PRIVATE_SOURCE_OVERLAY=PREPARED_WITH_MERGE_REVIEW")
print("DEPLOYABLE_RELEASE=NO")
print("PRODUCTION_SOURCE_ASSETS_MYSQL_OAUTH_KEYS=UNCHANGED")
print("NGINX=UNCHANGED")
print("MCP_PRODUCTION_ACTIVATION=NOT_STARTED")
PY
echo "PRIVATE_OVERLAY_WORKSPACE=$WORK"
echo "MCP_PRIVATE_LIVE_OVERLAY=COMPLETE_WITH_REVIEW_REQUIRED"
