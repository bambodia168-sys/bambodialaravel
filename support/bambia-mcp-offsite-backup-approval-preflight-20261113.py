#!/usr/bin/env python3
"""Bambodia MCP recovery: approval/config preflight, strictly no backup creation.

Reads a root-only OFF-SERVER restic approval manifest if present, never prints
repository addresses, credentials, password values, database names or paths.
No MySQL connection, media file reads, network access, chmod or snapshot calls.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys

APP = Path("/www/wwwroot/bambodia-staging-qa")
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
VHOST_SHA = "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116"
COMPOSER_BLOB = "457992551f4f234ad442d9db5ad73c8b54689e7b"
LOCK_SHA = "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
APPROVAL = Path("/root/bambodia-mcp-recovery/approval.json")
MAX_CONFIG = 8192


def emit(k, v):
    print(k + "=" + str(v), flush=True)


def sha256(path):
    d = hashlib.sha256()
    with path.open("rb") as f:
        while c := f.read(1024 * 1024):
            d.update(c)
    return d.hexdigest()


def git_blob(path):
    data = path.read_bytes()
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()


def guard_file(path, size_limit):
    p = Path(path)
    info = p.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or \
            (stat.S_IMODE(info.st_mode) & 0o077) or info.st_size > size_limit:
        raise ValueError("UNSAFE_PRIVATE_FILE")
    return p


def source_identity():
    if APP.is_symlink() or not APP.is_dir() or not (APP / "artisan").is_file() or \
            not (APP / "storage/app/installed.lock").is_file():
        raise ValueError("LIVE_APP_CHANGED")
    if VHOST.is_symlink() or sha256(VHOST) != VHOST_SHA:
        raise ValueError("VHOST_CHANGED")
    if git_blob(APP / "composer.json") != COMPOSER_BLOB or \
            sha256(APP / "composer.lock") != LOCK_SHA:
        raise ValueError("LIVE_DEPENDENCIES_CHANGED")
    emit("MCP_RECOVERY_SOURCE_IDENTITY", "PASS")


def validate_approval(a):
    # A signed, independently approved manifest is a separate prerequisite.
    # Booleans in a local JSON file only represent operator declarations;
    # this preflight cannot validate true off-server access or key custody.
    booleans = [
        "owner_approved", "security_reviewer_approved",
        "off_server_destination_independently_verified",
        "separate_encryption_key_custody_approved",
        "db_scope_and_privileges_privately_verified",
        "media_disks_and_content_consistency_reviewed",
        "approved_change_window_and_write_quiescence",
        "isolated_restore_target_confirmed",
        "rollback_artifact_and_plan_reviewed",
    ]
    for k in booleans:
        emit("MCP_RECOVERY_APPROVAL_" + k.upper(),
             "DECLARED" if a.get(k) is True else "MISSING")
    for k in ("rpo_minutes", "rto_minutes", "retention_days"):
        v = a.get(k)
        emit("MCP_RECOVERY_" + k.upper(), "APPROVED_VALUE_PRESENT"
             if isinstance(v, int) and not isinstance(v, bool) and 0 < v < 525600
             else "NOT_APPROVED")
    repo = a.get("repository", "")
    kind = ""
    if isinstance(repo, str):
        for prefix in ("sftp:", "s3:", "rest:", "rest+http:", "rclone:"):
            if repo.startswith(prefix):
                kind = prefix.rstrip(":").upper().replace("+", "_")
                break
    # Never log repository URI (it may contain private server/bucket identifiers).
    emit("MCP_RECOVERY_OFFSITE_REPOSITORY_TYPE", kind or "NOT_APPROVED")
    if not kind or repo.endswith(":") or re.search(r"localhost|127[.]0[.]0[.]1|::1", repo, re.I):
        emit("MCP_RECOVERY_OFFSITE_CONFIG", "BLOCKED")
        return False
    if a.get("encryption_engine") != "restic":
        emit("MCP_RECOVERY_ENCRYPTION_ENGINE", "NOT_APPROVED")
        return False
    emit("MCP_RECOVERY_ENCRYPTION_ENGINE", "RESTIC_CONFIG_DECLARED")
    p = a.get("restic_password_file")
    if not isinstance(p, str) or not p.startswith("/root/") or "\x00" in p:
        emit("MCP_RECOVERY_KEY_FILE", "NOT_APPROVED")
        return False
    try:
        safe = guard_file(p, 8192)
        if not safe.read_bytes():
            emit("MCP_RECOVERY_KEY_FILE", "EMPTY")
            return False
    except (OSError, ValueError):
        emit("MCP_RECOVERY_KEY_FILE", "MISSING_OR_UNSAFE")
        return False
    emit("MCP_RECOVERY_KEY_FILE", "PRIVATE_EXISTS_NOT_READ_FOR_OUTPUT")
    valid = all(a.get(k) is True for k in booleans) and all(
        isinstance(a.get(k), int) and not isinstance(a.get(k), bool)
        and 0 < a[k] < 525600 for k in ("rpo_minutes", "rto_minutes", "retention_days"))
    return valid


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--inspect", action="store_true", required=True)
    parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError("ROOT_ONLY")
    source_identity()
    for cmd in ("restic", "mysqldump", "tar", "mysql"):
        emit("MCP_RECOVERY_TOOL_" + cmd.upper(), "FOUND" if shutil.which(cmd) else "NOT_FOUND")
    if not APPROVAL.is_file() or APPROVAL.is_symlink():
        emit("MCP_RECOVERY_APPROVAL_MANIFEST", "NOT_PRESENT")
        approved = False
    else:
        guard_file(APPROVAL, MAX_CONFIG)
        a = json.loads(APPROVAL.read_text(encoding="utf-8"))
        if not isinstance(a, dict):
            raise ValueError("INVALID_APPROVAL")
        emit("MCP_RECOVERY_APPROVAL_MANIFEST", "PRIVATE_PRESENT")
        approved = validate_approval(a)
    emit("MCP_RECOVERY_OFFSITE_ACCESS", "NOT_TESTED")
    emit("MCP_RECOVERY_BACKUP_CREATED", "NO")
    emit("MCP_RECOVERY_ISOLATED_RESTORE", "NOT_PERFORMED")
    emit("MCP_RECOVERY_ROLLBACK_REHEARSAL", "NOT_PERFORMED")
    emit("MCP_RECOVERY_MANIFEST_DECLARATIONS", "COMPLETE_NOT_INDEPENDENTLY_VERIFIED"
         if approved else "BLOCKED_INCOMPLETE")
    emit("MCP_PRODUCTION_RELEASE", "BLOCKED")
    emit("MCP_RECOVERY_PREFLIGHT", "READ_ONLY_COMPLETED")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, json.JSONDecodeError):
        emit("MCP_RECOVERY_PREFLIGHT", "BLOCKED_PRIVATE_OPERATOR_REVIEW")
        sys.exit(1)
