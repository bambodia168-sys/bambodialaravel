#!/usr/bin/env bash
# No-change reconciliation of LIVE bambodia.com source against PRIVATE MCP candidate.
# Reports only relative source filenames, sizes and hashes. Never prints source text,
# .env, user data, database secrets or protected media. Does NOT copy assets.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == --inspect ]] || { echo 'Usage: bash script --inspect' >&2; exit 64; }

APP=/www/wwwroot/bambodia-staging-qa
STAGE=/root/Bambodia-MCP-Main-Source.tpE9k1zN
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
VHOST_SHA=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
LIVE_COMPOSER=457992551f4f234ad442d9db5ad73c8b54689e7b
LIVE_LOCK=ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03
STAGED_COMPOSER=fc687c2f0f82e034af38b6183b0c58502a0c7948
STAGED_LOCK=4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8
fail(){ printf 'MCP_LIVE_DRIFT_REVIEW_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in git python3 sha256sum stat; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
  -f "$APP/storage/app/installed.lock" ]] || fail LIVE_APP_ROOT_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHOST_SHA" ]] || fail VHOST_CHANGED
[[ -f "$APP/composer.json" && ! -L "$APP/composer.json" &&
  "$(git hash-object "$APP/composer.json")" == "$LIVE_COMPOSER" ]] || fail LIVE_MANIFEST_CHANGED
[[ -f "$APP/composer.lock" && ! -L "$APP/composer.lock" &&
  "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LIVE_LOCK" ]] || fail LIVE_LOCK_CHANGED
[[ -d "$STAGE" && ! -L "$STAGE" &&
  "$(stat -c '%u:%a' "$STAGE")" == "0:700" &&
  -d "$STAGE/source" && ! -L "$STAGE/source" ]] || fail PRIVATE_STAGING_CHANGED
[[ -f "$STAGE/source/composer.json" &&
  "$(git hash-object "$STAGE/source/composer.json")" == "$STAGED_COMPOSER" ]] || fail STAGED_MANIFEST_CHANGED
[[ -f "$STAGE/source/composer.lock" &&
  "$(sha256sum "$STAGE/source/composer.lock" | cut -d' ' -f1)" == "$STAGED_LOCK" ]] || fail STAGED_LOCK_CHANGED
[[ ! -e "$STAGE/source/public/build" ]] || fail PRIVATE_BUILD_ALREADY_COPIED_REVIEW_REQUIRED

echo '========== COMPARE LIVE VITE INPUTS WITH PRIVATE SOURCE =========='
python3 - "$APP" "$STAGE/source" "$VHOST" <<'PY'
import difflib, hashlib, json, pathlib, re, stat, sys
live,stage,vhost=map(pathlib.Path,sys.argv[1:])
active="\n".join(line for line in vhost.read_text().splitlines() if not line.lstrip().startswith("#"))
roots=[x.strip() for x in re.findall(r"(?m)^\s*root\s+([^;]+);",active)]
if roots!=["/www/wwwroot/bambodia-staging-qa/public"]:
    raise SystemExit("MCP_LIVE_DRIFT_REVIEW_BLOCKED=ACTUAL_VHOST_ROOT_CHANGED")

def hashfile(p):
    h=hashlib.sha256()
    with p.open("rb") as f:
        for chunk in iter(lambda:f.read(1024*1024),b""): h.update(chunk)
    return h.hexdigest()

vite=[
    "resources/css/app.css",
    "resources/js/app.js",
    "vite.config.js",
    "package.json"
]
for rel in vite:
    a,b=live/rel,stage/rel
    if not (a.is_file() and b.is_file() and not a.is_symlink() and not b.is_symlink()):
        raise SystemExit("MCP_LIVE_DRIFT_REVIEW_BLOCKED=VITE_INPUT_MISSING_OR_SYMLINK_"+rel)
    sa,sb=a.stat().st_size,b.stat().st_size
    h1,h2=hashfile(a),hashfile(b)
    print("VITE_INPUT="+rel)
    print("  LIVE_BYTES="+str(sa)+" LIVE_SHA256="+h1)
    print("  STAGED_BYTES="+str(sb)+" STAGED_SHA256="+h2)
    print("  EXACT_MATCH="+("YES" if h1==h2 else "NO"))
    if rel.endswith("app.css") and h1!=h2 and max(sa,sb)<2_000_000:
        la=a.read_text(encoding="utf-8",errors="replace").splitlines()
        lb=b.read_text(encoding="utf-8",errors="replace").splitlines()
        ops=difflib.SequenceMatcher(None,lb,la,autojunk=True).get_opcodes()
        changed=[(i1,i2,j1,j2) for kind,i1,i2,j1,j2 in ops if kind!="equal"]
        print("  CSS_CHANGE_BLOCKS="+str(len(changed)))
        print("  CSS_LINES_ONLY_IN_LIVE_OR_CHANGED="+str(sum(j2-j1 for i1,i2,j1,j2 in changed)))
        print("  CSS_LINES_ONLY_IN_STAGED_OR_CHANGED="+str(sum(i2-i1 for i1,i2,j1,j2 in changed)))

print("========== COMPARE PHP AND VIEW SOURCE WITHOUT READING SECRETS ==========")
roots=("app","config","routes","resources","database/migrations","public/css","public/js")
skip_dir={"vendor","node_modules",".git","storage","cache","build"}
def inventory(root):
    collected={}
    unsafe=[]
    for relroot in roots:
        d=root/relroot
        if not d.exists(): continue
        if d.is_symlink(): unsafe.append(relroot); continue
        for p in d.rglob("*"):
            rel=p.relative_to(root)
            if any(x in skip_dir or x.startswith(".env") for x in rel.parts):
                continue
            mode=p.lstat().st_mode
            if stat.S_ISREG(mode):
                collected[rel.as_posix()]=hashfile(p)
            elif not stat.S_ISDIR(mode):
                unsafe.append(rel.as_posix())
    return collected,unsafe
la,unsafe_l=inventory(live)
sa,unsafe_s=inventory(stage)
if unsafe_l or unsafe_s:
    print("SOURCE_UNSAFE_SPECIAL_FILES_COUNT="+str(len(unsafe_l)+len(unsafe_s)))
    print("SOURCE_AUDIT=BLOCKED_UNSAFE_SPECIAL_FILES")
    raise SystemExit(2)
both=set(la)&set(sa)
changed=sorted(p for p in both if la[p]!=sa[p])
live_only=sorted(set(la)-set(sa))
stage_only=sorted(set(sa)-set(la))
print("SOURCE_SHARED_FILES="+str(len(both)))
print("SOURCE_IDENTICAL_FILES="+str(len(both)-len(changed)))
print("SOURCE_CHANGED_FILES="+str(len(changed)))
print("SOURCE_LIVE_ONLY_FILES="+str(len(live_only)))
print("SOURCE_STAGE_ONLY_FILES="+str(len(stage_only)))
for tag,items in (
    ("SOURCE_CHANGED_PATH",changed),
    ("SOURCE_LIVE_ONLY_PATH",live_only),
    ("SOURCE_STAGE_ONLY_PATH",stage_only)
):
    for p in items[:40]: print(tag+"="+p)
    if len(items)>40: print(tag+"_REMAINING="+str(len(items)-40))
print("SOURCE_RECONCILIATION="+("EXACT" if not changed and not live_only and not stage_only else "REQUIRED_BEFORE_PRODUCTION_INSTALL"))

build=live/"public/build"
print("LIVE_BUILD_DIRECTORY="+("PRESENT" if build.is_dir() and not build.is_symlink() else "MISSING_OR_UNSAFE"))
manifest=build/"manifest.json"
if manifest.is_file() and not manifest.is_symlink():
    try:
        obj=json.loads(manifest.read_text())
        entries=("resources/css/app.css","resources/js/app.js")
        print("LIVE_BUILD_MANIFEST=JSON_VALID" if isinstance(obj,dict) else "LIVE_BUILD_MANIFEST=INVALID_SHAPE")
        if isinstance(obj,dict):
            for entry in entries:
                print("LIVE_BUILD_ENTRY_"+entry.replace("/","_")+"="+("YES" if entry in obj else "NO"))
    except (ValueError,UnicodeError,OSError):
        print("LIVE_BUILD_MANIFEST=UNREADABLE")
else:
    print("LIVE_BUILD_MANIFEST=MISSING_OR_UNSAFE")
print("PRIVATE_ASSET_REUSE=NOT_ATTEMPTED")
print("PRIVATE_SOURCE_MODIFICATION=NONE")
print("PRODUCTION_SOURCE_ASSETS_DATABASE=UNCHANGED")
print("PRODUCTION_NGINX=UNCHANGED")
print("MCP_LIVE_DRIFT_INSPECTION=COMPLETE")
PY
