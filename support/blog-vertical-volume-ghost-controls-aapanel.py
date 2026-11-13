#!/usr/bin/env python3
"""Guarded Node-free aaPanel upgrade for blog ghost controls and vertical volume."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile

REPO = "bambodia168-sys/bambodialaravel"
BRANCH = "feat/blog-player-ghost-controls-vertical-volume-20261113"
BASE_HEAD = "34f8b391279b99919437825d5616d9a161f7b202"
MAIN = "dd9b2657c63083f08f9da16278e6d6e3495468cb"
APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
BACKUPS = Path("/root/Bambodia-Vertical-Volume-Player-Backups")
SOURCE_BASELINES = {
    "resources/css/app.css": "af8aa9bd8b5dbd67d3bc41a667a0d21e659d60ab",
    "resources/js/blog-premium-player.js": "d4913723b8fbbcf3009e5c9f417ce56a5c15672f",
    "resources/views/components/blog-premium-native-video.blade.php": "58e461137099f2bedbca26f48a77031623b6694d",
}
PREVIOUS_PINNED = {
    "resources/views/content/show.blade.php": "0c54251c06204657bb24c6bed3ef47f63dd1c033",
    # This file is unchanged by the fullscreen patch. Pin the reconciled
    # GitHub main blob, which was verified on aaPanel by PR #51's audit.
    "resources/js/app.js": "31a039632ce8020572569f176da5e4fa2e8f27b8",
    "resources/views/home.blade.php": "6b002ecdbaf917f320d6cfe6a6617dfbfb7206af",
    "resources/views/content/blog.blade.php": "11c58c4c305341533051af1a160b469bbb1d3a21",
    "resources/views/components/blog-card-featured-media.blade.php": "55ce6de3fdc4d8d49989583d41381d91373f284d",
}
NEW_FILES = ()
MANIFEST = "public/build/manifest.json"
ARTIFACT = "Bambodia-Home-Discovery-Vite"

def blocked(message):
    raise RuntimeError("BLOCKED=" + message)

def call(*args, cwd=None, show=False):
    result = subprocess.run(args, cwd=cwd, check=True,
                            capture_output=not show, text=True)
    return result.stdout.strip() if not show else ""

def sha_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for part in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(part)
    return digest.hexdigest()

def safe_file(path):
    if path.is_symlink() or not path.is_file():
        blocked("Missing or symlink file: " + str(path))
    return path

def git_blob(path):
    return call("git", "hash-object", str(safe_file(path)))

def url_ref(value):
    if not isinstance(value, str):
        blocked("Invalid manifest asset reference")
    p = Path(value)
    if (p.is_absolute() or ".." in p.parts or len(p.parts) < 2
        or p.parts[0] != "assets" or "\\" in value):
        blocked("Unsafe Vite asset reference")
    return p

def manifest_assets(manifest):
    refs = set()
    for name, item in manifest.items():
        if not isinstance(item, dict):
            blocked("Invalid Vite manifest entry: " + name)
        for key in ("file", "src"):
            if key == "file" and isinstance(item.get(key), str):
                refs.add(str(url_ref(item[key])))
        for key in ("css", "assets"):
            if key in item:
                if not isinstance(item[key], list):
                    blocked("Unexpected Vite asset references")
                for filename in item[key]:
                    refs.add(str(url_ref(filename)))
    return refs

def guard(head):
    if os.geteuid() != 0:
        blocked("root operator required")
    for executable in ("gh", "git", "python3", "runuser"):
        if not shutil.which(executable):
            blocked("Missing utility: " + executable)
    if APP.is_symlink() or APP.resolve() != APP or not (APP / "artisan").is_file():
        blocked("Unexpected aaPanel application root")
    if "root " + str(APP / "public") + ";" not in safe_file(VHOST).read_text():
        blocked("aaPanel Nginx vhost root changed")
    for relative in ("resources", "resources/views", "resources/views/components",
                     "resources/css", "resources/js", "public", "public/build",
                     "public/build/assets"):
        folder = APP / relative
        if folder.is_symlink() or not folder.is_dir():
            blocked("Unsafe directory: " + relative)
    if call("gh", "api", f"repos/{REPO}/commits/main", "--jq", ".sha") != MAIN:
        blocked("GitHub main moved")
    previous_pr = json.loads(call("gh", "api", f"repos/{REPO}/pulls/52"))
    if previous_pr["head"]["sha"] != BASE_HEAD or previous_pr["state"] != "open":
        blocked("Previously deployed PR #52 changed")
    pr = json.loads(call("gh", "api", f"repos/{REPO}/pulls/53"))
    if (pr["head"]["ref"] != BRANCH or pr["head"]["sha"] != head
        or pr["base"]["ref"] != "feat/premium-player-fullscreen-controls-20261113"
        or pr["base"]["sha"] != BASE_HEAD
        or pr["state"] != "open" or pr["draft"] is not True):
        blocked("PR #53 head/base/state changed")
    for name, blob in {**SOURCE_BASELINES, **PREVIOUS_PINNED}.items():
        if git_blob(APP / name) != blob:
            blocked("Live source differs from approved release: " + name)
        print("LIVE_SOURCE_BASELINE=VERIFIED " + name)
    for rel in NEW_FILES:
        candidate = APP / rel
        if candidate.is_symlink() or candidate.exists():
            blocked("Unexpected existing premium player file: " + rel)
    header = safe_file(APP / "resources/views/layouts/main.blade.php").read_text()
    if ("header-flag-scroll-top.css" not in header or "data-back-to-top" not in header
        or "header-flag-only" not in header or "<x-language-menu" not in header):
        blocked("Previously installed public header controls changed")
    safe_file(APP / "resources/views/layouts/workspace.blade.php")
    safe_file(APP / "public/css/header-flag-scroll-top.css")
    print("PREVIOUS_HEADER_FLAG_AND_SCROLL_TOP=PRESERVED")
    live_manifest = json.loads(safe_file(APP / MANIFEST).read_text())
    for item, ext in [("resources/css/app.css", ".css"), ("resources/js/app.js", ".js")]:
        entry = live_manifest.get(item)
        if not isinstance(entry, dict) or not entry.get("file", "").endswith(ext):
            blocked("Live Vite manifest missing " + item)
    if live_manifest["resources/css/app.css"]["file"] != "assets/app-Dl163Qkx.css":
        blocked("Previously installed fullscreen CSS asset changed")
    if live_manifest["resources/js/app.js"]["file"] != "assets/app-lMxXGtw4.js":
        blocked("Previously installed fullscreen JavaScript asset changed")
    for rel in manifest_assets(live_manifest):
        safe_file(APP / "public/build" / rel)
    # Cross-check exact assets and manifest against PR #52's successfully
    # deployed Quality artifact 14017246746, not guessed build filenames.
    if sha_file(APP / MANIFEST) != "0c060ee5dd3692a563d32ca38169c0943cc6c5140be53af1d4162d76ec0bad92":
        blocked("Installed previous Vite manifest hash changed")
    if sha_file(APP / "public/build/assets/app-Dl163Qkx.css") != "8407f729c6230a792a5ac16feec89193db9c0179a51a3f4a162aa7b5e9b48b48":
        blocked("Installed previous CSS artifact hash changed")
    if sha_file(APP / "public/build/assets/app-lMxXGtw4.js") != "40e131dc84c95bfc48a32cd876fdbd875c0be9772e1cb9aee891628286f8a07f":
        blocked("Installed previous JavaScript artifact hash changed")
    print("LIVE_VITE_PREVIOUS_CI_ARTIFACT=HASH_VERIFIED")
    print("LIVE_VITE_MANIFEST=VERIFIED")
    return sha_file(APP / MANIFEST)

def verify_run(head, run_id):
    if not run_id.isdecimal() or len(run_id) > 20:
        blocked("Invalid workflow run ID")
    workflow = json.loads(call("gh", "api", f"repos/{REPO}/actions/runs/{run_id}"))
    if (workflow.get("head_sha") != head or workflow.get("name") != "Bambodia Quality"
        or workflow.get("event") != "pull_request"
        or workflow.get("status") != "completed"
        or workflow.get("conclusion") != "success"
        or workflow.get("repository", {}).get("full_name") != REPO):
        blocked("Vite build is not from successful exact-head Quality workflow")
    response = json.loads(call("gh", "api", f"repos/{REPO}/actions/runs/{run_id}/artifacts"))
    valid = [x for x in response.get("artifacts", [])
             if x.get("name") == ARTIFACT and not x.get("expired")
             and x.get("size_in_bytes", 0) > 0]
    if len(valid) != 1:
        blocked("Reviewed Vite artifact unavailable or expired")
    print("CI_BUILD_ARTIFACT=VERIFIED " + str(valid[0]["id"]))

def prepare(head, run_id, tmp):
    source = tmp / "source"
    bundle = tmp / "bundle"
    bundle.mkdir(mode=0o700)
    call("gh", "repo", "clone", REPO, str(source), "--",
         "--branch", BRANCH, "--single-branch", "--depth", "1", show=True)
    if call("git", "-C", str(source), "rev-parse", "HEAD") != head:
        blocked("Cloned source does not match reviewed PR head")
    for rel in list(SOURCE_BASELINES) + list(NEW_FILES):
        safe_file(source / rel)
    verify_run(head, run_id)
    call("gh", "run", "download", run_id, "--repo", REPO, "--name", ARTIFACT,
         "--dir", str(bundle), show=True)
    new_manifest = json.loads(safe_file(bundle / "manifest.json").read_text())
    old_manifest = json.loads(safe_file(APP / MANIFEST).read_text())
    if old_manifest.keys() != new_manifest.keys():
        blocked("Vite manifest keys changed")
    changed = {"resources/css/app.css", "resources/js/app.js"}
    for key in old_manifest:
        if key not in changed and old_manifest[key] != new_manifest[key]:
            blocked("Unrelated Vite manifest entry changed")
    for key, ext in [("resources/css/app.css", ".css"), ("resources/js/app.js", ".js")]:
        a, b = old_manifest[key], new_manifest[key]
        if (a.get("src") != b.get("src") or a.get("isEntry") != b.get("isEntry")
            or a.get("file") == b.get("file") or not b.get("file", "").endswith(ext)):
            blocked("Unexpected Vite manifest entry for " + key)
    assets = manifest_assets(new_manifest)
    for rel in assets:
        safe_file(bundle / rel)
        if (bundle / rel).stat().st_size > 15 * 1024 * 1024:
            blocked("Vite build asset exceeds reviewed limit")
    if len(assets) > 80 or sum((bundle / rel).stat().st_size for rel in assets) > 80 * 1024 * 1024:
        blocked("Unexpected Vite artifact payload")
    component = (source / "resources/views/components/blog-premium-native-video.blade.php").read_text()
    player_js = (source / "resources/js/blog-premium-player.js").read_text()
    css = (source / "resources/css/app.css").read_text()
    if not all(marker in component for marker in
               ("data-blog-player-hud", "data-blog-player-fullscreen",
                "data-blog-player-exit-fullscreen", "data-blog-player-volume-popover",
                "data-blog-player-volume-trigger", 'aria-orientation="vertical"')):
        blocked("Reviewed vertical-volume/player component controls missing")
    if "data-blog-player-fullscreen-label" in component or ">Full screen</span>" in component:
        blocked("Visible fullscreen label unexpectedly present")
    if not all(marker in player_js for marker in
               ("requestFullscreen", "exitFullscreen", "fullscreenchange",
                "setVolumeOpen", "data-blog-player-volume-popover", "data-blog-player-volume-trigger")):
        blocked("Reviewed fullscreen/volume interactions missing")
    if not all(marker in css for marker in
               (".blog-player-fullscreen-exit", ".blog-player-fullscreen-btn",
                ".blog-player-volume-popover", "writing-mode:vertical-lr",
                ".blog-premium-player:fullscreen")):
        blocked("Reviewed vertical-volume/fullscreen styling missing")
    stylesheet = bundle / new_manifest["resources/css/app.css"]["file"]
    javascript = bundle / new_manifest["resources/js/app.js"]["file"]
    if b"blog-player-volume-popover" not in stylesheet.read_bytes() or (
        b"data-blog-player-volume-trigger" not in javascript.read_bytes()):
        blocked("CI Vite artifact does not contain reviewed volume controls")
    print("REVIEWED_GHOST_AND_VERTICAL_VOLUME_SOURCE=VERIFIED")
    print("VITE_CSS_AND_JS=VERIFIED")
    return source, bundle, assets

def atomic_write(source, target, owner):
    fd, temporary = tempfile.mkstemp(prefix=".bambodia-blog-video-", dir=target.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(source)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(temporary, owner[0], owner[1])
        os.chmod(temporary, owner[2])
        os.replace(temporary, target)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)

def publish(head, original_manifest_sha, sources):
    # Sources is mapping of relative path => (bytes, existing or new).
    if guard(head) != original_manifest_sha:
        blocked("Manifest changed while preparing release")
    if BACKUPS.is_symlink():
        blocked("Backup root symlink")
    BACKUPS.mkdir(parents=True, mode=0o700, exist_ok=True)
    if BACKUPS.stat().st_uid != 0:
        blocked("Backup root owner changed")
    BACKUPS.chmod(0o700)
    backup = Path(tempfile.mkdtemp(prefix="release-", dir=BACKUPS))
    backup.chmod(0o700)
    saved = {}
    for rel, data in sources.items():
        dest = APP / rel
        if dest.is_symlink():
            blocked("Unsafe live file: " + rel)
        if dest.exists():
            if not dest.is_file():
                blocked("Unexpected target type: " + rel)
            meta = dest.stat()
            original = dest.read_bytes()
            out = backup / "original" / rel
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_bytes(original)
            out.chmod(0o600)
            if sha_file(out) != sha_file(dest):
                blocked("Backup integrity mismatch: " + rel)
            saved[rel] = (original, meta.st_uid, meta.st_gid, stat.S_IMODE(meta.st_mode))
        else:
            if not rel.startswith("public/build/assets/"):
                blocked("Unexpected new file during scoped vertical volume upgrade")
            # Only compiled content-addressed Vite assets may be newly created.
            saved[rel] = None
    (backup / "blobs.json").write_text(json.dumps({
        rel: (hashlib.sha256(row[0]).hexdigest() if row else None)
        for rel, row in saved.items()}, indent=2))
    (backup / "blobs.json").chmod(0o600)
    print("BACKUP=HASH_VERIFIED")
    touched = []
    try:
        # Files first, new assets second; Vite manifest LAST.
        order = [rel for rel in sources if rel != MANIFEST] + [MANIFEST]
        for rel in order:
            dest = APP / rel
            old = saved[rel]
            if dest.is_symlink() or (old is not None and
                (not dest.is_file() or dest.read_bytes() != old[0])) or (
                    old is None and dest.exists()):
                raise RuntimeError("Concurrent modification of " + rel)
            parent = dest.parent.stat()
            owner = (old[1], old[2], old[3]) if old else (
                parent.st_uid, parent.st_gid, 0o644)
            atomic_write(sources[rel], dest, owner)
            touched.append(rel)
        for rel, data in sources.items():
            if safe_file(APP / rel).read_bytes() != data:
                raise RuntimeError("Post-install hash mismatch " + rel)
        php = Path("/www/server/php/85/bin/php")
        if not php.is_file():
            raise RuntimeError("aaPanel PHP CLI not found")
        call("runuser", "-u", "www", "--", str(php), "artisan", "view:clear",
             cwd=str(APP), show=True)
    except BaseException:
        for rel in reversed(touched):
            old = saved[rel]
            if old is None:
                (APP / rel).unlink(missing_ok=True)
            else:
                atomic_write(old[0], APP / rel, (old[1], old[2], old[3]))
        print("DEPLOYMENT_ROLLBACK=RESTORE_ATTEMPTED")
        raise
    print("PRIVATE_BACKUP_DIR=" + str(backup))
    print("DEPLOYED_FILES=" + str(len(sources)) + "_HASH_VERIFIED")
    print("HEADER_LANGUAGE_BACK_TO_TOP=UNCHANGED")
    print("DATABASE_NGINX_PHP_FPM=UNCHANGED")
    print("RESULT=BLOG_GHOST_CONTROLS_VERTICAL_VOLUME_DEPLOYED")

def main():
    if len(sys.argv) < 3 or sys.argv[1] not in ("--check", "--apply-ci"):
        blocked("Usage: blog-vertical-volume-ghost-controls-aapanel.py --check HEAD | --apply-ci HEAD QUALITY_RUN")
    mode, head = sys.argv[1:3]
    if len(head) != 40 or any(c not in "0123456789abcdef" for c in head):
        blocked("Reviewed commit SHA required")
    if (mode == "--check" and len(sys.argv) != 3 or
        mode == "--apply-ci" and len(sys.argv) != 4):
        blocked("Wrong argument count")
    manifest_sha = guard(head)
    if mode == "--check":
        print("DEPLOYMENT=NOT_PERFORMED")
        print("RESULT=READY_FOR_BLOG_GHOST_CONTROLS_VERTICAL_VOLUME")
        return
    with tempfile.TemporaryDirectory(prefix="bambodia-blog-volume-", dir="/root") as folder:
        source, bundle, assets = prepare(head, sys.argv[3], Path(folder))
        updates = {rel: (source / rel).read_bytes()
                   for rel in list(SOURCE_BASELINES) + list(NEW_FILES)}
        for rel in assets:
            if not (APP / "public/build" / rel).exists():
                updates["public/build/" + rel] = (bundle / rel).read_bytes()
            elif sha_file(APP / "public/build" / rel) != sha_file(bundle / rel):
                blocked("Compiled asset filename collides with differing live file")
        updates[MANIFEST] = (bundle / "manifest.json").read_bytes()
        publish(head, manifest_sha, updates)

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(2)
