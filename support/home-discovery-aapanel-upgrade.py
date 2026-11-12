#!/usr/bin/env python3
"""Guarded aaPanel homepage responsive release. --check is read-only."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tempfile

REPO = "bambodia168-sys/bambodialaravel"
BRANCH = "feat/home-discovery-responsive-grids-20261112"
BASE = "133f82a3e8681f3efbc8de9424a83dc3306e15d5"
APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
BACKUPS = Path("/root/Bambodia-Home-Discovery-Backups")
LIVE_BASELINES = {
    "app/Http/Controllers/HomeController.php": "c0978cc3999961ee0c65671b23997a278e887e31",
    "resources/views/home.blade.php": "46e351faeb30d0f49ee686fad84a8e7a900c4e26",
    "resources/css/app.css": "40c59724376cb324b83633eb79f8ddca652d0667",
    "resources/js/app.js": "4bc4f4096e02249cf2a0bcb10438139b324ae0c3",
}
REVIEWED_SOURCES = {
    "app/Http/Controllers/HomeController.php": "a4d40e93a9c4be179dc6f2757eb9ca513017d1e2",
    "resources/views/home.blade.php": "e9ca6d91a1c79caf13d9e2dfceec7d0255054e22",
    "resources/css/app.css": "44f2f88256601478da7c17362426241d9af8d388",
    "resources/js/app.js": "4bc4f4096e02249cf2a0bcb10438139b324ae0c3",
    "package.json": "13ec88546892c1fd790d2161d7226c14e7be0b54",
    "vite.config.js": "1fd66d538c217d36db94b62968257a7d9a558bbb",
}
INSTALLED_HEADER = {
    "resources/views/layouts/main.blade.php": "ff63f82134dc0dde7554aeeb4fe6368396b25b85",
    "resources/views/layouts/workspace.blade.php": "97181be482e569ff02220c475d59ab41dbcab427",
    "public/css/header-flag-scroll-top.css": "3037d6879898ff47b861c025fbb4285dccfa6fca",
}

def refuse(message):
    raise RuntimeError("BLOCKED=" + message)

def cmd(*args, cwd=None, output=True):
    result = subprocess.run(args, cwd=cwd, text=True, check=True,
                            capture_output=output)
    return result.stdout.strip() if output else ""

def git_blob(path):
    return cmd("git", "hash-object", str(path))

def protected_file(path):
    if path.is_symlink() or not path.is_file():
        refuse("missing or symlink file: " + str(path))
    return path

def sha256(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for part in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(part)
    return h.hexdigest()

def asset_name(entry):
    if not isinstance(entry, dict) or not isinstance(entry.get("file"), str):
        refuse("Vite manifest missing file entry")
    value = PurePosixPath(entry["file"])
    if (value.is_absolute() or len(value.parts) != 2
            or value.parts[0] != "assets" or ".." in value.parts):
        refuse("unexpected Vite asset path")
    return value

def preflight():
    if os.geteuid() != 0:
        refuse("Root operator required")
    for binary in ("gh", "git", "python3", "node", "npm"):
        if not shutil.which(binary):
            refuse("Missing executable: " + binary)
    if APP.is_symlink() or APP.resolve() != APP or not (APP / "artisan").is_file():
        refuse("Wrong application root")
    if "root " + str(APP / "public") + ";" not in protected_file(VHOST).read_text():
        refuse("Nginx document root changed")
    protected_file(APP / ".env")
    for relative in ("app/Http/Controllers", "resources/views", "resources/css",
                     "public/build", "public/build/assets"):
        directory = APP / relative
        if directory.is_symlink() or not directory.is_dir():
            refuse("Unsafe live directory: " + relative)
    if cmd("gh", "api", "repos/" + REPO + "/commits/main", "--jq", ".sha") != BASE:
        refuse("GitHub main changed")
    pr = json.loads(cmd("gh", "api", "repos/" + REPO + "/pulls/45"))
    if (pr["head"]["ref"] != BRANCH or pr["base"]["ref"] != "main"
            or pr["state"] != "open" or not pr["draft"]):
        refuse("PR #45 changed")
    for rel, expected in LIVE_BASELINES.items():
        if git_blob(protected_file(APP / rel)) != expected:
            refuse("LIVE_FILE_DIVERGED_FROM_APPROVED_MAIN=" + rel)
        print("SOURCE_BASELINE=VERIFIED " + rel)
    for rel, expected in INSTALLED_HEADER.items():
        if git_blob(protected_file(APP / rel)) != expected:
            refuse("Previously deployed header drift: " + rel)
    print("HEADER_FLAG_AND_BACK_TO_TOP=PRESERVED")
    manifest = json.loads(protected_file(APP / "public/build/manifest.json").read_text())
    for key in ("resources/css/app.css", "resources/js/app.js"):
        value = asset_name(manifest.get(key))
        protected_file(APP / "public/build" / str(value))
    print("LIVE_VITE_MANIFEST=VERIFIED")

def prepare(source, staged):
    for rel, expected in REVIEWED_SOURCES.items():
        if git_blob(protected_file(source / rel)) != expected:
            refuse("Reviewed source mismatch: " + rel)
    print("REVIEWED_SOURCE=VERIFIED")
    cmd("npm", "install", "--ignore-scripts", "--no-audit", "--no-fund",
        cwd=source, output=False)
    cmd("npm", "run", "build", cwd=source, output=False)
    old = json.loads(protected_file(APP / "public/build/manifest.json").read_text())
    new = json.loads(protected_file(source / "public/build/manifest.json").read_text())
    css = "resources/css/app.css"
    if old.keys() != new.keys():
        refuse("Vite manifest keys diverged")
    if any(old[k] != new[k] for k in old if k != css):
        refuse("Vite JS or other asset manifest drift")
    if ({k: v for k, v in old[css].items() if k != "file"} !=
            {k: v for k, v in new[css].items() if k != "file"}):
        refuse("CSS Vite manifest metadata drift")
    old_asset, new_asset = asset_name(old[css]), asset_name(new[css])
    if (old_asset == new_asset or new_asset.suffix != ".css"):
        refuse("Expected new CSS bundle missing")
    protected_file(source / "public/build" / str(new_asset))
    if (APP / "public/build" / str(new_asset)).exists():
        refuse("Compiled CSS asset already exists")
    for rel in ("app/Http/Controllers/HomeController.php",
                "resources/views/home.blade.php", "resources/css/app.css"):
        target = staged / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source / rel, target)
    target = staged / "public/build" / str(new_asset)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source / "public/build" / str(new_asset), target)
    old[css]["file"] = str(new_asset)
    (staged / "public/build/manifest.json").write_text(
        json.dumps(old, ensure_ascii=False, indent=2) + "\n")
    cmd("php", "-l", str(staged / "app/Http/Controllers/HomeController.php"))
    print("COMPILED_CSS=VERIFIED " + str(new_asset))
    return str(new_asset)

def atomic_copy(src, dst, owner=None):
    fd, name = tempfile.mkstemp(prefix=".home-discovery-", dir=dst.parent)
    try:
        with os.fdopen(fd, "wb") as stream, src.open("rb") as original:
            shutil.copyfileobj(original, stream)
            stream.flush()
            os.fsync(stream.fileno())
        if owner is None:
            old = dst.stat()
            owner = (old.st_uid, old.st_gid, stat.S_IMODE(old.st_mode))
        os.chown(name, owner[0], owner[1])
        os.chmod(name, owner[2])
        os.replace(name, dst)
    finally:
        if os.path.lexists(name):
            os.unlink(name)

def install(staged, asset):
    # Repeat all drift checks after downloading/building before any live write.
    preflight()
    if BACKUPS.is_symlink():
        refuse("Backup root is a symlink")
    BACKUPS.mkdir(mode=0o700, exist_ok=True)
    if BACKUPS.stat().st_uid != 0:
        refuse("Backup root is not root owned")
    BACKUPS.chmod(0o700)
    backup = Path(tempfile.mkdtemp(prefix="home-", dir=BACKUPS))
    backup.chmod(0o700)
    files = ["app/Http/Controllers/HomeController.php",
             "resources/views/home.blade.php", "resources/css/app.css",
             "public/build/manifest.json"]
    asset_rel = "public/build/" + asset
    order = [asset_rel] + files
    originals = {}
    for rel in order:
        live = APP / rel
        if live.is_symlink() or (rel != asset_rel and not live.is_file()):
            refuse("Target changed unexpectedly: " + rel)
        if rel == asset_rel:
            if live.exists():
                refuse("New CSS asset appeared concurrently")
            originals[rel] = None
        else:
            original = backup / "original" / rel
            original.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(live, original)
            info = live.stat()
            originals[rel] = {"uid": info.st_uid, "gid": info.st_gid,
                              "mode": stat.S_IMODE(info.st_mode),
                              "sha256": sha256(live)}
            if sha256(original) != originals[rel]["sha256"]:
                refuse("Backup integrity failed: " + rel)
    (backup / "manifest.json").write_text(json.dumps(originals, indent=2) + "\n")
    (backup / "manifest.json").chmod(0o600)
    print("BACKUP=HASH_VERIFIED")
    changed = []
    try:
        for rel in order:
            destination, source = APP / rel, staged / rel
            data = originals[rel]
            if destination.is_symlink():
                raise RuntimeError("Symlink introduced: " + rel)
            if data and (not destination.is_file() or sha256(destination) != data["sha256"]):
                raise RuntimeError("Concurrent drift: " + rel)
            if data is None:
                directory = (APP / "public/build/assets").stat()
                owner = (directory.st_uid, directory.st_gid, 0o644)
            else:
                owner = (data["uid"], data["gid"], data["mode"])
            atomic_copy(source, destination, owner)
            changed.append(rel)
        for rel in order:
            if sha256(APP / rel) != sha256(staged / rel):
                raise RuntimeError("Installed checksum mismatch: " + rel)
    except BaseException:
        for rel in reversed(changed):
            data = originals[rel]
            destination = APP / rel
            if data is None:
                destination.unlink(missing_ok=True)
            else:
                atomic_copy(backup / "original" / rel, destination,
                            (data["uid"], data["gid"], data["mode"]))
        print("FAILURE=RESTORE_ATTEMPTED")
        raise
    print("PRIVATE_BACKUP_DIR=" + str(backup))
    print("DEPLOYED_FILES=5_HASH_VERIFIED")
    print("HEADER_LANGUAGE_NGINX_DATABASE_PHP_FPM=UNCHANGED")
    print("RESULT=HOME_DISCOVERY_RESPONSIVE_DEPLOYED")

def main():
    args = argparse.ArgumentParser(description=__doc__)
    args.add_argument("mode", choices=("--check", "--apply"))
    options = args.parse_args()
    preflight()
    if options.mode == "--check":
        print("DEPLOYMENT=NOT_PERFORMED")
        print("RESULT=READY_FOR_ISOLATED_HOME_DISCOVERY_BUILD")
        return
    with tempfile.TemporaryDirectory(prefix="bambodia-home-build-", dir="/root") as folder:
        base = Path(folder)
        source, staged = base / "source", base / "staged"
        cmd("gh", "repo", "clone", REPO, str(source), "--", "--branch", BRANCH,
            "--single-branch", "--depth", "1", output=False)
        staged.mkdir(mode=0o700)
        asset = prepare(source, staged)
        install(staged, asset)

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(2)
