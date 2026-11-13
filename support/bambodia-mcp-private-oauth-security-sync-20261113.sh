#!/usr/bin/env bash
# GitHub OAuth security changes -> refreshed PRIVATE Bambodia candidate.
# This does not modify production, the prior overlay, DB, keys or Nginx.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--private-sync" ]] || {
 echo "Usage: bash $0 --private-sync" >&2; exit 64;
}
fail(){ printf 'MCP_PRIVATE_OAUTH_SYNC_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for c in gh git python3 sha256sum stat mktemp cp; do
 command -v "$c" >/dev/null || fail MISSING_REQUIRED_TOOL
done
LIVE=/www/wwwroot/bambodia-staging-qa
OLD=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
NEW=/root/Bambodia-MCP-Overlay-Refresh.hZDR51NJ
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
SOURCE_REF=396614486e3b7e50a28cd42932af6b4aa6536cfd
REPO=bambodia168-sys/bambodialaravel
PHP=/www/server/php/85/bin/php

for p in "$OLD" "$NEW"; do
 [[ -d "$p" && ! -L "$p" && "$(stat -c '%u:%a' "$p")" == "0:700" &&
   -d "$p/source" && ! -L "$p/source" &&
   -d "$p/review" && ! -L "$p/review" ]] || fail PRIVATE_WORKSPACE_UNSAFE
done
[[ -d "$LIVE" && ! -L "$LIVE" && -f "$LIVE/artisan" &&
   -f "$LIVE/storage/app/installed.lock" ]] || fail LIVE_SOURCE_IDENTITY_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
 fail LIVE_VHOST_CHANGED
[[ -x "$PHP" ]] || fail PINNED_PHP85_MISSING
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
 fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
 fail LIVE_LOCK_CHANGED
[[ "$(sha256sum "$NEW/source/composer.lock" | awk '{print $1}')" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] ||
 fail PRIVATE_RELEASE_LOCK_CHANGED
for p in "$NEW/source/.env" "$NEW/source/.env.testing" \
 "$NEW/source/storage/oauth-private.key" "$NEW/source/storage/oauth-public.key" \
 "$NEW/source/bootstrap/cache/config.php"; do
 [[ ! -e "$p" && ! -L "$p" ]] || fail PRIVATE_SECRETS_OR_CONFIG_CACHE_PRESENT
done
[[ -f "$NEW/review/20261113-refresh-evidence.json" &&
  ! -L "$NEW/review/20261113-refresh-evidence.json" ]] || fail REFRESH_EVIDENCE_MISSING

TMP="$(mktemp -d /root/bambodia-mcp-oauth-security-sync.XXXXXXXX)"
cleanup() {
 if [[ "$TMP" == /root/bambodia-mcp-oauth-security-sync.* &&
   -d "$TMP" && ! -L "$TMP" ]]; then
   rm -rf --one-file-system -- "$TMP"
 fi
}
trap cleanup EXIT

# Only these THREE immutable GitHub source files are permitted.
# No application data or private server files are uploaded to GitHub.
for entry in \
 "config/mcp.php|215d9b15714e0a6b687191bdab44af54ace10450|config.php" \
 "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php|290f3d66998315235c9c6350e3b878d458344331|register.php" \
 "tests/Feature/BambodiaMcpOAuthTest.php|725a067ce74802cec15169eab87beab051c7e3ca|oauth-test.php"
do
 IFS='|' read -r relative blob filename <<< "$entry"
 gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/$relative?ref=$SOURCE_REF" > "$TMP/$filename" ||
  fail GITHUB_SECURITY_FILE_DOWNLOAD_FAILED
 [[ -s "$TMP/$filename" && ! -L "$TMP/$filename" ]] ||
  fail GITHUB_SECURITY_FILE_MISSING
 [[ "$(git hash-object "$TMP/$filename")" == "$blob" ]] ||
  fail GITHUB_SECURITY_BLOB_MISMATCH
 "$PHP" -l "$TMP/$filename" >/dev/null || fail SECURITY_SOURCE_PHP_LINT
done
echo "GITHUB_PINNED_OAUTH_SECURITY_FILES=3_VERIFIED"

# A dry run validates all private/current-live hashes, archived nine critical
# snapshots, 11 preserved frontend inputs, 23 Vite build hashes and preimages.
cat > "$TMP/verify.py" <<'PY'
from pathlib import Path
import hashlib
import json
import os
import stat
import sys
import tempfile

live, old, new, downloads = map(Path, sys.argv[1:5])
mode = sys.argv[5]
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
source_paths = {
 "config/mcp.php": ("config.php",
     "215d9b15714e0a6b687191bdab44af54ace10450",
     {"c6b917eec7d1573c13f3335d1ed811a212850afa"}),
 "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php":
     ("register.php", "290f3d66998315235c9c6350e3b878d458344331",
      {"2bb0f836dd9cc1668ca545686f901d5d651d6508"}),
 "tests/Feature/BambodiaMcpOAuthTest.php":
     ("oauth-test.php", "725a067ce74802cec15169eab87beab051c7e3ca",
      {"2c67651511f99eee3d6e3b1c3bad2364860912ad",
       "4a370bd74785584a609cb98254621968ad91512a"}),
}
changed = {
 "resources/css/app.css",
 "resources/js/app.js",
 "resources/views/content/blog.blade.php",
 "resources/views/content/show.blade.php",
 "resources/views/home.blade.php",
 "resources/views/layouts/main.blade.php",
}
added = {
 "public/css/footer-two-tone-themes.css",
 "public/css/home-hero-aurora.css",
 "resources/js/blog-premium-player.js",
 "resources/views/components/blog-card-featured-media.blade.php",
 "resources/views/components/blog-premium-native-video.blade.php",
}
def blocked(reason):
 print("MCP_PRIVATE_OAUTH_SYNC_BLOCKED=" + reason)
 raise SystemExit(2)
def digest(path):
 h = hashlib.sha256()
 with path.open("rb") as stream:
  for chunk in iter(lambda: stream.read(1048576), b""):
   h.update(chunk)
 return h.hexdigest()
def blob(data):
 return hashlib.sha1(
  b"blob " + str(len(data)).encode() + bytes([0]) + data).hexdigest()
def safe_file(path):
 try:
  m = path.lstat().st_mode
 except OSError:
  blocked("MISSING_REQUIRED_SOURCE_FILE")
 if not stat.S_ISREG(m):
  blocked("SYMLINK_OR_SPECIAL_SOURCE_FILE")
 return path.read_bytes()
def asset_hashes(path):
 if path.is_symlink() or not path.is_dir():
  blocked("VITE_BUILD_UNSAFE")
 items = {}
 for p in path.rglob("*"):
  if p.is_dir() and not p.is_symlink():
   continue
  if p.is_symlink() or not p.is_file():
   blocked("VITE_ASSET_UNSAFE")
  rel = p.relative_to(path).as_posix()
  if rel.startswith(".env") or p.suffix.lower() == ".php":
   blocked("VITE_ASSET_UNSAFE_NAME")
  items[rel] = digest(p)
 if len(items) > 15000:
  blocked("VITE_ASSET_COUNT_LIMIT")
 return items

evidence = json.loads(safe_file(
 new / "review/20261113-refresh-evidence.json"))
if (evidence.get("updated_live_paths") != sorted(changed) or
    evidence.get("added_live_paths") != sorted(added) or
    evidence.get("protected_originals_unchanged") != 9 or
    evidence.get("release_authorized") is not False):
 blocked("REFRESH_REPORT_CHANGED")
for rel in changed | added:
 saved = evidence.get("source_sha256", {}).get(rel)
 if (not isinstance(saved, str) or len(saved) != 64 or
     digest(new / "source" / rel) != saved or
     digest(live / rel) != saved):
  blocked("LIVE_OR_PRIVATE_FRONTEND_CHANGED_SINCE_REFRESH")
for rel in protected:
 old_snap = old / "review/live-critical" / rel
 new_snap = new / "review/live-critical" / rel
 if (digest(live / rel) != digest(old_snap) or
     digest(new_snap) != digest(old_snap) or
     digest(new / "source" / rel) != digest(old / "source" / rel)):
  blocked("PROTECTED_MCP_MERGE_OR_SNAPSHOT_DRIFT")
build_expected = evidence.get("live_build_sha256")
if (not isinstance(build_expected, dict) or len(build_expected) != 23 or
    asset_hashes(new / "source/public/build") != build_expected or
    asset_hashes(live / "public/build") != build_expected):
 blocked("CURRENT_VITE_BUILD_DRIFT")
print("MCP_PRIVATE_FRONTEND_AND_23_VITE_ASSETS=PRESERVED")
print("MCP_PROTECTED_MCP_MERGE_SNAPSHOTS=9_UNCHANGED")

states = []
for rel, (filename, target_blob, approved_old) in source_paths.items():
 target = new / "source" / rel
 current_blob = blob(safe_file(target))
 if blob(safe_file(downloads / filename)) != target_blob:
  blocked("PINNED_GITHUB_SECURITY_SOURCE_DRIFT")
 if current_blob != target_blob and current_blob not in approved_old:
  blocked("UNAPPROVED_OAUTH_SOURCE_PREIMAGE_" + rel.replace("/", "_"))
 states.append("CURRENT" if current_blob == target_blob else "OLD")
if len(set(states)) != 1:
 blocked("PARTIAL_PRIVATE_SECURITY_SYNC_REQUIRES_MANUAL_REVIEW")

report = new / "review/mcp-oauth-security-sync-20261113.json"
snapshot = new / "review/mcp-oauth-security-pre-sync"
if states[0] == "CURRENT":
 if not report.is_file() or report.is_symlink():
  blocked("CURRENT_SECURITY_SOURCE_WITHOUT_REVIEW_REPORT")
 prior = json.loads(safe_file(report))
 if prior.get("github_oauth_security_sync") != "PASSED":
  blocked("EXISTING_SECURITY_REPORT_UNRECOGNIZED")
 print("MCP_PRIVATE_OAUTH_SECURITY_SYNC=ALREADY_VERIFIED")
 print("MCP_PRIVATE_CANDIDATE_DEPLOYABLE=NO")
 sys.exit(0)
if report.exists() or report.is_symlink() or snapshot.exists() or snapshot.is_symlink():
 blocked("PREEXISTING_SECURITY_REPORT_OR_SNAPSHOTS")
print("MCP_PRIVATE_OAUTH_PREIMAGES=3_APPROVED")
if mode == "inspect":
 print("MCP_PRIVATE_OAUTH_SYNC_PREFLIGHT=PASS")
 print("PRODUCTION_SOURCE_DATABASE_NGINX=UNCHANGED")
 sys.exit(0)
if mode != "prepare":
 blocked("INVALID_MODE")

# Create rollback snapshots inside the NEW workspace only.
snapshot.mkdir(mode=0o700)
originals = {}
for rel in source_paths:
 originals[rel] = safe_file(new / "source" / rel)
 path = snapshot / rel
 path.parent.mkdir(parents=True, exist_ok=True)
 path.write_bytes(originals[rel])
 os.chmod(path, 0o600)
 if path.read_bytes() != originals[rel]:
  blocked("PRIVATE_PRE_SYNC_BACKUP_VERIFY_FAILED")

def replace_private(path, data):
 if path.is_symlink():
  blocked("PRIVATE_TARGET_IS_SYMLINK")
 fd, tmp = tempfile.mkstemp(prefix=".private-oauth-", dir=path.parent)
 try:
  os.fchmod(fd, 0o600)
  with os.fdopen(fd, "wb") as f:
   f.write(data)
   f.flush()
   os.fsync(f.fileno())
  os.replace(tmp, path)
 finally:
  if os.path.exists(tmp):
   os.unlink(tmp)

written = []
try:
 for rel, (filename, expected, _) in source_paths.items():
  path = new / "source" / rel
  replace_private(path, safe_file(downloads / filename))
  written.append(rel)
  if blob(safe_file(path)) != expected:
   blocked("PRIVATE_SECURITY_POSTWRITE_HASH_FAILED")
 # Frontend assets must still match the latest reviewed live release.
 for rel in changed | added:
  if digest(new / "source" / rel) != evidence["source_sha256"][rel]:
   blocked("FRONTEND_CHANGED_DURING_SECURITY_SYNC")
 if asset_hashes(new / "source/public/build") != build_expected:
  blocked("VITE_CHANGED_DURING_SECURITY_SYNC")
except BaseException:
 for rel in reversed(written):
  replace_private(new / "source" / rel, originals[rel])
 raise

result = {
 "github_oauth_security_sync": "PASSED",
 "source_git_commit": "396614486e3b7e50a28cd42932af6b4aa6536cfd",
 "private_workspace": str(new),
 "source_files": {
  rel: {"previous_git_blob": blob(originals[rel]), "approved_git_blob": pin}
  for rel, (_, pin, _) in source_paths.items()
 },
 "preserved_live_frontend_paths": len(changed | added),
 "preserved_current_vite_files": len(build_expected),
 "protected_mcp_originals_unchanged": len(protected),
 "production_changed": False,
 "private_candidate_deployable": False,
 "independent_runtime_and_browser_qa": "PENDING",
 "customer_backup_restore_and_rollback": "PENDING",
}
encoded = (json.dumps(result, indent=2, sort_keys=True) + "\n").encode()
fd, tmp = tempfile.mkstemp(prefix=".oauth-sync-report-", dir=report.parent)
try:
 os.fchmod(fd, 0o600)
 with os.fdopen(fd, "wb") as file:
  file.write(encoded)
  file.flush()
  os.fsync(file.fileno())
 os.replace(tmp, report)
finally:
 if os.path.exists(tmp):
  os.unlink(tmp)
print("MCP_PRIVATE_OAUTH_SECURITY_SYNC=PASS")
print("MCP_PRIVATE_OAUTH_SECURITY_SOURCE_FILES=3")
print("MCP_PRIVATE_OAUTH_SYNC_REPORT=" + str(report))
print("MCP_PRIVATE_CANDIDATE_DEPLOYABLE=NO")
print("MCP_PRIVATE_OAUTH_INDEPENDENT_RUNTIME_QA=PENDING")
print("PRODUCTION_SOURCE_DATABASE_NGINX_KEYS=UNCHANGED")
PY

echo "========== VERIFY PREPARED PRIVATE CANDIDATE =========="
python3 "$TMP/verify.py" "$LIVE" "$OLD" "$NEW" "$TMP" inspect
echo "========== APPROVAL: PRIVATE CANDIDATE ONLY =========="
[[ -c /dev/tty ]] || fail OPERATOR_TTY_REQUIRED
printf 'Type SYNC-MCP-PRIVATE-OAUTH-ONLY to update 3 files inside the new private copy: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == "SYNC-MCP-PRIVATE-OAUTH-ONLY" ]] || fail OPERATOR_DECLINED
unset APPROVAL

python3 "$TMP/verify.py" "$LIVE" "$OLD" "$NEW" "$TMP" prepare
for rel in \
 "config/mcp.php" \
 "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php" \
 "tests/Feature/BambodiaMcpOAuthTest.php"; do
 "$PHP" -l "$NEW/source/$rel" >/dev/null || fail PRIVATE_POST_SYNC_PHP_LINT
done
echo "MCP_PRIVATE_OAUTH_PHP_LINT=PASS"
echo "MCP_PRODUCTION_DEPLOYMENT=NOT_STARTED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
