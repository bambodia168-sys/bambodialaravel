#!/usr/bin/env python3
"""Read-only aaPanel verification of the six reconciled Bambodia UI releases.

No writes to the website, database, environment, Nginx, PHP-FPM or Git refs.
Safe to rerun after the reviewed GitHub release is merged into main.
"""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

REPO = "bambodia168-sys/bambodialaravel"
APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
BACKUP = Path("/root/Bambodia-Premium-Blog-Player-Backups/release-tzvpp4u3")
FILES = (
    "app/Http/Controllers/HomeController.php",
    "public/css/header-flag-scroll-top.css",
    "resources/css/app.css",
    "resources/js/app.js",
    "resources/js/blog-premium-player.js",
    "resources/views/account/index.blade.php",
    "resources/views/account/studio.blade.php",
    "resources/views/builder/index.blade.php",
    "resources/views/components/blog-card-featured-media.blade.php",
    "resources/views/components/blog-premium-native-video.blade.php",
    "resources/views/components/job-list-row.blade.php",
    "resources/views/components/listing-card.blade.php",
    "resources/views/components/pagination.blade.php",
    "resources/views/components/workspace-metric.blade.php",
    "resources/views/content/blog.blade.php",
    "resources/views/content/show.blade.php",
    "resources/views/dashboard/index.blade.php",
    "resources/views/details/support.blade.php",
    "resources/views/home.blade.php",
    "resources/views/jobs/show.blade.php",
    "resources/views/layouts/main.blade.php",
    "resources/views/layouts/workspace.blade.php",
    "resources/views/marketplace/detail.blade.php",
    "resources/views/support/knowledge-index.blade.php",
)

def fail(detail):
    raise RuntimeError("BLOCKED=" + detail)

def gh(path, raw=False):
    cmd = ["gh", "api"]
    if raw:
        cmd.extend(["-H", "Accept: application/vnd.github.raw+json"])
    cmd.append("repos/" + REPO + "/" + path)
    return subprocess.run(cmd, check=True, stdout=subprocess.PIPE).stdout

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def safe(root, name):
    if root.is_symlink() or root.resolve() != root:
        fail("Unsafe application root")
    current = root
    for component in Path(name).parts:
        current = current / component
        if current.is_symlink():
            fail("Symlink inside application path: " + name)
    if not current.is_file():
        fail("Missing live file: " + name)
    return current

def main():
    if sys.argv[1:] not in ([], ["--check"]):
        fail("Usage: python3 reconcile-live-ui-aapanel-readonly.py --check")
    if os.geteuid() != 0:
        fail("Run as aaPanel root operator")
    if shutil.which("gh") is None:
        fail("GitHub CLI is required")
    if not safe(APP, "artisan"):
        fail("Wrong Laravel application root")
    if not VHOST.is_file() or VHOST.is_symlink() or (
        "root " + str(APP / "public") + ";"
    ) not in VHOST.read_text():
        fail("Unexpected Bambodia Nginx document root")
    commit = json.loads(gh("commits/main"))["sha"]
    tree = json.loads(gh("git/trees/" + commit + "?recursive=1"))
    if tree.get("truncated"):
        fail("GitHub tree is truncated; verification is incomplete")
    expected = {item["path"]: item["sha"] for item in tree["tree"]
                if item["type"] == "blob"}
    print("GITHUB_MAIN=" + commit)
    print("LIVE_APP=" + str(APP))
    missing = [path for path in FILES if path not in expected]
    if missing:
        fail("GitHub main does not contain the reconciled release: " + ",".join(missing))
    verified = 0
    for rel in FILES:
        content = safe(APP, rel).read_bytes()
        if blob(content) == expected[rel]:
            print("LIVE_SOURCE=EXACT_MATCH " + rel)
            verified += 1
            continue
        if rel == "resources/views/layouts/workspace.blade.php":
            # This previously reviewed live host intentionally has only one
            # Preview Visibility navigation link absent. Accept only that
            # exact one-line omission, no other deviations.
            source = gh("contents/" + rel + "?ref=" + commit, raw=True)
            if blob(source) != expected[rel]:
                fail("Unverified GitHub workspace source")
            line = (b"        ['route'=>'dashboard.preview-visibility.index',"
                    b"'label'=>'Preview Visibility','icon'=>'shield-check',"
                    b"'roles'=>['super_admin']],\n")
            if source.count(line) == 1 and source.replace(line, b"", 1) == content:
                print("LIVE_SOURCE=APPROVED_MENU_ABSENCE_PRESERVED " + rel)
                verified += 1
                continue
        fail("Live source differs from reconciled GitHub main: " + rel)
    if verified != len(FILES):
        fail("Incomplete verified source count")

    header = safe(APP, "resources/views/layouts/main.blade.php").read_text()
    workspace = safe(APP, "resources/views/layouts/workspace.blade.php").read_text()
    home = safe(APP, "resources/views/home.blade.php").read_text()
    article = safe(APP, "resources/views/content/show.blade.php").read_text()
    controls = safe(APP, "resources/views/components/blog-premium-native-video.blade.php").read_text()
    for marker, text in (
        ("flag-only public header", "nav-language-menu header-flag-only" in header),
        ("flag-only account header", 'class="header-flag-only"' in workspace),
        ("arrow-only Back to Top", 'data-back-to-top' in header and "back-to-top-label" not in header),
        ("responsive home cards", "marketplace-freelancer" in home),
        ("featured blog cards", "x-blog-card-featured-media" in home),
        ("premium article video", "x-blog-premium-native-video" in article),
        ("custom player controls", "data-blog-player-fullscreen" in controls and
         "data-blog-player-seek" in controls and "data-blog-player-volume" in controls),
    ):
        if not text:
            fail("Missing feature: " + marker)
        print("FEATURE=VERIFIED " + marker)

    manifest = json.loads(safe(APP, "public/build/manifest.json").read_text())
    assets = []
    for key, suffix in (("resources/css/app.css", ".css"), ("resources/js/app.js", ".js")):
        item = manifest.get(key)
        name = item.get("file") if isinstance(item, dict) else None
        if not isinstance(name, str) or not name.startswith("assets/") or (
            ".." in Path(name).parts) or not name.endswith(suffix):
            fail("Invalid Vite manifest entry " + key)
        file = safe(APP, "public/build/" + name)
        if file.stat().st_size == 0:
            fail("Empty compiled Vite asset " + name)
        assets.append((name, file))
        print("VITE_ASSET=VERIFIED " + name)
    if b".blog-premium-player" not in assets[0][1].read_bytes() or (
        b"data-blog-premium-player" not in assets[1][1].read_bytes()):
        fail("Installed Vite bundle is not the premium article-player release")
    if not BACKUP.is_dir() or BACKUP.is_symlink():
        fail("Expected existing premium-player recovery backup missing")
    print("PRIVATE_BACKUP=VERIFIED " + str(BACKUP))
    print("GITHUB_AND_LIVE_SOURCES=" + str(verified) + "_VERIFIED")
    print("CHANGES_TO_APPLICATION=NONE")
    print("RESULT=BAMBODIA_RECONCILED_MAIN_AND_AAPANEL_MATCH")

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(2)
