#!/usr/bin/env python3
"""Guarded aaPanel upgrade for Bambodia Marketplace footer's five concise labels.

This operator tool installs exactly nine reviewed public layout/translation
files after exact old/new Git blob checks. It preserves the Aurora themes,
homepage contents, user/compliance columns, Vite and server configuration.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

REPO = "bambodia168-sys/bambodialaravel"
BASE = "2569dad9ec971e5db2a5299b4260ea93950cc4e9"
BRANCH = "style/footer-marketplace-short-labels-20261113"
APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
BACKUPS = Path("/root/Bambodia-Footer-Marketplace-Backups")
HERO_CSS = ("public/css/home-hero-aurora.css",
            "c4220b2db4fdd9a5abd341be5232e869229dfcd5")
FOOTER_CSS = ("public/css/footer-two-tone-themes.css",
              "4cbf944d33f622ebf282e7eb2f885d6af150d07a")

# Path, exact approved live baseline SHA, exact reviewed target blob SHA
FILES = [
    ("resources/views/layouts/main.blade.php", "19b4a6243224aebec518ed8614c3ad492dde0dfe", "2b205100d84729a45e3f99d16681e9977b470afc"),
    ("lang/en/ui.php", "3151fa566cf7d6f5441f14b75ed7c81c28299a8e", "4cbcab7ec3b56f52fe101d4d4e179d35f9a1f073"),
    ("lang/de/ui.php", "66294ce20fcf798dd7c1084e5cde5d09adb4b88b", "b48cf5b0576b07e825bcad1b17ee7874e6ac5244"),
    ("lang/ja/ui.php", "545334ce2eff7e6992e2c740badf2b37a69b1644", "1d3bd1233f12c7f34cea1238450568241faa6e23"),
    ("lang/km/ui.php", "32833a34a118b35f56c3ba42c9bce67a64031c9c", "371af654540078fdb34c3ca9c5991dbff659acbd"),
    ("lang/ko/ui.php", "d3f6da5f7693a60ee67b9d1191807121a6033ed1", "9b28dbd072b507ecc8b93821d93faefdbf8a6e9b"),
    ("lang/th/ui.php", "de7b8899f0840456f38ed44c1e76970e83529271", "884ef23632bc819ef85d28c5461250e67c67bb41"),
    ("lang/vi/ui.php", "34843d853c7b23c62f8ef0b2937278495d9ba73d", "f79fe1fbebc89063efe3e554cc4b106608ed666c"),
    ("lang/zh/ui.php", "cd6621eae95eefed67fccbdd5f12533874a62f8d", "20058d2a1209dc0d7ad9a241af8f9737c6ee29e6"),
]

def blocked(reason):
    raise RuntimeError("BLOCKED=" + reason)

def git_blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii")
                        + b"\x00" + data).hexdigest()

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def api(path, raw=False):
    command = ["gh", "api"]
    if raw:
        command += ["-H", "Accept: application/vnd.github.raw+json"]
    return run(*command, "repos/" + REPO + "/" + path)

def safe_file(relative):
    if APP.is_symlink() or not APP.is_dir():
        blocked("Unexpected application root")
    p = APP
    for part in Path(relative).parts:
        if part in ("..", "."):
            blocked("Unsafe application path")
        p = p / part
        if p.is_symlink():
            blocked("Symlink in application path: " + relative)
    if not p.is_file():
        blocked("Missing regular application file: " + relative)
    return p

def preflight(head):
    if os.geteuid() != 0:
        blocked("Root operator required")
    for cmd in ("gh", "git", "python3", "runuser"):
        if shutil.which(cmd) is None:
            blocked("Required command missing: " + cmd)
    if not Path(PHP).is_file() or not os.access(PHP, os.X_OK):
        blocked("Approved PHP runtime is unavailable")
    safe_file("artisan")
    if json.loads(api("commits/main"))["sha"] != BASE:
        blocked("Main moved; reconcile before deployment")
    pr = json.loads(api("pulls/59"))
    if (pr["head"]["sha"] != head or pr["head"]["ref"] != BRANCH
            or pr["base"]["sha"] != BASE or pr["base"]["ref"] != "main"
            or pr["state"] != "open" or not pr["draft"]):
        blocked("Reviewed pull request #59 changed")

    for relative, expected in (HERO_CSS, FOOTER_CSS):
        if git_blob(safe_file(relative).read_bytes()) != expected:
            blocked("Previously approved Aurora stylesheet changed: " + relative)

    original = {}
    reviewed = {}
    paths = {}
    states = []
    for path, previous_blob, next_blob in FILES:
        base = api("contents/" + path + "?ref=" + BASE, raw=True)
        update = api("contents/" + path + "?ref=" + head, raw=True)
        if git_blob(base) != previous_blob or git_blob(update) != next_blob:
            blocked("Reviewed source blob mismatch: " + path)
        current_path = safe_file(path)
        live = current_path.read_bytes()
        current_blob = git_blob(live)
        if current_blob not in (previous_blob, next_blob):
            blocked("Unreviewed live file: " + path)
        states.append(current_blob == next_blob)
        original[path] = live
        reviewed[path] = update
        paths[path] = (current_path, current_path.stat())
    if len(set(states)) != 1:
        blocked("Mixed live versions: stop before partial installation")

    manifest = safe_file("public/build/manifest.json").read_bytes()
    parsed = json.loads(manifest)
    for key in ("resources/css/app.css", "resources/js/app.js"):
        asset = parsed.get(key, {}).get("file")
        if not isinstance(asset, str) or not asset.startswith("assets/") or ".." in Path(asset).parts:
            blocked("Unexpected active Vite asset manifest")
        safe_file("public/build/" + asset)

    print("GITHUB_SOURCE_AND_SCOPE=HASH_VERIFIED")
    print("AURORA_HERO_AND_FOOTER=UNCHANGED")
    print("VITE_ASSETS=PRESERVED")
    print("REVIEWED_FILES=9")
    print("LIVE_BASELINE=" + ("ALREADY_NEW" if states[0] else "APPROVED_PREVIOUS"))
    if states[0]:
        return None
    return original, reviewed, paths, sha256(manifest)

def atomic_write(file, contents, st):
    descriptor, temporary = tempfile.mkstemp(prefix=".footer-marketplace-", dir=str(file.parent))
    try:
        os.fchown(descriptor, st.st_uid, st.st_gid)
        os.fchmod(descriptor, stat.S_IMODE(st.st_mode))
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(contents)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, file)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)

def clear_views():
    run("runuser", "-u", "www", "--", PHP, "artisan", "view:clear", cwd=str(APP))
    print("VIEW_CACHE=CLEARED")

def apply(head):
    result = preflight(head)
    if result is None:
        print("RESULT=FOOTER_MARKETPLACE_ALREADY_DEPLOYED")
        return
    original, reviewed, paths, manifest_digest = result
    if BACKUPS.is_symlink():
        blocked("Backup root is a symlink")
    BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
    if BACKUPS.is_symlink() or not BACKUPS.is_dir():
        blocked("Unsafe backup root")
    backup = Path(tempfile.mkdtemp(prefix="footer-", dir=str(BACKUPS)))
    os.chmod(backup, 0o700)
    for path, data in original.items():
        item = backup / path
        item.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        item.write_bytes(data)
        os.chmod(item, 0o600)
        if item.read_bytes() != data:
            blocked("Backup verification failed: " + path)
    manifest = backup / "manifest.json"
    manifest.write_text(json.dumps({
        "release_pr_head": head,
        "base": BASE,
        "files": {
            path: {"old_sha256": sha256(original[path]),
                   "new_sha256": sha256(reviewed[path])}
            for path in original
        },
        "vite_manifest_sha256": manifest_digest
    }, indent=2) + "\n")
    os.chmod(manifest, 0o600)
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))

    written = []
    try:
        for path, old_blob, _new_blob in FILES:
            current, st = paths[path]
            if git_blob(safe_file(path).read_bytes()) != old_blob:
                blocked("Live file changed since preflight: " + path)
            atomic_write(current, reviewed[path], st)
            written.append(path)
        clear_views()
        for path, _old_blob, next_blob in FILES:
            if git_blob(safe_file(path).read_bytes()) != next_blob:
                blocked("Deployed blob mismatch: " + path)
        for relative, expected in (HERO_CSS, FOOTER_CSS):
            if git_blob(safe_file(relative).read_bytes()) != expected:
                blocked("Aurora CSS changed during deployment")
        if sha256(safe_file("public/build/manifest.json").read_bytes()) != manifest_digest:
            blocked("Vite manifest changed during deployment")
        print("DEPLOYED_FILES=9_HASH_VERIFIED")
        print("VITE_DATABASE_NGINX_PHP_FPM=UNCHANGED_BY_INSTALLER")
        print("RESULT=PUBLIC_FOOTER_MARKETPLACE_SHORT_LABELS_DEPLOYED")
    except Exception:
        for path in reversed(written):
            current, st = paths[path]
            atomic_write(current, original[path], st)
        try:
            clear_views()
        except Exception:
            print("ROLLBACK_VIEW_CACHE=MANUAL_CLEAR_REQUIRED")
        print("ROLLBACK=PREVIOUS_MARKETPLACE_FOOTER_RESTORED")
        raise

def main():
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true")
    modes.add_argument("--apply", action="store_true")
    parser.add_argument("head", help="Pinned PR #59 head commit SHA")
    args = parser.parse_args()
    if args.check:
        result = preflight(args.head)
        print("RESULT=" + ("ALREADY_DEPLOYED" if result is None
                           else "READY_FOR_FOOTER_MARKETPLACE_SHORT_LABELS"))
    else:
        apply(args.head)

if __name__ == "__main__":
    main()
