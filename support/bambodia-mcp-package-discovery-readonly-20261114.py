#!/usr/bin/env python3
"""MCP-only read-only discovery-manifest investigation on aaPanel Laravel.

No PHP include, Artisan, database, .env, credentials, logs, writes or HTTP.
Checks the two default Laravel cached provider manifests against accepted new
vendor metadata. Custom APP_PACKAGES_CACHE/APP_SERVICES_CACHE overrides are
not resolved; report that limitation explicitly.
"""
import hashlib
import json
import os
from pathlib import Path
import stat
import sys

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
CANDIDATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp/source/vendor")
PACKAGES=("laravel/mcp","laravel/passport")
MAX_BYTES=5_000_000
class Stop(Exception):pass

def read_static(path):
    if path.is_symlink():raise Stop("CACHED_MANIFEST_SYMLINK_UNSAFE")
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_size>MAX_BYTES:
        raise Stop("CACHED_MANIFEST_TYPE_OR_SIZE_UNSAFE")
    return path.read_bytes()

def cached_status(path,providers,packages):
    if not path.exists():
        return {"exists":False,"sha":None,
                "packages":{name:None for name in packages},
                "providers":{name:None for name in providers}}
    raw=read_static(path)
    ascii_bytes=raw.lower()
    return {
        "exists":True,
        "sha":hashlib.sha256(raw).hexdigest(),
        "packages":{name:name.encode() in ascii_bytes for name in packages},
        "providers":{name:(name.encode() in raw or
                           name.replace("\\","\\\\").encode() in raw)
                     for name in providers},
    }

def get_provider_metadata(candidate):
    installed=candidate/"composer/installed.json"
    if not installed.is_file() or installed.is_symlink():
        raise Stop("ACCEPTED_VENDOR_METADATA_UNSAFE")
    data=json.loads(read_static(installed))
    records=data.get("packages",[]) if isinstance(data,dict) else data
    if not isinstance(records,list):raise Stop("VENDOR_METADATA_INVALID")
    index={p["name"]:p for p in records if isinstance(p,dict) and isinstance(p.get("name"),str)}
    output={}
    for name in PACKAGES:
        meta=index.get(name)
        if meta is None:raise Stop("MCP_VENDOR_PACKAGE_MISSING")
        laravel=meta.get("extra",{}).get("laravel",{})
        providers=laravel.get("providers",[])
        if not isinstance(providers,list) or not providers or not all(isinstance(x,str) and x for x in providers):
            raise Stop("MCP_PROVIDER_METADATA_MISSING")
        output[name]=providers
    return output

def audit():
    if os.geteuid()!=0:raise Stop("ROOT_REQUIRED")
    if LIVE.is_symlink() or CANDIDATE.is_symlink() or not LIVE.is_dir() or not CANDIDATE.is_dir():
        raise Stop("LIVE_OR_VENDOR_PATH_UNSAFE")
    providers=get_provider_metadata(CANDIDATE)
    all_providers=sorted(set(x for group in providers.values() for x in group))
    print("MCP_DISCOVERY_VENDOR_PROVIDERS=VERIFIED")
    base=LIVE/"bootstrap/cache"
    if not base.is_dir() or base.is_symlink():raise Stop("LIVE_BOOTSTRAP_CACHE_UNSAFE")
    pk=cached_status(base/"packages.php",all_providers,PACKAGES)
    sv=cached_status(base/"services.php",all_providers,PACKAGES)
    print("MCP_DISCOVERY_PACKAGES_CACHE="+("PRESENT" if pk["exists"] else "ABSENT"))
    print("MCP_DISCOVERY_SERVICES_CACHE="+("PRESENT" if sv["exists"] else "ABSENT"))
    for name in PACKAGES:
        label=name.replace("/","_").upper()
        print("MCP_DISCOVERY_PACKAGES_HAS_"+label+"="+
              ("YES" if pk["packages"][name] else "NO" if pk["exists"] else "NOT_CACHED"))
    for name in PACKAGES:
        label=name.replace("/","_").upper()
        exists=all(sv["providers"].get(p) for p in providers[name])
        print("MCP_DISCOVERY_SERVICES_HAS_"+label+"_PROVIDER="+
              ("YES" if exists else "NO" if sv["exists"] else "NOT_CACHED"))
    # A cached package manifest that predates either new Laravel package is
    # strong evidence of stale discovery, but not proof of the historic route
    # mismatch. Actual environments can override cache file paths.
    stale=pk["exists"] and any(not pk["packages"][name] for name in PACKAGES)
    print("MCP_DISCOVERY_DEFAULT_PACKAGES_STALE="+("LIKELY" if stale else "NOT_INDICATED"))
    print("MCP_DISCOVERY_CUSTOM_CACHE_PATHS=NOT_EVALUATED")
    print("MCP_DISCOVERY_CACHE_CHECKSUMS=PRIVATE_NOT_EMITTED")
    print("MCP_DISCOVERY_LIVE_FILES=UNCHANGED")
    print("MCP_DISCOVERY_PRODUCTION_DB=NOT_ACCESSED")

def main():
    if sys.argv[1:]!=["--inspect"]:
        print("USAGE=--inspect");return 64
    try:
        audit()
        print("MCP_DISCOVERY_READONLY_AUDIT=PASS")
        return 0
    except Stop as exc:
        print("MCP_DISCOVERY_BLOCKER="+str(exc))
    except (OSError,ValueError,TypeError,KeyError,AttributeError):
        print("MCP_DISCOVERY_BLOCKER=PRIVATE_REVIEW_REQUIRED")
    print("MCP_DISCOVERY_READONLY_AUDIT=BLOCKED")
    print("MCP_DISCOVERY_LIVE_FILES=UNCHANGED")
    return 1

if __name__=="__main__":sys.exit(main())
