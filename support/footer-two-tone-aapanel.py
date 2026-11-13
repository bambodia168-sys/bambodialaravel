#!/usr/bin/env python3
"""Pinned, backup-first aaPanel installer for the two-tone public footer.

Writes only the reviewed main layout and one new static CSS file.
No Vite rebuild, application dependency, database, Nginx or PHP-FPM changes.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

REPO = "bambodia168-sys/bambodialaravel"
BRANCH = "style/public-footer-two-tone-themes-20261113"
BASE = "b740a89f3cdcbe02a619f0664522c3c22f24e865"
MAIN = "dd9b2657c63083f08f9da16278e6d6e3495468cb"
APP = Path("/www/wwwroot/bambodia-staging-qa")
BACKUPS = Path("/root/Bambodia-Footer-Two-Tone-Backups")
PHP = Path("/www/server/php/85/bin/php")
LAYOUT = "resources/views/layouts/main.blade.php"
CSS = "public/css/footer-two-tone-themes.css"
OLD_LAYOUT_BLOB = "70a78d11cf3ee849d719b427d0a34e6fa8d3c781"
NEW_LAYOUT_BLOB = "fc0a25495f5c2573f3c0cbb33b77272ffbfe58f6"
NEW_CSS_BLOB = "2c3f44f9a47297e37feba54b569cf45418dac4a4"

def blocked(reason):
    raise RuntimeError("BLOCKED=" + reason)

def git_blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def api(path, raw=False):
    command = ["gh", "api"]
    if raw:
        command.extend(["-H", "Accept: application/vnd.github.raw+json"])
    command.append("repos/" + REPO + "/" + path)
    return run(*command)

def valid_file(rel, allow_missing=False):
    if APP.is_symlink() or not APP.is_dir():
        blocked("Unexpected application root")
    cur = APP
    for part in Path(rel).parts:
        if part in (".", ".."):
            blocked("Unsafe relative application path")
        cur = cur / part
        if cur.is_symlink():
            blocked("Symlink in target: " + rel)
    if not cur.exists():
        if allow_missing:
            return cur
        blocked("Required application file missing: " + rel)
    if not cur.is_file():
        blocked("Non-file target: " + rel)
    return cur

def exact_reviewed_layout(previous, reviewed):
    old_head = b'  <link rel="stylesheet" href="{{ asset(\'css/header-flag-scroll-top.css\') }}?v=1">'
    new_head = old_head + b'\n  <link rel="stylesheet" href="{{ asset(\'css/footer-two-tone-themes.css\') }}?v=1">'
    old_open = b'  <div class="container footer-bottom"><span class="footer-copyright">'
    new_open = b'  <div class="footer-bottom-band">\n    <div class="container footer-bottom"><span class="footer-copyright">'
    old_end = b'    </div>\n  </div>\n</footer>\n<button type="button" class="back-to-top"'
    new_end = b'    </div>\n  </div>\n  </div>\n</footer>\n<button type="button" class="back-to-top"'
    combined = previous
    for before, after in ((old_head,new_head),(old_open,new_open),(old_end,new_end)):
        if combined.count(before) != 1:
            blocked("Cannot independently verify scoped footer layout patch")
        combined = combined.replace(before, after, 1)
    if combined != reviewed:
        blocked("Reviewed footer layout contains unrelated changes")
    print("REVIEWED_LAYOUT_SCOPE=EXACT_TWO_TONE_PATCH")

def source_preflight(head):
    if os.geteuid() != 0:
        blocked("Must run as root")
    for command in ("gh", "git", "python3", "runuser"):
        if not shutil.which(command):
            blocked("Missing operator executable: " + command)
    if not PHP.is_file() or not os.access(PHP, os.X_OK):
        blocked("Expected approved PHP 8.5 binary missing")
    if not valid_file("artisan").is_file():
        blocked("Wrong Laravel application")
    main = json.loads(api("commits/main"))["sha"]
    if main != MAIN:
        blocked("GitHub main has moved; reconcile first")
    previous = json.loads(api("pulls/53"))
    if previous["head"]["sha"] != BASE or previous["state"] != "open" or not previous["draft"]:
        blocked("Deployed previous PR #53 changed")
    pr = json.loads(api("pulls/54"))
    if (pr["head"]["ref"] != BRANCH or pr["head"]["sha"] != head
        or pr["base"]["ref"] != "feat/blog-player-ghost-controls-vertical-volume-20261113"
        or pr["base"]["sha"] != BASE or pr["state"] != "open" or not pr["draft"]):
        blocked("PR #54 head/base/state changed")

    layout_source = api("contents/" + LAYOUT + "?ref=" + head, raw=True)
    css_source = api("contents/" + CSS + "?ref=" + head, raw=True)
    if git_blob(layout_source) != NEW_LAYOUT_BLOB or git_blob(css_source) != NEW_CSS_BLOB:
        blocked("Reviewed GitHub source blob changed")
    main_original = api("contents/" + LAYOUT + "?ref=" + BASE, raw=True)
    if git_blob(main_original) != OLD_LAYOUT_BLOB:
        blocked("Approved parent layout blob changed")
    exact_reviewed_layout(main_original, layout_source)
    for token in (b"#f1f5fb", b"#e6edf7", b"#111d30", b"#0b1424",
                  b":root[data-theme=\"dark\"]", b":root:not([data-theme=\"dark\"])",
                  b".footer-bottom-band"):
        if token not in css_source:
            blocked("Reviewed footer stylesheet missing palette or theme selector")
    print("GITHUB_SOURCE=VERIFIED")
    print("REVIEWED_CSS_AND_LAYOUT=HASH_VERIFIED")

    layout_path = valid_file(LAYOUT)
    css_path = valid_file(CSS, allow_missing=True)
    current_layout = layout_path.read_bytes()
    existing_css = css_path.read_bytes() if css_path.exists() else None
    if git_blob(current_layout) == NEW_LAYOUT_BLOB and existing_css == css_source:
        print("RESULT=PUBLIC_FOOTER_LIGHT_DARK_TWO_TONE_ALREADY_DEPLOYED")
        return None
    if git_blob(current_layout) != OLD_LAYOUT_BLOB:
        blocked("Live public layout differs from approved previous deployment")
    if existing_css is not None:
        blocked("New footer stylesheet already exists unexpectedly")
    print("LIVE_LAYOUT=APPROVED_PREVIOUS_RELEASE")
    print("FOOTER_STYLESHEET=NEW_PATH_AVAILABLE")

    manifest = valid_file("public/build/manifest.json").read_bytes()
    parsed = json.loads(manifest)
    for key in ("resources/css/app.css", "resources/js/app.js"):
        file = parsed.get(key, {}).get("file")
        if not isinstance(file,str) or not file.startswith("assets/") or ".." in Path(file).parts:
            blocked("Unexpected active Vite manifest")
        valid_file("public/build/" + file)
    print("EXISTING_VITE_ASSETS=PRESERVED")
    return layout_path, css_path, current_layout, layout_source, css_source, sha256(manifest)

def write_atomic(path, data, mode, uid, gid):
    fd, temporary = tempfile.mkstemp(prefix=".bambodia-footer-", dir=str(path.parent))
    try:
        os.fchmod(fd, mode)
        os.fchown(fd, uid, gid)
        with os.fdopen(fd,"wb") as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)

def clear_views():
    run("runuser","-u","www","--",str(PHP),"artisan","view:clear",cwd=str(APP))
    print("VIEW_CACHE=CLEARED")

def apply(head):
    prepared = source_preflight(head)
    if prepared is None:
        return
    layout, css, previous, next_layout, next_css, manifest_hash = prepared
    stats = layout.stat()
    BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
    if BACKUPS.is_symlink() or not BACKUPS.is_dir():
        blocked("Unsafe backup root")
    backup = Path(tempfile.mkdtemp(prefix="footer-", dir=str(BACKUPS)))
    os.chmod(backup,0o700)
    original_path = backup / "main.blade.php"
    original_path.write_bytes(previous)
    os.chmod(original_path,0o600)
    (backup / "metadata.json").write_text(json.dumps({
        "previous_layout_sha256": sha256(previous),
        "previous_layout_git_blob": OLD_LAYOUT_BLOB,
        "target_head": head,
        "new_layout_git_blob": NEW_LAYOUT_BLOB,
        "new_css_git_blob": NEW_CSS_BLOB,
        "uid": stats.st_uid, "gid": stats.st_gid, "mode": stats.st_mode & 0o777,
    }, indent=2) + "\n")
    os.chmod(backup / "metadata.json",0o600)
    if original_path.read_bytes() != previous:
        blocked("Backup integrity mismatch")
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))
    wrote_css = False
    wrote_layout = False
    try:
        # Recheck targets immediately before writing.
        if git_blob(valid_file(LAYOUT).read_bytes()) != OLD_LAYOUT_BLOB:
            blocked("Live layout changed after preflight")
        if valid_file(CSS, allow_missing=True).exists():
            blocked("Footer stylesheet changed after preflight")
        write_atomic(css,next_css,0o644,stats.st_uid,stats.st_gid)
        wrote_css = True
        write_atomic(layout,next_layout,stats.st_mode & 0o777,stats.st_uid,stats.st_gid)
        wrote_layout = True
        if git_blob(layout.read_bytes()) != NEW_LAYOUT_BLOB or git_blob(css.read_bytes()) != NEW_CSS_BLOB:
            blocked("New footer file integrity mismatch")
        clear_views()
        if sha256(valid_file("public/build/manifest.json").read_bytes()) != manifest_hash:
            blocked("Existing Vite manifest unexpectedly changed")
        print("DEPLOYED_FILES=2_HASH_VERIFIED")
        print("VITE_DATABASE_NGINX_PHP_FPM=UNCHANGED_BY_SCRIPT")
        print("RESULT=PUBLIC_FOOTER_LIGHT_DARK_TWO_TONE_DEPLOYED")
    except Exception:
        if wrote_layout:
            write_atomic(layout,previous,stats.st_mode & 0o777,stats.st_uid,stats.st_gid)
        if wrote_css and css.exists():
            css.unlink()
        try:
            clear_views()
        except Exception as error:
            print("ROLLBACK_CACHE_CLEAR_FAILED=" + str(error),file=sys.stderr)
        print("ROLLBACK=RESTORED_PREVIOUS_FOOTER",file=sys.stderr)
        raise

def main():
    parser=argparse.ArgumentParser()
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check",action="store_true")
    mode.add_argument("--apply",action="store_true")
    parser.add_argument("head")
    args=parser.parse_args()
    if len(args.head)!=40 or any(c not in "0123456789abcdef" for c in args.head):
        blocked("Invalid pinned commit SHA")
    if args.check:
        prepared=source_preflight(args.head)
        if prepared is not None:
            print("DEPLOYMENT=NOT_PERFORMED")
            print("RESULT=READY_FOR_PUBLIC_FOOTER_LIGHT_DARK_TWO_TONE")
    else:
        apply(args.head)

if __name__=="__main__":
    try:
        main()
    except Exception as exc:
        print(str(exc),file=sys.stderr)
        sys.exit(2)
