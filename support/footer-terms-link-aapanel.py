#!/usr/bin/env python3
"""Bambodia: fail-closed, backup-first fix for footer Terms of Service link.

Updates only public layout Blade, and ONLY after confirming the approved GitHub
patch, live file hash, and published canonical policy. No DB or Vite writes.
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
BASE = "2c1745990c713af7ea52b42ed768ad95e9e88167"
BRANCH = "fix/footer-terms-of-service-exact-policy-20261113"
APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
LAYOUT = "resources/views/layouts/main.blade.php"
OLD_BLOB = "5c40fb3e22bf19a792a4fcc189ada1c6a938d232"
NEW_BLOB = "9eb17188f7f484497a0fcf8183a22b1eb2cdf5ff"
BACKUPS = Path("/root/Bambodia-Footer-Terms-Link-Backups")
AURORA = (
    ("public/css/home-hero-aurora.css", "c4220b2db4fdd9a5abd341be5232e869229dfcd5"),
    ("public/css/footer-two-tone-themes.css", "4cbf944d33f622ebf282e7eb2f885d6af150d07a"),
)

OLD_QUERY = rb"""  $footerPolicies = \App\Models\Policy::query()->where('status', 'published')
      ->whereIn('category', ['cookies', 'gdpr', 'privacy', 'terms'])
      ->orderByDesc('updated_at')->get()->unique('category')->keyBy('category');"""
NEW_QUERY = rb"""  $footerPolicies = \App\Models\Policy::query()->where('status', 'published')
      ->whereIn('category', ['cookies', 'gdpr', 'privacy'])
      ->orderByDesc('updated_at')->get()->unique('category')->keyBy('category');
  // Several published documents share the "terms" category. Select the actual
  // Terms of Service by canonical slug, not an unrelated recently edited policy.
  $footerTermsOfService = \App\Models\Policy::query()
      ->where('status', 'published')
      ->where('category', 'terms')
      ->where('slug', 'terms-of-service')
      ->orderByDesc('published_at')->orderByDesc('id')->first();"""
OLD_LINK = b"""@if($footerPolicies->has('terms'))<a href="{{ $footerPolicies->get('terms')->publicUrl() }}">Terms of Service</a>@endif"""
NEW_LINK = b"""@if($footerTermsOfService)<a href="{{ $footerTermsOfService->publicUrl() }}">Terms of Service</a>@endif"""

def blocked(message):
    raise RuntimeError("BLOCKED=" + message)

def git_blob(payload):
    return hashlib.sha1(b"blob " + str(len(payload)).encode("ascii") + b"\x00" + payload).hexdigest()

def sha256(payload):
    return hashlib.sha256(payload).hexdigest()

def run(*args, cwd=None):
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

def api(relative, raw=False):
    args = ["gh", "api"]
    if raw:
        args += ["-H", "Accept: application/vnd.github.raw+json"]
    return run(*args, "repos/" + REPO + "/" + relative)

def file_at(relative):
    if not APP.is_dir() or APP.is_symlink():
        blocked("Unsafe application root")
    here = APP
    for part in Path(relative).parts:
        if part in ("..", "."):
            blocked("Unsafe file path")
        here /= part
        if here.is_symlink():
            blocked("Symlink in target path " + relative)
    if not here.is_file():
        blocked("Expected file missing " + relative)
    return here

def verify_published_canonical_terms():
    # Read-only Laravel ORM query. No database writes or secrets are printed.
    php_code = (
        "require 'vendor/autoload.php';"
        "$app = require 'bootstrap/app.php';"
        "$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
        "$policy = \\App\\Models\\Policy::query()"
        "->where('status','published')->where('category','terms')"
        "->where('slug','terms-of-service')"
        "->orderByDesc('published_at')->orderByDesc('id')->first();"
        "echo $policy ? $policy->public_code.'-'.$policy->slug : 'NOT_PUBLISHED';"
    )
    try:
        result = run("runuser", "-u", "www", "--", PHP, "-r", php_code, cwd=str(APP))
    except subprocess.CalledProcessError:
        blocked("Could not verify published canonical policy in Laravel")
    if result.decode("utf-8", errors="replace").strip() != "91204896-terms-of-service":
        blocked("Canonical Terms publication does not match reviewed public reference")
    print("PUBLISHED_TERMS_REFERENCE=91204896-terms-of-service_VERIFIED")

def preflight(head):
    if os.geteuid() != 0:
        blocked("Root operator required")
    for command in ("gh", "git", "runuser", "python3"):
        if shutil.which(command) is None:
            blocked("Missing command " + command)
    if not Path(PHP).is_file() or not os.access(PHP, os.X_OK):
        blocked("Expected PHP CLI unavailable")
    file_at("artisan")
    if json.loads(api("commits/main"))["sha"] != BASE:
        blocked("Main moved: reconcile before installation")
    pr = json.loads(api("pulls/61"))
    if (pr["head"]["sha"] != head or pr["head"]["ref"] != BRANCH
            or pr["base"]["ref"] != "main" or pr["base"]["sha"] != BASE
            or pr["state"] != "open" or not pr["draft"]):
        blocked("Reviewed PR #61 no longer matches this release")

    before = api("contents/" + LAYOUT + "?ref=" + BASE, raw=True)
    reviewed = api("contents/" + LAYOUT + "?ref=" + head, raw=True)
    if git_blob(before) != OLD_BLOB or git_blob(reviewed) != NEW_BLOB:
        blocked("GitHub layout blobs do not match reviewed release")
    if before.count(OLD_QUERY) != 1 or before.count(OLD_LINK) != 1:
        blocked("Old Terms policy selection differs from baseline")
    expected = before.replace(OLD_QUERY, NEW_QUERY, 1).replace(OLD_LINK, NEW_LINK, 1)
    if expected != reviewed:
        blocked("Unreviewed layout changes detected")
    if reviewed.count(b"route('policy-center')") != 1:
        blocked("Policy Center link changed unexpectedly")
    for relative, expected_blob in AURORA:
        if git_blob(file_at(relative).read_bytes()) != expected_blob:
            blocked("Approved Aurora asset differs: " + relative)
    manifest = file_at("public/build/manifest.json").read_bytes()
    for key in ("resources/css/app.css", "resources/js/app.js"):
        entry = json.loads(manifest).get(key, {}).get("file")
        if not isinstance(entry, str) or not entry.startswith("assets/") or ".." in Path(entry).parts:
            blocked("Unexpected active Vite manifest entry")
        file_at("public/build/" + entry)

    existing_path = file_at(LAYOUT)
    current = existing_path.read_bytes()
    current_blob = git_blob(current)
    if current_blob not in (OLD_BLOB, NEW_BLOB):
        blocked("Live layout differs from approved deployed source")
    verify_published_canonical_terms()
    print("GITHUB_SOURCE_AND_SCOPE=HASH_VERIFIED")
    print("AURORA_AND_VITE=UNTOUCHED")
    print("LIVE_LAYOUT=" + ("ALREADY_NEW" if current_blob == NEW_BLOB else "APPROVED_PREVIOUS"))
    return None if current_blob == NEW_BLOB else (existing_path, current, reviewed, existing_path.stat(), sha256(manifest))

def atomic_write(path, data, previous_stat):
    fd, temporary = tempfile.mkstemp(prefix=".footer-terms-", dir=str(path.parent))
    try:
        os.fchown(fd, previous_stat.st_uid, previous_stat.st_gid)
        os.fchmod(fd, stat.S_IMODE(previous_stat.st_mode))
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)

def clear_views():
    run("runuser", "-u", "www", "--", PHP, "artisan", "view:clear", cwd=str(APP))
    print("VIEW_CACHE=CLEARED")

def apply(head):
    prepared = preflight(head)
    if prepared is None:
        print("RESULT=FOOTER_TERMS_LINK_ALREADY_FIXED")
        return
    path, before, reviewed, st, vite_digest = prepared
    if BACKUPS.is_symlink():
        blocked("Unsafe backup directory symlink")
    BACKUPS.mkdir(parents=True, mode=0o700, exist_ok=True)
    if BACKUPS.is_symlink() or not BACKUPS.is_dir():
        blocked("Backup directory invalid")
    backup = Path(tempfile.mkdtemp(prefix="terms-", dir=str(BACKUPS)))
    os.chmod(backup, 0o700)
    saved = backup / "main.blade.php"
    saved.write_bytes(before)
    os.chmod(saved, 0o600)
    if saved.read_bytes() != before:
        blocked("Backup verification failed")
    manifest = backup / "manifest.json"
    manifest.write_text(json.dumps({
        "head": head, "base": BASE, "old_sha256": sha256(before),
        "new_sha256": sha256(reviewed), "vite_manifest_sha256": vite_digest,
        "verified_terms_reference": "91204896-terms-of-service"
    }, indent=2) + "\n")
    os.chmod(manifest, 0o600)
    print("BACKUP=HASH_VERIFIED")
    print("PRIVATE_BACKUP_DIR=" + str(backup))
    wrote = False
    try:
        if git_blob(file_at(LAYOUT).read_bytes()) != OLD_BLOB:
            blocked("Live layout changed after preflight")
        atomic_write(path, reviewed, st)
        wrote = True
        clear_views()
        if git_blob(file_at(LAYOUT).read_bytes()) != NEW_BLOB:
            blocked("Installed layout integrity mismatch")
        if sha256(file_at("public/build/manifest.json").read_bytes()) != vite_digest:
            blocked("Vite manifest changed during installation")
        for relative, expected_blob in AURORA:
            if git_blob(file_at(relative).read_bytes()) != expected_blob:
                blocked("Aurora stylesheet modified unexpectedly")
        print("DEPLOYED_FILES=1_HASH_VERIFIED")
        print("DATABASE_VITE_NGINX_PHP_FPM=UNCHANGED_BY_INSTALLER")
        print("RESULT=PUBLIC_FOOTER_TERMS_LINK_FIXED")
    except Exception:
        if wrote:
            atomic_write(path, before, st)
        try:
            clear_views()
        except Exception:
            print("ROLLBACK_CACHE_CLEAR=MANUAL_ACTION_REQUIRED")
        print("ROLLBACK=APPROVED_PREVIOUS_LAYOUT_RESTORED")
        raise

def main():
    parser = argparse.ArgumentParser()
    commands = parser.add_mutually_exclusive_group(required=True)
    commands.add_argument("--check", action="store_true")
    commands.add_argument("--apply", action="store_true")
    parser.add_argument("head", help="Pinned GitHub PR #61 commit")
    args = parser.parse_args()
    if args.check:
        result = preflight(args.head)
        print("RESULT=" + ("ALREADY_FIXED" if result is None else "READY_FOR_FOOTER_TERMS_LINK_FIX"))
    else:
        apply(args.head)

if __name__ == "__main__":
    main()
