#!/usr/bin/env python3
"""MCP-only vendor/route rehearsal on an immutable root-private accepted candidate.

No live files, keys, .env, DB, migrations, vendor installs, R2 or network writes.
Print only PASS/BLOCKED markers; never emit php/Composer output or secrets.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
STAGE=Path("/root/Bambodia-MCP-Only-Install.kw4pjkmi")
PRIVATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp")
PHP=Path("/www/server/php/85/bin/php")
LOCK_SHA_LIVE="ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
LOCK_SHA_CANDIDATE="4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8"
EXACT_CHANGES={
 "guzzlehttp/guzzle":("8.3.1","8.3.2"),
 "guzzlehttp/psr7":("3.2.0","3.3.0"),
 "laravel/framework":("v13.40.0","v13.42.0"),
 "laravel/prompts":("v0.3.26","v0.3.27"),
 "laravel/serializable-closure":("v2.1.0","v2.2.0"),
 "nunomaduro/termwind":("v2.4.0","v2.4.1"),
}
REQUIRED_ADDITIONS={
 "laravel/mcp":"v1.2.0",
 "laravel/passport":"v13.11.0",
 "league/oauth2-server":"9.4.1",
}
ROUTES={
 ("mcp","POST"),
 (".well-known/oauth-protected-resource","GET"),
 (".well-known/oauth-protected-resource/mcp","GET"),
 (".well-known/oauth-authorization-server","GET"),
 ("oauth/register","POST"),
 ("oauth/token","POST"),
}

class Stop(Exception):pass

def file_bytes(path,max_size=20_000_000):
    if path.is_symlink():
        raise Stop("SYMLINK_FILE")
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_size<=0 or st.st_size>max_size:
        raise Stop("INVALID_FILE")
    return path.read_bytes()

def sha(data):return hashlib.sha256(data).hexdigest()

def private_dir(p):
    st=p.lstat()
    return stat.S_ISDIR(st.st_mode) and st.st_uid==0 and stat.S_IMODE(st.st_mode)==0o700 and not p.is_symlink()

def lock_packages(data):
    parsed=json.loads(data)
    out={}
    for key in ("packages","packages-dev"):
        items=parsed.get(key,[])
        if not isinstance(items,list):raise Stop("INVALID_COMPOSER_LOCK")
        for row in items:
            if not isinstance(row,dict) or not isinstance(row.get("name"),str):
                raise Stop("INVALID_COMPOSER_PACKAGE")
            if row["name"] in out:raise Stop("DUPLICATE_PACKAGE")
            out[row["name"]]=row.get("version")
    return out

def compare(old,new):
    before=lock_packages(old);after=lock_packages(new)
    changed={k:(before[k],after[k]) for k in set(before)&set(after) if before[k]!=after[k]}
    additions={k:after[k] for k in set(after)-set(before)}
    removed=set(before)-set(after)
    return changed,additions,removed

def installed_versions(data):
    obj=json.loads(data)
    entries=obj.get("packages",[]) if isinstance(obj,dict) else obj
    if not isinstance(entries,list):raise Stop("BAD_VENDOR_INSTALLED_JSON")
    return {item["name"]:item.get("version") for item in entries if isinstance(item,dict) and isinstance(item.get("name"),str)}

def run_php(args):
    env={
        "PATH":"/usr/local/bin:/usr/bin:/bin",
        "HOME":str(PRIVATE),
        "APP_ENV":"testing",
        "APP_DEBUG":"false",
        "APP_URL":"https://bambodia.com",
        "APP_KEY":"base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        "DB_CONNECTION":"sqlite",
        "DB_DATABASE":":memory:",
        "DB_URL":"",
        "CACHE_STORE":"array",
        "QUEUE_CONNECTION":"sync",
        "MAIL_MAILER":"log",
        "SESSION_DRIVER":"array",
        "BAMBODIA_MCP_ENABLED":"false",
        "BAMBODIA_MCP_WRITES_ENABLED":"false",
        "COMPOSER_ALLOW_SUPERUSER":"1",
    }
    try:
        result=subprocess.run([str(PHP),*args],cwd=str(PRIVATE/"source"),
                              env=env,capture_output=True,timeout=35,check=False)
    except (OSError,subprocess.TimeoutExpired):
        raise Stop("PHP85_TEST_NOT_COMPLETED")
    if result.returncode!=0 or len(result.stdout)>2_000_000 or len(result.stderr)>2_000_000:
        raise Stop("ISOLATED_PHP85_SMOKE_FAILED")
    return result.stdout

def validate_routes(items):
    if not isinstance(items,list):raise Stop("ROUTE_JSON_INVALID")
    present=set()
    for item in items:
        if not isinstance(item,dict):continue
        uri=item.get("uri","")
        methods=item.get("method","")
        if not isinstance(uri,str) or not isinstance(methods,str):continue
        for method in methods.split("|"):
            present.add((uri.lstrip("/"),method.upper()))
    return ROUTES.issubset(present)

def inspect():
    if os.geteuid()!=0:raise Stop("ROOT_REQUIRED")
    if not private_dir(STAGE) or not private_dir(PRIVATE):
        raise Stop("PRIVATE_STAGE_CUSTODY")
    if not PHP.is_file() or not os.access(PHP,os.X_OK):
        raise Stop("PHP85_MISSING")
    if (PRIVATE/"source/.env").exists() or (PRIVATE/"source/.env.testing").exists():
        raise Stop("PRIVATE_CANDIDATE_HAS_ENV")
    if (PRIVATE/"source/bootstrap/cache/config.php").exists():
        raise Stop("PRIVATE_CANDIDATE_HAS_CONFIG_CACHE")
    before=file_bytes(LIVE/"composer.lock")
    after=file_bytes(STAGE/"composer.lock")
    if sha(before)!=LOCK_SHA_LIVE or sha(after)!=LOCK_SHA_CANDIDATE or sha(file_bytes(PRIVATE/"source/composer.lock"))!=LOCK_SHA_CANDIDATE:
        raise Stop("COMPOSER_LOCK_DRIFT")
    changed,added,removed=compare(before,after)
    if changed!=EXACT_CHANGES or len(added)!=19 or removed or any(added.get(k)!=v for k,v in REQUIRED_ADDITIONS.items()):
        raise Stop("PACKAGE_SET_CHANGED")
    print("MCP_ONLY_VENDOR_SIX_CHANGED=EXACT_ACCEPTED")
    print("MCP_ONLY_VENDOR_NINETEEN_ADDITIONS=EXACT_ACCEPTED")
    manifest=json.loads(file_bytes(STAGE/"review.json",65536))
    paths=manifest.get("mcp_only_patch_sha256")
    if manifest.get("state")!="PRIVATE_STAGED_UNAPPROVED_NO_DEPLOY" or not isinstance(paths,dict) or len(paths)!=36:
        raise Stop("STAGE_MANIFEST_INVALID")
    if sha(file_bytes(STAGE/"composer.lock"))!=manifest.get("composer_lock_sha256"):
        raise Stop("STAGE_LOCK_NOT_PINNED")
    for path,digest in paths.items():
        if not isinstance(path,str) or path.startswith("/") or ".." in Path(path).parts or not re.fullmatch(r"[a-f0-9]{64}",str(digest)):
            raise Stop("STAGE_PATH_INVALID")
        if sha(file_bytes(STAGE/"patch"/path))!=digest:
            raise Stop("STAGE_FILE_CHANGED")
    print("MCP_ONLY_SOURCE_THIRTY_SIX=PASS")
    vendor=PRIVATE/"source/vendor"
    if not (vendor/"autoload.php").is_file():
        raise Stop("CANDIDATE_VENDOR_MISSING")
    installed=installed_versions(file_bytes(vendor/"composer/installed.json"))
    required={**{name:versions[1] for name,versions in EXACT_CHANGES.items()},**added}
    if any(installed.get(name)!=version for name,version in required.items()):
        raise Stop("VENDOR_PACKAGE_VERSION_MISMATCH")
    print("MCP_ONLY_VENDOR_ALL_NINETEEN_AND_SIX_INSTALLED=PASS")
    run_php(["-r","require 'vendor/autoload.php'; if (!class_exists('Laravel\\Passport\\Passport') || !class_exists('Laravel\\Mcp\\Facades\\Mcp')) exit(3);"])
    print("MCP_ONLY_PHP85_VENDOR_AUTOLOAD=PASS")
    routes=json.loads(run_php(["artisan","route:list","--json","--no-ansi"]))
    if not validate_routes(routes):
        raise Stop("MCP_OAUTH_ROUTES_MISSING")
    print("MCP_ONLY_PHP85_MCP_OAUTH_ROUTES=PASS")
    print("MCP_ONLY_READONLY_REHEARSAL=PASS")
    print("MCP_ONLY_VENDOR_PRODUCTION=UNCHANGED")
    print("MCP_ONLY_MYSQL_PRODUCTION=NOT_ACCESSED")
    print("MCP_ONLY_PRODUCTION_MCP=NOT_ENABLED")

if __name__=="__main__":
    if sys.argv[1:]!=["--rehearse"]:
        print("USAGE=--rehearse");sys.exit(64)
    try:inspect()
    except (Stop,FileNotFoundError,ValueError,KeyError,TypeError,UnicodeError,OSError,subprocess.TimeoutExpired):
        print("MCP_ONLY_READONLY_REHEARSAL=BLOCKED")
        print("MCP_ONLY_PRODUCTION_UNCHANGED=YES")
        sys.exit(1)
