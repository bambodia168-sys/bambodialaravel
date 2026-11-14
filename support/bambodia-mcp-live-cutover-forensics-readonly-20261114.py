#!/usr/bin/env python3
"""Read-only production MCP cutover forensics, without reattempting deployment.

Examines paths and permissions, Laravel CLI help and prior installer snapshots.
Does not execute artisan down/up/migrate, modify source/vendor/env, or read logs.
"""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
STAGE=Path("/root/Bambodia-MCP-Only-Install.kw4pjkmi")
PRIVATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp")
PHP="/www/server/php/85/bin/php"
OLD_LOCK_SHA="ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
NEW_LOCK_SHA="4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8"
BOOT_BLOB="e2e5294ccc41d7304504fed9b451eae737f22899"
class Stop(Exception):pass

def sha(data):return hashlib.sha256(data).hexdigest()
def blob(data):return hashlib.sha1(f"blob {len(data)}\0".encode()+data).hexdigest()

def read(path,maximum=20_000_000):
    if path.is_symlink():raise Stop("SYMLINK_FILE")
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_size<=0 or st.st_size>maximum:
        raise Stop("UNSAFE_FILE")
    return path.read_bytes()

def stat_metadata(path):
    try:st=path.lstat()
    except FileNotFoundError:return None
    if stat.S_ISLNK(st.st_mode):raise Stop("SOURCE_PATH_SYMLINK")
    return st

def normalized_path(root,rel):
    if not isinstance(rel,str) or rel.startswith("/") or "\0" in rel or str(Path(rel))!=rel or ".." in Path(rel).parts:
        raise Stop("STAGE_UNSAFE_PATH")
    result=root/rel
    for ancestor in (result,*result.parents):
        if ancestor==root:break
        if ancestor.is_symlink():raise Stop("LIVE_PARENT_SYMLINK")
    return result

def accessible_to_www(st,uid,gids,readable=False,writable=False):
    if st is None:return False
    mode=stat.S_IMODE(st.st_mode)
    for bit in ([4] if readable else [])+([2] if writable else [])+([1] if stat.S_ISDIR(st.st_mode) else []):
        value=(mode>>6 if st.st_uid==uid else mode>>3 if st.st_gid in gids else mode) & 7
        if not value & bit:return False
    return True

def www_cli_command(php,command):
    executable=next((str(p) for p in
         (Path("/usr/sbin/runuser"),Path("/usr/bin/runuser"))
         if p.is_file() and os.access(p,os.X_OK)),None)
    if executable is None:raise Stop("RUNUSER_NOT_FOUND")
    try:
        result=subprocess.run([executable,"-u","www","--",php,"artisan",*command],
               cwd=str(LIVE),capture_output=True,timeout=35,check=False,
               stdin=subprocess.DEVNULL)
    except (OSError,subprocess.TimeoutExpired):
        return False
    return result.returncode==0

def audit():
    if os.geteuid()!=0:raise Stop("ROOT_REQUIRED")
    if LIVE.is_symlink() or not LIVE.is_dir() or STAGE.is_symlink() or not STAGE.is_dir():
        raise Stop("APP_OR_STAGE_UNSAFE")
    if sha(read(LIVE/"composer.lock"))!=OLD_LOCK_SHA:
        raise Stop("LIVE_COMPOSER_NOT_RESTORED")
    if blob(read(LIVE/"bootstrap/app.php"))!=BOOT_BLOB:
        raise Stop("LIVE_BOOTSTRAP_NOT_RESTORED")
    if sha(read(STAGE/"composer.lock"))!=NEW_LOCK_SHA:
        raise Stop("CANDIDATE_LOCK_CHANGED")
    print("MCP_FORENSICS_ORIGINAL_COMPOSER_BOOTSTRAP=PASS")
    manifest=json.loads(read(STAGE/"review.json",65536))
    entries=manifest.get("mcp_only_patch_sha256")
    if not isinstance(entries,dict) or len(entries)!=36:
        raise Stop("STAGE_PATCH_MANIFEST_CHANGED")
    originals=manifest.get("existing_file_differences_require_code_review")
    if not isinstance(originals,list) or len(originals)!=10:
        raise Stop("STAGE_PATCH_ORIGINAL_INVENTORY_CHANGED")
    for rel in originals:
        if rel=="bootstrap/app.php":continue
        if read(normalized_path(LIVE,rel))!=read(normalized_path(PRIVATE/"review/live-critical",rel)):
            raise Stop("LIVE_SOURCE_NOT_RESTORED")
    print("MCP_FORENSICS_NINE_SOURCE_SNAPSHOTS=PASS")
    if (LIVE/"storage/framework/down").exists():
        raise Stop("APPLICATION_MAINTENANCE_MARKER_PRESENT")
    print("MCP_FORENSICS_LIVE_MAINTENANCE=OFF")
    www=pwd.getpwnam("www")
    gids=set(os.getgrouplist("www",www.pw_gid))
    anomalies=[]; new_paths=0;existing_paths=0
    for rel,hash_expected in entries.items():
        if not re.fullmatch("[0-9a-f]{64}",str(hash_expected)) or sha(read(normalized_path(STAGE/"patch",rel)))!=hash_expected:
            raise Stop("STAGE_PATCH_HASH_MISMATCH")
        path=normalized_path(LIVE,rel)
        st=stat_metadata(path)
        if st is None:new_paths+=1
        elif not stat.S_ISREG(st.st_mode):anomalies.append((rel,"TARGET_IS_NOT_REGULAR_FILE"))
        else:existing_paths+=1
        if st is not None and not accessible_to_www(st,www.pw_uid,gids,readable=True):
            anomalies.append((rel,"EXISTING_FILE_UNREADABLE_BY_WWW"))
        parent=path.parent
        while parent!=LIVE and parent.is_relative_to(LIVE):
            meta=stat_metadata(parent)
            if meta is not None:
                if not stat.S_ISDIR(meta.st_mode):anomalies.append((rel,"PARENT_NOT_DIRECTORY"))
                elif not accessible_to_www(meta,www.pw_uid,gids):
                    anomalies.append((rel,"PARENT_NOT_TRAVERSABLE_BY_WWW"))
            parent=parent.parent
    print(f"MCP_FORENSICS_STAGED_FILES=36")
    print(f"MCP_FORENSICS_EXISTING_FILES={existing_paths}")
    print(f"MCP_FORENSICS_NEW_FILES={new_paths}")
    if anomalies:
        # Print only a relative code path + finite safe reason. No contents.
        for rel,reason in anomalies[:20]:
            print(f"MCP_FORENSICS_PATH_BLOCKER={rel}|{reason}")
    print(f"MCP_FORENSICS_PATH_ANOMALIES={len(anomalies)}")
    if not (LIVE/"vendor/composer/installed.json").is_file():
        raise Stop("OLD_VENDOR_INSTALLED_JSON_MISSING")
    data=json.loads(read(LIVE/"vendor/composer/installed.json"))
    records=data.get("packages",[]) if isinstance(data,dict) else data
    indexed={x["name"]:x.get("version") for x in records}
    if indexed.get("laravel/framework")!="v13.40.0" or "laravel/mcp" in indexed or "laravel/passport" in indexed:
        raise Stop("LIVE_VENDOR_NOT_RESTORED")
    print("MCP_FORENSICS_ORIGINAL_VENDOR=PASS")
    f=os.statvfs(LIVE)
    available=f.f_bavail*f.f_frsize//(1024*1024)
    print(f"MCP_FORENSICS_APP_DISK_FREE_MIB={available}")
    storage_ok=accessible_to_www(
        stat_metadata(LIVE/"storage/framework"),www.pw_uid,gids,writable=True)
    print("MCP_FORENSICS_APP_STORAGE_WWW_WRITABLE="+(
        "PASS" if storage_ok else "BLOCKED"))
    cli_ok=True
    for label,command in (
      ("ORIGINAL_ROUTE_CLI",["route:list","--json","--no-ansi"]),
      ("ARTISAN_DOWN_HELP",["down","--help","--no-ansi"]),
      ("ARTISAN_UP_HELP",["up","--help","--no-ansi"]),
    ):
        completed=www_cli_command(PHP,command)
        cli_ok=cli_ok and completed
        print("MCP_FORENSICS_"+label+"="+(
            "PASS" if completed else "BLOCKED"))
    candidates=list(Path("/root").glob("Bambodia-MCP-LiveRollback.*/rollback.json"))
    states={"READY":0,"INSTALLED_FLAGS_OFF":0,"ROLLED_BACK":0,"OTHER":0}
    for file in candidates:
        try:
            if file.is_symlink() or file.parent.is_symlink():continue
            rec=json.loads(read(file,100000))
            state=rec.get("state","OTHER")
            states[state if state in states else "OTHER"]+=1
        except (OSError,ValueError,KeyError,Stop):
            states["OTHER"]+=1
    print("MCP_FORENSICS_FAILED_ROLLBACK_SNAPSHOTS="+str(states["READY"]))
    print("MCP_FORENSICS_INSTALLER_FAILURE_PHASE=NOT_RECORDED_IN_OLD_RELEASE")
    print("MCP_FORENSICS_PRIVATE_LOGS=NOT_READ")
    print("MCP_FORENSICS_PRODUCTION_MUTATION=NONE")
    return not anomalies and storage_ok and cli_ok

def main():
    if sys.argv[1:]!=["--inspect"]:
        print("USAGE=--inspect")
        return 64
    try:
        clean=audit()
        print("MCP_FORENSICS_STATIC_DESTINATION_CHECK="+("PASS" if clean else "BLOCKED"))
        return 0 if clean else 2
    except Stop as error:
        print("MCP_FORENSICS_BLOCKER="+str(error))
    except (OSError,ValueError,KeyError,TypeError,AttributeError):
        print("MCP_FORENSICS_BLOCKER=REVIEW_REQUIRED")
    print("MCP_FORENSICS_PRODUCTION_MUTATION=NONE")
    return 1

if __name__=="__main__":sys.exit(main())
