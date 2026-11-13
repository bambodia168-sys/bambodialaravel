#!/usr/bin/env python3
"""Guarded Node-free aaPanel install for Bambodia blog video/YouTube featured media."""
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
BRANCH = "fix/blog-video-youtube-featured-20261113"
BASE_HEAD = "06b19b480ba4fdbef69bf1df14bb011dd6cbd67a"
MAIN = "133f82a3e8681f3efbc8de9424a83dc3306e15d5"
APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
BACKUPS = Path("/root/Bambodia-Blog-Video-Featured-Backups")
SOURCE_BASELINES = {
    "resources/views/home.blade.php": "5e05173cbfec18f8d0c06bdc5443e0045fa35e60",
    "resources/views/content/blog.blade.php": "ed4b92b38228b9375bc2e2ca43a6b4524d182200",
    "resources/css/app.css": "0ee0d9eb8483226f1cec85fecad1002b3188f4ad",
    "resources/js/app.js": "4bc4f4096e02249cf2a0bcb10438139b324ae0c3",
}
NEW_COMPONENT = "resources/views/components/blog-card-featured-media.blade.php"
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
    if call("gh", "api", f"repos/{REPO}/pulls/48", "--jq", ".head.sha") != BASE_HEAD:
        blocked("Deployed arrow-removal base PR changed")
    pr = json.loads(call("gh", "api", f"repos/{REPO}/pulls/49"))
    if (pr["head"]["ref"] != BRANCH or pr["head"]["sha"] != head
        or pr["base"]["ref"] != "style/remove-trailing-cta-arrows-20261113"
        or pr["state"] != "open" or pr["draft"] is not True):
        blocked("PR #49 head/base/state changed")
    for name, blob in SOURCE_BASELINES.items():
        if git_blob(APP / name) != blob:
            blocked("Live source differs from approved release: " + name)
        print("LIVE_SOURCE_BASELINE=VERIFIED " + name)
    candidate = APP / NEW_COMPONENT
    if candidate.is_symlink() or candidate.exists():
        blocked("Unexpected existing shared blog media component")
    header = safe_file(APP / "resources/views/layouts/main.blade.php").read_text()
    if ("header-flag-scroll-top.css" not in header or "data-back-to-top" not in header
        or "data-language" not in header and "language-flag" not in header):
        blocked("Previously installed public header controls changed")
    safe_file(APP / "resources/views/layouts/workspace.blade.php")
    safe_file(APP / "public/css/header-flag-scroll-top.css")
    print("PREVIOUS_HEADER_FLAG_AND_SCROLL_TOP=PRESERVED")
    live_manifest = json.loads(safe_file(APP / MANIFEST).read_text())
    for item, ext in [("resources/css/app.css", ".css"), ("resources/js/app.js", ".js")]:
        entry = live_manifest.get(item)
        if not isinstance(entry, dict) or not entry.get("file", "").endswith(ext):
            blocked("Live Vite manifest missing " + item)
    if live_manifest["resources/css/app.css"]["file"] != "assets/app-CR4KnsIP.css":
        blocked("The last approved homepage CSS asset changed")
    for rel in manifest_assets(live_manifest):
        safe_file(APP / "public/build" / rel)
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
    for rel in list(SOURCE_BASELINES) + [NEW_COMPONENT]:
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
    if (source / "resources/views/home.blade.php").read_text().count(
        '<x-blog-card-featured-media :post="$post" />') != 1:
        blocked("Reviewed homepage media component missing")
    if (source / "resources/views/content/blog.blade.php").read_text().count(
        '<x-blog-card-featured-media :post="$post" />') != 1:
        blocked("Reviewed Blog page media component missing")
    print("REVIEWED_MEDIA_SOURCE=VERIFIED")
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
            if rel not in (NEW_COMPONENT,) and not rel.startswith("public/build/assets/"):
                blocked("Expected existing target missing: " + rel)
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
    print("RESULT=BLOG_FEATURED_VIDEO_AND_YOUTUBE_DEPLOYED")

def main():
    if len(sys.argv) < 3 or sys.argv[1] not in ("--check", "--apply-ci"):
        blocked("Usage: blog-featured-video-aapanel.py --check HEAD | --apply-ci HEAD QUALITY_RUN")
    mode, head = sys.argv[1:3]
    if len(head) != 40 or any(c not in "0123456789abcdef" for c in head):
        blocked("Reviewed commit SHA required")
    if (mode == "--check" and len(sys.argv) != 3 or
        mode == "--apply-ci" and len(sys.argv) != 4):
        blocked("Wrong argument count")
    manifest_sha = guard(head)
    if mode == "--check":
        print("DEPLOYMENT=NOT_PERFORMED")
        print("RESULT=READY_FOR_BLOG_VIDEO_YOUTUBE_RELEASE")
        return
    with tempfile.TemporaryDirectory(prefix="bambodia-blog-video-", dir="/root") as folder:
        source, bundle, assets = prepare(head, sys.argv[3], Path(folder))
        updates = {rel: (source / rel).read_bytes()
                   for rel in list(SOURCE_BASELINES) + [NEW_COMPONENT]}
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
