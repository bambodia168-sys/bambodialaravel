#!/usr/bin/env python3
"""Bambodia MCP Cloudflare R2 Restic approval inspection — offline, read-only.

Never creates buckets, tokens, keys, repositories, snapshots, backups, or restores.
Reads *metadata* of root-private credential files, never their contents.
Repository/account/bucket names are never printed.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
from urllib.parse import urlsplit

APPROVAL=Path("/root/bambodia-mcp-recovery/approval.json")
MAX_APPROVAL_BYTES=16384
HOST_RE=re.compile(r"^([0-9a-f]{32})(?:\.(eu|us|fedramp))?\.r2\.cloudflarestorage\.com$")
BUCKET_RE=re.compile(r"^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$")
GENERIC_APPROVALS=(
    "owner_approved",
    "security_reviewer_approved",
    "off_server_destination_independently_verified",
    "separate_encryption_key_custody_approved",
    "db_scope_and_privileges_privately_verified",
    "media_disks_and_content_consistency_reviewed",
    "approved_change_window_and_write_quiescence",
    "isolated_restore_target_confirmed",
    "rollback_artifact_and_plan_reviewed",
)
R2_APPROVALS=(
    "r2_private_bucket_verified",
    "r2_scoped_s3_credential_reviewed",
    "r2_endpoint_independently_verified",
    "r2_repo_prefix_no_expiry_verified",
    "r2_restic_retention_maintenance_approved",
    "r2_independent_offhost_secret_escrow_verified",
)
OBJECTIVES=("rpo_minutes","rto_minutes","retention_days")


class Blocked(Exception):
    pass


def private_file_metadata(path,limit):
    p=Path(path)
    if not p.is_absolute() or not p.is_relative_to("/root") or p==Path("/root") or \
       any(x in (".","..") for x in p.parts) or not p.exists() or p.is_symlink():
        raise Blocked("PRIVATE_FILE_UNSAFE")
    # Check every intermediate ancestor from /root, never follow a symlink.
    for ancestor in (Path("/root"),*tuple(reversed(tuple(p.parents)))[:-1]):
        if ancestor==Path("/") or not ancestor.is_relative_to("/root"):
            continue
        s=ancestor.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or (stat.S_IMODE(s.st_mode)&0o077):
            raise Blocked("PRIVATE_DIRECTORY_UNSAFE")
    info=p.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or \
       stat.S_IMODE(info.st_mode)!=0o600 or info.st_size<1 or info.st_size>limit:
        raise Blocked("PRIVATE_FILE_MODE_OR_SIZE_UNSAFE")
    return p


def parse_r2_repository(raw):
    if not isinstance(raw,str) or not raw.startswith("s3:https://") or \
       len(raw)>1024 or any(x in raw for x in ("\r","\n","\t","\x00")):
        raise Blocked("R2_REPOSITORY_URI_UNSAFE")
    u=urlsplit(raw[3:])
    if u.scheme!="https" or u.username is not None or u.password is not None or \
       u.port is not None or u.query or u.fragment:
        raise Blocked("R2_REPOSITORY_URI_UNSAFE")
    m=HOST_RE.fullmatch(u.hostname or "")
    if not m or u.netloc!=u.hostname:
        raise Blocked("R2_ENDPOINT_NOT_CLOUDFLARE")
    # A dedicated bucket and nonempty repository prefix are required to
    # prevent collisions with other workloads.
    segments=u.path.split("/")
    if len(segments)<3 or segments[0]!="" or not BUCKET_RE.fullmatch(segments[1]):
        raise Blocked("R2_BUCKET_OR_PREFIX_INVALID")
    prefix=segments[2:]
    if not prefix or any(not x or x in (".","..") or
                         not re.fullmatch(r"[A-Za-z0-9_-]+",x)
                         for x in prefix):
        raise Blocked("R2_PREFIX_UNSAFE")
    return {"account":m.group(1),"jurisdiction":m.group(2) or "global",
            "bucket":segments[1],"prefix_segments":len(prefix)}


def evaluate(a):
    if not isinstance(a,dict):
        raise Blocked("APPROVAL_NOT_OBJECT")
    statuses={}
    for key in (*GENERIC_APPROVALS,*R2_APPROVALS):
        statuses[key]=a.get(key) is True
    for key in OBJECTIVES:
        v=a.get(key)
        statuses[key]=type(v) is int and 0<v<525600
    statuses["restic_engine"]=a.get("encryption_engine")=="restic"
    try:
        result=parse_r2_repository(a.get("repository"))
        statuses["r2_endpoint"]=(
            a.get("r2_account_id")==result["account"]
            and a.get("r2_bucket_name")==result["bucket"]
            and a.get("r2_jurisdiction")==result["jurisdiction"]
        )
    except (Blocked,ValueError):
        result=None
        statuses["r2_endpoint"]=False
    for label,key in (("restic_key_file","restic_password_file"),
                      ("r2_s3_credential_file","r2_credentials_file")):
        try:
            v=a.get(key)
            statuses[label]=isinstance(v,str) and v!=str(APPROVAL) and \
                            bool(private_file_metadata(v,16384))
        except (OSError,Blocked,ValueError,TypeError):
            statuses[label]=False
    return statuses,result


def read_manifest():
    # No automatic approval file creation; missing is normal.
    if not APPROVAL.exists():
        return None
    if APPROVAL.is_symlink():
        raise Blocked("APPROVAL_SYMLINK_BLOCKED")
    private_file_metadata(APPROVAL,MAX_APPROVAL_BYTES)
    fd=os.open(APPROVAL,os.O_RDONLY|os.O_NOFOLLOW|getattr(os,"O_CLOEXEC",0))
    try:
        s=os.fstat(fd)
        if not stat.S_ISREG(s.st_mode) or s.st_size>MAX_APPROVAL_BYTES or \
           s.st_uid!=0 or stat.S_IMODE(s.st_mode)!=0o600:
            raise Blocked("APPROVAL_CHANGED")
        raw=os.read(fd,MAX_APPROVAL_BYTES+1)
    finally:
        os.close(fd)
    if len(raw)>MAX_APPROVAL_BYTES:
        raise Blocked("APPROVAL_TOO_LARGE")
    return json.loads(raw.decode("utf-8"))


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--inspect",action="store_true",required=True)
    parser.parse_args()
    if os.geteuid()!=0:
        raise Blocked("ROOT_OPERATOR_REQUIRED")
    print("MCP_R2_PROVIDER=CLOUDFLARE_R2_PLANNED")
    print("MCP_R2_RESTIC_TOOL="+("INSTALLED" if shutil.which("restic") else "NOT_FOUND"))
    a=read_manifest()
    if a is None:
        print("MCP_R2_PRIVATE_APPROVAL_MANIFEST=NOT_PRESENT")
        print("MCP_R2_REQUIRED_DECLARATIONS=NOT_PRESENT")
    else:
        print("MCP_R2_PRIVATE_APPROVAL_MANIFEST=ROOT_ONLY_PRESENT")
        checks,result=evaluate(a)
        for name,approved in checks.items():
            print("MCP_R2_CHECK_"+name.upper()+"="+("DECLARED" if approved else "MISSING_OR_UNVERIFIED"))
        print("MCP_R2_ENDPOINT_FORMAT="+("VALID" if result else "INVALID_OR_MISSING"))
        print("MCP_R2_DECLARATIONS="+(
            "COMPLETE_NOT_INDEPENDENTLY_PROVEN" if all(checks.values())
            else "BLOCKED_INCOMPLETE"))
    print("MCP_R2_REMOTE_ACCESS=NOT_TESTED")
    print("MCP_R2_BUCKET_OR_KEYS_CREATED=NO")
    print("MCP_R2_RESTIC_REPOSITORY_INITIALIZED=NO")
    print("MCP_R2_BACKUP_CREATED=NO")
    print("MCP_R2_RESTORE_PERFORMED=NO")
    print("MCP_R2_WRITE_OPERATIONS=NONE")
    print("MCP_PRODUCTION_RELEASE=BLOCKED")


if __name__=="__main__":
    try:
        main()
    except (Blocked,OSError,ValueError,TypeError,UnicodeError,json.JSONDecodeError):
        print("MCP_R2_PREFLIGHT=BLOCKED_PRIVATE_OPERATOR_REVIEW")
        print("MCP_PRODUCTION_RELEASE=BLOCKED")
        sys.exit(1)
