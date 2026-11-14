#!/usr/bin/env python3
"""Bambodia MCP — offline/no-secret-output R2 backup/restore execution readiness.

This is approval and source-scope METADATA validation, not backup execution.
It never opens media files, reads DB or credential contents, contacts Cloudflare,
executes programs, creates secrets, modifies modes, initializes restic or restores.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys

MANIFEST=Path("/root/bambodia-mcp-recovery/approval.json")
LIVE_APP=Path("/www/wwwroot/bambodia-staging-qa")
EXPECTED_MEDIA=(
    str(LIVE_APP/"storage/app/private"),
    str(LIVE_APP/"storage/app/public"),
)
EXPECTED_BUCKET="bambodia-backup"
EXPECTED_JURISDICTION="global"
MAX_MANIFEST_BYTES=16384
MAX_SECRET_BYTES=8192
BOOL_FIELDS=(
    "owner_approved", "security_reviewer_approved",
    "off_server_destination_independently_verified",
    "separate_encryption_key_custody_approved",
    "db_scope_and_privileges_privately_verified",
    "media_disks_and_content_consistency_reviewed",
    "approved_change_window_and_write_quiescence",
    "isolated_restore_target_confirmed",
    "rollback_artifact_and_plan_reviewed",
    "r2_private_bucket_verified",
    "r2_scoped_s3_credential_reviewed",
    "r2_endpoint_independently_verified",
    "r2_repo_prefix_no_expiry_verified",
    "r2_restic_retention_maintenance_approved",
    "r2_independent_offhost_secret_escrow_verified",
)
SPECIFIC_FIELDS=(
    "mysql_export_consistency_reviewed",
    "nontransactional_tables_excluded_or_locked",
    "schema_change_and_ddl_quiescence_reviewed",
    "queue_scheduler_uploads_quiesced_plan_reviewed",
    "remote_media_sources_accounted_for",
    "encrypted_sql_streaming_reviewed",
    "root_private_mysql_client_custody_reviewed",
    "isolated_restore_network_and_database_independently_verified",
    "application_decryption_secrets_escrow_independently_verified",
    "restoration_acceptance_criteria_approved",
)
OBJECTIVES=("rpo_minutes","rto_minutes","retention_days")

class Blocked(Exception):
    pass


def root_private_metadata(path,limit):
    """No credentials opened; reject symlinks and unsafe root ancestry."""
    if not isinstance(path,str) or not path.startswith("/root/") or "\x00" in path:
        raise Blocked()
    p=Path(path)
    if any(x==".." for x in p.parts):
        raise Blocked()
    for ancestor in reversed(p.parents):
        if ancestor==Path("/"):
            continue
        if not ancestor.is_relative_to("/root"):
            raise Blocked()
        s=ancestor.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or \
            stat.S_IMODE(s.st_mode)&0o077:
            raise Blocked()
    v=p.lstat()
    if not stat.S_ISREG(v.st_mode) or v.st_uid!=0 or \
       stat.S_IMODE(v.st_mode)!=0o600 or not 0<v.st_size<=limit or v.st_nlink!=1:
        raise Blocked()
    return p


def check_media_scope(scope):
    # Only these two local roots are permissible without further review;
    # remote disks and encryption keys remain separately approved inventory.
    if type(scope) is not list or len(scope)!=2 or set(scope)!=set(EXPECTED_MEDIA):
        return False
    try:
        for path in scope:
            p=Path(path)
            if p.is_symlink() or not p.is_dir():
                return False
            if not p.resolve(strict=True).is_relative_to(LIVE_APP.resolve(strict=True)):
                return False
    except (OSError,RuntimeError):
        return False
    return True


def check_objectives(m):
    return {k:type(m.get(k)) is int and 0<m[k]<525600 for k in OBJECTIVES}


def evaluate(m,check_files=True):
    if not isinstance(m,dict):
        raise Blocked()
    checks={k:(m.get(k) is True) for k in (*BOOL_FIELDS,*SPECIFIC_FIELDS)}
    checks.update(check_objectives(m))
    checks["bucket_name"]=m.get("r2_bucket_name")==EXPECTED_BUCKET
    checks["jurisdiction"]=m.get("r2_jurisdiction")==EXPECTED_JURISDICTION
    checks["encryption_engine"]=m.get("encryption_engine")=="restic"
    checks["mysql_strategy"]=m.get("mysql_backup_strategy")=="single_transaction"
    checks["media_scope"]=check_media_scope(m.get("local_media_roots"))
    checks["nonsecret_reviewed"]=m.get("recovery_runbook_version")==1
    # Manifest has no credentials or secret key values, only root private paths.
    for output_key,manifest_key in (
        ("restic_password_file","restic_password_file"),
        ("r2_credentials_file","r2_credentials_file"),
        ("mysql_client_file","mysql_defaults_file")
    ):
        path=m.get(manifest_key)
        try:
            checks[output_key]=(
                check_files and path!=str(MANIFEST) and
                bool(root_private_metadata(path,MAX_SECRET_BYTES))
            ) if check_files else isinstance(path,str) and path.startswith("/root/")
        except (Blocked,OSError,ValueError):
            checks[output_key]=False
    return checks


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--inspect",required=True,action="store_true")
    options=parser.parse_args()
    if not options.inspect or os.geteuid()!=0:
        raise Blocked()
    print("MCP_RECOVERY_BUCKET=bambodia-backup")
    print("MCP_RECOVERY_BUCKET_ACCESS=NOT_TESTED_IN_THIS_OFFLINE_CHECK")
    print("MCP_RECOVERY_RESTIC_TOOL="+("FOUND" if shutil.which("restic") else "NOT_FOUND"))
    print("MCP_RECOVERY_MYSQLDUMP_TOOL="+(
        "FOUND" if shutil.which("mysqldump") or Path("/www/server/mysql/bin/mysqldump").is_file()
        else "NOT_FOUND"))
    try:
        p=root_private_metadata(str(MANIFEST),MAX_MANIFEST_BYTES)
        fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|getattr(os,"O_CLOEXEC",0))
        try:
            before=os.fstat(fd)
            raw=os.read(fd,MAX_MANIFEST_BYTES+1)
            after=os.fstat(fd)
            if before.st_ino!=after.st_ino or before.st_dev!=after.st_dev or \
               len(raw)>MAX_MANIFEST_BYTES:
                raise Blocked()
        finally:
            os.close(fd)
        m=json.loads(raw.decode("utf-8"))
    except FileNotFoundError:
        print("MCP_RECOVERY_APPROVAL_MANIFEST=NOT_PRESENT")
        print("MCP_RECOVERY_EXECUTION_GATE=BLOCKED_NO_MANIFEST")
    else:
        print("MCP_RECOVERY_APPROVAL_MANIFEST=ROOT_PRIVATE_PRESENT")
        checks=evaluate(m,True)
        for key,okay in checks.items():
            print("MCP_RECOVERY_"+key.upper()+"="+("PRESENT_DECLARED" if okay else "MISSING_OR_UNVERIFIED"))
        print("MCP_RECOVERY_EXECUTION_GATE="+(
            "ALL_DECLARATIONS_PRESENT_NOT_INDEPENDENTLY_PROVEN" if all(checks.values())
            else "BLOCKED_INCOMPLETE"))
    print("MCP_RECOVERY_NO_DATABASE_ACCESS=YES")
    print("MCP_RECOVERY_NO_MEDIA_CONTENT_READ=YES")
    print("MCP_RECOVERY_NO_SECRET_READ=YES")
    print("MCP_RECOVERY_R2_WRITES=NONE")
    print("MCP_RECOVERY_RESTIC_INIT=NOT_PERFORMED")
    print("MCP_RECOVERY_BACKUP=NOT_PERFORMED")
    print("MCP_RECOVERY_RESTORE=NOT_PERFORMED")
    print("MCP_PRODUCTION_RELEASE=BLOCKED")


if __name__=="__main__":
    try:
        main()
    except (Blocked,OSError,ValueError,TypeError,UnicodeError,json.JSONDecodeError):
        print("MCP_RECOVERY_EXECUTION_GATE=BLOCKED_PRIVATE_REVIEW")
        print("MCP_PRODUCTION_RELEASE=BLOCKED")
        sys.exit(1)
