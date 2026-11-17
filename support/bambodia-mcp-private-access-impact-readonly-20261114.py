#!/usr/bin/env python3
"""Fail-closed, read-only one-file permission-impact estimate for Bambodia MCP.

Review report remains private on the actual host. No file content opened, no
chmod, no remote request, and no production file writes.
"""
import argparse
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import stat
import sys

PRIVATE_ROOT=Path("/www/wwwroot/bambodia-staging-qa/storage/app/private")
REPORT_DIR_PREFIX="bambodia-mcp-private-review-"
MAX_REPORT=16384


class Blocked(Exception):
    pass


def identity_allows(mode, owner, group, user_uid, user_groups, bit):
    """POSIX mode interpretation only. Never a complete ACL/LSM decision."""
    if user_uid == owner:
        mask = {"r": stat.S_IRUSR, "x": stat.S_IXUSR}[bit]
    elif group in user_groups:
        mask = {"r": stat.S_IRGRP, "x": stat.S_IXGRP}[bit]
    else:
        mask = {"r": stat.S_IROTH, "x": stat.S_IXOTH}[bit]
    return bool(mode & mask)


def path_accessible(dir_meta, file_meta, target_mode, uid, groups):
    return all(identity_allows(s.st_mode,s.st_uid,s.st_gid,uid,groups,"x")
               for s in dir_meta) and identity_allows(
                   target_mode,file_meta.st_uid,file_meta.st_gid,uid,groups,"r")


def read_private_report(path):
    if not path.is_absolute() or path.name!="review.json" or \
       not path.parent.name.startswith(REPORT_DIR_PREFIX) or \
       path.parent.parent!=Path("/root"):
        raise Blocked("REPORT_PATH_NOT_APPROVED")
    parent=path.parent.lstat()
    if not stat.S_ISDIR(parent.st_mode) or parent.st_uid!=0 or \
       stat.S_IMODE(parent.st_mode)!=0o700:
        raise Blocked("REPORT_DIRECTORY_UNSAFE")
    info=path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or \
       stat.S_IMODE(info.st_mode)!=0o600 or info.st_size>MAX_REPORT:
        raise Blocked("REPORT_MODE_OR_SIZE_UNSAFE")
    fd=os.open(path,os.O_RDONLY | getattr(os,"O_NOFOLLOW",0))
    try:
        check=os.fstat(fd)
        if not stat.S_ISREG(check.st_mode) or check.st_ino!=info.st_ino or \
           check.st_dev!=info.st_dev or check.st_size>MAX_REPORT:
            raise Blocked("REPORT_CHANGED_DURING_READ")
        data=os.read(fd,MAX_REPORT+1)
    finally:
        os.close(fd)
    if len(data)>MAX_REPORT:
        raise Blocked("REPORT_TOO_LARGE")
    report=json.loads(data.decode("utf-8"))
    if not isinstance(report,dict) or report.get("schema_version")!=1 or \
       report.get("purpose")!="LOCAL_OPERATOR_REVIEW_ONLY_NOT_APPROVED_NOT_EXECUTABLE":
        raise Blocked("REPORT_SCHEMA_MISMATCH")
    return report


def review_metadata(report, root=PRIVATE_ROOT):
    if report.get("app_private_root")!=str(root):
        raise Blocked("PRIVATE_ROOT_MISMATCH")
    relative=report.get("affected_relative_path")
    if not isinstance(relative,str) or not relative or "\x00" in relative:
        raise Blocked("PRIVATE_RELATIVE_PATH_INVALID")
    p=PurePosixPath(relative)
    if p.is_absolute() or "." in p.parts or ".." in p.parts or \
       p.as_posix()!=relative:
        raise Blocked("PRIVATE_RELATIVE_PATH_UNSAFE")
    target=root.joinpath(*p.parts)
    chain=list(reversed((target.parent,*target.parent.parents)))
    dir_meta=[]
    for directory in chain:
        s=directory.lstat()
        if not stat.S_ISDIR(s.st_mode):
            raise Blocked("PARENT_NOT_DIRECTORY_OR_SYMLINK")
        dir_meta.append(s)
    s=target.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_nlink!=1:
        raise Blocked("TARGET_NOT_SINGLE_REGULAR_INODE")
    for key,observed in (
        ("device",s.st_dev),("inode",s.st_ino),("owner_uid",s.st_uid),
        ("group_gid",s.st_gid),("links",s.st_nlink),
        ("file_size_metadata",s.st_size),("modified_ns",s.st_mtime_ns)):
        if report.get(key)!=observed:
            raise Blocked("PRIVATE_INODE_METADATA_CHANGED")
    current=stat.S_IMODE(s.st_mode)
    try:
        original=int(report["original_mode_octal"],8)
        proposed=int(report["proposed_single_file_mode_octal"],8)
    except (TypeError,ValueError,KeyError):
        raise Blocked("MODE_REPORT_INVALID")
    if current!=original or proposed!=(original & ~stat.S_IROTH) or \
       not bool(current & stat.S_IROTH):
        raise Blocked("MODE_DRIFT_OR_PROPOSAL_INVALID")
    acl="ABSENT_IN_XATTR_LIST"
    for candidate in [*chain,target]:
        try:
            attributes=os.listxattr(candidate,follow_symlinks=False)
        except OSError:
            acl="NOT_VERIFIED"
            break
        if "system.posix_acl_access" in attributes:
            acl="PRESENT_NEEDS_REVIEW"
            break
    return s,dir_meta,current,proposed,acl


def account(name):
    try:
        p=pwd.getpwnam(name)
        if p.pw_uid==0:
            raise Blocked("ACCOUNT_UNEXPECTED_ROOT")
        groups=set(os.getgrouplist(name,p.pw_gid))
        return p.pw_uid,groups
    except KeyError:
        raise Blocked("SYSTEM_ACCOUNT_MISSING")


def verdict(www_after,nobody_after,acl):
    if acl!="ABSENT_IN_XATTR_LIST":
        return "BLOCKED_ACL_OR_XATTR_UNCERTAINTY"
    if not www_after:
        return "BLOCKED_WWW_WOULD_LOSE_READ"
    if nobody_after:
        return "BLOCKED_NOBODY_REMAINS_READABLE"
    return "CONDITIONALLY_VIABLE_REQUIRES_OWNER_APPROVAL"


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--inspect",action="store_true",required=True)
    parser.add_argument("--review-report",required=True)
    args=parser.parse_args()
    if os.geteuid()!=0:
        raise Blocked("ROOT_REQUIRED")
    r=read_private_report(Path(args.review_report))
    s,dirs,current,proposed,acl=review_metadata(r)
    www_uid,www_groups=account("www")
    nobody_uid,nobody_groups=account("nobody")
    # Predictions are mode-based, not actual PHP-FPM process credentials.
    before_www=path_accessible(dirs,s,current,www_uid,www_groups)
    after_www=path_accessible(dirs,s,proposed,www_uid,www_groups)
    before_nobody=path_accessible(dirs,s,current,nobody_uid,nobody_groups)
    after_nobody=path_accessible(dirs,s,proposed,nobody_uid,nobody_groups)
    print("MCP_PRIVATE_ACCESS_REVIEW_IDENTITY=PASS")
    print("MCP_PRIVATE_ACCESS_ACL="+acl)
    for key,val in (
        ("WWW_BEFORE",before_www),("WWW_AFTER_PROPOSAL",after_www),
        ("NOBODY_BEFORE",before_nobody),("NOBODY_AFTER_PROPOSAL",after_nobody)):
        print("MCP_PRIVATE_MODE_PREDICTED_"+key+"="+("READABLE" if val else "DENIED"))
    print("MCP_SINGLE_FILE_MODE_PROPOSAL="+verdict(after_www,after_nobody,acl))
    print("MCP_PRIVATE_FILE_CONTENT=NOT_OPENED")
    print("MCP_PHP_FPM_RUNTIME_IDENTITY=NOT_VERIFIED")
    print("MCP_APPARMOR_SELINUX_AND_HTTP_EXPOSURE=NOT_VERIFIED")
    print("MCP_PRIVATE_FILE_PERMISSIONS=UNCHANGED")
    print("MCP_PERMISSION_CHANGE=NOT_AUTHORIZED")
    print("MCP_PRODUCTION_RELEASE=BLOCKED")


if __name__=="__main__":
    try:
        main()
    except (Blocked,OSError,ValueError,UnicodeError,json.JSONDecodeError):
        print("MCP_PRIVATE_ACCESS_REVIEW=BLOCKED_CONTACT_OPERATOR")
        sys.exit(1)
