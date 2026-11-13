#!/usr/bin/env python3
"""Bambodia MCP root-only metadata review. NEVER changes production file modes.

--inspect outputs aggregate metadata only.
--prepare-local-review writes a restricted local JSON review/rollback proposal.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile

APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
VHOST_SHA256 = "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116"
COMPOSER_GIT_BLOB = "457992551f4f234ad442d9db5ad73c8b54689e7b"
LOCK_SHA256 = "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
MAX_FILES = 50000
MAX_DIRS = 5000


class Blocked(Exception):
    pass


def sha256_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        while chunk := stream.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def git_blob_file(path):
    content = path.read_bytes()
    return hashlib.sha1(b"blob " + str(len(content)).encode() + b"\0" + content).hexdigest()


def assert_site_identity(app=APP, vhost=VHOST):
    if app.is_symlink() or not app.is_dir() or not (app / "artisan").is_file():
        raise Blocked("APP_IDENTITY_CHANGED")
    if not (app / "storage/app/installed.lock").is_file():
        raise Blocked("APP_INSTALL_IDENTITY_CHANGED")
    if vhost.is_symlink() or not vhost.is_file() or sha256_file(vhost) != VHOST_SHA256:
        raise Blocked("VHOST_IDENTITY_CHANGED")
    if git_blob_file(app / "composer.json") != COMPOSER_GIT_BLOB:
        raise Blocked("COMPOSER_IDENTITY_CHANGED")
    if sha256_file(app / "composer.lock") != LOCK_SHA256:
        raise Blocked("LOCK_IDENTITY_CHANGED")


def ancestors(path):
    path = Path(os.path.abspath(path))
    return tuple(reversed((path, *path.parents)))


def inspect_private(root, max_files=MAX_FILES, max_dirs=MAX_DIRS):
    if root.is_symlink() or not root.is_dir():
        raise Blocked("PRIVATE_ROOT_MISSING_OR_LINK")
    ancestry = ancestors(root)
    modes = []
    for p in ancestry:
        meta = p.lstat()
        if not stat.S_ISDIR(meta.st_mode) or p.is_symlink():
            raise Blocked("PRIVATE_ANCESTOR_UNSAFE")
        modes.append(meta.st_mode)
    other_can_traverse_root = all(m & stat.S_IXOTH for m in modes)
    root_device = root.lstat().st_dev
    counts = {"regular": 0, "directories": 0, "links_skipped": 0,
              "other_readable_traversable": 0, "other_writable": 0}
    matches = []
    queue = [(root, other_can_traverse_root)]
    while queue:
        folder, traverse = queue.pop()
        directory_stat = folder.lstat()
        if directory_stat.st_dev != root_device:
            raise Blocked("MOUNT_BOUNDARY_FOUND")
        counts["directories"] += 1
        if counts["directories"] > max_dirs:
            raise Blocked("DIRECTORY_LIMIT_REACHED")
        with os.scandir(folder) as entries:
            for item in entries:
                meta = item.stat(follow_symlinks=False)
                if stat.S_ISLNK(meta.st_mode):
                    counts["links_skipped"] += 1
                    continue
                if meta.st_dev != root_device:
                    raise Blocked("MOUNT_BOUNDARY_FOUND")
                if stat.S_ISDIR(meta.st_mode):
                    queue.append((Path(item.path), bool(traverse and (meta.st_mode & stat.S_IXOTH))))
                    continue
                if not stat.S_ISREG(meta.st_mode):
                    raise Blocked("UNEXPECTED_PRIVATE_OBJECT_TYPE")
                counts["regular"] += 1
                if counts["regular"] > max_files:
                    raise Blocked("FILE_LIMIT_REACHED")
                if meta.st_mode & stat.S_IWOTH:
                    counts["other_writable"] += 1
                if traverse and (meta.st_mode & stat.S_IROTH):
                    counts["other_readable_traversable"] += 1
                    matches.append((Path(item.path), meta))
    return counts, matches


def make_local_report(matches, root):
    if len(matches) != 1:
        raise Blocked("EXPECTED_EXACTLY_ONE_AFFECTED_FILE")
    target, meta = matches[0]
    if not target.is_relative_to(root) or target.is_symlink():
        raise Blocked("TARGET_NOT_REGULAR_UNDER_PRIVATE_ROOT")
    new_mode = stat.S_IMODE(meta.st_mode) & ~stat.S_IROTH
    report = {
        "schema_version": 1,
        "purpose": "LOCAL_OPERATOR_REVIEW_ONLY_NOT_APPROVED_NOT_EXECUTABLE",
        "app_private_root": str(root),
        "affected_relative_path": str(target.relative_to(root)),
        "device": meta.st_dev,
        "inode": meta.st_ino,
        "links": meta.st_nlink,
        "owner_uid": meta.st_uid,
        "group_gid": meta.st_gid,
        "original_mode_octal": format(stat.S_IMODE(meta.st_mode), "04o"),
        "proposed_single_file_mode_octal": format(new_mode, "04o"),
        "file_size_metadata": meta.st_size,
        "modified_ns": meta.st_mtime_ns,
        "cautions": [
            "Path and mode are private. Do not paste this file into chat or GitHub.",
            "Review exact owner, group, ACL, linked inode count and PHP-FPM access privately.",
            "Assess Nginx/public serving and authorized upload/download workflows.",
            "Do not chmod/chown without separate approval and narrow rollback."
        ],
        "rollback_plan": "For the same inode and approved change, restore original_mode_octal after private review."
    }
    private_dir = Path(tempfile.mkdtemp(prefix="bambodia-mcp-private-review-", dir="/root"))
    os.chmod(private_dir, 0o700)
    report_path = private_dir / "review.json"
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(report_path, flags, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(report, stream, indent=2)
            stream.write("\n")
        os.chmod(report_path, 0o600)
    except Exception:
        report_path.unlink(missing_ok=True)
        raise
    return private_dir


def main():
    parser = argparse.ArgumentParser()
    flags = parser.add_mutually_exclusive_group(required=True)
    flags.add_argument("--inspect", action="store_true")
    flags.add_argument("--prepare-local-review", action="store_true")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise Blocked("ROOT_OPERATOR_REQUIRED")
    assert_site_identity()
    root = APP / "storage/app/private"
    counts, matches = inspect_private(root)
    print("MCP_PRIVATE_REVIEW_IDENTITY=PASS")
    for key in ("regular", "directories", "links_skipped",
                "other_readable_traversable", "other_writable"):
        print("MCP_PRIVATE_REVIEW_" + key.upper() + "=" + str(counts[key]))
    if args.prepare_local_review:
        private_dir = make_local_report(matches, root)
        print("MCP_PRIVATE_REVIEW_LOCAL_REPORT=" + str(private_dir / "review.json"))
        print("MCP_PRIVATE_REVIEW_REPORT_ACCESS=ROOT_ONLY")
    print("MCP_PRIVATE_REVIEW_FILE_CONTENT=NOT_READ")
    print("MCP_PRIVATE_REMEDIATION=NOT_PERFORMED")
    print("MCP_PRODUCTION_RELEASE=BLOCKED")


if __name__ == "__main__":
    try:
        main()
    except (Blocked, OSError, ValueError):
        print("MCP_PRIVATE_REVIEW=BLOCKED_CONTACT_OPERATOR")
        sys.exit(1)
