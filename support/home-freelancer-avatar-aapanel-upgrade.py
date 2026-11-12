#!/usr/bin/env python3
"""Guarded aaPanel professionally framed freelancer headshot release. --check is read-only."""
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
BRANCH = "fix/home-freelancer-avatar-link-20261112"
BASE = "133f82a3e8681f3efbc8de9424a83dc3306e15d5"
APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
BACKUPS = Path("/root/Bambodia-Home-Freelancer-Headshot-Backups")
LIVE_BASELINES = {
    "app/Http/Controllers/HomeController.php": "c1f974d2f36d97c40094514e2a162c5ec543cb61",
    "resources/views/home.blade.php": "65c5da2e3a1e4b5b3d6999fd430e9319a790ffa8",
    "resources/css/app.css": "cd99155b75dcc109d2220c6c22ca2a849b347f30",
    "resources/js/app.js": "4bc4f4096e02249cf2a0bcb10438139b324ae0c3",
}
REVIEWED_SOURCES = {
    "app/Http/Controllers/HomeController.php": "c1f974d2f36d97c40094514e2a162c5ec543cb61",
    "resources/views/home.blade.php": "65c5da2e3a1e4b5b3d6999fd430e9319a790ffa8",
    "resources/css/app.css": "0ee0d9eb8483226f1cec85fecad1002b3188f4ad",
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

def preflight(expected_head=None, require_node=False):
    if os.geteuid() != 0:
        refuse("Root operator required")
    binaries = ("gh", "git", "python3", "node", "npm") if require_node else ("gh", "git", "python3")
    for binary in binaries:
        if not shutil.which(binary):
            refuse("Missing executable: " + binary)
    if APP.is_symlink() or APP.resolve() != APP or not (APP / "artisan").is_file():
        refuse("Wrong application root")
    if "root " + str(APP / "public") + ";" not in protected_file(VHOST).read_text():
        refuse("Nginx document root changed")
    protected_file(APP / ".env")
    for relative in ("app", "app/Http", "app/Http/Controllers", "resources",
                     "resources/views", "resources/css", "public",
                     "public/build", "public/build/assets"):
        directory = APP / relative
        if directory.is_symlink() or not directory.is_dir():
            refuse("Unsafe live directory: " + relative)
    if cmd("gh", "api", "repos/" + REPO + "/commits/main", "--jq", ".sha") != BASE:
        refuse("GitHub main changed")
    pr = json.loads(cmd("gh", "api", "repos/" + REPO + "/pulls/47"))
    if (pr["head"]["ref"] != BRANCH or pr["base"]["ref"] != "feat/home-discovery-responsive-grids-20261112"
            or pr["state"] != "open" or not pr["draft"]):
        refuse("PR #47 changed")
    if expected_head is not None and pr["head"]["sha"] != expected_head:
        refuse("PR #47 head changed since CI verification")
    for rel, expected in LIVE_BASELINES.items():
        if git_blob(protected_file(APP / rel)) != expected:
            refuse("LIVE_FILE_DIVERGED_FROM_PREVIOUS_RELEASE=" + rel)
        print("SOURCE_BASELINE=VERIFIED " + rel)
    for rel, expected in INSTALLED_HEADER.items():
        if git_blob(protected_file(APP / rel)) != expected:
            refuse("Previously deployed header drift: " + rel)
    print("HEADER_FLAG_AND_BACK_TO_TOP=PRESERVED")
    manifest = json.loads(protected_file(APP / "public/build/manifest.json").read_text())
    if str(asset_name(manifest.get("resources/css/app.css"))) != "assets/app-3sdvcUpS.css":
        refuse("Expected previous responsive CSS asset changed")
    for key in ("resources/css/app.css", "resources/js/app.js"):
        value = asset_name(manifest.get(key))
        protected_file(APP / "public/build" / str(value))
    print("LIVE_VITE_MANIFEST=VERIFIED")

def prepare(source, staged, bundle=None):
    for rel, expected in REVIEWED_SOURCES.items():
        if git_blob(protected_file(source / rel)) != expected:
            refuse("Reviewed source mismatch: " + rel)
    print("REVIEWED_SOURCE=VERIFIED")
    if bundle is None:
        cmd("npm", "install", "--ignore-scripts", "--no-audit", "--no-fund",
            cwd=source, output=False)
        cmd("npm", "run", "build", cwd=source, output=False)
        bundle = source / "public/build"
    else:
        print("VITE_BUILD_SOURCE=VERIFIED_GITHUB_ACTIONS_ARTIFACT")
    live_manifest = protected_file(APP / "public/build/manifest.json")
    manifest_hash = sha256(live_manifest)
    old = json.loads(live_manifest.read_text())
    new = json.loads(protected_file(bundle / "manifest.json").read_text())
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
    protected_file(bundle / str(new_asset))
    if (APP / "public/build" / str(new_asset)).exists():
        refuse("Compiled CSS asset already exists")
    for rel in ("app/Http/Controllers/HomeController.php",
                "resources/views/home.blade.php", "resources/css/app.css"):
        target = staged / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source / rel, target)
    target = staged / "public/build" / str(new_asset)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(bundle / str(new_asset), target)
    old[css]["file"] = str(new_asset)
    (staged / "public/build/manifest.json").write_text(
        json.dumps(old, ensure_ascii=False, indent=2) + "\n")
    php = shutil.which("php") or next((x for x in ("/www/server/php/85/bin/php",
             "/www/server/php/84/bin/php") if Path(x).is_file()), None)
    if not php:
        refuse("PHP CLI unavailable for source lint")
    cmd(php, "-l", str(staged / "app/Http/Controllers/HomeController.php"))
    print("COMPILED_CSS=VERIFIED " + str(new_asset))
    return str(new_asset), manifest_hash

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

def install(staged, asset, manifest_hash, expected_head=None):
    # Repeat all drift checks after downloading/building before any live write.
    preflight(expected_head=expected_head)
    if sha256(APP / "public/build/manifest.json") != manifest_hash:
        refuse("Live Vite manifest changed during isolated build")
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
    print("RESULT=HOME_FREELANCER_HEADSHOT_FRAMING_DEPLOYED")

def verified_ci_bundle(run_id, source, folder):
    if not run_id.isdigit() or not 1 <= len(run_id) <= 20:
        refuse("Invalid GitHub Actions run ID")
    run = json.loads(cmd("gh", "api", "repos/" + REPO + "/actions/runs/" + run_id))
    head = cmd("git", "-C", str(source), "rev-parse", "HEAD")
    if (run.get("head_sha") != head or run.get("status") != "completed"
            or run.get("conclusion") != "success"
            or run.get("name") != "Bambodia Quality"
            or run.get("event") != "pull_request"
            or run.get("repository", {}).get("full_name") != REPO):
        refuse("GitHub CI bundle does not belong to successful reviewed commit")
    current = json.loads(cmd("gh", "api", "repos/" + REPO + "/pulls/47"))
    if head != current["head"]["sha"]:
        refuse("Source branch moved since approved CI run")
    artifacts = json.loads(cmd("gh", "api", "repos/" + REPO + "/actions/runs/" + run_id + "/artifacts"))
    candidates = [x for x in artifacts.get("artifacts", [])
                  if x.get("name") == "Bambodia-Home-Discovery-Vite"
                  and not x.get("expired") and x.get("size_in_bytes", 0) > 0]
    if len(candidates) != 1:
        refuse("Verified compiled Vite artifact missing or expired")
    cmd("gh", "run", "download", run_id, "--repo", REPO,
        "--name", "Bambodia-Home-Discovery-Vite",
        "--dir", str(folder), output=False)
    protected_file(folder / "manifest.json")
    if (folder / "assets").is_symlink() or not (folder / "assets").is_dir():
        refuse("Compiled Vite artifact directory unsafe")
    print("CI_BUILD_ARTIFACT=VERIFIED_SUCCESSFUL_RUN " + run_id)
    return head

def main():
    check = sys.argv[1:] == ["--check"]
    local = sys.argv[1:] == ["--apply"]
    ci = (len(sys.argv) == 3 and sys.argv[1] == "--apply-ci")
    if not (check or local or ci):
        refuse("Usage: home-freelancer-avatar-aapanel-upgrade.py --check|--apply|--apply-ci RUN_ID")
    preflight(require_node=local)
    if check:
        print("DEPLOYMENT=NOT_PERFORMED")
        print("RESULT=READY_FOR_VERIFIED_HOME_DISCOVERY_RELEASE")
        return
    with tempfile.TemporaryDirectory(prefix="bambodia-home-build-", dir="/root") as folder:
        base = Path(folder)
        source, staged = base / "source", base / "staged"
        cmd("gh", "repo", "clone", REPO, str(source), "--", "--branch", BRANCH,
            "--single-branch", "--depth", "1", output=False)
        staged.mkdir(mode=0o700)
        bundle, head = None, None
        if ci:
            bundle = base / "ci-build"
            bundle.mkdir(mode=0o700)
            head = verified_ci_bundle(sys.argv[2], source, bundle)
        asset, manifest_hash = prepare(source, staged, bundle=bundle)
        install(staged, asset, manifest_hash, expected_head=head)

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(2)
