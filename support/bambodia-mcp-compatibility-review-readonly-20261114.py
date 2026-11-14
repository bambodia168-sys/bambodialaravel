#!/usr/bin/env python3
"""Review Bambodia MCP-only stage against actual aaPanel live source.

Does not write files, connect to MySQL, read .env, execute PHP or install
dependencies. Prints ONLY code-relative filenames and aggregate line changes.
"""
import difflib
import hashlib
import json
import os
from pathlib import Path
import stat
import sys

STAGE=Path("/root/Bambodia-MCP-Only-Install.kw4pjkmi")
LIVE=Path("/www/wwwroot/bambodia-staging-qa")
PRIVATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp")
PREPARE="support/bambodia-mcp-minimal-runtime-stage-20261114.py"
CRITICAL=(
    "app/Http/Controllers/Api/BambodiaMcpController.php",
    "app/Http/Middleware/SetLocale.php",
    "app/Models/User.php",
    "app/Providers/AppServiceProvider.php",
    "app/Support/Mcp/BambodiaTools.php",
    "config/auth.php",
    "resources/views/developer/index.blade.php",
    "resources/views/layouts/workspace.blade.php",
    "routes/web.php",
)
class Blocked(Exception):
    pass

def only_root_dir(path):
    s=path.lstat()
    return stat.S_ISDIR(s.st_mode) and s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700 and not path.is_symlink()

def file_bytes(base,rel,allow_absent=False):
    if not isinstance(rel,str) or rel.startswith("/") or ".." in Path(rel).parts or "\x00" in rel:
        raise Blocked("UNSAFE_SOURCE_PATH")
    path=base/rel
    for parent in (path, *path.parents):
        if parent==base:break
        if parent.is_symlink():
            raise Blocked("CODE_PATH_SYMLINK")
    try: s=path.lstat()
    except FileNotFoundError:
        if allow_absent:return None
        raise Blocked("CODE_FILE_MISSING")
    if not stat.S_ISREG(s.st_mode) or not 0<s.st_size<=20*1024*1024:
        raise Blocked("CODE_FILE_UNSAFE")
    return path.read_bytes()

def sha(data):
    return hashlib.sha256(data).hexdigest()

def diff_size(before,after):
    old=before.decode("utf-8",errors="replace").splitlines()
    new=after.decode("utf-8",errors="replace").splitlines()
    additions=deletions=0
    for tag,i1,i2,j1,j2 in difflib.SequenceMatcher(a=old,b=new,autojunk=False).get_opcodes():
        if tag in ("replace","delete"):deletions+=i2-i1
        if tag in ("replace","insert"):additions+=j2-j1
    return additions,deletions

def inspect():
    if os.geteuid()!=0:raise Blocked("ROOT_REQUIRED")
    if not only_root_dir(STAGE) or not only_root_dir(PRIVATE):
        raise Blocked("STAGE_OR_PRIVATE_ROOT_UNSAFE")
    report=STAGE/"review.json"
    s=report.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or stat.S_IMODE(s.st_mode)!=0o600 or s.st_nlink!=1 or s.st_size>65536:
        raise Blocked("STAGE_MANIFEST_UNSAFE")
    m=json.loads(file_bytes(STAGE,"review.json"))
    if m.get("state")!="PRIVATE_STAGED_UNAPPROVED_NO_DEPLOY" or m.get("live_root")!=str(LIVE) or m.get("candidate")!=str(PRIVATE):
        raise Blocked("STAGE_PROVENANCE_MISMATCH")
    entries=m.get("mcp_only_patch_sha256")
    originals=m.get("existing_file_differences_require_code_review")
    if not isinstance(entries,dict) or len(entries)!=36 or not isinstance(originals,list) or len(originals)!=10 or len(set(originals))!=10:
        raise Blocked("STAGE_SOURCE_INVENTORY_INVALID")
    if any(p not in entries for p in originals):
        raise Blocked("CHANGED_PATH_NOT_IN_MCP_PATCH")
    if not (PRIVATE/"source/vendor/laravel/mcp").is_dir() or not (PRIVATE/"source/vendor/laravel/passport").is_dir():
        raise Blocked("PRIVATE_VENDOR_NOT_READY")
    expected_lock=m.get("composer_lock_sha256")
    if not isinstance(expected_lock,str) or len(expected_lock)!=64:
        raise Blocked("CANDIDATE_LOCK_INVALID")
    if sha(file_bytes(PRIVATE/"source","composer.lock"))!=expected_lock or sha(file_bytes(STAGE,"composer.lock"))!=expected_lock:
        raise Blocked("COMPOSER_LOCK_CHANGED")
    print("MCP_ONLY_COMPAT_CANDIDATE=PRIVATE_VENDOR_PRESENT")
    print("MCP_ONLY_COMPAT_COMPOSER_LOCK=PASS")
    patches={}
    for rel,expected in entries.items():
        candidate=file_bytes(STAGE/"patch",rel)
        if not isinstance(expected,str) or sha(candidate)!=expected:
            raise Blocked("MCP_PATCH_CONTENT_CHANGED")
        patches[rel]=candidate
    if len(patches)!=36:
        raise Blocked("PATCH_COUNT_MISMATCH")
    print("MCP_ONLY_COMPAT_PATCH_INTEGRITY=36_OF_36_PASS")
    if any(file_bytes(LIVE,rel)!=file_bytes(PRIVATE/"review/live-critical",rel) for rel in CRITICAL):
        raise Blocked("LIVE_MCP_CRITICAL_DRIFT")
    print("MCP_ONLY_COMPAT_CRITICAL_SNAPSHOTS=9_OF_9_PASS")
    actual=[]
    for rel,candidate in patches.items():
        original=file_bytes(LIVE,rel,allow_absent=True)
        if original is not None and candidate!=original:
            actual.append(rel)
    if set(actual)!=set(originals):
        raise Blocked("LIVE_SOURCE_CHANGED_SINCE_STAGING")
    print("MCP_ONLY_COMPAT_CHANGED_EXISTING=10_OF_10_VERIFIED")
    for rel in sorted(actual):
        original=file_bytes(LIVE,rel)
        additions,deletions=diff_size(original,patches[rel])
        print(f"MCP_ONLY_COMPAT_DIFF={rel}|ADDED_LINES={additions}|REMOVED_LINES={deletions}")
    print("MCP_ONLY_COMPAT_PRIVATE_SOURCE_CONTENT=NOT_EXPORTED")
    print("MCP_ONLY_COMPAT_MIGRATIONS=NOT_RUN")
    print("MCP_ONLY_COMPAT_VENDOR=NOT_INSTALLED")
    print("MCP_ONLY_COMPAT_PRODUCTION_FILES=UNCHANGED")
    print("MCP_ONLY_COMPAT_NEXT_GATE=REVIEW_DIFFERENCES_BEFORE_INSTALL")

if __name__=="__main__":
    if sys.argv[1:]!=["--inspect"]:
        print("USAGE=--inspect");sys.exit(64)
    try:inspect()
    except (Blocked,FileNotFoundError,OSError,UnicodeError,ValueError,TypeError,KeyError) as err:
        code=str(err) if isinstance(err,Blocked) else "PRIVATE_LOCAL_INSPECTION_REQUIRED"
        print("MCP_ONLY_COMPAT_BLOCKED="+code)
        print("MCP_ONLY_COMPAT_PRODUCTION_FILES=UNCHANGED")
        sys.exit(1)
