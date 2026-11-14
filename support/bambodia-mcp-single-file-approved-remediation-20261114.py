#!/usr/bin/env python3
"""Bambodia MCP — single approved file permission correction.

Changes exactly the other-read bit of one inode in private storage after
verifying an owner-only review report and the live app identity. Saves a
root-private, fsynced rollback journal BEFORE chmod and automatically reverts
the same inode if post-change www/nobody access checks fail.

No file content is read; no file names are printed; no recursive operations.
Requires explicit operator --apply-approved-single-file.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import stat
import sys

APP=Path("/www/wwwroot/bambodia-staging-qa")
PRIVATE_ROOT=APP/"storage/app/private"
REPORT=Path("/root/bambodia-mcp-private-review-cjk6rv6z/review.json")
VHOST=Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
VHOST_HASH="5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116"
COMPOSER_BLOB="457992551f4f234ad442d9db5ad73c8b54689e7b"
LOCK_HASH="ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
JOURNAL_NAME="approved-permission-rollback.json"
CONFIRM="APPLY-ONE-VERIFIED-FILE-OTHER-READ"


class Blocked(Exception):
    pass


def sha256_file(path):
    h=hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda:f.read(65536),b""):
            h.update(chunk)
    return h.hexdigest()


def git_blob(path):
    data=path.read_bytes()
    return hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest()


def site_identity():
    if APP.is_symlink() or not APP.is_dir() or \
       not (APP/"artisan").is_file() or not (APP/"storage/app/installed.lock").is_file():
        raise Blocked("LIVE_APP_IDENTITY_CHANGED")
    if VHOST.is_symlink() or sha256_file(VHOST)!=VHOST_HASH:
        raise Blocked("LIVE_VHOST_CHANGED")
    if git_blob(APP/"composer.json")!=COMPOSER_BLOB or \
       sha256_file(APP/"composer.lock")!=LOCK_HASH:
        raise Blocked("LIVE_DEPENDENCIES_CHANGED")
    www=pwd.getpwnam("www")
    socket=Path("/tmp/php-cgi-85.sock")
    sock=socket.lstat()
    if not stat.S_ISSOCK(sock.st_mode) or socket.is_symlink() or \
       sock.st_uid!=www.pw_uid or stat.S_IMODE(sock.st_mode)!=0o600:
        raise Blocked("PHP85_SOCKET_IDENTITY_CHANGED")


def owner_report():
    if REPORT.parent.parent!=Path("/root") or \
       not REPORT.parent.name.startswith("bambodia-mcp-private-review-"):
        raise Blocked("REPORT_DIRECTORY_NOT_APPROVED")
    directory=REPORT.parent.lstat()
    info=REPORT.lstat()
    if not stat.S_ISDIR(directory.st_mode) or directory.st_uid!=0 or \
       stat.S_IMODE(directory.st_mode)!=0o700 or \
       not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or \
       stat.S_IMODE(info.st_mode)!=0o600 or info.st_size>16384:
        raise Blocked("REPORT_PERMISSIONS_CHANGED")
    flags=os.O_RDONLY|os.O_NOFOLLOW|getattr(os,"O_CLOEXEC",0)
    fd=os.open(REPORT,flags)
    try:
        s=os.fstat(fd)
        if s.st_dev!=info.st_dev or s.st_ino!=info.st_ino or s.st_size>16384:
            raise Blocked("REPORT_IDENTITY_RACE")
        body=os.read(fd,16385)
    finally:
        os.close(fd)
    if len(body)>16384:
        raise Blocked("REPORT_TOO_LARGE")
    report=json.loads(body.decode("utf-8"))
    if not isinstance(report,dict) or report.get("schema_version")!=1 or \
       report.get("app_private_root")!=str(PRIVATE_ROOT) or \
       report.get("purpose")!="LOCAL_OPERATOR_REVIEW_ONLY_NOT_APPROVED_NOT_EXECUTABLE":
        raise Blocked("REPORT_CONTENT_NOT_EXPECTED")
    return report


def safe_path_and_fd(report):
    relative=report.get("affected_relative_path")
    if not isinstance(relative,str) or not relative or "\x00" in relative:
        raise Blocked("TARGET_RELATIVE_PATH_INVALID")
    p=PurePosixPath(relative)
    if p.is_absolute() or any(s in (".","..","") for s in relative.split("/")) or \
       p.as_posix()!=relative:
        raise Blocked("TARGET_RELATIVE_PATH_UNSAFE")
    target=PRIVATE_ROOT.joinpath(*p.parts)
    # Open every ancestor as a directory with NOFOLLOW; changing path entries
    # after this point cannot silently redirect fchmod to an arbitrary inode.
    parts=target.parts[1:]
    dir_flags=os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|getattr(os,"O_CLOEXEC",0)
    parent=os.open("/",dir_flags)
    try:
        for name in parts[:-1]:
            nextfd=os.open(name,dir_flags,dir_fd=parent)
            os.close(parent)
            parent=nextfd
        fd=os.open(parts[-1],os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|
                   getattr(os,"O_CLOEXEC",0),dir_fd=parent)
    finally:
        os.close(parent)
    meta=os.fstat(fd)
    if not stat.S_ISREG(meta.st_mode) or meta.st_nlink!=1:
        os.close(fd)
        raise Blocked("TARGET_UNSAFE_INODE")
    fields={
        "device":meta.st_dev,"inode":meta.st_ino,"owner_uid":meta.st_uid,
        "group_gid":meta.st_gid,"links":meta.st_nlink,
        "file_size_metadata":meta.st_size,"modified_ns":meta.st_mtime_ns,
    }
    if any(report.get(k)!=v for k,v in fields.items()):
        os.close(fd)
        raise Blocked("TARGET_INODE_DRIFT")
    actual=stat.S_IMODE(meta.st_mode)
    try:
        original=int(report["original_mode_octal"],8)
        proposed=int(report["proposed_single_file_mode_octal"],8)
    except (KeyError,ValueError,TypeError):
        os.close(fd)
        raise Blocked("REPORT_MODE_INVALID")
    if actual!=original or proposed!=(original & ~stat.S_IROTH) or \
       not (original & stat.S_IROTH):
        os.close(fd)
        raise Blocked("UNEXPECTED_MODE_OR_PROPOSAL")
    return fd,target,meta,original,proposed


def check_acl_chain(path):
    chain=list(reversed((path,*path.parents)))
    for p in chain:
        s=p.lstat()
        if p!=path and not stat.S_ISDIR(s.st_mode):
            raise Blocked("PATH_ANCESTOR_CHANGED")
        if p==path and not stat.S_ISREG(s.st_mode):
            raise Blocked("PATH_TARGET_CHANGED")
        try:
            attrs=os.listxattr(p,follow_symlinks=False)
        except OSError:
            raise Blocked("ACL_INSPECTION_UNAVAILABLE")
        if "system.posix_acl_access" in attrs:
            raise Blocked("ACL_REQUIRES_REVIEW")


def account_access(name,target):
    try:
        p=pwd.getpwnam(name)
    except KeyError:
        raise Blocked("REQUIRED_ACCOUNT_MISSING")
    if p.pw_uid==0:
        raise Blocked("ROOT_ACCOUNT_UNEXPECTED")
    r,w=os.pipe()
    pid=os.fork()
    if pid==0:
        os.close(r)
        try:
            os.initgroups(p.pw_name,p.pw_gid)
            os.setgid(p.pw_gid)
            os.setuid(p.pw_uid)
            result=b"YES" if os.access(target,os.R_OK) else b"NO"
        except BaseException:
            result=b"ERR"
        try:
            os.write(w,result)
        finally:
            os.close(w)
        os._exit(0)
    os.close(w)
    result=os.read(r,16)
    os.close(r)
    _,status=os.waitpid(pid,0)
    if status!=0 or result not in (b"YES",b"NO"):
        raise Blocked("ACCOUNT_ACCESS_NOT_VERIFIABLE")
    return result==b"YES"


def matches_path_inode(target,original):
    s=target.lstat()
    return (stat.S_ISREG(s.st_mode) and s.st_dev==original.st_dev and
            s.st_ino==original.st_ino and s.st_nlink==1 and
            s.st_uid==original.st_uid and s.st_gid==original.st_gid)


def candidate_mode(original):
    return original & ~stat.S_IROTH


def acceptable_post_access(www_reads,nobody_reads):
    return www_reads and not nobody_reads


def write_journal(report,meta,old,new):
    # Root-only rollback evidence is fsynced before any mode modification.
    p=REPORT.parent/JOURNAL_NAME
    record={
        "schema_version":1,
        "purpose":"APPROVED_ONE_FILE_MODE_CHANGE_ROLLBACK_EVIDENCE",
        "relative_file_path":report["affected_relative_path"],
        "device":meta.st_dev,"inode":meta.st_ino,"owner_uid":meta.st_uid,
        "group_gid":meta.st_gid,"links":meta.st_nlink,
        "original_mode_octal":format(old,"04o"),
        "proposed_mode_octal":format(new,"04o"),
        "recovery_instruction":"Verify exact device and inode before restoring original mode. No recursive changes.",
    }
    flags=os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|getattr(os,"O_CLOEXEC",0)
    fd=os.open(p,flags,0o600)
    try:
        with os.fdopen(fd,"w",encoding="utf-8") as f:
            json.dump(record,f,indent=2)
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        parentfd=os.open(REPORT.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:
            os.fsync(parentfd)
        finally:
            os.close(parentfd)
    except BaseException:
        # Do not overwrite or delete possible rollback evidence.
        raise
    print("MCP_ONE_FILE_ROLLBACK_JOURNAL=ROOT_ONLY_FSYNCED")


def apply(report,fd,target,meta,old,new):
    if not matches_path_inode(target,meta):
        raise Blocked("TARGET_PATH_CHANGED")
    if not account_access("www",target) or not account_access("nobody",target):
        raise Blocked("LIVE_ACCESS_NO_LONGER_MATCHES_REVIEW")
    check_acl_chain(target)
    # Recheck the inode and existing mode immediately before any write.
    if not matches_path_inode(target,meta) or \
       stat.S_IMODE(os.fstat(fd).st_mode)!=old:
        raise Blocked("PRECHANGE_TARGET_DRIFT")
    write_journal(report,meta,old,new)
    changed=False
    try:
        os.fchmod(fd,new)
        changed=True
        if not matches_path_inode(target,meta) or \
           stat.S_IMODE(os.fstat(fd).st_mode)!=new:
            raise Blocked("POSTCHANGE_TARGET_CHANGED")
        check_acl_chain(target)
        www=account_access("www",target)
        nobody=account_access("nobody",target)
        if not acceptable_post_access(www,nobody):
            raise Blocked("POSTCHANGE_ACCOUNT_ACCESS_UNSAFE")
        print("MCP_APPROVED_SINGLE_FILE_CHANGE=APPLIED_VERIFIED")
        print("MCP_POSTCHANGE_WWW_READ=PASS")
        print("MCP_POSTCHANGE_NOBODY_DENIED=PASS")
        print("MCP_OTHER_PRIVATE_FILES=NOT_MODIFIED_BY_SCRIPT")
        return
    except BaseException:
        if changed:
            try:
                if not matches_path_inode(target,meta) or \
                   stat.S_IMODE(os.fstat(fd).st_mode)!=new:
                    print("MCP_AUTO_ROLLBACK=BLOCKED_INODE_OR_MODE_DRIFT")
                else:
                    os.fchmod(fd,old)
                    if stat.S_IMODE(os.fstat(fd).st_mode)==old:
                        print("MCP_AUTO_ROLLBACK=ORIGINAL_MODE_RESTORED")
                    else:
                        print("MCP_AUTO_ROLLBACK=NOT_VERIFIED")
            except BaseException:
                print("MCP_AUTO_ROLLBACK=FAILED_REQUIRES_OPERATOR")
        raise


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--apply-approved-single-file",action="store_true",required=True)
    parser.add_argument("--confirmation",required=True)
    args=parser.parse_args()
    if args.confirmation!=CONFIRM or os.geteuid()!=0:
        raise Blocked("APPROVAL_CONFIRMATION_OR_ROOT_MISSING")
    site_identity()
    report=owner_report()
    fd,target,meta,old,new=safe_path_and_fd(report)
    try:
        if new!=candidate_mode(old):
            raise Blocked("NONMINIMAL_PERMISSION_CHANGE")
        print("MCP_APPROVED_SINGLE_FILE_IDENTITY=PASS")
        apply(report,fd,target,meta,old,new)
    finally:
        os.close(fd)
    print("MCP_PRIVATE_FILE_CONTENT=NOT_READ")
    print("MCP_BACKUP_RESTORE=NOT_PERFORMED")
    print("MCP_PRODUCTION_MCP=UNCHANGED")
    print("MCP_RELEASE_GATE=BLOCKED")


if __name__=="__main__":
    try:
        main()
    except BaseException:
        # Filenames and private report details never enter stdout/stderr.
        print("MCP_ONE_FILE_REMEDIATION=BLOCKED_REVIEW_ROOT_PRIVATE_JOURNAL")
        print("MCP_RELEASE_GATE=BLOCKED")
        sys.exit(1)
