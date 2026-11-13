#!/usr/bin/env python3
"""Guarded, Node-free, Blade-only Bambodia action-arrow removal for aaPanel."""
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
APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
BACKUPS = Path("/root/Bambodia-No-Trailing-Action-Arrows-Backups")
GITHUB_MAIN = "133f82a3e8681f3efbc8de9424a83dc3306e15d5"
BASE_PR = "46cbf292657548fc53934cfa3910f5432dd8c58d"
BRANCH = "style/remove-trailing-cta-arrows-20261113"
MAIN_LAYOUT = "resources/views/layouts/main.blade.php"
LIVE_HEADER_BLOB = "ff63f82134dc0dde7554aeeb4fe6368396b25b85"
UNTOUCHED_HEADERS = {
    "resources/views/layouts/workspace.blade.php": "97181be482e569ff02220c475d59ab41dbcab427",
    "public/css/header-flag-scroll-top.css": "3037d6879898ff47b861c025fbb4285dccfa6fca",
}
# Each entry pins the exact source before and after the reviewed PR.
# Do not update these baselines to force an incompatible server installation.
FILES = {
    "resources/views/account/index.blade.php": ("253b8fc9b8dd12647fde5d8c231046a41ba02c85","bde56bf8c7c04705078c851b4deefe2071581504"),
    "resources/views/account/studio.blade.php": ("494e35bf711bc9e695e42ce5361c66ea86ea0daf","dce95a60b73a8faf1bf7b6643f18e526dc2098c1"),
    "resources/views/builder/index.blade.php": ("e41462db3915554d55f521328faf9c0d74ad9ebd","3b33f817c312ac2be4143b13af811d16adbaadd3"),
    "resources/views/components/job-list-row.blade.php": ("2f346e63a0944b2d39b0b4cdca271c0bd8cf19bb","fac01cb6692ea189e473198daef35b20793befdf"),
    "resources/views/components/listing-card.blade.php": ("1637df07962cb042ad2ada365b1c81c95c494668","7d12b3831a6b60eb07ec0c84a724a1e92aa90854"),
    "resources/views/components/pagination.blade.php": ("bdd96e31834f5ee1156b35b197b8449c0fceb731","aa20bcd1e9cac30f5eb82fdcc55cff004b530e60"),
    "resources/views/components/workspace-metric.blade.php": ("fb5761bce6d053d7f3d418ad32e60f1cbe1c621d","65dba9742b5cb255484d9e33d42aa1edb28964c5"),
    "resources/views/content/blog.blade.php": ("9cbfee3095a58061a510d70e3bc247d449bd688d","ed4b92b38228b9375bc2e2ca43a6b4524d182200"),
    "resources/views/dashboard/index.blade.php": ("d0b972b8505142a344b0fa6e944a3887e2ba822a","7bbeb8755610f6fe143ffc38290dea2157b87f75"),
    "resources/views/details/support.blade.php": ("8aec6d4a70688634161c658ad97e7eb9bcf4c0d2","2f798be07758855b85ef08a942874c82809de5ea"),
    "resources/views/home.blade.php": ("65c5da2e3a1e4b5b3d6999fd430e9319a790ffa8","5e05173cbfec18f8d0c06bdc5443e0045fa35e60"),
    "resources/views/jobs/show.blade.php": ("596a05e8975ad294e6c6800bdee32cc00448c153","1aee0e3d4bb9536803da2518c7ba28bab6d23d9f"),
    "resources/views/marketplace/detail.blade.php": ("216b6908490010bc0ca799cd7304f471ee7b4494","ebb72fb3b1423e13145cd033d31654ba178f26ce"),
    "resources/views/support/knowledge-index.blade.php": ("94fb3a1c2d68b8a8b4fb24843a38cd44ffce77ad","3b3ef2f4bb4ebf280805bc3960198c12f72a89aa"),
}
MAIN_REPO_BEFORE = "36245e37663bfa6d276267d84275feeab22a4599"
MAIN_REPO_AFTER = "7f460688556945b7c4801c3d38e10e976d5b3f90"
REMOVALS = [
    (b'                <x-icon name="chevron-right" size="15"/>\n              </a>',
     b'              </a>'),
    (b'Explore all listings <x-icon name="chevron-right" size="16"/>',
     b'Explore all listings'),
]


def blocked(message):
    raise RuntimeError("BLOCKED=" + message)


def run(*args, capture=True, cwd=None):
    result = subprocess.run(args, cwd=cwd, capture_output=capture, check=True)
    return result.stdout if capture else b""


def blob_bytes(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


def checked(path):
    if path.is_symlink() or not path.is_file():
        blocked("Missing or symlink file: " + str(path))
    return path


def live_blob(rel):
    return blob_bytes(checked(APP / rel).read_bytes())


def git_api(url):
    return json.loads(run("gh", "api", url).decode("utf-8"))


def reviewed_file(rel, ref):
    # gh api sends raw bytes; the pinned git blob is independently checked.
    return run("gh", "api", "-H",
               "Accept: application/vnd.github.raw+json",
               "repos/" + REPO + "/contents/" + rel + "?ref=" + ref)


def patch_header(data):
    for old, new in REMOVALS:
        if data.count(old) != 1:
            blocked("Header action patch is not unique")
        data = data.replace(old, new, 1)
    return data


def guard(expected_head):
    if os.geteuid() != 0:
        blocked("root operator required")
    for executable in ("gh", "python3", "git", "runuser"):
        if not shutil.which(executable):
            blocked("Missing executable: " + executable)
    if APP.is_symlink() or APP.resolve() != APP or not checked(APP / "artisan"):
        blocked("Wrong aaPanel application root")
    if "root " + str(APP / "public") + ";" not in checked(VHOST).read_text():
        blocked("Nginx root changed unexpectedly")
    for rel in ("resources", "resources/views", "app", "public",
                "public/build", "public/build/assets"):
        folder = APP / rel
        if not folder.is_dir() or folder.is_symlink():
            blocked("Unsafe directory " + rel)
    if git_api("repos/" + REPO + "/commits/main")["sha"] != GITHUB_MAIN:
        blocked("GitHub main changed")
    if git_api("repos/" + REPO + "/pulls/47")["head"]["sha"] != BASE_PR:
        blocked("Underlying freelancer release changed")
    p = git_api("repos/" + REPO + "/pulls/48")
    if (p["head"]["ref"] != BRANCH or p["head"]["sha"] != expected_head or
            p["base"]["ref"] != "fix/home-freelancer-avatar-link-20261112"
            or p["state"] != "open" or not p["draft"]):
        blocked("Reviewed PR #48 head/base/state changed")
    for rel, (old, _) in FILES.items():
        if live_blob(rel) != old:
            blocked("Live template differs from approved baseline: " + rel)
        print("LIVE_BASELINE=VERIFIED " + rel)
    if live_blob(MAIN_LAYOUT) != LIVE_HEADER_BLOB:
        blocked("Installed PR #41 public header version differs")
    for rel, expected in UNTOUCHED_HEADERS.items():
        if live_blob(rel) != expected:
            blocked("Previously installed header component drift: " + rel)
    print("EXISTING_HEADER_LANGUAGE_AND_BACK_TO_TOP=PRESERVED")
    print("BLADE_TEMPLATES_TO_UPDATE=15")


def staged_files(head):
    staged = {}
    for rel, (_, expected) in FILES.items():
        content = reviewed_file(rel, head)
        if blob_bytes(content) != expected:
            blocked("Reviewed GitHub source hash differs: " + rel)
        staged[rel] = content

    prior = reviewed_file(MAIN_LAYOUT, BASE_PR)
    approved = reviewed_file(MAIN_LAYOUT, head)
    if blob_bytes(prior) != MAIN_REPO_BEFORE or blob_bytes(approved) != MAIN_REPO_AFTER:
        blocked("Reviewed header source revision changed")
    if patch_header(prior) != approved:
        blocked("Header changes are not exactly two decorative icon removals")
    # Apply exactly the same tiny patch to the *live* PR #41 header, avoiding
    # loss of the SVG language flags and Back to Top improvements.
    staged[MAIN_LAYOUT] = patch_header(checked(APP / MAIN_LAYOUT).read_bytes())
    if b"header-flag-scroll-top.css" not in staged[MAIN_LAYOUT]:
        blocked("Previously deployed language/header stylesheet missing")
    print("REVIEWED_SOURCE=15_TEMPLATES_HASH_VERIFIED")
    print("PR41_HEADER_PATCH=RECONCILED_WITHOUT_DOWNGRADE")
    return staged


def atomic_replace(source, target, uid, gid, mode):
    fd, temp = tempfile.mkstemp(prefix=".bambodia-action-", dir=target.parent)
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(source)
            out.flush()
            os.fsync(out.fileno())
        os.chown(temp, uid, gid)
        os.chmod(temp, mode)
        os.replace(temp, target)
    finally:
        if os.path.lexists(temp):
            os.unlink(temp)


def publish(staged, expected_head):
    # Re-check *all* live and GitHub baselines just before changing a file.
    guard(expected_head)
    if BACKUPS.is_symlink():
        blocked("Private backup directory is a symlink")
    BACKUPS.mkdir(parents=True, mode=0o700, exist_ok=True)
    if BACKUPS.stat().st_uid != 0:
        blocked("Private backup directory not owned by root")
    BACKUPS.chmod(0o700)
    backup = Path(tempfile.mkdtemp(prefix="release-", dir=BACKUPS))
    backup.chmod(0o700)
    originals = {}
    for rel in staged:
        live = checked(APP / rel)
        meta = live.stat()
        old = live.read_bytes()
        if blob_bytes(old) != (LIVE_HEADER_BLOB if rel == MAIN_LAYOUT else FILES[rel][0]):
            blocked("Source changed during backup " + rel)
        destination = backup / "original" / rel
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(old)
        destination.chmod(0o600)
        if destination.read_bytes() != old:
            blocked("Private backup hash mismatch " + rel)
        originals[rel] = (old, meta.st_uid, meta.st_gid, stat.S_IMODE(meta.st_mode))
    print("BACKUP=HASH_VERIFIED")
    (backup / "blobs.json").write_text(json.dumps(
        {rel: blob_bytes(entry[0]) for rel, entry in originals.items()}, indent=2) + "\n")
    (backup / "blobs.json").chmod(0o600)
    changed = []
    try:
        for rel, data in staged.items():
            live = checked(APP / rel)
            old, uid, gid, mode = originals[rel]
            if live.read_bytes() != old:
                raise RuntimeError("Concurrent change before installation: " + rel)
            atomic_replace(data, live, uid, gid, mode)
            changed.append(rel)
        for rel, expected in staged.items():
            if checked(APP / rel).read_bytes() != expected:
                raise RuntimeError("Installed template hash mismatch: " + rel)
        php = Path("/www/server/php/85/bin/php")
        if not php.is_file():
            raise RuntimeError("aaPanel PHP CLI missing")
        run("runuser", "-u", "www", "--", str(php),
            "artisan", "view:clear", cwd=str(APP), capture=False)
    except BaseException:
        for rel in reversed(changed):
            old, uid, gid, mode = originals[rel]
            atomic_replace(old, APP / rel, uid, gid, mode)
        print("DEPLOYMENT_ROLLBACK=RESTORED_ORIGINAL_TEMPLATES")
        raise
    print("PRIVATE_BACKUP_DIR=" + str(backup))
    print("DEPLOYED_BLADE_TEMPLATES=15_HASH_VERIFIED")
    print("VIEW_CACHE=CLEARED")
    print("HEADER_FLAG_AND_BACK_TO_TOP=PRESERVED")
    print("DATABASE_NGINX_PHP_FPM_VITE=UNCHANGED")
    print("RESULT=NO_TRAILING_CTA_ARROWS_PLATFORM_WIDE_DEPLOYED")


def main():
    if len(sys.argv) != 3 or sys.argv[1] not in ("--check", "--apply"):
        blocked("Usage: remove-trailing-action-arrows-aapanel.py --check|--apply EXPECTED_PR_HEAD_SHA")
    mode, head = sys.argv[1:]
    if len(head) != 40 or any(c not in "0123456789abcdef" for c in head):
        blocked("Expected reviewed GitHub SHA is invalid")
    guard(head)
    if mode == "--check":
        print("DEPLOYMENT=NOT_PERFORMED")
        print("RESULT=READY_FOR_GUARDED_ACTION_ARROW_CLEANUP")
        return
    staged = staged_files(head)
    publish(staged, head)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(2)
