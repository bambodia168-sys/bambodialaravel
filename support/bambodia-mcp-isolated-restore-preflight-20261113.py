#!/usr/bin/env python3
"""Bambodia MCP: isolated-restore environment preflight, metadata only.

NEVER imports data, runs artisan, connects to MySQL, or alters the restore target.
A pass on this script does NOT prove network isolation, backups, or restore success.
"""
import argparse
import os
from pathlib import Path
import re
import stat
import sys


class Blocked(Exception):
    pass


def parse_known_env(path):
    info=path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_size>131072 or \
       info.st_mode & (stat.S_IROTH|stat.S_IWOTH):
        raise Blocked("ENV_UNSAFE")
    flags=os.O_RDONLY | getattr(os,"O_NOFOLLOW",0) | getattr(os,"O_CLOEXEC",0)
    fd=os.open(path,flags)
    try:
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_size>131072:
            raise Blocked("ENV_CHANGED")
        data=os.read(fd,131073)
        if len(data)>131072:
            raise Blocked("ENV_OVERSIZED")
    finally:
        os.close(fd)
    allowed={
        "APP_ENV", "APP_DEBUG", "APP_URL", "MAIL_MAILER",
        "QUEUE_CONNECTION", "BROADCAST_CONNECTION", "CACHE_STORE",
        "SESSION_DRIVER", "BAMBODIA_MCP_ENABLED",
        "BAMBODIA_MCP_WRITES_ENABLED", "DB_CONNECTION"
    }
    result={}
    for line in data.decode("utf-8","replace").splitlines():
        m=re.match(r"^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$",line)
        if m and m.group(1) in allowed:
            value=m.group(2).strip().strip('"').strip("'")
            result[m.group(1)]=value
    del data
    return result


def check_offline_flags(env):
    required={
        "APP_ENV": {"testing"},
        "APP_DEBUG": {"false","0"},
        "MAIL_MAILER": {"array"},
        "QUEUE_CONNECTION": {"null"},
        "BROADCAST_CONNECTION": {"log","null"},
        "CACHE_STORE": {"array"},
        "SESSION_DRIVER": {"array"},
        "BAMBODIA_MCP_ENABLED": {"false","0"},
        "BAMBODIA_MCP_WRITES_ENABLED": {"false","0"}
    }
    flags={key: (env.get(key,"").lower() in allowed) for key,allowed in required.items()}
    url=env.get("APP_URL","")
    flags["APP_URL_LOOPBACK_ONLY"]=bool(re.fullmatch(r"http://(127\.0\.0\.1|localhost)(:\d+)?/?",url,re.I))
    flags["DB_DRIVER_RECOGNIZED"]=env.get("DB_CONNECTION","").lower() in {"mysql","mariadb","sqlite"}
    return flags


def isolated_root(path):
    if not path.is_absolute():
        raise Blocked("TARGET_NOT_ABSOLUTE")
    # Restrict to an operator-selected private restore workspace convention.
    allowed=Path("/var/lib/bambodia-mcp-restore-qa")
    if path==allowed or not path.is_relative_to(allowed):
        raise Blocked("TARGET_OUTSIDE_PRIVATE_RESTORE_AREA")
    current=Path("/")
    for part in path.parts[1:]:
        current=current / part
        if current.is_symlink():
            raise Blocked("TARGET_SYMLINK")
    if not path.is_dir() or not (path/"artisan").is_file():
        raise Blocked("RESTORE_TARGET_NOT_PREPARED")
    if (path/"bootstrap/cache/config.php").exists():
        raise Blocked("CACHED_FLAGS_UNREVIEWED")
    return path


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--inspect",action="store_true",required=True)
    parser.add_argument("--restore-root",required=True)
    a=parser.parse_args()
    if os.geteuid()!=0:
        raise Blocked("ROOT_REQUIRED")
    root=isolated_root(Path(a.restore_root))
    flags=check_offline_flags(parse_known_env(root/".env"))
    for name,value in flags.items():
        print("MCP_RESTORE_CONFIG_"+name+"="+("PASS" if value else "NOT_PROVEN"))
    print("MCP_RESTORE_CONFIG_FLAGS="+("ALL_DECLARED_SAFE" if all(flags.values()) else "BLOCKED"))
    print("MCP_RESTORE_ACTUAL_DATABASE_IDENTITY=NOT_VERIFIED")
    print("MCP_RESTORE_HOST_NETWORK_ISOLATION=NOT_VERIFIED")
    print("MCP_RESTORE_EMAIL_QUEUE_WEBHOOK_EXECUTION=NOT_TESTED")
    print("MCP_RESTORE_MEDIA_CHECKSUM_RECONCILIATION=NOT_PERFORMED")
    print("MCP_RESTORE_OAUTH_SIGNING_KEYS=NOT_INSPECTED")
    print("MCP_RESTORE_DATA_IMPORT=NOT_PERFORMED")
    print("MCP_RESTORE_ACCEPTANCE=BLOCKED_PENDING_INDEPENDENT_EVIDENCE")
    print("MCP_PRODUCTION_RELEASE=BLOCKED")


if __name__=="__main__":
    try:
        main()
    except (Blocked,OSError,ValueError):
        print("MCP_RESTORE_PREFLIGHT=BLOCKED_PRIVATE_OPERATOR_REVIEW")
        sys.exit(1)
