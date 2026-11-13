#!/usr/bin/env python3
"""Guarded aaPanel installer for Bambodia approved For Users / Legal footer.

Updates only nine reviewed Blade/i18n files. Verifies pinned GitHub refs,
approved previous live blobs, and existing Aurora CSS before writing.
Makes private hash-verified backups, atomically updates, and rolls back on failure.
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
BASE = "e46b98e6ee648d2f0d461dd73dbdc518798606ee"
BRANCH = "style/footer-legal-and-users-20261113"
APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
BACKUP_ROOT = Path("/root/Bambodia-Footer-Legal-Backups")
AURORA = [
    ("public/css/home-hero-aurora.css", "c4220b2db4fdd9a5abd341be5232e869229dfcd5"),
    ("public/css/footer-two-tone-themes.css", "4cbf944d33f622ebf282e7eb2f885d6af150d07a"),
]
# Target relative path, approved previous Git blob, reviewed new Git blob
FILES = [
    ("resources/views/layouts/main.blade.php", "2b205100d84729a45e3f99d16681e9977b470afc", "5c40fb3e22bf19a792a4fcc189ada1c6a938d232"),
    ("lang/en/ui.php", "4cbcab7ec3b56f52fe101d4d4e179d35f9a1f073", "04cf049a7f347fca8f5309eeec678d026c0b06e4"),
    ("lang/de/ui.php", "b48cf5b0576b07e825bcad1b17ee7874e6ac5244", "04a11d654eb9d8631f4783ca19ca0f3eba4accdd"),
    ("lang/ja/ui.php", "1d3bd1233f12c7f34cea1238450568241faa6e23", "51aa04b74592bda23abf5acb082788631dd09e88"),
    ("lang/km/ui.php", "371af654540078fdb34c3ca9c5991dbff659acbd", "c5cd0e5a539f1ce8d44937a7c2478fa0a75ca8e1"),
    ("lang/ko/ui.php", "9b28dbd072b507ecc8b93821d93faefdbf8a6e9b", "6c93026b5564bffa88acc070c32bc7402e0c3f52"),
    ("lang/th/ui.php", "884ef23632bc819ef85d28c5461250e67c67bb41", "c497421cb19967f42cb552029801b2a957257a27"),
    ("lang/vi/ui.php", "f79fe1fbebc89063efe3e554cc4b106608ed666c", "6d357da62363a6c7afe30ca0838a0926c1ff02a4"),
    ("lang/zh/ui.php", "20058d2a1209dc0d7ad9a241af8f9737c6ee29e6", "edf97aa23d9cc2908bca907998262f70f5b73952"),
]

def blocked(reason):
    raise RuntimeError("BLOCKED=" + reason)

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\x00" + data).hexdigest()

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def api(path, raw=False):
    command = ["gh", "api"]
    if raw:
        command.extend(["-H", "Accept: application/vnd.github.raw+json"])
    return run(*command, "repos/" + REPO + "/" + path)

def safe_file(relative):
    if APP.is_symlink() or not APP.is_dir():
        blocked("Unsafe application root")
    p = APP
    for component in Path(relative).parts:
        if component in ("..", "."):
            blocked("Invalid target component")
        p = p / component
        if p.is_symlink():
            blocked("Symlink in path: " + relative)
    if not p.is_file():
        blocked("Missing regular target file: " + relative)
    return p

def validate_reviewed_scope(base, updated):
    """Reject unexpected changes to the shared public footer layout."""
    policy = b"      @if(Route::has('policy-center'))<a href=\"{{ route('policy-center') }}\">Policy Center</a>@endif\n"
    old_head = b"<div><h3>{{ __('ui.compliance') }}</h3>\n"
    new_head = b"<div><h3>{{ __('ui.footer_legal_policies') }}</h3>\n" + policy
    old_email = b">Email support</a>"
    new_email = b">{{ __('ui.footer_email_support') }}</a>"
    for marker in (policy, old_head, old_email):
        if base.count(marker) != 1:
            blocked("Unexpected old footer layout")
    expected = base.replace(policy, b"", 1).replace(old_head, new_head, 1).replace(old_email, new_email, 1)
    if expected != updated or updated.count(b"route('policy-center')") != 1:
        blocked("Unreviewed public layout modifications")

def preflight(head):
    if os.geteuid() != 0:
        blocked("Requires root operator")
    for command in ("gh", "git", "python3", "runuser"):
        if shutil.which(command) is None:
            blocked("Missing command: " + command)
    if not Path(PHP).is_file() or not os.access(PHP, os.X_OK):
        blocked("Required PHP-FPM CLI unavailable")
    safe_file("artisan")
    if json.loads(api("commits/main"))["sha"] != BASE:
        blocked("GitHub main has advanced; reconcile first")
    pr = json.loads(api("pulls/60"))
    if (pr["head"]["sha"] != head or pr["head"]["ref"] != BRANCH
            or pr["base"]["ref"] != "main" or pr["base"]["sha"] != BASE
            or pr["state"] != "open" or not pr["draft"]):
        blocked("Reviewed draft PR #60 changed")

    for path, expected in AURORA:
        if blob(safe_file(path).read_bytes()) != expected:
            blocked("Previously approved Aurora CSS differs: " + path)

    originals, updates, files, states = {}, {}, {}, []
    for path, previous, new in FILES:
        old_source = api("contents/" + path + "?ref=" + BASE, raw=True)
        reviewed = api("contents/" + path + "?ref=" + head, raw=True)
        if blob(old_source) != previous or blob(reviewed) != new:
            blocked("Source integrity mismatch: " + path)
        if path == "resources/views/layouts/main.blade.php":
            validate_reviewed_scope(old_source, reviewed)
        else:
            if reviewed.count(b"'footer_legal_policies' =>") != 1 or reviewed.count(b"'footer_email_support' =>") != 1:
                blocked("Locale copy incomplete: " + path)
        file = safe_file(path)
        current = file.read_bytes()
        state = blob(current)
        if state not in (previous, new):
            blocked("Live file not in approved state: " + path)
        originals[path] = current
        updates[path] = reviewed
        files[path] = (file, file.stat())
        states.append(state == new)
    if len(set(states)) != 1:
        blocked("Mixed live versions; do not partially upgrade")

    vite = safe_file("public/build/manifest.json").read_bytes()
    parsed = json.loads(vite)
    for key in ("resources/css/app.css", "resources/js/app.js"):
        asset = parsed.get(key, {}).get("file")
        if not isinstance(asset, str) or not asset.startswith("assets/") or ".." in Path(asset).parts:
            blocked("Unexpected Vite manifest")
        safe_file("public/build/" + asset)

    print("GITHUB_SOURCE_AND_SCOPE=HASH_VERIFIED")
    print("AURORA_HERO_AND_FOOTER=UNCHANGED")
    print("VITE_ASSETS=PRESERVED")
    print("REVIEWED_FILES=9")
    print("LIVE_BASELINE=" + ("ALREADY_NEW" if states[0] else "APPROVED_PREVIOUS"))
    return None if states[0] else (originals, updates, files, sha256(vite))

def atomic_write(path, data, st):
    fd, temp = tempfile.mkstemp(prefix=".footer-legal-", dir=str(path.parent))
    try:
        os.fchown(fd, st.st_uid, st.st_gid)
        os.fchmod(fd, stat.S_IMODE(st.st_mode))
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp, path)
    finally:
        if os.path.lexists(temp):
            os.unlink(temp)

def clear_views():
    run("runuser", "-u", "www", "--", PHP, "artisan", "view:clear", cwd=str(APP))
    print("VIEW_CACHE=CLEARED")

def apply(head):
    prepared = preflight(head)
    if prepared is None:
        print("RESULT=FOOTER_LEGAL_AND_USERS_ALREADY_DEPLOYED")
        return
    originals, updates, files, vite_hash = prepared
    if BACKUP_ROOT.is_symlink():
        blocked("Backup root is a symlink")
    BACKUP_ROOT.mkdir(parents=True, mode=0o700, exist_ok=True)
    if not BACKUP_ROOT.is_dir() or BACKUP_ROOT.is_symlink():
        blocked("Unsafe backup root")
    backup = Path(tempfile.mkdtemp(prefix="footer-", dir=str(BACKUP_ROOT)))
    os.chmod(backup, 0o700)
    for name, data in originals.items():
        target = backup / name
        target.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        target.write_bytes(data)
        os.chmod(target, 0o600)
        if target.read_bytes() != data:
            blocked("Backup verification failed: " + name)
    metadata = backup / "manifest.json"
    metadata.write_text(json.dumps({
        "pr_head": head,
        "previous_main": BASE,
        "files": {k: {"previous_sha256": sha256(originals[k]), "new_sha256": sha256(updates[k])}
                  for k in originals},
        "vite_manifest_sha256": vite_hash
    }, indent=2) + "\n")
    os.chmod(metadata, 0o600)
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))

    changed = []
    try:
        for name, old_hash, _new in FILES:
            file, st = files[name]
            if blob(safe_file(name).read_bytes()) != old_hash:
                blocked("Live file drift after preflight: " + name)
            atomic_write(file, updates[name], st)
            changed.append(name)
        clear_views()
        for name, _old, updated_hash in FILES:
            if blob(safe_file(name).read_bytes()) != updated_hash:
                blocked("Installed file integrity mismatch: " + name)
        for path, expected in AURORA:
            if blob(safe_file(path).read_bytes()) != expected:
                blocked("Aurora CSS changed during installation")
        if sha256(safe_file("public/build/manifest.json").read_bytes()) != vite_hash:
            blocked("Vite manifest changed during installation")
        print("DEPLOYED_FILES=9_HASH_VERIFIED")
        print("VITE_DATABASE_NGINX_PHP_FPM=UNCHANGED_BY_INSTALLER")
        print("RESULT=PUBLIC_FOOTER_LEGAL_AND_USERS_DEPLOYED")
    except Exception:
        for name in reversed(changed):
            file, st = files[name]
            atomic_write(file, originals[name], st)
        try:
            clear_views()
        except Exception:
            print("ROLLBACK_VIEW_CACHE=MANUAL_CLEAR_REQUIRED")
        print("ROLLBACK=PREVIOUS_FOOTER_RESTORED")
        raise

def main():
    parser = argparse.ArgumentParser()
    commands = parser.add_mutually_exclusive_group(required=True)
    commands.add_argument("--check", action="store_true")
    commands.add_argument("--apply", action="store_true")
    parser.add_argument("head", help="Pinned reviewed PR #60 SHA")
    args = parser.parse_args()
    if args.check:
        result = preflight(args.head)
        print("RESULT=" + ("ALREADY_DEPLOYED" if result is None else "READY_FOR_FOOTER_LEGAL_AND_USERS"))
    else:
        apply(args.head)

if __name__ == "__main__":
    main()
