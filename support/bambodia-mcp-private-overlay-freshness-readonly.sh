#!/usr/bin/env bash
# Compare current live source to the last approved PRIVATE overlay baseline.
# No copying, restore, secrets, SQL, Nginx reload or production mutation.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--inspect" ]] || {
 echo "Usage: bash $0 --inspect" >&2; exit 64;
}
fail() { echo "MCP_OVERLAY_FRESHNESS_BLOCKED=$1"; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for c in python3 sha256sum stat; do command -v "$c" > /dev/null || fail MISSING_COMMAND; done

APP=/www/wwwroot/bambodia-staging-qa
OVERLAY=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
VHOST_SHA=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
   -f "$APP/storage/app/installed.lock" ]] || fail LIVE_APP_CHANGED
[[ -d "$OVERLAY" && ! -L "$OVERLAY" &&
   "$(stat -c '%u:%a' "$OVERLAY")" == "0:700" &&
   -d "$OVERLAY/source" && ! -L "$OVERLAY/source" &&
   -d "$OVERLAY/review/live-critical" &&
   ! -L "$OVERLAY/review/live-critical" ]] || fail OVERLAY_UNSAFE
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | awk '{print $1}')" == "$VHOST_SHA" ]] ||
   fail VHOST_CHANGED

echo "MCP_OVERLAY_FRESHNESS_PREFLIGHT=PASS"
python3 - "$APP" "$OVERLAY" <<'PY'
from pathlib import Path
import hashlib
import stat
import sys

live, overlay = map(Path, sys.argv[1:])
original = overlay / "review/live-critical"
candidate = overlay / "source"
protected = {
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
sections = (
    "app", "config", "routes", "resources",
    "database/migrations", "public/css", "public/js"
)
ignored = {"vendor", "node_modules", ".git", "storage", "cache", "build"}

def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for part in iter(lambda: stream.read(1048576), b""):
            h.update(part)
    return h.hexdigest()

def inventory(root):
    result = {}
    for section in sections:
        directory = root / section
        if not directory.exists():
            continue
        if directory.is_symlink():
            raise RuntimeError("symlink")
        for path in directory.rglob("*"):
            rel = path.relative_to(root)
            if any(x in ignored or x.startswith(".env") for x in rel.parts):
                continue
            mode = path.lstat().st_mode
            if stat.S_ISDIR(mode):
                continue
            if not stat.S_ISREG(mode):
                raise RuntimeError("special file")
            result[rel.as_posix()] = digest(path)
    return result

try:
    current = inventory(live)
    prior = inventory(candidate)
    archived = inventory(original)
except (OSError, RuntimeError):
    print("MCP_OVERLAY_FRESHNESS=BLOCKED_UNSAFE_INPUT")
    sys.exit(2)

# These nine snapshots were copied from production when the private overlay
# was prepared. Never compare them against the intentionally changed MCP
# implementation in the private candidate.
if not protected.issubset(archived):
    print("MCP_OVERLAY_FRESHNESS=BLOCKED_MISSING_REVIEW_SNAPSHOTS")
    sys.exit(2)

changed, newly_added, missing = [], [], []
for name, live_digest in sorted(current.items()):
    prior_digest = archived.get(name) if name in protected else prior.get(name)
    if prior_digest is None:
        newly_added.append(name)
    elif prior_digest != live_digest:
        changed.append(name)

for name in sorted(prior):
    if name not in current and name not in protected:
        missing.append(name)

print("MCP_OVERLAY_LIVE_CHANGED_SINCE_REVIEW=" + str(len(changed)))
print("MCP_OVERLAY_LIVE_NEW_SINCE_REVIEW=" + str(len(newly_added)))
print("MCP_OVERLAY_STAGE_ONLY_NOT_IN_LIVE=" + str(len(missing)))
for name in changed[:60]:
    print("MCP_OVERLAY_CHANGED_SINCE_REVIEW_PATH=" + name)
for name in newly_added[:60]:
    print("MCP_OVERLAY_NEW_LIVE_PATH=" + name)
for name in missing[:60]:
    print("MCP_OVERLAY_STAGE_ONLY_PATH=" + name)
for name in sorted(protected):
    state = ("UNCHANGED" if current.get(name) == archived[name]
             else ("CHANGED" if name in current else "MISSING"))
    print("MCP_PROTECTED_LIVE_SNAPSHOT=" + name + "|" + state)

if changed or newly_added:
    print("MCP_PRIVATE_OVERLAY_REFRESH_REQUIRED=YES")
    print("MCP_PRIVATE_OVERLAY_RECONCILIATION=BLOCKED")
else:
    print("MCP_PRIVATE_OVERLAY_REFRESH_REQUIRED=NO")
    print("MCP_PRIVATE_OVERLAY_RECONCILIATION=REVIEW_ONLY")
print("MCP_PRIVATE_OVERLAY_COPIED_FILES=NONE")
print("PRODUCTION_SOURCE_AND_DATABASE=UNCHANGED")
PY
