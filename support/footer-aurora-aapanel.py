#!/usr/bin/env python3
"""Verified, backup-first Aurora footer update for aaPanel; only two public files."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

REPO = "bambodia168-sys/bambodialaravel"
BASE = "d9c9b069e51f49e83c6e54b4da81af4793940a3c"
BRANCH = "style/public-footer-subtle-aurora-20261113"
APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
BACKUPS = Path("/root/Bambodia-Footer-Aurora-Backups")
LAYOUT = "resources/views/layouts/main.blade.php"
CSS = "public/css/footer-two-tone-themes.css"
OLD_LAYOUT = "fc0a25495f5c2573f3c0cbb33b77272ffbfe58f6"
OLD_CSS = "2c3f44f9a47297e37feba54b569cf45418dac4a4"
NEW_LAYOUT = "00a08e62be193cf03a40526a5acd6038fae9764b"
NEW_CSS = "4cbf944d33f622ebf282e7eb2f885d6af150d07a"

def blocked(reason):
    raise RuntimeError("BLOCKED=" + reason)

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\x00" + data).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def api(path, raw=False):
    args = ["gh", "api"]
    if raw:
        args += ["-H", "Accept: application/vnd.github.raw+json"]
    return run(*args, "repos/" + REPO + "/" + path)

def safe_file(relative):
    if not APP.is_dir() or APP.is_symlink():
        blocked("Unsafe application root")
    path = APP
    for part in Path(relative).parts:
        if part in (".", ".."):
            blocked("Unsafe relative path")
        path = path / part
        if path.is_symlink():
            blocked("Symlink in application target")
    if not path.is_file():
        blocked("Required file missing: " + relative)
    return path

def preflight(head):
    if os.geteuid() != 0 or not Path(PHP).is_file():
        blocked("Wrong operator or PHP installation")
    safe_file("artisan")
    parent, pr = json.loads(api("pulls/54")), json.loads(api("pulls/55"))
    if parent["head"]["sha"] != BASE or parent["state"] != "open" or not parent["draft"]:
        blocked("Parent PR changed")
    if (pr["head"]["sha"] != head or pr["head"]["ref"] != BRANCH
        or pr["base"]["sha"] != BASE or pr["base"]["ref"] != parent["head"]["ref"]
        or pr["state"] != "open" or not pr["draft"]):
        blocked("Aurora PR changed")
    old_l = api("contents/" + LAYOUT + "?ref=" + BASE, raw=True)
    old_c = api("contents/" + CSS + "?ref=" + BASE, raw=True)
    new_l = api("contents/" + LAYOUT + "?ref=" + head, raw=True)
    new_c = api("contents/" + CSS + "?ref=" + head, raw=True)
    if [blob(b) for b in (old_l, old_c, new_l, new_c)] != [
        OLD_LAYOUT, OLD_CSS, NEW_LAYOUT, NEW_CSS
    ]:
        blocked("Reviewed source mismatch")
    before = b"{{ asset('css/footer-two-tone-themes.css') }}?v=1"
    after = b"{{ asset('css/footer-two-tone-themes.css') }}?v=2"
    if old_l.count(before) != 1 or old_l.replace(before, after) != new_l:
        blocked("Layout exceeds approved cache-version change")
    for marker in (b"#f1f5fb", b"#e6edf7", b"#111d30", b"#0b1424",
                   b"--footer-aurora-teal", b"--footer-aurora-indigo",
                   b"prefers-reduced-motion", b":focus-visible"):
        if marker not in new_c:
            blocked("Reviewed Aurora CSS missing required tokens")
    print("GITHUB_SOURCE_AND_SCOPE=VERIFIED")
    layout, css = safe_file(LAYOUT), safe_file(CSS)
    if layout.read_bytes() == new_l and css.read_bytes() == new_c:
        print("RESULT=PUBLIC_FOOTER_AURORA_ALREADY_DEPLOYED")
        return None
    if blob(layout.read_bytes()) != OLD_LAYOUT or blob(css.read_bytes()) != OLD_CSS:
        blocked("Live files differ from approved previous footer")
    manifest = safe_file("public/build/manifest.json").read_bytes()
    parsed = json.loads(manifest)
    for key in ("resources/css/app.css", "resources/js/app.js"):
        target = parsed.get(key, {}).get("file")
        if not isinstance(target, str) or not target.startswith("assets/") or ".." in Path(target).parts:
            blocked("Unrecognized Vite manifest entry")
        safe_file("public/build/" + target)
    print("LIVE_BASELINE_AND_VITE_ASSETS=VERIFIED")
    return layout, css, old_l, old_c, new_l, new_c, hashlib.sha256(manifest).hexdigest()

def write_file(path, data, stat):
    fd, temp = tempfile.mkstemp(prefix=".footer-aurora-", dir=str(path.parent))
    try:
        os.fchmod(fd, stat.st_mode & 0o777)
        os.fchown(fd, stat.st_uid, stat.st_gid)
        with os.fdopen(fd, "wb") as file:
            file.write(data)
            file.flush()
            os.fsync(file.fileno())
        os.replace(temp, path)
    finally:
        if os.path.lexists(temp):
            os.unlink(temp)

def clear_views():
    run("runuser", "-u", "www", "--", PHP, "artisan", "view:clear", cwd=str(APP))

def apply(head):
    sources = preflight(head)
    if sources is None:
        return
    layout, css, old_l, old_c, new_l, new_c, manifest_sha = sources
    layout_stat, css_stat = layout.stat(), css.stat()
    BACKUPS.mkdir(parents=True, mode=0o700, exist_ok=True)
    if BACKUPS.is_symlink() or not BACKUPS.is_dir():
        blocked("Unsafe backup root")
    backup = Path(tempfile.mkdtemp(prefix="footer-", dir=str(BACKUPS)))
    os.chmod(backup, 0o700)
    for filename, data in (("main.blade.php", old_l), ("footer-two-tone-themes.css", old_c)):
        destination = backup / filename
        destination.write_bytes(data)
        os.chmod(destination, 0o600)
        if destination.read_bytes() != data:
            blocked("Backup integrity failure")
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))
    wrote_css = wrote_layout = False
    try:
        if blob(safe_file(LAYOUT).read_bytes()) != OLD_LAYOUT or blob(safe_file(CSS).read_bytes()) != OLD_CSS:
            blocked("Live files changed after preflight")
        write_file(css, new_c, css_stat)
        wrote_css = True
        write_file(layout, new_l, layout_stat)
        wrote_layout = True
        clear_views()
        if blob(layout.read_bytes()) != NEW_LAYOUT or blob(css.read_bytes()) != NEW_CSS:
            blocked("Installed files changed unexpectedly")
        if hashlib.sha256(safe_file("public/build/manifest.json").read_bytes()).hexdigest() != manifest_sha:
            blocked("Vite asset manifest changed")
        print("DEPLOYED_FILES=2_HASH_VERIFIED")
        print("RESULT=PUBLIC_FOOTER_AURORA_LIGHT_DARK_DEPLOYED")
    except Exception:
        if wrote_layout:
            write_file(layout, old_l, layout_stat)
        if wrote_css:
            write_file(css, old_c, css_stat)
        clear_views()
        print("ROLLBACK=PREVIOUS_TWO_TONE_RESTORED")
        raise

def main():
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true")
    group.add_argument("--apply", action="store_true")
    parser.add_argument("head", help="Pinned GitHub PR #55 head SHA")
    args = parser.parse_args()
    if args.check:
        result = preflight(args.head)
        if result is not None:
            print("RESULT=READY_FOR_PUBLIC_FOOTER_AURORA")
    else:
        apply(args.head)

if __name__ == "__main__":
    main()
