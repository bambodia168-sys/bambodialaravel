#!/usr/bin/env python3
"""Apply the marketplace manager source delta to the verified aaPanel installation."""
import fcntl
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = Path("/www/server/php/85/bin/php")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
SITE = "https://bambodia.com"
PATCH_SHA256 = "394287368a358541ffe2e5621a5818320d7c87b04d5b8a18b3a11da431b7e2af"
JOB_VIEW = Path("resources/views/dashboard/jobs.blade.php")
LIVE_JOB_VIEW_BLOB = "c3273f0a279908c691f8661de8a59d892d690fdf"
ROUTES = {
    "dashboard.marketplace.index", "dashboard.jobs.index", "dashboard.products.index",
    "dashboard.marketplace.asset", "dashboard.marketplace.status",
    "dashboard.marketplace.transfer", "dashboard.marketplace.transfer.store", "builder.manage.update",
}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(args, *, cwd=APP, quiet=False):
    result = subprocess.run(args, cwd=cwd, capture_output=True, text=True)
    if result.returncode:
        label = " ".join(args[5:7]) if args[0] == "runuser" else args[0]
        detail = "" if quiet else result.stderr.strip() or result.stdout.strip()
        raise RuntimeError(f"{label} exited with {result.returncode}: {detail}")
    return result.stdout


def artisan(*args):
    return run(["runuser", "-u", "www", "--", str(PHP), "artisan", *args], quiet=True)


def blob(data):
    return hashlib.sha1(f"blob {len(data)}\0".encode() + data).hexdigest()


def verify_site():
    require(os.geteuid() == 0, "Run this operator as root.")
    require(APP.resolve() == APP and (APP / "artisan").is_file() and os.access(PHP, os.X_OK),
            "The expected Laravel or PHP installation is missing.")
    nginx = re.sub(r"#[^\n]*", "", VHOST.read_text())
    roots = re.findall(r"(?m)^\s*root\s+([^;]+);", nginx)
    names = re.findall(r"(?m)^\s*server_name\s+([^;]+);", nginx)
    require(any("bambodia.com" in values.split() for values in names)
            and str(APP / "public") in {value.strip().strip('"\'') for value in roots},
            "The Bambodia vhost does not point to the expected app.")
    snippet = (
        'require "vendor/autoload.php"; $app = require "bootstrap/app.php";'
        '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        'echo json_encode([config("app.url"), $app->isDownForMaintenance()]);'
    )
    app_url, is_down = json.loads(run(
        ["runuser", "-u", "www", "--", str(PHP), "-r", snippet], quiet=True))
    require(app_url.rstrip("/") == SITE and not is_down,
            "The effective app URL is different or the site is in maintenance mode.")
    artisan("bambodia:taxonomy:verify")


def snapshot(path):
    if not path.exists():
        return None
    require(path.is_file() and not path.is_symlink(), f"Unexpected source file type: {path}")
    return path.read_bytes()


def install(path, data, metadata):
    parents = []
    directory = path.parent
    while not directory.exists():
        parents.append(directory)
        directory = directory.parent
    for directory in reversed(parents):
        directory.mkdir()
        directory.chmod(0o755)
    fd, temp = tempfile.mkstemp(prefix=".manager-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
            os.fchown(handle.fileno(), metadata[0], metadata[1])
            os.fchmod(handle.fileno(), metadata[2])
        os.replace(temp, path)
    finally:
        Path(temp).unlink(missing_ok=True)


def verify_routes():
    artisan("route:clear")
    artisan("view:clear")
    artisan("view:cache")
    records = json.loads(artisan("route:list", "--json"))
    names = {record["name"] for record in records}
    require(ROUTES <= names, f"Installed routes missing: {', '.join(sorted(ROUTES - names))}")
    artisan("bambodia:taxonomy:verify")


def apply(changes, original):
    backup = Path(tempfile.mkdtemp(prefix="bambodia-marketplace-managers-backup-", dir="/root"))
    print(f"MARKETPLACE_MANAGERS_BACKUP={backup}", flush=True)
    previous = {}
    for relative in changes:
        path = APP / relative
        require(snapshot(path) == original[relative], f"Source changed during preflight: {relative}")
        if original[relative] is None:
            previous[relative] = None
            continue
        info = path.stat()
        previous[relative] = (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        dest = backup / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, dest)
    (backup / "added-files.txt").write_text(
        "".join(f"{path}\n" for path, meta in previous.items() if meta is None))

    installed = []
    safe_to_reopen = True
    artisan("down", "--retry=30")
    try:
        for relative, data in changes.items():
            require(snapshot(APP / relative) == original[relative], f"Source changed during maintenance: {relative}")
            install(APP / relative, data, previous[relative] or (0, 0, 0o644))
            installed.append(relative)
        verify_routes()
    except BaseException:
        safe_to_reopen = False
        for relative in reversed(installed):
            if previous[relative] is None:
                (APP / relative).unlink(missing_ok=True)
            else:
                install(APP / relative, (backup / relative).read_bytes(), previous[relative])
        artisan("route:clear")
        artisan("view:clear")
        safe_to_reopen = True
        print("MARKETPLACE_MANAGERS_ROLLBACK=PASS", file=sys.stderr)
        raise
    finally:
        if safe_to_reopen:
            artisan("up")


def main():
    require(len(sys.argv) == 3 and sys.argv[1] in {"--check", "--apply"},
            "Usage: operator.py --check|--apply /absolute/path/to/verified.patch")
    require(os.geteuid() == 0, "Run this operator as root.")
    os.umask(0o077)
    patch = Path(sys.argv[2]).resolve(strict=True)
    require(hashlib.sha256(patch.read_bytes()).hexdigest() == PATCH_SHA256, "Patch checksum mismatch.")
    with open("/run/lock/bambodia-marketplace-managers.lock", "a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        verify_site()
        with tempfile.TemporaryDirectory(prefix="bambodia-manager-stage-", dir="/root") as name:
            stage = Path(name)
            path_rows = run(["git", "apply", "--numstat", str(patch)], cwd=stage).splitlines()
            original = {}
            for row in path_rows:
                relative = Path(row.split("\t", 2)[2])
                source = APP / relative
                require(relative.parts[0] in {"app", "resources", "routes"} and source.resolve() == source,
                        f"Unexpected patch target: {relative}")
                data = snapshot(source)
                original[relative] = data
                if data is not None:
                    target = stage / relative
                    target.parent.mkdir(parents=True, exist_ok=True)
                    if relative == JOB_VIEW and blob(data) == LIVE_JOB_VIEW_BLOB:
                        data = data.replace(b"'closed'=>'Closed','all'=>'All'", b"'closed'=>'Closed','withdrawn'=>'Withdrawn','all'=>'All'", 1)
                    target.write_bytes(data)

            check = subprocess.run(["git", "apply", "--reverse", "--check", str(patch)],
                                   cwd=stage, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if check.returncode == 0:
                print("MARKETPLACE_MANAGERS_ALREADY_INSTALLED=YES")
                return

            run(["git", "apply", "--check", str(patch)], cwd=stage)
            run(["git", "apply", str(patch)], cwd=stage)
            changes = {}
            for relative in original:
                target = stage / relative
                if not relative.name.endswith(".blade.php"):
                    run([str(PHP), "-l", str(target)], cwd=stage)
                changes[relative] = target.read_bytes()
                require(changes[relative] != original[relative], f"Patch did not update: {relative}")

            print(f"MARKETPLACE_MANAGERS_PREFLIGHT=PASS ({len(changes)} files; no migrations)", flush=True)
            if sys.argv[1] == "--apply":
                apply(changes, original)
                print("MARKETPLACE_MANAGERS_DEPLOYMENT=PASS")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError) as exc:
        sys.exit(f"MARKETPLACE_MANAGERS_STOPPED={exc}")
