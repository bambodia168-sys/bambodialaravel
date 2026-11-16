#!/usr/bin/env python3
"""Replace the featured-media filter in the verified live Builder Studio bundle."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

APP = Path("/www/wwwroot/bambodia-staging-qa")
LOCK = Path("/run/lock/bambodia-marketplace-transfer.lock")
MANIFEST = APP / "public/build/manifest.json"
ENTRY = "resources/js/app.js"
OLD_FILE = "assets/app-DEWRnOWT.js"
OLD_SHA256 = "d637784f58e9837c45abd5ce0bf45049d36278554c994f0349766b8accbcad97"
OLD_INITIALIZE = b"let t=E.value===\x60\x60;Array.from(E.options)"
NEW_INITIALIZE = b"let t=E.value,o=\x60\x60;Array.from(E.options)"
OLD_FILTER = b"n.selected&&!i&&(t=!1)}),t||(E.value=\x60\x60)"
NEW_FILTER = b"i&&n.value===t&&(o=t)}),E.value=o"


def checked_file(path):
    if not path.is_file() or path.is_symlink() or path.resolve() != path:
        raise RuntimeError(f"Missing or unexpected path: {path}")
    return path


def digest(data):
    return hashlib.sha256(data).hexdigest()


def patch_bundle(original):
    if digest(original) != OLD_SHA256:
        raise RuntimeError("Unexpected Builder Studio bundle checksum")
    if original.count(OLD_INITIALIZE) != 1 or original.count(OLD_FILTER) != 1:
        raise RuntimeError("Featured media filter does not match")
    return original.replace(OLD_INITIALIZE, NEW_INITIALIZE, 1).replace(OLD_FILTER, NEW_FILTER, 1)


def write_file(target, data, reference):
    permissions = reference.stat()
    descriptor, temp = tempfile.mkstemp(prefix=".featured-media-", dir=target.parent)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            os.fchown(stream.fileno(), permissions.st_uid, permissions.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(permissions.st_mode))
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp, target)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def verify_http(asset):
    result = subprocess.run(
        ["curl", "-ksSf", "--max-time", "20", "--resolve", "bambodia.com:443:127.0.0.1",
         f"https://bambodia.com/build/{asset}"], check=True, capture_output=True,
    )
    return result.stdout


def deploy(check=False):
    manifest = checked_file(MANIFEST)
    previous = manifest.read_bytes()
    entries = json.loads(previous)
    old_path = checked_file(manifest.parent / OLD_FILE)
    patched = patch_bundle(old_path.read_bytes())
    target_relative = "assets/app-featured-media-"+digest(patched)[:12]+".js"
    target = manifest.parent / target_relative
    active = entries[ENTRY]["file"]

    if active == target_relative:
        if checked_file(target).read_bytes() != patched or verify_http(target_relative) != patched:
            raise RuntimeError("Installed Builder Studio bundle does not match")
        print("BAMBODIA_FEATURED_MEDIA=PASS")
        return
    if active != OLD_FILE:
        raise RuntimeError(f"Unexpected active JavaScript bundle: {active}")
    if check:
        print("BAMBODIA_FEATURED_MEDIA_PREFLIGHT=PASS")
        return
    if target.is_symlink() or (target.exists() and checked_file(target).read_bytes() != patched):
        raise RuntimeError("The new Builder Studio bundle path already has different contents")
    if not target.exists():
        write_file(target, patched, old_path)
    if verify_http(target_relative) != patched:
        raise RuntimeError("The web server did not serve the new Builder Studio bundle")

    backup = Path(tempfile.mkdtemp(prefix="bambodia-featured-media-backup.", dir="/root"))
    shutil.copy2(manifest, backup / "manifest.json")
    print(f"BACKUP={backup}", flush=True)

    entries[ENTRY]["file"] = target_relative
    replacement = (json.dumps(entries, indent=2, ensure_ascii=False) + "\n").encode()
    if manifest.read_bytes() != previous:
        raise RuntimeError("The live Vite manifest changed during staging")
    write_file(manifest, replacement, manifest)
    try:
        home = subprocess.run(
            ["curl", "-ksSf", "--max-time", "20", "--resolve", "bambodia.com:443:127.0.0.1",
             "-H", "Cache-Control: no-cache", "https://bambodia.com/"], check=True, capture_output=True,
        ).stdout
        if ("/build/"+target_relative).encode() not in home or manifest.read_bytes() != replacement:
            raise RuntimeError("The public homepage did not load the new Builder Studio bundle")
    except BaseException:
        if manifest.read_bytes() == replacement:
            write_file(manifest, previous, manifest)
        raise
    print("BAMBODIA_FEATURED_MEDIA=PASS")


def main():
    import fcntl

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    if os.geteuid() != 0 or APP.resolve() != APP or not (APP / "artisan").is_file():
        raise RuntimeError("Run as root on the Bambodia aaPanel host")
    os.umask(0o077)
    with LOCK.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        deploy(check=args.check)


if __name__ == "__main__":
    try:
        main()
    except (KeyError, OSError, RuntimeError, ValueError, subprocess.SubprocessError) as error:
        raise SystemExit(f"BAMBODIA_FEATURED_MEDIA_BLOCKED={error}") from None
