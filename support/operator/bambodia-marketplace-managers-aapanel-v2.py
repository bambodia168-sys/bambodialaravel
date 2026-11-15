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
LEGACY_BASELINES = {
    Path('app/Http/Controllers/Dashboard/JobManagerController.php'): (
        'ab3814af9ba077dc08425b70b9057f447f39084c', '74708909b477f1837325fe3588e58017c4abe0ac',
        b"        abort_unless(in_array($status,['all','pending','published','rejected','closed'],true),404);\n",
        b"        abort_unless(in_array($status,['all','pending','published','rejected','closed','withdrawn'],true),404);\n",
    ),
    Path('app/Http/Controllers/Dashboard/MarketplaceManagerController.php'): (
        '3f978b925cd73f2ea94c555a86c03b2f25f8d654', 'bb735cc36dbbb449b1b1f44453365fc394026af9',
        b"        abort_unless(in_array($status,['all','pending','published','rejected','draft'],true),404);\n",
        b"        abort_unless(in_array($status,['all','pending','published','rejected','draft','withdrawn'],true),404);\n",
    ),
    Path('app/Http/Controllers/Dashboard/ProductManagerController.php'): (
        'fde0cd196cd5b435e310f45760aa52c78d72fcf7', '42ec385266a222e3e04a4e0f7294beeed0141118',
        b"        abort_unless(in_array($status, ['all', 'draft', 'pending', 'published', 'rejected'], true), 404);\n",
        b"        abort_unless(in_array($status, ['all', 'draft', 'pending', 'published', 'rejected', 'withdrawn'], true), 404);\n",
    ),
    Path('app/Models/MarketplaceListing.php'): (
        'ec6ab400882f89faa983a714e1d5021e25cc53c1', '6c2816e8f2999a4a6b2f6b9a20b6e1824921de84',
        b"    public const STATUSES = ['draft', 'pending', 'published', 'rejected'];\n",
        b"    public const STATUSES = ['draft', 'pending', 'published', 'rejected', 'withdrawn'];\n",
    ),
    Path('resources/views/dashboard/marketplace.blade.php'): (
        '8a5247a5600e7af691d42733a79872eb6170aa66', '31887da0413374cd655fa63cfa6c2cad733af1fb',
        b'@foreach([\'all\'=>\'All\',\'pending\'=>\'Pending\',\'published\'=>\'Published\',\'rejected\'=>\'Rejected\',\'draft\'=>\'Draft\'] as $key=>$value)<option value="{{ $key }}" @selected($status===$key)>{{ $value }}</option>@endforeach\n',
        b'@foreach([\'all\'=>\'All\',\'pending\'=>\'Pending\',\'published\'=>\'Published\',\'rejected\'=>\'Rejected\',\'draft\'=>\'Draft\',\'withdrawn\'=>\'Withdrawn\'] as $key=>$value)<option value="{{ $key }}" @selected($status===$key)>{{ $value }}</option>@endforeach\n',
    ),
    Path('resources/views/dashboard/products.blade.php'): (
        'a15cae0b78b594f0c92554037cba42803296a0ee', '3a89cb955866a1d4fa5f5defd49af37f70dc8c53',
        b"        @foreach(['all'=>'All statuses','draft'=>'Draft','pending'=>'Pending','published'=>'Published','rejected'=>'Rejected'] as $value=>$label)\n",
        b"        @foreach(['all'=>'All statuses','draft'=>'Draft','pending'=>'Pending','published'=>'Published','rejected'=>'Rejected','withdrawn'=>'Withdrawn'] as $value=>$label)\n",
    ),
    Path('resources/views/dashboard/jobs.blade.php'): (
        'c3273f0a279908c691f8661de8a59d892d690fdf', '4064aad3919e85b2daf5c1c20430bb0421eb9bb1',
        b"'closed'=>'Closed','all'=>'All'", b"'closed'=>'Closed','withdrawn'=>'Withdrawn','all'=>'All'",
    ),
}
PREVIEW_REVIEW = Path('app/Support/PreviewContentReview.php')
LEGACY_PREVIEW_HASHES = {
    'f9ba7c49172d17b0db943ee7ebc400f24242f817': 'ccb907313edb23ace6d84a191a43e48b2882837c',
    '9bd5bf7db38bceca58835bcc1c0d85191aef2e7c': '22dad75074a751b4976a305f52da3b45cc42cec0',
}
PREVIEW_ANCHOR = b'    public static function isPreviewTitle'
PREVIEW_ADDITION = b"    public static function hasCustomerActivity(MarketplaceJob|MarketplaceListing $record): bool\n    {\n        $report = $record instanceof MarketplaceJob ? self::jobReport($record) : self::listingReport($record);\n\n        return $report['customer_activity_blocked'];\n    }\n\n"
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


def prepare(stage, patch):
    path_rows = run(["git", "apply", "--numstat", str(patch)], cwd=stage).splitlines()
    original = {}
    legacy_preview = None
    accepted = []
    for row in path_rows:
        relative = Path(row.split("\t", 2)[2])
        source = APP / relative
        require(relative.parts[0] in {"app", "resources", "routes"} and source.resolve() == source,
                f"Unexpected patch target: {relative}")
        data = snapshot(source)
        original[relative] = data
        if data is None:
            continue

        actual = blob(data)
        baseline = LEGACY_BASELINES.get(relative)
        if baseline and actual == baseline[0]:
            require(data.count(baseline[2]) == 1, f"Legacy source anchor mismatch: {relative}")
            data = data.replace(baseline[2], baseline[3], 1)
            require(blob(data) == baseline[1], f"Legacy source reconciliation mismatch: {relative}")
            accepted.append(str(relative))
        if relative == PREVIEW_REVIEW:
            if actual in LEGACY_PREVIEW_HASHES:
                require(data.count(PREVIEW_ANCHOR) == 1, "Legacy preview source anchor mismatch.")
                legacy_preview = data.replace(PREVIEW_ANCHOR, PREVIEW_ADDITION + PREVIEW_ANCHOR, 1)
                require(blob(legacy_preview) == LEGACY_PREVIEW_HASHES[actual],
                        "Legacy preview source reconciliation mismatch.")
                accepted.append(str(relative))
            elif actual in LEGACY_PREVIEW_HASHES.values():
                legacy_preview = data

        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)

    flags = [f"--exclude={PREVIEW_REVIEW}"] if legacy_preview is not None else []
    reverse = subprocess.run(["git", "apply", "--reverse", "--check", *flags, str(patch)],
                             cwd=stage, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if reverse.returncode == 0:
        require(legacy_preview is None or legacy_preview == original[PREVIEW_REVIEW],
                "The preview dependency helper still needs reconciliation.")
        return {}, original

    try:
        run(["git", "apply", "--check", *flags, str(patch)], cwd=stage)
        run(["git", "apply", *flags, str(patch)], cwd=stage)
    except RuntimeError:
        for relative, data in original.items():
            if data is not None:
                print(f"MARKETPLACE_SOURCE_BLOB {relative} {blob(data)}", file=sys.stderr)
        raise

    if legacy_preview is not None:
        (stage / PREVIEW_REVIEW).write_bytes(legacy_preview)
    changes = {}
    for relative, previous in original.items():
        target = stage / relative
        data = target.read_bytes()
        if data != previous:
            if not relative.name.endswith(".blade.php"):
                run([str(PHP), "-l", str(target)], cwd=stage)
            changes[relative] = data
    for relative in accepted:
        print(f"MARKETPLACE_KNOWN_LIVE_BASELINE={relative}", flush=True)
    return changes, original


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
            changes, original = prepare(stage, patch)
            if not changes:
                print("MARKETPLACE_MANAGERS_ALREADY_INSTALLED=YES")
                return

            print(f"MARKETPLACE_MANAGERS_PREFLIGHT=PASS ({len(changes)} files; no migrations)", flush=True)
            if sys.argv[1] == "--apply":
                apply(changes, original)
                print("MARKETPLACE_MANAGERS_DEPLOYMENT=PASS")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError) as exc:
        sys.exit(f"MARKETPLACE_MANAGERS_STOPPED={exc}")
