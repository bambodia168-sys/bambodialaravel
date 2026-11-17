#!/usr/bin/env bash
# Resolve ONLY the private workspace layout conflict by adding the MCP
# page-scoped style hook to the actual live layout, then report exact relevant
# source differences for the remaining MCP integration. No live writes.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--review" ]] || {
  echo "Usage: bash script --review" >&2
  exit 64
}
OVERLAY=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
LIVE=/www/wwwroot/bambodia-staging-qa
STAGE=/root/Bambodia-MCP-Main-Source.tpE9k1zN
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
fail(){ echo "MCP_PRIVATE_RECONCILIATION_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in python3 git sha256sum stat; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$OVERLAY" && ! -L "$OVERLAY" &&
  "$(stat -c '%u:%a' "$OVERLAY")" == '0:700' ]] || fail OVERLAY_MISSING_OR_UNSAFE
[[ -d "$STAGE" && ! -L "$STAGE" &&
  "$(stat -c '%u:%a' "$STAGE")" == '0:700' ]] || fail PINNED_SOURCE_STAGE_MISSING
[[ -f "$LIVE/artisan" && -f "$LIVE/storage/app/installed.lock" &&
  ! -L "$LIVE" ]] || fail LIVE_INSTALL_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == 5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116 ]] ||
  fail LIVE_VHOST_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == 457992551f4f234ad442d9db5ad73c8b54689e7b ]] ||
  fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | cut -d' ' -f1)" == ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03 ]] ||
  fail LIVE_LOCK_CHANGED
[[ "$(sha256sum "$OVERLAY/source/composer.lock" | cut -d' ' -f1)" == 4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8 ]] ||
  fail OVERLAY_LOCK_CHANGED
[[ -f "$OVERLAY/source/vendor/autoload.php" ]] || fail OVERLAY_VENDOR_MISSING

echo "========== INSPECT PRIVATE MERGE ARTIFACTS =========="
python3 - "$OVERLAY" "$LIVE" "$STAGE" <<'PY'
import difflib,hashlib,pathlib,re,stat,sys
overlay, live, stage=map(pathlib.Path,sys.argv[1:])
layout="resources/views/layouts/workspace.blade.php"
routes="routes/web.php"
classes=("app/Http/Controllers/Api/BambodiaMcpController.php",
         "app/Support/Mcp/BambodiaTools.php")
for rel in (layout,routes,*classes):
    a=overlay/"review/live-critical"/rel
    b=overlay/"source"/rel
    c=live/rel
    if not all(p.is_file() and not p.is_symlink() for p in (a,b,c)):
        raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=REVIEW_FILE_MISSING_"+rel)
    if a.read_bytes()!=c.read_bytes():
        raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=LIVE_CRITICAL_FILE_CHANGED_"+rel)
for rel in (layout,routes):
    conflict=overlay/"review/merge-conflicts"/rel
    if not conflict.is_file() or conflict.is_symlink():
        raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=MERGE_CONFLICT_FILE_MISSING_"+rel)
current=overlay/"source"/layout
original=overlay/"review/live-critical"/layout
stage_layout=stage/"source"/layout
if current.read_bytes()!=stage_layout.read_bytes():
    raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=LAYOUT_SOURCE_ALREADY_EDITED_REVIEW")
raw=original.read_bytes()
hook=b"@stack('workspace-head')"
anchor=b"@include('layouts.partials.site-typography')"
if raw.count(anchor)!=1 or raw.count(hook)>1:
    raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=LIVE_LAYOUT_ANCHOR_UNEXPECTED")
if b"<<<<<<<" in raw or b">>>>>>>" in raw:
    raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=LIVE_LAYOUT_HAS_CONFLICT_MARKERS")
if not raw.endswith(b"\n"):
    raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=UNEXPECTED_LAYOUT_NEWLINE")
if hook in raw:
    merged=raw
    result="EXISTING_LIVE_HOOK_PRESERVED"
else:
    merged=raw.replace(anchor,anchor+b"\n  {{-- MCP page-scoped styles; preserves current workspace layout. --}}\n  "+hook,1)
    result="INSERTED_ONLY_PAGE_SCOPED_STYLESHEET_HOOK"
if merged.count(hook)!=1:
    raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=LAYOUT_HOOK_COUNT_INVALID")
# All existing live bytes must occur unchanged in the merged output, except
# a single inserted segment immediately after the already-verified anchor.
if merged.replace(b"\n  {{-- MCP page-scoped styles; preserves current workspace layout. --}}\n  "+hook,b"",1)!=raw and merged!=raw:
    raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=LAYOUT_PRESERVATION_INVARIANT_FAILED")
current.write_bytes(merged)
print("WORKSPACE_LAYOUT_MERGE="+result)
print("LIVE_LAYOUT_OTHER_BYTES=PRESERVED")
print("PRIVATE_LAYOUT_SHA256="+hashlib.sha256(merged).hexdigest())
print("LIVE_LAYOUT_SHA256="+hashlib.sha256(raw).hexdigest())

print("========== REMAINING CONFLICT BLOCKS (SOURCE ONLY) ==========")
for rel in (routes,):
    p=overlay/"review/merge-conflicts"/rel
    lines=p.read_text(errors="replace").splitlines()
    starts=[i for i,line in enumerate(lines) if line.startswith("<<<<<<< ")]
    print("CONFLICT_FILE="+rel)
    print("CONFLICT_BLOCK_COUNT="+str(len(starts)))
    print("CONFLICT_MERGED_LINES="+str(len(lines)))
    if not starts:
        raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=ROUTE_CONFLICT_ARTIFACT_NO_MARKERS")
    for n,start in enumerate(starts[:8],1):
        end=next((j for j in range(start+1,len(lines)) if lines[j].startswith(">>>>>>> ")),-1)
        if end<0: raise SystemExit("MCP_PRIVATE_RECONCILIATION_BLOCKED=INCOMPLETE_CONFLICT_MARKERS")
        block=lines[max(0,start-3):min(len(lines),end+4)]
        print("CONFLICT_BLOCK_"+str(n)+"_LINES="+str(len(block)))
        # Show only a focused source excerpt; do not print environment or DB files.
        if len(block)>105:
            block=block[:50]+["[... conflict block shortened; full review remains private ...]"]+block[-50:]
        for line in block:
            print("  "+line[:260])

print("========== LIVE VS MCP SOURCE FOR TWO PREEXISTING CLASSES ==========")
for rel in classes:
    a=(overlay/"review/live-critical"/rel).read_text(errors="replace").splitlines()
    b=(stage/"source"/rel).read_text(errors="replace").splitlines()
    diff=list(difflib.unified_diff(a,b,fromfile="LIVE/"+rel,tofile="MCP/"+rel,n=2,lineterm=""))
    print("MCP_CLASS="+rel)
    print("LIVE_LINES="+str(len(a)))
    print("MCP_LINES="+str(len(b)))
    print("UNIFIED_DIFF_TOTAL_LINES="+str(len(diff)))
    # Pinpoint code changed in the live version, without full file dumps.
    shown=diff[:160]
    for line in shown:
        print(line[:260])
    if len(diff)>160: print("MCP_DIFF_OUTPUT_TRUNCATED_LINES="+str(len(diff)-160))
    methods=lambda lines: sorted(set(re.findall(r"\bfunction\s+([A-Za-z_][A-Za-z_0-9]*)\s*\(", "\n".join(lines))))
    live_methods=set(methods(a))
    mcp_methods=set(methods(b))
    print("MCP_METHODS_ONLY_LIVE="+(",".join(sorted(live_methods-mcp_methods)) or "NONE"))
    print("MCP_METHODS_ONLY_RELEASE="+(",".join(sorted(mcp_methods-live_methods)) or "NONE"))

print("MCP_CRITICAL_PRIVATE_REVIEW=COMPLETE")
print("ROUTES_RECONCILIATION=PENDING")
print("PREEXISTING_MCP_CLASSES_RECONCILIATION=PENDING")
print("DEPLOYABLE_RELEASE=NO")
print("PRODUCTION_SOURCE_ENV_KEYS_DATABASE=UNCHANGED")
print("PRODUCTION_NGINX=UNCHANGED")
PY
