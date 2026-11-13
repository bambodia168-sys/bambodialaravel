#!/usr/bin/env python3
"""Fail-closed, backup-first Bambodia Freelance terminology installation.

Only updates 10 approved public Blade/locale files after verifying exact
previous and release Git blobs. Does not change Vite, routes, roles, data,
Nginx, PHP-FPM or the already-installed Hero/Footer styles.
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
RELEASE = "caeb0d7228f0d78d75517484df3f0cd5489dfe2b"
APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
BACKUPS = Path("/root/Bambodia-Freelance-Terminology-Backups")
HERO_CSS = "public/css/home-hero-aurora.css"
HERO_BLOB = "c4220b2db4fdd9a5abd341be5232e869229dfcd5"

# relative file, approved previous blob, reviewed merged-main blob
FILES = [
    ("resources/views/home.blade.php",
     "6b002ecdbaf917f320d6cfe6a6617dfbfb7206af",
     "2e86729cc320a7fd3c93cb1032763ae3f6480074"),
    ("resources/views/layouts/main.blade.php",
     "e66bc1390b98f4876a3a00813ad2b53e6cd116b1",
     "19b4a6243224aebec518ed8614c3ad492dde0dfe"),
    ("lang/de/ui.php", "3a8843e2b72f039e3e664688c26cc673dc8ffcd8",
     "66294ce20fcf798dd7c1084e5cde5d09adb4b88b"),
    ("lang/en/ui.php", "e4c841793aded56346f345d09a40f6d21f9fd67d",
     "3151fa566cf7d6f5441f14b75ed7c81c28299a8e"),
    ("lang/ja/ui.php", "49583f9fea014698b8567f0b88aadf933cbd4a9f",
     "545334ce2eff7e6992e2c740badf2b37a69b1644"),
    ("lang/km/ui.php", "2c931ab0d1b8f87977789bdc9cdc3ade5f67c41a",
     "32833a34a118b35f56c3ba42c9bce67a64031c9c"),
    ("lang/ko/ui.php", "ce67e249d368609cf3aa74182cf5a379b121a535",
     "d3f6da5f7693a60ee67b9d1191807121a6033ed1"),
    ("lang/th/ui.php", "234f33e65998e192035300af3ab4974a13cc6f69",
     "de7b8899f0840456f38ed44c1e76970e83529271"),
    ("lang/vi/ui.php", "ebe764367332c31f6628d13ac30f149cf4f4d16c",
     "34843d853c7b23c62f8ef0b2937278495d9ba73d"),
    ("lang/zh/ui.php", "7e006c09778f49825f81b683d40604bbbf60f311",
     "cd6621eae95eefed67fccbdd5f12533874a62f8d"),
]

def blocked(reason):
    raise RuntimeError("BLOCKED=" + reason)

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\x00" + data).hexdigest()

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=True).stdout

def api(path, raw=False):
    command = ["gh", "api"]
    if raw:
        command += ["-H", "Accept: application/vnd.github.raw+json"]
    return run(*command, "repos/" + REPO + "/" + path)

def target(relative):
    if not APP.is_dir() or APP.is_symlink():
        blocked("Unsafe application root")
    current = APP
    for segment in Path(relative).parts:
        if segment in ("..", "."):
            blocked("Unsafe application path")
        current = current / segment
        if current.is_symlink():
            blocked("Symlink found: " + relative)
    if not current.is_file():
        blocked("Required application file missing: " + relative)
    return current

def preflight():
    if os.geteuid() != 0:
        blocked("Root required")
    for command in ("gh", "git", "runuser", "python3"):
        if shutil.which(command) is None:
            blocked("Missing command: " + command)
    if not Path(PHP).is_file() or not os.access(PHP, os.X_OK):
        blocked("Expected PHP runtime unavailable")
    target("artisan")
    current_main = json.loads(api("commits/main"))["sha"]
    if current_main != RELEASE:
        blocked("GitHub main moved; review and prepare an updated release")
    pr = json.loads(api("pulls/57"))
    if not pr.get("merged") or pr["head"]["sha"] != "930cfd4e36e50171b6569b9f98181774b11e28cb":
        blocked("Approved terminology pull request changed")
    if blob(target(HERO_CSS).read_bytes()) != HERO_BLOB:
        blocked("Previously approved Aurora Hero stylesheet missing or changed")

    downloaded, live, metadata = {}, {}, {}
    for name, old_hash, new_hash in FILES:
        source = api("contents/" + name + "?ref=" + RELEASE, raw=True)
        if blob(source) != new_hash:
            blocked("Unexpected reviewed GitHub blob: " + name)
        local = target(name)
        original = local.read_bytes()
        existing = blob(original)
        if existing not in (old_hash, new_hash):
            blocked("Unreviewed live file: " + name)
        downloaded[name] = source
        live[name] = original
        metadata[name] = (local, local.stat(), existing, old_hash, new_hash)

    statuses = {metadata[name][2] == newer for name, _old, newer in FILES}
    if len(statuses) != 1:
        blocked("Mixed live versions; avoid partial installation")
    already = True in statuses
    print("GITHUB_MAIN_AND_SOURCE=HASH_VERIFIED")
    print("AURORA_HERO=UNCHANGED")
    print("TARGET_FILES=10_REVIEWED")
    print("LIVE_BASELINE=" + ("ALREADY_NEW" if already else "APPROVED_PREVIOUS"))
    return None if already else (downloaded, live, metadata)

def atomic_write(file, content, original_stat):
    descriptor, temp = tempfile.mkstemp(prefix=".terminology-", dir=str(file.parent))
    try:
        os.fchown(descriptor, original_stat.st_uid, original_stat.st_gid)
        os.fchmod(descriptor, stat.S_IMODE(original_stat.st_mode))
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp, file)
    finally:
        if os.path.lexists(temp):
            os.unlink(temp)

def clear_views():
    run("runuser", "-u", "www", "--", PHP, "artisan", "view:clear", cwd=str(APP))
    print("VIEW_CACHE=CLEARED")

def apply():
    checked = preflight()
    if checked is None:
        print("RESULT=FREELANCE_TERMINOLOGY_ALREADY_DEPLOYED")
        return
    downloaded, live, metadata = checked
    if BACKUPS.is_symlink():
        blocked("Backup root is a symlink")
    BACKUPS.mkdir(parents=True, mode=0o700, exist_ok=True)
    if not BACKUPS.is_dir() or BACKUPS.is_symlink():
        blocked("Invalid private backup directory")
    backup = Path(tempfile.mkdtemp(prefix="terms-", dir=str(BACKUPS)))
    os.chmod(backup, 0o700)
    for name, data in live.items():
        backup_file = backup / name
        backup_file.parent.mkdir(parents=True, exist_ok=True)
        backup_file.write_bytes(data)
        os.chmod(backup_file, 0o600)
        if backup_file.read_bytes() != data:
            blocked("Backup mismatch: " + name)
    details = {name: {"previous_sha256": sha256(live[name]),
                      "next_sha256": sha256(downloaded[name])} for name in live}
    manifest = backup / "manifest.json"
    manifest.write_text(json.dumps({"release": RELEASE, "files": details}, indent=2) + "\n")
    os.chmod(manifest, 0o600)
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))

    written = []
    try:
        for name, old_hash, new_hash in FILES:
            file, original_stat, _existing, _old, _new = metadata[name]
            if blob(target(name).read_bytes()) != old_hash:
                blocked("Live file changed since read-only preflight: " + name)
            atomic_write(file, downloaded[name], original_stat)
            written.append(name)
        clear_views()
        for name, _old, new_hash in FILES:
            if blob(target(name).read_bytes()) != new_hash:
                blocked("Post-install integrity mismatch: " + name)
        if blob(target(HERO_CSS).read_bytes()) != HERO_BLOB:
            blocked("Aurora Hero changed during installation")
        print("DEPLOYED_FILES=10_HASH_VERIFIED")
        print("DATABASE_VITE_NGINX_PHP_FPM=UNCHANGED_BY_INSTALLER")
        print("RESULT=FREELANCE_TERMINOLOGY_LIGHT_DARK_DEPLOYED")
    except Exception:
        for name in reversed(written):
            original_stat = metadata[name][1]
            atomic_write(target(name), live[name], original_stat)
        try:
            clear_views()
        except Exception:
            print("ROLLBACK_VIEW_CACHE=MANUAL_CLEAR_REQUIRED")
        print("ROLLBACK=APPROVED_PREVIOUS_COPY_RESTORED")
        raise

def main():
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true")
    modes.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if args.check:
        checked = preflight()
        print("RESULT=" + ("ALREADY_DEPLOYED" if checked is None else "READY_FOR_FREELANCE_TERMINOLOGY"))
    else:
        apply()

if __name__ == "__main__":
    main()
