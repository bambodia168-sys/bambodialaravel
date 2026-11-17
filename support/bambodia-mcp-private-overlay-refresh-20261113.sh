#!/usr/bin/env bash
# Root-private, live-first overlay REFRESH. Does not change production.
# The only approved live source changes are the six updated paths and
# five live-only paths printed by the 2026-11-13 freshness audit.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--prepare" ]] || {
  echo "Usage: bash $0 --prepare" >&2; exit 64;
}
fail() { printf 'MCP_PRIVATE_REFRESH_BLOCKED=%s\n' "$1"; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for bin in python3 sha256sum stat cp mktemp git; do
  command -v "$bin" >/dev/null || fail MISSING_REQUIRED_BINARY
done
LIVE=/www/wwwroot/bambodia-staging-qa
OLD=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
[[ -d "$LIVE" && ! -L "$LIVE" && -f "$LIVE/artisan" &&
   -f "$LIVE/storage/app/installed.lock" ]] || fail PRODUCTION_ROOT_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail VHOST_NOT_REGULAR
[[ "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
  fail VHOST_HASH_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
  fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
  fail LIVE_LOCK_CHANGED
[[ -d "$OLD" && ! -L "$OLD" && "$(stat -c '%u:%a' "$OLD")" == "0:700" &&
   -d "$OLD/source" && ! -L "$OLD/source" &&
   -d "$OLD/review/live-critical" && ! -L "$OLD/review/live-critical" &&
   -f "$OLD/source/vendor/autoload.php" ]] || fail PRIOR_OVERLAY_NOT_SAFE
[[ ! -e "$OLD/source/.env" && ! -L "$OLD/source/.env" ]] ||
  fail PRIOR_OVERLAY_HAS_ENV
[[ "$(sha256sum "$OLD/source/composer.lock" | awk '{print $1}')" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] ||
  fail PRIVATE_RELEASE_LOCK_CHANGED

# A single embedded Python entry-point performs a dry-run before approval and
# validates the same conditions immediately before writing the new copy.
validate_and_refresh="$(mktemp /root/bambodia-mcp-refresh-code.XXXXXXXX.py)"
trap 'rm -f -- "$validate_and_refresh"' EXIT
cat > "$validate_and_refresh" <<'PY'
from pathlib import Path
import hashlib
import json
import os
import shutil
import stat
import sys

live, old = map(Path, sys.argv[1:3])
mode = sys.argv[3]
new = Path(sys.argv[4]) if mode == "prepare" else None
allowed_changed = {
    "resources/css/app.css",
    "resources/js/app.js",
    "resources/views/content/blog.blade.php",
    "resources/views/content/show.blade.php",
    "resources/views/home.blade.php",
    "resources/views/layouts/main.blade.php",
}
allowed_new = {
    "public/css/footer-two-tone-themes.css",
    "public/css/home-hero-aurora.css",
    "resources/js/blog-premium-player.js",
    "resources/views/components/blog-card-featured-media.blade.php",
    "resources/views/components/blog-premium-native-video.blade.php",
}
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
roots = ("app", "config", "routes", "resources", "database/migrations", "public/css", "public/js")
excluded = {"vendor", "node_modules", ".git", "storage", "cache", "build"}

def stop(reason):
    print("MCP_PRIVATE_REFRESH_BLOCKED=" + reason)
    raise SystemExit(2)

def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as file:
        for block in iter(lambda: file.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()

def regular(p):
    try:
        return stat.S_ISREG(p.lstat().st_mode)
    except FileNotFoundError:
        return False

def inventory(root):
    found = {}
    for section in roots:
        directory = root / section
        if not directory.exists():
            continue
        if not directory.is_dir() or directory.is_symlink():
            stop("UNSAFE_SOURCE_DIRECTORY")
        for path in directory.rglob("*"):
            rel = path.relative_to(root)
            if any(p in excluded or p.startswith(".env") for p in rel.parts):
                continue
            mode = path.lstat().st_mode
            if stat.S_ISDIR(mode):
                continue
            if not stat.S_ISREG(mode):
                stop("SYMLINK_OR_SPECIAL_SOURCE")
            found[rel.as_posix()] = digest(path)
    return found

previous = inventory(old / "source")
current = inventory(live)
originals = inventory(old / "review/live-critical")
if not protected.issubset(originals):
    stop("MISSING_NINE_MERGE_SNAPSHOTS")
for rel in protected:
    if current.get(rel) != originals[rel]:
        stop("PROTECTED_MCP_SOURCE_CHANGED_" + rel.replace("/", "_"))

changed = {rel for rel in current.keys() & previous.keys()
           if rel not in protected and current[rel] != previous[rel]}
added = set(current) - set(previous)
stage_only = set(previous) - set(current) - protected
if changed != allowed_changed or added != allowed_new or len(stage_only) != 26:
    print("MCP_PRIVATE_REFRESH_DRIFT_COUNTS=" +
          str(len(changed)) + "|" + str(len(added)) + "|" + str(len(stage_only)))
    stop("UNREVIEWED_SOURCE_DRIFT")
print("MCP_APPROVED_LIVE_CHANGES=" + str(len(changed)))
print("MCP_APPROVED_LIVE_ADDITIONS=" + str(len(added)))
print("MCP_PROTECTED_MCP_SNAPSHOTS=9_UNCHANGED")
print("MCP_STAGE_ONLY_RELEASE_PATHS=" + str(len(stage_only)))

vite = (
    "resources/css/app.css", "resources/js/app.js",
    "vite.config.js", "package.json",
)
build = live / "public/build"

def asset_hashes(directory):
    if not directory.is_dir() or directory.is_symlink():
        stop("VITE_BUILD_UNSAFE_OR_MISSING")
    items = {}
    byte_count = 0
    for path in directory.rglob("*"):
        mode = path.lstat().st_mode
        if stat.S_ISDIR(mode):
            continue
        if not stat.S_ISREG(mode):
            stop("VITE_BUILD_SYMLINK_OR_SPECIAL")
        rel = path.relative_to(directory).as_posix()
        if path.name.startswith(".env") or path.suffix.lower() == ".php":
            stop("VITE_BUILD_UNSAFE_FILENAME")
        byte_count += path.stat().st_size
        if byte_count > 800 * 1024 * 1024 or len(items) > 15000:
            stop("VITE_BUILD_SIZE_LIMIT")
        items[rel] = digest(path)
    if "manifest.json" not in items:
        stop("VITE_MANIFEST_MISSING")
    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8"))
    if not isinstance(manifest, dict):
        stop("VITE_MANIFEST_INVALID")
    for entry in ("resources/css/app.css", "resources/js/app.js"):
        value = manifest.get(entry)
        if not isinstance(value, dict) or not value.get("isEntry"):
            stop("VITE_REQUIRED_ENTRY_MISSING")
    for value in manifest.values():
        if not isinstance(value, dict):
            stop("VITE_INVALID_ROW")
        refs = [value.get("file")] if "file" in value else []
        for key in ("css", "assets"):
            extra = value.get(key, [])
            if not isinstance(extra, list):
                stop("VITE_INVALID_REFS")
            refs += extra
        for ref in refs:
            if (not isinstance(ref, str)
                or not ref
                or ref.startswith("/")
                or ".." in Path(ref).parts
                or ref not in items):
                stop("VITE_BAD_ASSET_REF")
    return items

before_assets = asset_hashes(build)
print("MCP_LIVE_VITE_MANIFEST=VALID")
print("MCP_LIVE_VITE_BUILD_FILES=" + str(len(before_assets)))

if mode == "inspect":
    print("MCP_PRIVATE_REFRESH_PREFLIGHT=PASS")
    print("PRODUCTION_SOURCE_DATABASE=UNCHANGED")
    sys.exit(0)
if mode != "prepare" or new is None:
    stop("INVALID_MODE")
if (not new.is_dir() or new.is_symlink()
    or not (new / "source").is_dir() or (new / "source").is_symlink()
    or not (new / "review").is_dir() or (new / "review").is_symlink()):
    stop("COPIED_PRIVATE_DESTINATION_UNSAFE")

# The source tree is copied first (by the outer shell) into a new, root-private
# directory. No original overlay or production file is ever changed.
for rel in sorted(changed | added):
    source = live / rel
    dest = new / "source" / rel
    if not regular(source) or (dest.exists() and not regular(dest)):
        stop("SOURCE_FILE_NOT_REGULAR")
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, dest)
    if digest(dest) != current[rel] or digest(source) != current[rel]:
        stop("SOURCE_CHANGED_DURING_PRIVATE_COPY")

for rel in vite:
    if not regular(live / rel) or not regular(new / "source" / rel):
        stop("VITE_INPUT_UNSAFE")
    if digest(live / rel) != digest(new / "source" / rel):
        stop("VITE_INPUT_NOT_IDENTICAL_" + rel.replace("/", "_"))

new_build = new / "source/public/build"
if not new_build.is_dir() or new_build.is_symlink():
    stop("OLD_OVERLAY_BUILD_MISSING")
retained = new / "review/prior-vite-build"
if retained.exists() or retained.is_symlink():
    stop("PRIOR_VITE_SNAPSHOT_COLLISION")
new_build.rename(retained)  # Only inside the NEW private copy.
shutil.copytree(build, new_build, symlinks=True)
after_assets = asset_hashes(new_build)
if before_assets != after_assets or before_assets != asset_hashes(build):
    stop("VITE_LIVE_ASSET_HASH_CHANGED_DURING_COPY")

# Check protected live snapshots did not change midway through the procedure.
for rel in protected:
    if not regular(live / rel) or digest(live / rel) != originals[rel]:
        stop("PROTECTED_SNAPSHOT_CHANGED_DURING_COPY")
for rel in changed | added:
    if digest(live / rel) != current[rel]:
        stop("LIVE_SOURCE_CHANGED_DURING_COPY")

report = {
    "source": "previous private overlay, not production installation",
    "updated_live_paths": sorted(changed),
    "added_live_paths": sorted(added),
    "protected_originals_unchanged": 9,
    "source_sha256": {rel: current[rel] for rel in sorted(changed | added)},
    "live_build_sha256": before_assets,
    "vite_inputs_match_current_live": True,
    "vendor_and_lock_inherited_from_previous_private_overlay": True,
    "new_oauth_source_security_review": "PENDING",
    "reproducible_vite_build_review": "PENDING",
    "production_backup_restore_review": "PENDING",
    "release_authorized": False,
}
output = new / "review/20261113-refresh-evidence.json"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
os.chmod(output, 0o600)
print("MCP_PRIVATE_REFRESH=PASS_NEEDS_INDEPENDENT_REVIEW")
print("MCP_PRIVATE_REFRESH_LIVE_FILES_COPIED=11")
print("MCP_PRIVATE_REFRESH_VITE_BUILD=COPIED_WITH_EXACT_LIVE_SHA256")
print("MCP_PRIVATE_REFRESH_PRIOR_OVERLAY=UNCHANGED")
print("MCP_PRIVATE_REFRESH_GITHUB_OAUTH_SECURITY_SYNC=PENDING")
print("MCP_PRIVATE_REFRESH_IS_DEPLOYABLE=NO")
print("MCP_PRIVATE_REFRESH_REPORT=" + str(output))
print("PRODUCTION_SOURCE_DATABASE_NGINX_KEYS=UNCHANGED")
PY

python3 "$validate_and_refresh" "$LIVE" "$OLD" inspect
echo "========== PRIVATE OVERLAY ONLY: OPERATOR APPROVAL =========="
[[ -c /dev/tty ]] || fail TTY_REQUIRED
printf 'Type REFRESH-MCP-PRIVATE-OVERLAY to create a NEW private source copy: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == "REFRESH-MCP-PRIVATE-OVERLAY" ]] ||
  fail OPERATOR_DECLINED
unset APPROVAL

WORK="$(mktemp -d /root/Bambodia-MCP-Overlay-Refresh.XXXXXXXX)"
chmod 700 "$WORK"
echo "MCP_REFRESH_PRIVATE_WORKSPACE=$WORK"
cp -a -- "$OLD/." "$WORK/" || fail PRIVATE_OVERLAY_COPY_FAILED
python3 "$validate_and_refresh" "$LIVE" "$OLD" prepare "$WORK"
echo "MCP_PRIVATE_REFRESH_PRODUCTION_DEPLOYMENT=NOT_STARTED"
echo "MCP_PRIVATE_REFRESH_MCP_ACTIVATION=NOT_STARTED"
