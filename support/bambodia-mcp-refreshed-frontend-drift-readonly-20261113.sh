#!/usr/bin/env bash
# Bambodia refreshed private candidate: SHA256 FRONTEND DRIFT DIAGNOSTIC ONLY.
# No production writes, no database/secret reads, no patches, no deployment.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--inspect" ]] || {
  echo "Usage: bash $0 --inspect" >&2; exit 64;
}
fail() { printf 'MCP_FRONTEND_DRIFT_AUDIT_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for command in python3 sha256sum stat git; do
  command -v "$command" >/dev/null || fail REQUIRED_TOOL_MISSING
done
LIVE=/www/wwwroot/bambodia-staging-qa
PRIVATE=/root/Bambodia-MCP-Overlay-Refresh.hZDR51NJ
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf

[[ -d "$LIVE" && ! -L "$LIVE" && -f "$LIVE/artisan" &&
   -f "$LIVE/storage/app/installed.lock" ]] || fail LIVE_ROOT_CHANGED
[[ -d "$PRIVATE" && ! -L "$PRIVATE" &&
   "$(stat -c '%u:%a' "$PRIVATE")" == "0:700" &&
   -d "$PRIVATE/source" && ! -L "$PRIVATE/source" &&
   -d "$PRIVATE/review" && ! -L "$PRIVATE/review" ]] || fail PRIVATE_WORKSPACE_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
 fail NGINX_VHOST_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
 fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
 fail LIVE_LOCK_CHANGED
[[ "$(sha256sum "$PRIVATE/source/composer.lock" | awk '{print $1}')" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] ||
 fail PRIVATE_LOCK_CHANGED
for secret in "$PRIVATE/source/.env" "$PRIVATE/source/.env.testing" \
 "$PRIVATE/source/storage/oauth-private.key" "$PRIVATE/source/storage/oauth-public.key" \
 "$PRIVATE/source/bootstrap/cache/config.php"; do
 [[ ! -e "$secret" && ! -L "$secret" ]] || fail PRIVATE_SECRET_OR_CONFIG_CACHE_PRESENT
done

echo "MCP_FRONTEND_DRIFT_PREFLIGHT=PASS"
python3 - "$LIVE" "$PRIVATE" <<'PY'
from pathlib import Path
from collections import Counter
import hashlib
import json
import stat
import sys

live, private = map(Path, sys.argv[1:])
old = private / "review/20261113-refresh-evidence.json"
sync_path = private / "review/mcp-oauth-security-sync-20261113.json"
six = {
    "resources/css/app.css",
    "resources/js/app.js",
    "resources/views/content/blog.blade.php",
    "resources/views/content/show.blade.php",
    "resources/views/home.blade.php",
    "resources/views/layouts/main.blade.php",
}
five = {
    "public/css/footer-two-tone-themes.css",
    "public/css/home-hero-aurora.css",
    "resources/js/blog-premium-player.js",
    "resources/views/components/blog-card-featured-media.blade.php",
    "resources/views/components/blog-premium-native-video.blade.php",
}
expected_paths = six | five

def fail(label):
    print("MCP_FRONTEND_DRIFT_AUDIT_BLOCKED=" + label)
    sys.exit(2)

def read_regular(path):
    for part in (path, *path.parents):
        if part == Path("/"):
            break
        if part.is_symlink():
            fail("SYMLINK_IN_SOURCE_PATH")
    try:
        mode = path.lstat().st_mode
    except OSError:
        return None
    if not stat.S_ISREG(mode):
        fail("SPECIAL_SOURCE_FILE")
    return path.read_bytes()

def digest(data):
    return hashlib.sha256(data).hexdigest()

source_json = read_regular(old)
sync_json = read_regular(sync_path)
if source_json is None or sync_json is None:
    fail("REVIEW_EVIDENCE_MISSING")
try:
    report = json.loads(source_json)
    sync = json.loads(sync_json)
except (ValueError, TypeError):
    fail("INVALID_REVIEW_EVIDENCE")

if (set(report.get("updated_live_paths", [])) != six
    or set(report.get("added_live_paths", [])) != five
    or set(report.get("source_sha256", {})) != expected_paths
    or report.get("release_authorized") is not False
    or sync.get("github_oauth_security_sync") != "PASSED"
    or sync.get("private_candidate_deployable") is not False
    or sync.get("production_changed") is not False):
    fail("REVIEW_REPORT_CHANGED")

baseline = report["source_sha256"]
if any(not isinstance(baseline.get(p), str) or len(baseline[p]) != 64
       for p in expected_paths):
    fail("INVALID_BASELINE_DIGEST")

status = Counter()
for rel in sorted(expected_paths):
    current = read_regular(live / rel)
    candidate = read_regular(private / "source" / rel)
    live_state = ("MISSING" if current is None else
                  "BASELINE" if digest(current) == baseline[rel] else "CHANGED")
    private_state = ("MISSING" if candidate is None else
                     "BASELINE" if digest(candidate) == baseline[rel] else "CHANGED")
    status["live_" + live_state.lower()] += 1
    status["private_" + private_state.lower()] += 1
    if live_state != "BASELINE" or private_state != "BASELINE":
        same = ("YES" if current is not None and candidate is not None
                and current == candidate else "NO")
        print(f"MCP_FRONTEND_DRIFT_PATH={rel}|LIVE={live_state}|PRIVATE={private_state}|SAME_BYTES={same}")

print(f"MCP_FRONTEND_BASELINE_MATCH_LIVE={status['live_baseline']}/11")
print(f"MCP_FRONTEND_BASELINE_MATCH_PRIVATE={status['private_baseline']}/11")
print(f"MCP_FRONTEND_LIVE_CHANGED_OR_MISSING={11-status['live_baseline']}")
print(f"MCP_FRONTEND_PRIVATE_CHANGED_OR_MISSING={11-status['private_baseline']}")

build_expected = report.get("live_build_sha256")
if not isinstance(build_expected, dict) or len(build_expected) != 23:
    fail("VITE_EVIDENCE_INVALID")
if any(not isinstance(p,str) or not p or p.startswith("/")
       or ".." in Path(p).parts or not isinstance(v,str) or len(v)!=64
       for p,v in build_expected.items()):
    fail("VITE_BASELINE_INVALID")

def build_inventory(root):
    if not root.is_dir() or root.is_symlink():
        fail("VITE_BUILD_ROOT_UNSAFE")
    files = {}
    for path in root.rglob("*"):
        if path.is_dir() and not path.is_symlink():
            continue
        if path.is_symlink() or not path.is_file():
            fail("VITE_BUILD_UNSAFE_ENTRY")
        rel = path.relative_to(root).as_posix()
        if path.name.startswith(".env") or path.suffix.lower() == ".php":
            fail("VITE_BUILD_UNSAFE_NAME")
        contents = read_regular(path)
        if contents is None:
            fail("VITE_FILE_MISSING_DURING_READ")
        files[rel] = digest(contents)
        if len(files) > 15000:
            fail("VITE_BUILD_FILE_LIMIT")
    return files

live_assets = build_inventory(live / "public/build")
private_assets = build_inventory(private / "source/public/build")
for rel in sorted(build_expected):
    value = build_expected[rel]
    live_state = ("MISSING" if rel not in live_assets else
                  "BASELINE" if live_assets[rel]==value else "CHANGED")
    private_state = ("MISSING" if rel not in private_assets else
                     "BASELINE" if private_assets[rel]==value else "CHANGED")
    status["vite_live_" + live_state.lower()] += 1
    status["vite_private_" + private_state.lower()] += 1
    if live_state != "BASELINE" or private_state != "BASELINE":
        print(f"MCP_VITE_DRIFT_PATH={rel}|LIVE={live_state}|PRIVATE={private_state}")

extra_live = set(live_assets) - set(build_expected)
extra_private = set(private_assets) - set(build_expected)
print(f"MCP_VITE_LIVE_BASELINE_MATCH={status['vite_live_baseline']}/23")
print(f"MCP_VITE_PRIVATE_BASELINE_MATCH={status['vite_private_baseline']}/23")
print(f"MCP_VITE_LIVE_EXTRA_FILES={len(extra_live)}")
print(f"MCP_VITE_PRIVATE_EXTRA_FILES={len(extra_private)}")

live_drift = (status["live_baseline"] != 11 or
              status["vite_live_baseline"] != 23 or extra_live)
private_drift = (status["private_baseline"] != 11 or
                 status["vite_private_baseline"] != 23 or extra_private)
if not live_drift and not private_drift:
    reason = "NO_DRIFT_AT_INSPECTION"
elif live_drift and not private_drift:
    reason = "LIVE_ADVANCED_CANDIDATE_STILL_AT_CAPTURED_BASELINE"
elif private_drift and not live_drift:
    reason = "PRIVATE_CANDIDATE_DIVERGED_FROM_CAPTURED_BASELINE"
else:
    reason = "LIVE_AND_PRIVATE_DRIFT_REQUIRE_MANUAL_RECONCILIATION"
print("MCP_FRONTEND_DRIFT_DIAGNOSIS=" + reason)
print("MCP_FRONTEND_DRIFT_AUDIT=READ_ONLY_COMPLETE")
print("MCP_FRONTEND_DRIFT_FILES_COPIED=0")
print("PRODUCTION_SOURCE_DATABASE_NGINX_KEYS=UNCHANGED")
if live_drift or private_drift:
    print("REFRESHED_CANDIDATE_HTTP_QA_GATE=BLOCKED")
else:
    print("REFRESHED_CANDIDATE_HTTP_QA_GATE=SOURCE_BASELINE_MATCH")
PY
