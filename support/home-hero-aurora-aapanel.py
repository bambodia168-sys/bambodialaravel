#!/usr/bin/env python3
"""Bambodia: fail-closed backup-first static Aurora homepage hero installation.

Usage: python3 support/home-hero-aurora-aapanel.py --check HEAD
       python3 support/home-hero-aurora-aapanel.py --apply HEAD

Only a new public CSS file and one link in the reviewed public Blade layout
may change. No homepage markup, Vite assets, database or server config writes.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

REPO = "bambodia168-sys/bambodialaravel"
BRANCH = "style/homepage-hero-subtle-aurora-20261113"
BASE = "cb34e0550b0865e20d913bfca912ef7aeb0b842c"
APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
BACKUP_ROOT = Path("/root/Bambodia-Hero-Aurora-Backups")
LAYOUT = "resources/views/layouts/main.blade.php"
CSS = "public/css/home-hero-aurora.css"
HOME = "resources/views/home.blade.php"
OLD_LAYOUT_BLOB = "00a08e62be193cf03a40526a5acd6038fae9764b"
NEW_LAYOUT_BLOB = "e66bc1390b98f4876a3a00813ad2b53e6cd116b1"
NEW_CSS_BLOB = "c4220b2db4fdd9a5abd341be5232e869229dfcd5"
HOME_BLOB = "6b002ecdbaf917f320d6cfe6a6617dfbfb7206af"

def blocked(reason):
    raise RuntimeError("BLOCKED=" + reason)

def git_blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\x00" + data).hexdigest()

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def api(relative, raw=False):
    command = ["gh", "api"]
    if raw:
        command += ["-H", "Accept: application/vnd.github.raw+json"]
    return run(*command, "repos/" + REPO + "/" + relative)

def safe_target(relative, must_exist=True):
    if APP.is_symlink() or not APP.is_dir():
        blocked("Unexpected application root")
    current = APP
    for part in Path(relative).parts:
        if part in (".", ".."):
            blocked("Unsafe application relative path")
        current = current / part
        if current.is_symlink():
            blocked("Symlink detected: " + relative)
    if must_exist:
        if not current.is_file():
            blocked("Missing required file: " + relative)
    elif current.exists():
        blocked("New CSS target already exists; do not overwrite")
    elif not current.parent.is_dir():
        blocked("Missing CSS parent directory")
    return current

def preflight(head):
    if os.geteuid() != 0:
        blocked("Root operator required")
    for cmd in ("gh", "git", "python3", "runuser"):
        if shutil.which(cmd) is None:
            blocked("Missing executable: " + cmd)
    if not Path(PHP).is_file() or not os.access(PHP, os.X_OK):
        blocked("Expected PHP 8.5 binary unavailable")
    safe_target("artisan")
    if json.loads(api("commits/main"))["sha"] != BASE:
        blocked("Main branch moved; reconcile before applying")
    pr = json.loads(api("pulls/56"))
    if (pr["head"]["ref"] != BRANCH or pr["head"]["sha"] != head
            or pr["base"]["ref"] != "main" or pr["base"]["sha"] != BASE
            or pr["state"] != "open" or not pr["draft"]):
        blocked("Reviewed draft PR #56 changed")

    old_layout = api("contents/" + LAYOUT + "?ref=" + BASE, raw=True)
    new_layout = api("contents/" + LAYOUT + "?ref=" + head, raw=True)
    new_css = api("contents/" + CSS + "?ref=" + head, raw=True)
    home_base = api("contents/" + HOME + "?ref=" + BASE, raw=True)
    home_head = api("contents/" + HOME + "?ref=" + head, raw=True)

    if (git_blob(old_layout) != OLD_LAYOUT_BLOB
        or git_blob(new_layout) != NEW_LAYOUT_BLOB
        or git_blob(new_css) != NEW_CSS_BLOB
        or git_blob(home_base) != HOME_BLOB or home_head != home_base):
        blocked("Reviewed GitHub source or homepage markup mismatch")

    original_link = b'  <link rel="stylesheet" href="{{ asset(\'css/footer-two-tone-themes.css\') }}?v=2">'
    added_link = b'\n  <link rel="stylesheet" href="{{ asset(\'css/home-hero-aurora.css\') }}?v=1">'
    if old_layout.count(original_link) != 1 or old_layout.replace(
            original_link, original_link + added_link, 1) != new_layout:
        blocked("Layout change exceeds approved one-link patch")

    for token in (b"#f6f8fc", b"#07162b", b".marketplace-hero-scene::before",
                  b".marketplace-hero-scene::after", b"animation: none",
                  b"prefers-reduced-motion", b"48px 48px", b"40px 40px"):
        if token not in new_css:
            blocked("Reviewed CSS missing theme or motion guard")
    print("REVIEWED_HERO_AND_LAYOUT=HASH_VERIFIED")

    layout_file = safe_target(LAYOUT)
    css_file = safe_target(CSS, must_exist=False)
    if git_blob(layout_file.read_bytes()) != OLD_LAYOUT_BLOB:
        blocked("Live layout differs from approved main baseline")
    if git_blob(safe_target(HOME).read_bytes()) != HOME_BLOB:
        blocked("Live homepage content differs from approved baseline")
    manifest = safe_target("public/build/manifest.json").read_bytes()
    parsed = json.loads(manifest)
    for key in ("resources/css/app.css", "resources/js/app.js"):
        asset = parsed.get(key, {}).get("file")
        if not isinstance(asset, str) or not asset.startswith("assets/") or ".." in Path(asset).parts:
            blocked("Unexpected Vite manifest")
        safe_target("public/build/" + asset)

    print("LIVE_HERO_LAYOUT_AND_VITE=VERIFIED")
    return layout_file, css_file, old_layout, new_layout, new_css, sha256(manifest)

def atomic_write(path, data, uid, gid, mode):
    fd, temporary = tempfile.mkstemp(prefix=".bambodia-hero-", dir=str(path.parent))
    try:
        os.fchown(fd, uid, gid)
        os.fchmod(fd, mode)
        with os.fdopen(fd, "wb") as writer:
            writer.write(data)
            writer.flush()
            os.fsync(writer.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)

def clear_views():
    run("runuser", "-u", "www", "--", PHP, "artisan", "view:clear", cwd=str(APP))
    print("VIEW_CACHE=CLEARED")

def apply(head):
    prepared = preflight(head)
    layout, css, previous, reviewed_layout, reviewed_css, manifest_hash = prepared
    stat = layout.stat()
    BACKUP_ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
    if BACKUP_ROOT.is_symlink() or not BACKUP_ROOT.is_dir():
        blocked("Unsafe backup directory")
    backup = Path(tempfile.mkdtemp(prefix="hero-", dir=str(BACKUP_ROOT)))
    os.chmod(backup, 0o700)
    original = backup / "main.blade.php"
    original.write_bytes(previous)
    os.chmod(original, 0o600)
    metadata = backup / "metadata.json"
    metadata.write_text(json.dumps({
        "head": head, "base": BASE, "layout_sha256": sha256(previous),
        "new_layout_sha256": sha256(reviewed_layout),
        "new_css_sha256": sha256(reviewed_css), "manifest_sha256": manifest_hash
    }, indent=2) + "\n")
    os.chmod(metadata, 0o600)
    if original.read_bytes() != previous:
        blocked("Backup integrity mismatch")
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))

    wrote_css = False
    wrote_layout = False
    try:
        if git_blob(safe_target(LAYOUT).read_bytes()) != OLD_LAYOUT_BLOB:
            blocked("Layout changed after preflight")
        safe_target(CSS, must_exist=False)
        atomic_write(css, reviewed_css, stat.st_uid, stat.st_gid, 0o644)
        wrote_css = True
        atomic_write(layout, reviewed_layout, stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
        wrote_layout = True
        clear_views()

        if git_blob(layout.read_bytes()) != NEW_LAYOUT_BLOB or git_blob(css.read_bytes()) != NEW_CSS_BLOB:
            blocked("Deployed file integrity mismatch")
        if sha256(safe_target("public/build/manifest.json").read_bytes()) != manifest_hash:
            blocked("Active Vite manifest changed")
        print("DEPLOYED_FILES=2_HASH_VERIFIED")
        print("DATABASE_NGINX_PHP_FPM_VITE=UNCHANGED_BY_INSTALLER")
        print("RESULT=PUBLIC_HERO_AURORA_LIGHT_DARK_DEPLOYED")
    except Exception:
        if wrote_layout:
            atomic_write(layout, previous, stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
        if wrote_css and css.is_file() and git_blob(css.read_bytes()) == NEW_CSS_BLOB:
            css.unlink()
        clear_views()
        print("ROLLBACK=PREVIOUS_HERO_RESTORED")
        raise

def main():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true")
    mode.add_argument("--apply", action="store_true")
    parser.add_argument("head", help="Pinned GitHub PR #56 SHA")
    args = parser.parse_args()
    if args.check:
        preflight(args.head)
        print("RESULT=READY_FOR_PUBLIC_HERO_AURORA")
    else:
        apply(args.head)

if __name__ == "__main__":
    main()
