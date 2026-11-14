#!/usr/bin/env python3
"""Bambodia MCP: reproduce failed live source/vendor cutover in an isolated clone.

The live installation is read-only. No .env/production storage/database/media or
credentials are copied. PHP 8.5 Laravel route bootstrap runs as www with a
restricted synthetic environment, ephemeral SQLite, and write flags disabled.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
STAGE=Path("/root/Bambodia-MCP-Only-Install.kw4pjkmi")
PRIVATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp")
PHP=Path("/www/server/php/85/bin/php")
REVIEW=PRIVATE/"review/live-critical"
ORIGINAL_LOCK_SHA="ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
CANDIDATE_LOCK_SHA="4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8"
ORIGINAL_BOOTSTRAP_BLOB="e2e5294ccc41d7304504fed9b451eae737f22899"
CHANGED={
 "app/Http/Controllers/Api/BambodiaMcpController.php",
 "app/Http/Middleware/SetLocale.php",
 "app/Models/User.php",
 "app/Providers/AppServiceProvider.php",
 "app/Support/Mcp/BambodiaTools.php",
 "bootstrap/app.php",
 "config/auth.php",
 "resources/views/developer/index.blade.php",
 "resources/views/layouts/workspace.blade.php",
 "routes/web.php",
}
EXPECT={
 ("mcp","POST"),
 (".well-known/oauth-protected-resource","GET"),
 (".well-known/oauth-protected-resource/mcp","GET"),
 (".well-known/oauth-authorization-server","GET"),
 ("oauth/register","POST"),
 ("oauth/token","POST"),
}
class Stop(Exception):pass

def digest(b):return hashlib.sha256(b).hexdigest()
def blob(b):return hashlib.sha1(f"blob {len(b)}\0".encode()+b).hexdigest()

def read(root,rel):
    path=root/rel
    if not isinstance(rel,str) or rel.startswith("/") or ".." in Path(rel).parts or "\0" in rel:
        raise Stop("UNSAFE_SOURCE_PATH")
    for part in (path,*path.parents):
        if part==root:break
        if part.is_symlink():raise Stop("SOURCE_SYMLINK")
    s=path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_size<1 or s.st_size>20000000:
        raise Stop("UNSAFE_SOURCE_TYPE")
    return path.read_bytes()

def checked_originals():
    if os.geteuid()!=0:raise Stop("ROOT_REQUIRED")
    if not STAGE.is_dir() or STAGE.is_symlink() or stat.S_IMODE(STAGE.stat().st_mode)!=0o700:
        raise Stop("STAGE_CUSTODY")
    if not PRIVATE.is_dir() or PRIVATE.is_symlink() or stat.S_IMODE(PRIVATE.stat().st_mode)!=0o700:
        raise Stop("PRIVATE_CANDIDATE_CUSTODY")
    if digest(read(LIVE,"composer.lock"))!=ORIGINAL_LOCK_SHA:
        raise Stop("LIVE_DEPENDENCY_DRIFT")
    if digest(read(STAGE,"composer.lock"))!=CANDIDATE_LOCK_SHA:
        raise Stop("CANDIDATE_DEPENDENCY_DRIFT")
    if blob(read(LIVE,"bootstrap/app.php"))!=ORIGINAL_BOOTSTRAP_BLOB:
        raise Stop("LIVE_BOOTSTRAP_DRIFT")
    for rel in CHANGED-{"bootstrap/app.php"}:
        if read(LIVE,rel)!=read(REVIEW,rel):
            raise Stop("LIVE_MCP_SOURCE_DRIFT")
    report=json.loads(read(STAGE,"review.json"))
    entries=report.get("mcp_only_patch_sha256")
    if not isinstance(entries,dict) or len(entries)!=36 or set(report.get("existing_file_differences_require_code_review",[]))!=CHANGED:
        raise Stop("STAGED_PATCH_INCOMPLETE")
    for rel,expected in entries.items():
        if not re.fullmatch("[a-f0-9]{64}",str(expected)):
            raise Stop("SOURCE_SHA_UNSAFE")
        if digest(read(STAGE/"patch",rel))!=expected:
            raise Stop("STAGED_PATCH_CHANGED")
    if not PHP.is_file() or not os.access(PHP,os.X_OK):
        raise Stop("PHP85_MISSING")
    return entries

def safe_copy_source(root,rel,dest):
    # Only copy relevant code trees, not user data, hidden files, public media,
    # .env, storage, node_modules, backups or cache.
    source=root/rel
    if source.is_symlink():raise Stop("SOURCE_TREE_SYMLINK")
    if source.is_dir():
        for current,dirs,files in os.walk(source,followlinks=False):
            for name in dirs+files:
                item=Path(current)/name
                if item.is_symlink():raise Stop("SOURCE_TREE_SYMLINK")
        shutil.copytree(source,dest/rel)
    else:
        data=read(root,rel)
        (dest/rel).parent.mkdir(parents=True,exist_ok=True)
        (dest/rel).write_bytes(data)

def normalize_code(tree):
    import pwd
    www=pwd.getpwnam("www")
    for current,dirs,files in os.walk(tree,followlinks=False):
        for name in ["."]+dirs+files:
            path=Path(current) if name=="." else Path(current)/name
            if path.is_symlink():
                # Composer may legitimately contain relative internal symlinks.
                # They were all verified to remain inside the copied vendor.
                if path.is_relative_to(tree/"vendor"):continue
                raise Stop("CLONE_SYMLINK_UNSAFE")
            m=path.lstat().st_mode
            os.chown(path,0,www.pw_gid)
            if stat.S_ISDIR(m):path.chmod(0o750)
            elif stat.S_ISREG(m):path.chmod(0o640)
            else:raise Stop("CLONE_SPECIAL_FILE")

def sanitize_php_error(stderr):
    # Never display original runtime stderr: it could contain env, SQL, URLs,
    # session/cookie values, source fragments or private exception traces.
    s=stderr.decode("utf-8",errors="replace").lower()
    for pattern,label in [
        ("permission denied","FS_PERMISSION"),
        ("sqlstate","DB_SCHEMA_OR_CONNECTION"),
        ("class ","CLASS_AUTOLOAD"),
        ("failed opening required","REQUIRED_PHP_FILE"),
        ("parse error","PHP_PARSE"),
        ("undefined method","API_COMPATIBILITY"),
        ("route","ROUTE_BOOTSTRAP"),
        ("memory exhausted","MEMORY_LIMIT"),
    ]:
        if pattern in s:return label
    return "NONDISCLOSING_PHP_FAILURE"

def validate_routes(payload):
    if not isinstance(payload,list):raise Stop("ROUTE_JSON_INVALID")
    seen=set()
    for obj in payload:
        if not isinstance(obj,dict):continue
        uri=obj.get("uri")
        methods=obj.get("method")
        if not isinstance(uri,str) or not isinstance(methods,str):continue
        seen.update((uri.lstrip("/"),method) for method in methods.split("|"))
    return EXPECT.issubset(seen)

def safe_runuser_binary():
    # Ubuntu util-linux often installs runuser under /usr/sbin, which the
    # deliberately restricted test PATH does not contain.
    for value in ("/usr/sbin/runuser","/usr/bin/runuser"):
        candidate=Path(value)
        if candidate.is_file() and os.access(candidate,os.X_OK):
            return str(candidate)
    raise Stop("PHP85_RUNUSER_BINARY_NOT_FOUND")


def run_ww_php(private_root,env,args,timeout_s,phase):
    """Launch only isolated test PHP; kill/reap the whole child group on timeout.

    No shell, no raw stderr/stdout in user-facing diagnostics. The phase is
    a locally authored constant, never source-derived or user-controlled.
    """
    if phase not in ("PHP_BINARY","ARTISAN_ROUTE","ARTISAN_DOWN","ARTISAN_UP"):
        raise Stop("UNSAFE_EXECUTION_PHASE")
    proc=None
    try:
        proc=subprocess.Popen(
            [safe_runuser_binary(),"-u","www","--",str(PHP),*args],
            cwd=str(private_root),env=env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,
            stdin=subprocess.DEVNULL,start_new_session=True,
        )
    except OSError:
        raise Stop("PHP85_"+phase+"_START_FAILED")
    try:
        stdout,stderr=proc.communicate(timeout=timeout_s)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(proc.pid,signal.SIGKILL)
        except ProcessLookupError:
            pass
        try:
            proc.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            raise Stop("PHP85_"+phase+"_TIMEOUT_KILL_UNCONFIRMED")
        raise Stop("PHP85_"+phase+"_TIMEOUT_"+str(timeout_s)+"S")
    if proc.returncode!=0:
        raise Stop("PHP85_"+phase+"_"+sanitize_php_error(stderr))
    if len(stdout)>2_000_000 or len(stderr)>2_000_000:
        raise Stop("PHP85_"+phase+"_EXCESSIVE_OUTPUT")
    return stdout


def clone_runtime(mode="testing", simulate_maintenance=False):
    if mode not in ("testing","production") or not isinstance(simulate_maintenance,bool):
        raise Stop("INVALID_SYNTHETIC_ENV_MODE")
    entries=checked_originals()
    print("MCP_REPRODUCTION_LIVE_ORIGINALS=VERIFIED")
    temp=Path(tempfile.mkdtemp(prefix="bambodia-mcp-cutover-repro-",dir="/var/tmp"))
    # Default /var/tmp root-owned, temporary path is never a webserver vhost.
    try:
        if temp.is_symlink():raise Stop("CLONE_DIRECTORY_SYMLINK")
        # The clone is private to the operator and the app's www account.
        import pwd
        os.chown(temp,0,pwd.getpwnam("www").pw_gid)
        temp.chmod(0o750)
        for rel in ("app","config","routes","resources/views","database/migrations"):
            safe_copy_source(LIVE,rel,temp)
        for rel in ("artisan","bootstrap/app.php","bootstrap/providers.php"):
            safe_copy_source(LIVE,rel,temp)
        # No bootstrap/cache, user storage, .env, production database, secrets,
        # public uploads or network-backed credentials are copied.
        for rel in entries:
            p=temp/rel
            p.parent.mkdir(parents=True,exist_ok=True)
            p.write_bytes(read(STAGE/"patch",rel))
        for rel in ("composer.json","composer.lock"):
            (temp/rel).write_bytes(read(STAGE,rel))
        cand_vendor=PRIVATE/"source/vendor"
        if cand_vendor.is_symlink() or not cand_vendor.is_dir():
            raise Stop("PRIVATE_VENDOR_UNSAFE")
        shutil.copytree(cand_vendor,temp/"vendor",symlinks=True)
        for name in ("framework/cache","framework/sessions","framework/views","logs"):
            (temp/"storage"/name).mkdir(parents=True,exist_ok=True)
        (temp/"bootstrap/cache").mkdir(parents=True,exist_ok=True)
        (temp/"public").mkdir(parents=True,exist_ok=True)
        # Avoid symlinks from the private accepted vendor escaping that tree.
        for root,dirs,files in os.walk(temp/"vendor",followlinks=False):
            for name in dirs+files:
                p=Path(root)/name
                if p.is_symlink():
                    try: resolved=p.resolve(strict=True)
                    except OSError:raise Stop("PRIVATE_VENDOR_DANGLING_SYMLINK")
                    if not resolved.is_relative_to((temp/"vendor").resolve()):
                        raise Stop("PRIVATE_VENDOR_EXTERNAL_SYMLINK")
        normalize_code(temp)
        import pwd
        www=pwd.getpwnam("www")
        for root in (temp/"storage",temp/"bootstrap/cache"):
            for current,dirs,files in os.walk(root):
                directory=Path(current)
                os.chown(directory,0,www.pw_gid)
                directory.chmod(0o770)
        if any((temp/p).exists() for p in (".env",".env.testing","storage/oauth-private.key","storage/oauth-public.key")):
            raise Stop("PRIVATE_CLONE_SECRET_PRESENT")
        env={
          "PATH":"/usr/local/bin:/usr/bin:/bin",
          "HOME":str(temp),
          "APP_ENV":mode,
          "APP_DEBUG":"false",
          "APP_URL":"https://bambodia.com",
          "APP_KEY":"base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
          "DB_CONNECTION":"sqlite",
          "DB_DATABASE":":memory:",
          "DB_URL":"",
          "CACHE_STORE":"array",
          "SESSION_DRIVER":"array",
          "QUEUE_CONNECTION":"sync",
          "MAIL_MAILER":"log",
          "BAMBODIA_MCP_ENABLED":"false",
          "BAMBODIA_MCP_WRITES_ENABLED":"false",
        }
        print("MCP_REPRODUCTION_PRIVATE_SOURCE_OVERLAY=36_FILES",flush=True)
        print("MCP_REPRODUCTION_SYNTHETIC_APP_ENV="+mode.upper(),flush=True)
        marker=run_ww_php(temp,env,["-r","echo 'PHP85_OK';"],15,"PHP_BINARY")
        if marker.strip()!=b"PHP85_OK":
            raise Stop("PHP85_BINARY_UNEXPECTED_OUTPUT")
        print("MCP_REPRODUCTION_WWW_PHP85_PROCESS=PASS",flush=True)
        if simulate_maintenance:
            print("MCP_REPRODUCTION_PRIVATE_MAINTENANCE=STARTING",flush=True)
            run_ww_php(temp,env,
                ["artisan","down","--no-ansi","--no-interaction"],
                30,"ARTISAN_DOWN")
            if not (temp/"storage/framework/down").is_file():
                raise Stop("PRIVATE_MAINTENANCE_MARKER_NOT_CREATED")
            print("MCP_REPRODUCTION_PRIVATE_MAINTENANCE=ENTERED",flush=True)
        print("MCP_REPRODUCTION_ARTISAN_ROUTE_CHECK=STARTED",flush=True)
        route_output=run_ww_php(temp,env,
            ["artisan","route:list","--json","--no-ansi"],60,"ARTISAN_ROUTE")
        try: payload=json.loads(route_output)
        except (ValueError,UnicodeError):raise Stop("PHP85_ROUTE_JSON_INVALID")
        if not validate_routes(payload):raise Stop("MCP_OAUTH_ROUTE_REGISTRATION_MISSING")
        print("MCP_REPRODUCTION_REAL_LIVE_SOURCE_PRIVATE_PHP85=PASS")
        print("MCP_REPRODUCTION_REAL_LIVE_SOURCE_ROUTES=PASS")
        if simulate_maintenance:
            print("MCP_REPRODUCTION_PRIVATE_MAINTENANCE=EXITING",flush=True)
            run_ww_php(temp,env,
                ["artisan","up","--no-ansi","--no-interaction"],
                30,"ARTISAN_UP")
            if (temp/"storage/framework/down").exists():
                raise Stop("PRIVATE_MAINTENANCE_MARKER_STILL_PRESENT")
            print("MCP_REPRODUCTION_PRIVATE_MAINTENANCE_RECOVERY=PASS",flush=True)
    finally:
        # Only this newly created /var/tmp directory; never recursively touch
        # production source/vendor, staging, private accepted or backup trees.
        if temp.parent==Path("/var/tmp") and temp.name.startswith("bambodia-mcp-cutover-repro-") and not temp.is_symlink():
            shutil.rmtree(temp)

def main():
    if sys.argv[1:]==["--reproduce"]:
        mode="testing";simulate_maintenance=False
    elif sys.argv[1:]==["--reproduce-production-mode"]:
        mode="production";simulate_maintenance=False
    elif sys.argv[1:]==["--reproduce-private-maintenance"]:
        mode="production";simulate_maintenance=True
    else:
        print("USAGE=--reproduce | --reproduce-production-mode | --reproduce-private-maintenance");return 64
    try:
        clone_runtime(mode,simulate_maintenance=simulate_maintenance)
        print("MCP_REPRODUCTION_LIVE_FILES=UNCHANGED")
        print("MCP_REPRODUCTION_PRODUCTION_DB=NOT_ACCESSED")
        print("MCP_REPRODUCTION_PRODUCTION_MAINTENANCE=NOT_TOUCHED")
        return 0
    except Stop as e:
        print("MCP_REPRODUCTION_BLOCKER="+str(e))
    except (FileNotFoundError,OSError,ValueError,TypeError,KeyError,AttributeError):
        print("MCP_REPRODUCTION_BLOCKER=PRIVATE_REVIEW_REQUIRED")
    print("MCP_REPRODUCTION_LIVE_FILES=UNCHANGED")
    print("MCP_REPRODUCTION_STATUS=BLOCKED")
    return 1

if __name__=="__main__":sys.exit(main())
