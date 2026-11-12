#!/usr/bin/env bash
# Reuse ONLY current live Vite assets, IF exact live and release frontend
# sources match approved main. Copy is INTO PRIVATE CANDIDATE ONLY.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == --reuse ]] || { echo "Usage: bash script --reuse" >&2; exit 64; }
APP=/www/wwwroot/bambodia-staging-qa
STAGE=/root/Bambodia-MCP-Main-Source.tpE9k1zN
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
VHOST_SHA=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
BASE_JSON=457992551f4f234ad442d9db5ad73c8b54689e7b
BASE_LOCK=ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03
NEW_JSON=fc687c2f0f82e034af38b6183b0c58502a0c7948
NEW_LOCK=4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8
fail(){ echo "MCP_ASSET_REUSE_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for c in git stat python3 sha256sum mktemp cp mv; do
  command -v "$c" >/dev/null || fail "MISSING_$c"
done
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
  -f "$APP/storage/app/installed.lock" ]] || fail LIVE_APP_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHOST_SHA" ]] || fail LIVE_VHOST_CHANGED
[[ -f "$APP/composer.json" && ! -L "$APP/composer.json" &&
  "$(git hash-object "$APP/composer.json")" == "$BASE_JSON" ]] || fail LIVE_MANIFEST_CHANGED
[[ -f "$APP/composer.lock" && ! -L "$APP/composer.lock" &&
  "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$BASE_LOCK" ]] || fail LIVE_LOCK_CHANGED
[[ -d "$STAGE" && ! -L "$STAGE" &&
  "$(stat -c '%u:%a' "$STAGE")" == "0:700" ]] || fail PRIVATE_STAGE_NOT_PROTECTED
[[ -d "$STAGE/source" && ! -L "$STAGE/source" &&
  -f "$STAGE/source/vendor/autoload.php" ]] || fail PRIVATE_SOURCE_VENDOR_MISSING
[[ -f "$STAGE/source/composer.json" && ! -L "$STAGE/source/composer.json" &&
  "$(git hash-object "$STAGE/source/composer.json")" == "$NEW_JSON" ]] || fail PRIVATE_MANIFEST_CHANGED
[[ -f "$STAGE/source/composer.lock" && ! -L "$STAGE/source/composer.lock" &&
  "$(sha256sum "$STAGE/source/composer.lock" | cut -d' ' -f1)" == "$NEW_LOCK" ]] || fail PRIVATE_LOCK_CHANGED
[[ ! -e "$STAGE/source/public/build" && ! -L "$STAGE/source/public/build" ]] ||
  fail PRIVATE_BUILD_ALREADY_EXISTS
[[ -d "$APP/public/build" && ! -L "$APP/public/build" &&
  -f "$APP/public/build/manifest.json" &&
  ! -L "$APP/public/build/manifest.json" ]] || fail LIVE_BUILD_NOT_FOUND

echo "========== VERIFY EXACT LIVE AND PRIVATE FRONTEND SOURCES =========="
for spec in \
  "resources/css/app.css:40c59724376cb324b83633eb79f8ddca652d0667" \
  "resources/js/app.js:4bc4f4096e02249cf2a0bcb10438139b324ae0c3" \
  "vite.config.js:1fd66d538c217d36db94b62968257a7d9a558bbb" \
  "package.json:13ec88546892c1fd790d2161d7226c14e7be0b54"; do
  IFS=: read -r relative expected <<< "$spec"
  [[ -f "$APP/$relative" && ! -L "$APP/$relative" &&
    "$(git hash-object "$APP/$relative")" == "$expected" ]] || fail "LIVE_SOURCE_DIFFERS_$relative"
  [[ -f "$STAGE/source/$relative" && ! -L "$STAGE/source/$relative" &&
    "$(git hash-object "$STAGE/source/$relative")" == "$expected" ]] || fail "PRIVATE_SOURCE_DIFFERS_$relative"
  echo "APP_SOURCE_EXACT_MATCH=$relative"
done
echo "MAIN_AND_RELEASE_VITE_INPUTS=BYTE_IDENTICAL"

echo "========== VALIDATE LIVE BUILD MANIFEST =========="
python3 - "$VHOST" "$APP/public/build" <<'PY'
import pathlib,json,stat,re,sys
v=pathlib.Path(sys.argv[1]); root=pathlib.Path(sys.argv[2])
active="\n".join(x for x in v.read_text().splitlines() if not x.lstrip().startswith("#"))
if [s.strip() for s in re.findall(r'(?m)^\s*root\s+([^;]+);',active)]!=[
  "/www/wwwroot/bambia-staging-qa/public".replace("bambia","bambodia")]:
    raise SystemExit("MCP_ASSET_REUSE_BLOCKED=LIVE_NGINX_ROOT_CHANGED")
def inspect(root):
    paths={};size=0
    for p in root.rglob("*"):
        mode=p.lstat().st_mode
        if not (stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
            raise ValueError("symlink or special build file")
        if stat.S_ISREG(mode):
            relative=p.relative_to(root).as_posix()
            if p.suffix.lower()==".php" or p.name.startswith(".env"):
                raise ValueError("unexpected file inside public build")
            size+=p.stat().st_size
            paths[relative]=p
            if size>800*1024*1024 or len(paths)>15000:
                raise ValueError("build exceeds size or count limits")
    if "manifest.json" not in paths:
        raise ValueError("missing build manifest")
    d=json.loads(paths["manifest.json"].read_text())
    if not isinstance(d,dict) or not d: raise ValueError("invalid build manifest")
    for src in ("resources/css/app.css","resources/js/app.js"):
        if src not in d or not isinstance(d[src],dict) or not d[src].get("isEntry"):
            raise ValueError("missing build entry "+src)
    for obj in d.values():
        if not isinstance(obj,dict): raise ValueError("invalid manifest row")
        references=([obj["file"]] if isinstance(obj.get("file"),str) else [])
        for k in ("css","assets"):
            values=obj.get(k,[])
            if not isinstance(values,list): raise ValueError("invalid manifest arrays")
            references+=values
        for name in references:
            if not isinstance(name,str) or name.startswith("/") or "\\" in name or ".." in pathlib.PurePosixPath(name).parts or name not in paths:
                raise ValueError("manifest refers to a missing/unsafe asset")
    return paths,size,len(d)
try: files,size,entries=inspect(root)
except (OSError,ValueError,UnicodeError) as ex:
    raise SystemExit("MCP_ASSET_REUSE_BLOCKED=INVALID_LIVE_BUILD: "+str(ex))
print("LIVE_VITE_MANIFEST=VERIFIED")
print("LIVE_VITE_MANIFEST_ENTRIES="+str(entries))
print("LIVE_PUBLIC_BUILD_FILES="+str(len(files)))
print("LIVE_PUBLIC_BUILD_BYTES="+str(size))
PY

[[ -c /dev/tty ]] || fail INTERACTIVE_APPROVAL_REQUIRED
printf 'Type REUSE-BAMBODIA-MCP-ASSETS to copy ONLY the approved live assets into PRIVATE staging: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == REUSE-BAMBODIA-MCP-ASSETS ]] || fail OPERATOR_DECLINED
unset APPROVAL

TEMP="$(mktemp -d "$STAGE/.asset-reuse.XXXXXXXX")"
chmod 700 "$TEMP"
echo "PRIVATE_ASSET_REVIEW_DIRECTORY=$TEMP"
cp -a -- "$APP/public/build" "$TEMP/build" || fail ASSET_COPY_FAILED
python3 - "$APP/public/build" "$TEMP/build" <<'PY'
import pathlib,hashlib,stat,sys
def hashes(root):
    d={}
    for p in pathlib.Path(root).rglob("*"):
        mode=p.lstat().st_mode
        if not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)):
            raise ValueError("unsafe symlink or special file")
        if stat.S_ISREG(mode):
            h=hashlib.sha256()
            with p.open("rb") as f:
                for buf in iter(lambda: f.read(1024*1024),b""): h.update(buf)
            d[p.relative_to(root).as_posix()]=h.hexdigest()
    return d
try:
    left=hashes(sys.argv[1]); right=hashes(sys.argv[2])
    if not left or left!=right: raise ValueError("copied build does not match")
except (ValueError,OSError) as ex:
    raise SystemExit("MCP_ASSET_REUSE_BLOCKED=BUILD_COPY_INTEGRITY: "+str(ex))
print("PRIVATE_BUILD_SHA256_TREE=EXACT_LIVE_MATCH")
print("PRIVATE_BUILD_FILES="+str(len(right)))
PY
mv -- "$TEMP/build" "$STAGE/source/public/build" || fail PRIVATE_BUILD_FINALIZE_FAILED
rmdir "$TEMP"
echo "PRIVATE_VITE_MANIFEST_SHA256=$(sha256sum "$STAGE/source/public/build/manifest.json" | cut -d' ' -f1)"
echo "FRONTEND_BUILD_ASSET_REUSE=PASS"
echo "NPM_REBUILD=NOT_PERFORMED"
echo "DEPLOYABLE_RELEASE=NOT_YET_APPROVED"
echo "PRODUCTION_APP_ASSETS_DATABASE=UNCHANGED"
echo "NGINX_AND_KEYS=UNCHANGED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
