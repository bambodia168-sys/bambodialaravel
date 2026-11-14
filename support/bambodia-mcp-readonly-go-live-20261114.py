#!/usr/bin/env python3
"""Bambodia MCP: guarded live READ-ONLY switch, not application deployment.

No Composer install, database migrations, Passport keys, permission changes,
write-tool enablement, or backup operations. Fail closed if runtime incomplete.
"""
import argparse
import json
import os
import re
import stat
import subprocess
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, build_opener, HTTPRedirectHandler

APP=Path("/www/wwwroot/bambodia-staging-qa")
VHOST=Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
PHP=Path("/www/server/php/85/bin/php")
ENV=APP/".env"
BACKUP_DIR=Path("/root/Bambodia-MCP-Readonly-Activation")
RESOURCE="https://bambodia.com/mcp"
AS="https://bambodia.com"
CALLBACK=re.compile(r"^https://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]{6,128}$")


class Blocked(Exception):
    pass


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,request,fp,code,msg,headers,newurl):
        return None


def safe_dir(path):
    s=path.lstat()
    return stat.S_ISDIR(s.st_mode) and not (stat.S_IMODE(s.st_mode)&0o022)


def env_read():
    s=ENV.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_nlink!=1 or not 0<s.st_size<=131072 or (stat.S_IMODE(s.st_mode)&0o027):
        raise Blocked("ENV_METADATA_UNSAFE")
    f=os.open(ENV,os.O_RDONLY|os.O_NOFOLLOW|getattr(os,"O_CLOEXEC",0))
    try:
        data=os.read(f,131073)
        if len(data)>131072 or os.fstat(f).st_ino!=s.st_ino:
            raise Blocked("ENV_CHANGED")
    finally: os.close(f)
    raw=data.decode("utf-8")
    values={}
    for line in raw.splitlines():
        m=re.match(r"^\s*(BAMBODIA_MCP_ENABLED|BAMBODIA_MCP_WRITES_ENABLED|BAMBODIA_MCP_ALLOWED_CALLBACK_URIS)\s*=\s*(.*?)\s*$",line)
        if m:
            if m.group(1) in values: raise Blocked("DUPLICATE_SWITCH")
            values[m.group(1)]=m.group(2).strip("\"'")
    redirects=values.get("BAMBODIA_MCP_ALLOWED_CALLBACK_URIS","").split(",")
    if not redirects or not all(CALLBACK.fullmatch(x.strip()) for x in redirects):
        raise Blocked("EXACT_CHATGPT_CALLBACK_MISSING")
    if values.get("BAMBODIA_MCP_WRITES_ENABLED","false").lower() not in ("false","0",""):
        raise Blocked("WRITES_MUST_BE_OFF")
    if values.get("BAMBODIA_MCP_ENABLED","false").lower() not in ("false","0",""):
        raise Blocked("ALREADY_ENABLED_OR_INVALID_FLAG")
    return data,s


def discovery_ok(url,key,expected):
    try:
        with build_opener(NoRedirect).open(Request(url,headers={"Accept":"application/json"}),timeout=10) as r:
            if r.status!=200 or "json" not in (r.headers.get("Content-Type") or "").lower():
                return False
            data=r.read(16385)
        if len(data)>16384: return False
        payload=json.loads(data)
        return isinstance(payload,dict) and payload.get(key)==expected
    except (HTTPError,URLError,TimeoutError,OSError,ValueError):
        return False


def migrations_ok():
    command=["runuser","-u","www","--",str(PHP),"artisan","migrate:status","--no-ansi","--no-interaction"]
    try:
        p=subprocess.run(command,cwd=str(APP),capture_output=True,text=True,timeout=25,check=False)
    except (OSError,subprocess.TimeoutExpired):
        return False
    if p.returncode!=0: return False
    return all(any(name in line and "Ran" in line for line in p.stdout.splitlines()) for name in (
        "create_oauth_auth_codes_table",
        "create_oauth_access_tokens_table",
        "create_oauth_refresh_tokens_table",
        "create_oauth_clients_table",
    ))


def key_private(path):
    try:
        s=path.lstat()
        return stat.S_ISREG(s.st_mode) and s.st_nlink==1 and not(stat.S_IMODE(s.st_mode)&0o007)
    except OSError:
        return False


def inspect():
    checks={}
    def check(key,value):
        checks[key]=bool(value)
        print("MCP_ONLY_"+key+"="+("PASS" if value else "BLOCKED"))
    check("ROOT_OPERATOR",os.geteuid()==0)
    check("LIVE_APP",APP.is_dir() and safe_dir(APP) and (APP/"artisan").is_file())
    check("NGINX_ROOT",VHOST.is_file() and not VHOST.is_symlink() and
          bool(re.search(r"\broot\s+/www/wwwroot/bambodia-staging-qa/public\s*;",VHOST.read_text(errors="replace"))))
    check("PHP85",PHP.is_file() and os.access(PHP,os.X_OK))
    check("MCP_PACKAGE",(APP/"vendor/laravel/mcp").is_dir())
    check("PASSPORT_PACKAGE",(APP/"vendor/laravel/passport").is_dir())
    check("MCP_CONFIG",(APP/"config/mcp.php").is_file() and all(k in (APP/"config/mcp.php").read_text(errors="replace") for k in ("BAMBODIA_MCP_ENABLED","BAMBODIA_MCP_WRITES_ENABLED")))
    check("PASSPORT_PRIVATE_KEY",key_private(APP/"storage/oauth-private.key"))
    check("PASSPORT_PUBLIC_KEY",(APP/"storage/oauth-public.key").is_file())
    check("CONFIG_NOT_CACHED",not (APP/"bootstrap/cache/config.php").exists())
    check("MCP_CONTROLLER",(APP/"app/Http/Controllers/Api/BambodiaMcpController.php").is_file() and "production_writes_enabled" in (APP/"app/Http/Controllers/Api/BambodiaMcpController.php").read_text(errors="replace"))
    try:
        env_read()
        env_ok=True
    except (Blocked,OSError,UnicodeError):
        env_ok=False
    check("FLAGS_AND_EXACT_CALLBACK",env_ok)
    check("PROTECTED_RESOURCE_ROOT",discovery_ok(AS+"/.well-known/oauth-protected-resource","resource",RESOURCE))
    check("PROTECTED_RESOURCE_MCP",discovery_ok(AS+"/.well-known/oauth-protected-resource/mcp","resource",RESOURCE))
    check("OAUTH_ISSUER",discovery_ok(AS+"/.well-known/oauth-authorization-server","issuer",AS))
    check("OAUTH_SCHEMA",migrations_ok() if all(checks.get(x) for x in ("LIVE_APP","PHP85","MCP_PACKAGE","PASSPORT_PACKAGE")) else False)
    return all(checks.values())


def render_env(data):
    lines=data.decode("utf-8").splitlines(keepends=True)
    seen=set()
    for i,line in enumerate(lines):
        m=re.match(r"^\s*(BAMBODIA_MCP_ENABLED|BAMBODIA_MCP_WRITES_ENABLED)\s*=",line)
        if not m: continue
        name=m.group(1)
        if name in seen: raise Blocked("DUPLICATE_SWITCH")
        seen.add(name)
        lines[i]=name+("=true" if name=="BAMBODIA_MCP_ENABLED" else "=false")+("\n" if line.endswith("\n") else "")
    if lines and not lines[-1].endswith("\n"):
        lines.append("\n")
    if "BAMBODIA_MCP_ENABLED" not in seen: lines.append("BAMBODIA_MCP_ENABLED=true\n")
    if "BAMBODIA_MCP_WRITES_ENABLED" not in seen: lines.append("BAMBODIA_MCP_WRITES_ENABLED=false\n")
    return "".join(lines).encode("utf-8")


def update_env():
    data,st=env_read()
    if st.st_uid!=0:
        raise Blocked("ENV_OWNER_NOT_ROOT_REVIEW_REQUIRED")
    updated=render_env(data)
    if BACKUP_DIR.exists():
        if not safe_dir(BACKUP_DIR) or BACKUP_DIR.lstat().st_uid!=0 or stat.S_IMODE(BACKUP_DIR.lstat().st_mode)!=0o700:
            raise Blocked("ROLLBACK_DIRECTORY_UNSAFE")
    else:
        BACKUP_DIR.mkdir(mode=0o700)
    copy=BACKUP_DIR/"original.env"
    fd=os.open(copy,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        os.write(fd,data);os.fsync(fd)
    finally:os.close(fd)
    tmp=APP/".env.mcp-readonly-tmp"
    fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,stat.S_IMODE(st.st_mode))
    try:
        os.fchown(fd,st.st_uid,st.st_gid)
        os.write(fd,updated);os.fsync(fd)
    finally:os.close(fd)
    if ENV.lstat().st_ino!=st.st_ino:
        tmp.unlink(missing_ok=True)
        raise Blocked("ENV_CHANGED_DURING_ACTIVATION")
    os.replace(tmp,ENV)
    return copy


def bearer_challenge():
    request=Request(RESOURCE,method="POST",
        data=b'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
        headers={"Content-Type":"application/json","Accept":"application/json"})
    try:
        with build_opener(NoRedirect).open(request,timeout=12):
            return False
    except HTTPError as e:
        return e.code==401 and e.headers.get("WWW-Authenticate","").lower().startswith("bearer")
    except (URLError,TimeoutError,OSError):
        return False


def restore(copy):
    data=copy.read_bytes()
    s=ENV.lstat()
    tmp=APP/".env.mcp-readonly-rollback"
    fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,stat.S_IMODE(s.st_mode))
    try:
        os.fchown(fd,s.st_uid,s.st_gid);os.write(fd,data);os.fsync(fd)
    finally:os.close(fd)
    os.replace(tmp,ENV)


def main():
    parser=argparse.ArgumentParser()
    flags=parser.add_mutually_exclusive_group(required=True)
    flags.add_argument("--inspect",action="store_true")
    flags.add_argument("--enable-readonly",action="store_true")
    options=parser.parse_args()
    if os.geteuid()!=0: raise Blocked("ROOT_REQUIRED")
    if not inspect():
        print("MCP_ONLY_ACTIVATION=BLOCKED_INCOMPLETE_LIVE_RUNTIME")
        return 2
    if options.inspect:
        print("MCP_ONLY_ACTIVATION=READY_NOT_ENABLED")
        return 0
    if not sys.stdin.isatty(): raise Blocked("OPERATOR_TTY_REQUIRED")
    if input("Type ENABLE-BAMBODIA-MCP-READONLY: ")!="ENABLE-BAMBODIA-MCP-READONLY":
        raise Blocked("OPERATOR_NOT_CONFIRMED")
    copy=update_env()
    if not bearer_challenge():
        try: restore(copy)
        except (Blocked,OSError):
            print("MCP_ONLY_ACTIVATION=CRITICAL_MANUAL_ROLLBACK_REQUIRED")
            return 5
        print("MCP_ONLY_ACTIVATION=ROLLED_BACK_AUTH_CHALLENGE_FAILED")
        return 4
    print("MCP_ONLY_READ_ONLY_FLAG=ENABLED")
    print("MCP_ONLY_WRITE_TOOLS=DISABLED")
    print("MCP_ONLY_UNAUTHORIZED_BEARER_CHALLENGE=PASS")
    print("MCP_ONLY_CHATGPT_OAUTH_LINKING=NOT_YET_INDEPENDENTLY_VERIFIED")
    return 0


if __name__=="__main__":
    try: sys.exit(main())
    except (Blocked,OSError,ValueError,UnicodeError) as e:
        print("MCP_ONLY_ACTIVATION=BLOCKED_"+str(e)[:85].replace(" ","_"))
        sys.exit(1)
