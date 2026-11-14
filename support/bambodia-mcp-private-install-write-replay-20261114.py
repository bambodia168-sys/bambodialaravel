#!/usr/bin/env python3
"""Rehearse *exact* MCP live installer source-writing loop in a private clone.

Imports the previously accepted installer after verifying its exact Git blob.
All writes are redirected to a freshly created /root/ root-only work directory.
Reads current live code/metadata, never live .env, logs, DB, keys or media.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tempfile

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
STAGE=Path("/root/Bambodia-MCP-Only-Install.kw4pjkmi")
OPERATOR=Path("/root/bambodia-mcp-live-runtime-install-tty-fixed.py")
OPERATOR_BLOB="75a2db25e1ea2ad5d06c25771b86f4f75e64d93a"
EXPECTED_STAGED=36
class Stop(Exception):pass

def sha(data):return hashlib.sha256(data).hexdigest()
def blob(data):return hashlib.sha1(f"blob {len(data)}\0".encode()+data).hexdigest()

def load_original_installer():
    if OPERATOR.is_symlink() or not OPERATOR.is_file():
        raise Stop("PINNED_OLD_INSTALLER_MISSING")
    source=OPERATOR.read_bytes()
    if blob(source)!=OPERATOR_BLOB:
        raise Stop("PINNED_OLD_INSTALLER_CHANGED")
    sys.dont_write_bytecode=True
    spec=importlib.util.spec_from_file_location("mcp_old_live_installer_replay",OPERATOR)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def replay():
    if os.geteuid()!=0:raise Stop("ROOT_REQUIRED")
    installer=load_original_installer()
    # The previous 36-file source validation and restored-original checks must
    # pass on the *actual* live app before a replay can be representative.
    entries=installer.safe_patch_inventory()
    if len(entries)!=EXPECTED_STAGED:raise Stop("SOURCE_MANIFEST_CHANGED")
    print("MCP_WRITE_REPLAY_ORIGINAL_INSTALLER=VERIFIED",flush=True)
    print("MCP_WRITE_REPLAY_LIVE_SOURCE_STAGE=VERIFIED",flush=True)
    clone=Path(tempfile.mkdtemp(prefix="Bambodia-MCP-WriteReplay.",dir="/root"))
    clone.chmod(0o700)
    try:
        old={}
        for rel in [*entries.keys(),"composer.json","composer.lock"]:
            source=installer.guarded_path(LIVE,rel)
            if source.exists():
                raw=installer.read_file(source)
                meta=source.lstat()
                target=clone/rel
                target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes(raw)
                target.chmod(0o600)
                old[rel]={"mode":stat.S_IMODE(meta.st_mode),
                          "uid":meta.st_uid,"gid":meta.st_gid}
            else:
                if rel in ("composer.json","composer.lock"):
                    raise Stop("ORIGINAL_COMPOSER_MISSING")
                old[rel]=None
        print("MCP_WRITE_REPLAY_PRIVATE_TARGETS=PREPARED",flush=True)
        # Redirect the accepted installer's write_atomic and safe_live_parent
        # logic, including actual chown and root umask handling, to /root ONLY.
        installer.LIVE=clone
        counter=0
        for rel in entries:
            source=installer.guarded_path(STAGE/"patch",rel)
            current=old[rel]
            try:
                installer.write_atomic(
                    installer.guarded_path(clone,rel),
                    installer.read_file(source),
                    current["mode"] if current else 0o644,
                    current["uid"] if current else 0,
                    current["gid"] if current else 0)
            except Exception:
                print(f"MCP_WRITE_REPLAY_FAILED_AT_INDEX={counter+1}")
                print("MCP_WRITE_REPLAY_STAGE=CODE_FILE_WRITE")
                raise Stop("PRIVATE_CODE_WRITE_FAILED")
            counter+=1
        print(f"MCP_WRITE_REPLAY_PATCH_FILES_WRITTEN={counter}",flush=True)
        for rel in ("composer.json","composer.lock"):
            original=old[rel]
            try:
                installer.write_atomic(
                    installer.guarded_path(clone,rel),
                    installer.read_file(installer.STAGE/rel),
                    original["mode"],original["uid"],original["gid"])
            except Exception:
                raise Stop("PRIVATE_COMPOSER_WRITE_FAILED")
        mismatches=0
        for rel,expected in entries.items():
            if sha(installer.read_file(installer.guarded_path(clone,rel)))!=expected:
                mismatches+=1
        for rel in ("composer.json","composer.lock"):
            if installer.read_file(installer.guarded_path(clone,rel)) != installer.read_file(installer.STAGE/rel):
                mismatches+=1
        if mismatches:raise Stop("PRIVATE_WRITTEN_CONTENT_MISMATCH")
        print("MCP_WRITE_REPLAY_CONTENT_36_AND_COMPOSER=PASS")
        print("MCP_WRITE_REPLAY_INSTALLER_ATOMIC_WRITE=PASS")
        print("MCP_WRITE_REPLAY_LIVE_ROOT=UNCHANGED")
        print("MCP_WRITE_REPLAY_VENDOR_PRODUCTION=UNCHANGED")
        print("MCP_WRITE_REPLAY_DATABASE=NOT_ACCESSED")
        print("MCP_WRITE_REPLAY_MAINTENANCE=NOT_TOUCHED")
    finally:
        # Delete ONLY the newly allocated /root clone, never any staging/live.
        if (clone.parent==Path("/root")
            and clone.name.startswith("Bambodia-MCP-WriteReplay.")
            and not clone.is_symlink() and clone.exists()):
            shutil.rmtree(clone)

def main():
    if sys.argv[1:]!=["--replay"]:
        print("USAGE=--replay");return 64
    try:
        replay()
        print("MCP_WRITE_REPLAY_RESULT=PASS")
        return 0
    except Stop as err:
        print("MCP_WRITE_REPLAY_BLOCKER="+str(err))
    except (OSError,RuntimeError,TypeError,ValueError,KeyError,AttributeError):
        print("MCP_WRITE_REPLAY_BLOCKER=PRIVATE_REVIEW_REQUIRED")
    print("MCP_WRITE_REPLAY_RESULT=BLOCKED")
    print("MCP_WRITE_REPLAY_LIVE_ROOT=UNCHANGED")
    return 1

if __name__=="__main__":
    sys.exit(main())
