#!/usr/bin/env python3
"""Bambodia MCP-only reversible runtime installation.

Only swaps pinned MCP/OAuth source, Composer manifest/lock and accepted vendor.
MCP flags remain OFF; no migrations/Passport keys/secrets/network writes/R2.
Operator must use --install with a real local TTY. Fails closed on source drift.
Rollback is handled automatically on install failure. A private rollback tree
and the prior vendor are retained after success for a later operator rollback.
"""
import fcntl
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
import tempfile
import uuid

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
STAGE=Path("/root/Bambodia-MCP-Only-Install.kw4pjkmi")
PRIVATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp")
PHP=Path("/www/server/php/85/bin/php")
VHOST=Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
LIVE_LOCK_SHA="ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
NEW_LOCK_SHA="4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8"
VHOST_SHA="5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116"
BOOTSTRAP_LIVE_BLOB="e2e5294ccc41d7304504fed9b451eae737f22899"
EXISTING_CHANGED={
 "app/Http/Controllers/Api/BambodiaMcpController.php",
 "app/Http/Middleware/SetLocale.php","app/Models/User.php",
 "app/Providers/AppServiceProvider.php","app/Support/Mcp/BambodiaTools.php",
 "bootstrap/app.php","config/auth.php",
 "resources/views/developer/index.blade.php",
 "resources/views/layouts/workspace.blade.php","routes/web.php",
}
CRITICAL=EXISTING_CHANGED-{"bootstrap/app.php"}
ROUTE_CHECK={("mcp","POST"),(".well-known/oauth-protected-resource","GET"),
             (".well-known/oauth-protected-resource/mcp","GET"),
             (".well-known/oauth-authorization-server","GET"),
             ("oauth/register","POST"),("oauth/token","POST")}
class Stop(Exception):pass

def sha(data):return hashlib.sha256(data).hexdigest()
def blob(data):return hashlib.sha1(f"blob {len(data)}\0".encode()+data).hexdigest()

def private_dir(path):
    s=path.lstat()
    return stat.S_ISDIR(s.st_mode) and s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700 and not path.is_symlink()

def read_file(path,maxsize=20000000):
    if path.is_symlink():raise Stop("SYMLINK_FILE")
    s=path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_size<1 or s.st_size>maxsize:
        raise Stop("FILE_TYPE_OR_SIZE")
    return path.read_bytes()

def good_relative(rel):
    return isinstance(rel,str) and bool(rel) and not rel.startswith("/") and "\x00" not in rel and str(Path(rel))==rel and all(x not in ("",".","..") for x in Path(rel).parts)

def guarded_path(root,rel):
    if not good_relative(rel):raise Stop("RELATIVE_PATH_UNSAFE")
    p=root/rel
    for x in (p,*p.parents):
        if x==root:break
        if x.is_symlink():raise Stop("SOURCE_SYMLINK")
    return p

def env_flag_off():
    env=LIVE/".env"
    content=read_file(env,131072).decode("utf-8")
    vals={}
    for line in content.splitlines():
        m=re.match(r"^\s*(BAMBODIA_MCP_ENABLED|BAMBODIA_MCP_WRITES_ENABLED)\s*=\s*(.*?)\s*$",line)
        if m:
            if m.group(1) in vals:raise Stop("DUPLICATE_FLAGS")
            vals[m.group(1)]=m.group(2).strip("'\"").lower()
    if any(vals.get(key,"false") not in ("false","0","") for key in
       ("BAMBODIA_MCP_ENABLED","BAMBODIA_MCP_WRITES_ENABLED")):
        raise Stop("MCP_FLAGS_MUST_BE_OFF")

def lock_versions(data):
    obj=json.loads(data)
    result={}
    for key in ("packages","packages-dev"):
        for p in obj.get(key,[]):
            if p["name"] in result:raise Stop("DUPLICATE_LOCK_PACKAGE")
            result[p["name"]]=p["version"]
    return result

def safe_patch_inventory():
    if os.geteuid()!=0 or not private_dir(STAGE) or not private_dir(PRIVATE):
        raise Stop("ROOT_OR_PRIVATE_STAGE_UNSAFE")
    if not LIVE.is_dir() or LIVE.is_symlink() or not (LIVE/"storage/app/installed.lock").is_file():
        raise Stop("LIVE_SITE_CHANGED")
    if not PHP.is_file() or not os.access(PHP,os.X_OK):
        raise Stop("PHP85_MISSING")
    if sha(read_file(VHOST))!=VHOST_SHA:
        raise Stop("VHOST_CHANGED")
    env_flag_off()
    if (LIVE/"storage/framework/down").exists():raise Stop("LIVE_ALREADY_IN_MAINTENANCE")
    if (LIVE/"bootstrap/cache/config.php").exists() or list((LIVE/"bootstrap/cache").glob("routes-*.php")):
        raise Stop("LIVE_CONFIG_OR_ROUTE_CACHED")
    if sha(read_file(LIVE/"composer.lock"))!=LIVE_LOCK_SHA:
        raise Stop("LIVE_LOCK_CHANGED")
    if sha(read_file(STAGE/"composer.lock"))!=NEW_LOCK_SHA or sha(read_file(PRIVATE/"source/composer.lock"))!=NEW_LOCK_SHA:
        raise Stop("CANDIDATE_LOCK_CHANGED")
    old=lock_versions(read_file(LIVE/"composer.lock"))
    new=lock_versions(read_file(STAGE/"composer.lock"))
    changed={x for x in old.keys()&new.keys() if old[x]!=new[x]}
    if changed!={"guzzlehttp/guzzle","guzzlehttp/psr7","laravel/framework","laravel/prompts",
                 "laravel/serializable-closure","nunomaduro/termwind"}:
        raise Stop("DEPENDENCY_CHANGE_UNEXPECTED")
    if len(set(new)-set(old))!=19 or set(old)-set(new) or new.get("laravel/mcp")!="v1.2.0" or new.get("laravel/passport")!="v13.11.0":
        raise Stop("MCP_DEPENDENCY_INVENTORY_UNSAFE")
    manifest=json.loads(read_file(STAGE/"review.json",65536))
    if manifest.get("state")!="PRIVATE_STAGED_UNAPPROVED_NO_DEPLOY" or manifest.get("live_root")!=str(LIVE) or manifest.get("candidate")!=str(PRIVATE):
        raise Stop("STAGE_PROVENANCE_INVALID")
    entries=manifest.get("mcp_only_patch_sha256")
    if not isinstance(entries,dict) or len(entries)!=36 or set(manifest.get("existing_file_differences_require_code_review",[]))!=EXISTING_CHANGED:
        raise Stop("STAGE_FILE_INVENTORY_INVALID")
    for rel,digest in entries.items():
        if not good_relative(rel) or not re.fullmatch("[0-9a-f]{64}",str(digest)):
            raise Stop("STAGE_RELATIVE_PATH_INVALID")
        if sha(read_file(guarded_path(STAGE/"patch",rel)))!=digest:
            raise Stop("STAGE_SOURCE_CHANGED")
    for rel in CRITICAL:
        if read_file(guarded_path(LIVE,rel))!=read_file(guarded_path(PRIVATE/"review/live-critical",rel)):
            raise Stop("LIVE_MCP_FILE_DRIFT")
    if blob(read_file(LIVE/"bootstrap/app.php"))!=BOOTSTRAP_LIVE_BLOB:
        raise Stop("LIVE_BOOTSTRAP_NEW_DRIFT")
    changed_actual={rel for rel in entries if (guarded_path(LIVE,rel).is_file()
                    and read_file(guarded_path(LIVE,rel))!=read_file(guarded_path(STAGE/"patch",rel)))}
    if changed_actual!=EXISTING_CHANGED:
        raise Stop("LIVE_PATCH_DRIFT")
    vendor=LIVE/"vendor"
    if not vendor.is_dir() or vendor.is_symlink():
        raise Stop("LIVE_VENDOR_UNSAFE")
    candidate=PRIVATE/"source/vendor"
    if not candidate.is_dir() or candidate.is_symlink():
        raise Stop("PRIVATE_VENDOR_UNSAFE")
    installed=json.loads(read_file(candidate/"composer/installed.json"))
    packages=installed["packages"] if isinstance(installed,dict) else installed
    indexed={x["name"]:x.get("version") for x in packages}
    if any(indexed.get(n)!=v for n,v in new.items() if n in
           set(new)-set(old) | changed):
        raise Stop("CANDIDATE_VENDOR_MISMATCH")
    if not (candidate/"autoload.php").is_file():
        raise Stop("CANDIDATE_AUTOLOAD_MISSING")
    # Ensure copy does not preserve candidate symlinks that escape vendor.
    for directory,dirs,files in os.walk(candidate,followlinks=False):
        for name in dirs+files:
            p=Path(directory)/name
            if p.is_symlink():
                target=p.resolve(strict=True)
                if not target.is_relative_to(candidate.resolve(strict=True)):
                    raise Stop("VENDOR_SYMLINK_ESCAPES_CANDIDATE")
    return entries

def safe_live_parent(path):
    # With the invoking operator's umask 077, mkdir(mode=0755) alone leaves
    # new Laravel PHP directories unreadable to the www FPM user.
    # Never chmod/chown existing directories; only those created by this release.
    try:
        relative=path.parent.relative_to(LIVE)
    except ValueError:
        raise Stop("LIVE_WRITE_PATH_OUTSIDE_ROOT")
    current=LIVE
    for component in relative.parts:
        current=current/component
        if current.is_symlink():raise Stop("LIVE_PARENT_SYMLINK")
        if current.exists():
            if not current.is_dir():raise Stop("LIVE_PARENT_NOT_DIRECTORY")
        else:
            current.mkdir(mode=0o700)
            current.chmod(0o755)


def write_atomic(path,data,mode,uid,gid):
    safe_live_parent(path)
    temporary=path.with_name("."+path.name+".mcp-"+uuid.uuid4().hex)
    fd=os.open(temporary,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,mode)
    try:
        os.fchmod(fd,mode)
        os.fchown(fd,uid,gid)
        with os.fdopen(fd,"wb",closefd=False) as f:
            f.write(data);f.flush();os.fsync(f.fileno())
    finally:
        os.close(fd)
    os.replace(temporary,path)

def classify_artisan_error(stderr):
    # Only fixed public-safe category strings are emitted. PHP stderr and
    # credentials must NEVER be printed or stored in failure metadata.
    sample=stderr[:65536].decode("utf-8",errors="replace").lower()
    if "sqlstate" in sample:
        return "DATABASE_SCHEMA_OR_CONNECTION"
    if "permission denied" in sample or "failed to open stream" in sample:
        return "FILESYSTEM_ACCESS"
    if "failed opening required" in sample or "class " in sample and "not found" in sample:
        return "PHP_FILE_OR_CLASS_AUTOLOAD"
    if "parse error" in sample or "syntax error" in sample:
        return "PHP_SYNTAX"
    if "private key" in sample or "passport" in sample and "key" in sample:
        return "OAUTH_KEY_CONFIGURATION"
    return "UNCLASSIFIED_APPLICATION_FAILURE"


def as_www(*args):
    try:
        p=subprocess.run(["runuser","-u","www","--",str(PHP),"artisan",*args],
            cwd=str(LIVE),capture_output=True,timeout=90,check=False)
    except subprocess.TimeoutExpired:
        print("MCP_ONLY_ARTISAN_DIAGNOSTIC=PROCESS_TIMEOUT")
        raise Stop("LIVE_ARTISAN_EXECUTION_FAILED")
    except OSError:
        print("MCP_ONLY_ARTISAN_DIAGNOSTIC=PROCESS_START_FAILED")
        raise Stop("LIVE_ARTISAN_EXECUTION_FAILED")
    if p.returncode!=0:
        print("MCP_ONLY_ARTISAN_DIAGNOSTIC="+classify_artisan_error(p.stderr))
        raise Stop("LIVE_ARTISAN_CHECK_FAILED")
    return p.stdout

def verify_routes():
    try:
        items=json.loads(as_www("route:list","--json","--no-ansi"))
    except (ValueError,TypeError):raise Stop("LIVE_ROUTES_INVALID")
    seen={(p.get("uri","").lstrip("/"),method) for p in items
          for method in p.get("method","").split("|")}
    if not ROUTE_CHECK.issubset(seen):
        raise Stop("MCP_ROUTES_NOT_REGISTERED")

def snapshot_originals(path,entries):
    original={}
    for rel in list(entries)+["composer.json","composer.lock"]:
        p=guarded_path(LIVE,rel)
        if p.exists():
            s=p.lstat()
            if not stat.S_ISREG(s.st_mode):raise Stop("LIVE_SOURCE_TYPE_UNSAFE")
            data=read_file(p)
            back=guarded_path(path/"original",rel)
            back.parent.mkdir(parents=True,exist_ok=True)
            back.write_bytes(data)
            back.chmod(0o600)
            original[rel]={"exists":True,"mode":stat.S_IMODE(s.st_mode),
                           "uid":s.st_uid,"gid":s.st_gid,"sha":sha(data)}
        else:
            original[rel]={"exists":False}
    return original

def rollback(path,original,oldvendor,newvendor):
    # Failed code installs are rolled back while maintenance mode remains ON.
    if oldvendor.exists():
        current=LIVE/"vendor"
        if current.exists():
            if newvendor.exists():raise Stop("VENDOR_ROLLBACK_COLLISION")
            os.rename(current,newvendor)
        os.rename(oldvendor,current)
    for rel,record in original.items():
        target=guarded_path(LIVE,rel)
        if record["exists"]:
            data=read_file(guarded_path(path/"original",rel))
            if sha(data)!=record["sha"]:raise Stop("ROLLBACK_SNAPSHOT_CHANGED")
            write_atomic(target,data,record["mode"],record["uid"],record["gid"])
        else:
            if target.is_file() and not target.is_symlink():target.unlink()
    if newvendor.exists() and newvendor.is_dir() and not newvendor.is_symlink():
        shutil.rmtree(newvendor)

def prepare_vendor_permissions(vendor_root):
    # The previously accepted Composer vendor may have been copied from a
    # root-only QA tree (umask 077). Production PHP-FPM runs as www, so
    # make only the COPIED vendor tree non-writable but traversable/readable.
    if vendor_root.is_symlink() or not vendor_root.is_dir():
        raise Stop("NEW_VENDOR_TYPE_UNSAFE")
    for root,dirs,files in os.walk(vendor_root,followlinks=False):
        for name in ["."]+dirs+files:
            path=Path(root) if name=="." else Path(root)/name
            if path.is_symlink():continue
            st=path.lstat()
            if stat.S_ISDIR(st.st_mode):
                path.chmod((stat.S_IMODE(st.st_mode)|0o555)&~0o022)
            elif stat.S_ISREG(st.st_mode):
                path.chmod((stat.S_IMODE(st.st_mode)|0o444)&~0o022)
            else:raise Stop("COPIED_VENDOR_SPECIAL_FILE")
    try:
        ok=subprocess.run(["runuser","-u","www","--","/usr/bin/test","-r",str(vendor_root/"autoload.php")],
            capture_output=True,timeout=10,check=False)
    except (OSError,subprocess.TimeoutExpired):raise Stop("WWW_VENDOR_CHECK_FAILED")
    if ok.returncode!=0:raise Stop("WWW_VENDOR_UNREADABLE")


def require_local_operator_confirmation(phrase):
    # Read the EXISTING SSH terminal fds. Some aaPanel shells have a real
    # /dev/pts/N for stdin but cannot reopen /dev/tty.
    # Never accept piped heredoc input or skip the typed operator approval.
    if not sys.stdin.isatty() or not sys.stdout.isatty():
        raise Stop("OPERATOR_INTERACTIVE_TTY_REQUIRED")
    try:
        if not os.isatty(sys.stdin.fileno()) or not os.isatty(sys.stdout.fileno()):
            raise Stop("OPERATOR_INTERACTIVE_TTY_REQUIRED")
        sys.stdout.write("Type "+phrase+": ")
        sys.stdout.flush()
        response=sys.stdin.readline()
    except (OSError,ValueError,TypeError):
        raise Stop("OPERATOR_TERMINAL_READ_FAILED")
    if not response:
        raise Stop("OPERATOR_TERMINAL_EOF")
    if response.strip()!=phrase:
        raise Stop("OPERATOR_NOT_CONFIRMED")


def install():
    entries=safe_patch_inventory()
    print("MCP_ONLY_LIVE_INSTALL_PREFLIGHT=PASS")
    print("MCP_ONLY_OPERATOR_CONFIRMATION=REQUIRED_IN_LOCAL_TERMINAL",flush=True)
    require_local_operator_confirmation("INSTALL-BAMBODIA-MCP-RUNTIME")
    lock=STAGE/"mcp-deployment.lock"
    fd=os.open(lock,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
    runid=uuid.uuid4().hex
    backup=Path(tempfile.mkdtemp(prefix="Bambodia-MCP-LiveRollback.",dir="/root"))
    backup.chmod(0o700)
    oldvendor=LIVE/(".mcp-previous-vendor-"+runid)
    newvendor=LIVE/(".mcp-next-vendor-"+runid)
    previous={}
    maintenance=False
    mutation=False
    phase="PREPARE_NEW_VENDOR"
    try:
        # Pre-stage the vendor on the same filesystem; never copy production
        # vendor over the independently accepted candidate.
        if oldvendor.exists() or newvendor.exists():
            raise Stop("VENDOR_STAGE_COLLISION")
        shutil.copytree(PRIVATE/"source/vendor",newvendor,symlinks=True)
        prepare_vendor_permissions(newvendor)
        phase="SNAPSHOT_ORIGINAL_SOURCE"
        previous=snapshot_originals(backup,entries)
        (backup/"rollback.json").write_text(json.dumps({
          "state":"READY","old_vendor":str(oldvendor),
          "new_vendor":str(newvendor),"original":previous},indent=2)+"\n")
        (backup/"rollback.json").chmod(0o600)
        phase="REVALIDATE_LIVE_BEFORE_MAINTENANCE"
        safe_patch_inventory()
        print("MCP_ONLY_ROLLBACK_SNAPSHOT=READY")
        phase="ENTER_MAINTENANCE"
        as_www("down","--no-ansi","--no-interaction")
        maintenance=True
        phase="MOVE_ORIGINAL_VENDOR"
        os.rename(LIVE/"vendor",oldvendor)
        mutation=True
        phase="ACTIVATE_NEW_VENDOR"
        os.rename(newvendor,LIVE/"vendor")
        phase="INSTALL_MCP_SOURCE_PATCH"
        for rel in entries:
            target=guarded_path(LIVE,rel)
            mode=previous[rel].get("mode",0o644)
            uid=previous[rel].get("uid",pwd.getpwnam("www").pw_uid)
            gid=previous[rel].get("gid",pwd.getpwnam("www").pw_gid)
            write_atomic(target,read_file(guarded_path(STAGE/"patch",rel)),mode,uid,gid)
        phase="INSTALL_COMPOSER_MANIFESTS"
        for name in ("composer.json","composer.lock"):
            record=previous[name]
            write_atomic(LIVE/name,read_file(STAGE/name),record["mode"],record["uid"],record["gid"])
        phase="VERIFY_MCP_FLAGS_DISABLED"
        env_flag_off()
        phase="VERIFY_LIVE_PHP85_MCP_OAUTH_ROUTES"
        verify_routes()
        print("MCP_ONLY_PHP85_LIVE_ROUTE_REGISTRATION=PASS")
        state=json.loads(read_file(backup/"rollback.json",65536))
        state["state"]="INSTALLED_FLAGS_OFF"
        (backup/"rollback.json").write_text(json.dumps(state,indent=2)+"\n")
        (backup/"rollback.json").chmod(0o600)
        phase="EXIT_MAINTENANCE"
        as_www("up","--no-ansi","--no-interaction")
        maintenance=False
        print("MCP_ONLY_RUNTIME_INSTALL=PASS")
        print("MCP_ONLY_ROLLBACK_PATH="+str(backup))
        print("MCP_ONLY_FEATURE_FLAGS=OFF")
        print("MCP_ONLY_OAUTH_MIGRATIONS=NOT_PERFORMED")
        print("MCP_ONLY_PASSPORT_KEYS=NOT_GENERATED")
        print("MCP_ONLY_CHATGPT_CONNECT=NOT_YET_VERIFIED")
        return 0
    except Exception:
        # Never print exception text, environment, SQL, stack traces or tokens.
        print("MCP_ONLY_RUNTIME_FAILURE_PHASE="+phase)
        print("MCP_ONLY_RUNTIME_INSTALL=FAILED_ROLLBACK_ATTEMPTED")
        if mutation:
            try:
                rollback(backup,previous,oldvendor,newvendor)
                print("MCP_ONLY_SOURCE_VENDOR_ROLLBACK=PASS")
            except Exception:
                print("MCP_ONLY_SOURCE_VENDOR_ROLLBACK=CRITICAL_MANUAL_REVIEW")
                print("MCP_ONLY_ROLLBACK_PATH="+str(backup))
                return 5
        else:
            if newvendor.exists():shutil.rmtree(newvendor)
        if maintenance:
            try:
                as_www("up","--no-ansi","--no-interaction")
                print("MCP_ONLY_MAINTENANCE_RECOVERY=PASS")
            except Exception:
                print("MCP_ONLY_MAINTENANCE_RECOVERY=CRITICAL_MANUAL_REVIEW")
                return 6
        return 2
    finally:
        os.close(fd)

def manual_rollback(directory):
    # Only a release produced by this exact installer may be rolled back.
    if os.geteuid()!=0 or not directory.is_relative_to(Path("/root")) or \
       not directory.name.startswith("Bambodia-MCP-LiveRollback.") or not private_dir(directory):
        raise Stop("ROLLBACK_DIRECTORY_UNSAFE")
    obj=json.loads(read_file(directory/"rollback.json",65536))
    if obj.get("state")!="INSTALLED_FLAGS_OFF":
        raise Stop("ROLLBACK_NOT_IN_INSTALLED_STATE")
    original=obj.get("original",{})
    if not isinstance(original,dict) or set(original)!=set(json.loads(read_file(STAGE/"review.json",65536))["mcp_only_patch_sha256"])|{"composer.json","composer.lock"}:
        raise Stop("ROLLBACK_SOURCE_MANIFEST_CHANGED")
    if sha(read_file(LIVE/"composer.lock"))!=NEW_LOCK_SHA:
        raise Stop("LIVE_CHANGED_AFTER_MCP_INSTALL")
    for rel in json.loads(read_file(STAGE/"review.json",65536))["mcp_only_patch_sha256"]:
        if sha(read_file(guarded_path(LIVE,rel)))!=sha(read_file(guarded_path(STAGE/"patch",rel))):
            raise Stop("LIVE_SOURCE_EDITED_SINCE_INSTALL")
    env_flag_off()
    oldvendor=Path(obj.get("old_vendor",""))
    newvendor=Path(obj.get("new_vendor",""))
    if oldvendor.parent!=LIVE or newvendor.parent!=LIVE or \
       not re.fullmatch(r"\.mcp-previous-vendor-[0-9a-f]{32}",oldvendor.name) or \
       not re.fullmatch(r"\.mcp-next-vendor-[0-9a-f]{32}",newvendor.name):
        raise Stop("ROLLBACK_VENDOR_PATH_UNSAFE")
    if not oldvendor.is_dir() or oldvendor.is_symlink() or newvendor.exists():
        raise Stop("ROLLBACK_OLD_VENDOR_MISSING")
    require_local_operator_confirmation("ROLLBACK-BAMBODIA-MCP-RUNTIME")
    as_www("down","--no-ansi","--no-interaction")
    try:
        rollback(directory,original,oldvendor,newvendor)
        as_www("up","--no-ansi","--no-interaction")
        obj["state"]="ROLLED_BACK"
        (directory/"rollback.json").write_text(json.dumps(obj,indent=2)+"\n")
        (directory/"rollback.json").chmod(0o600)
    except Exception:
        print("MCP_ONLY_MANUAL_ROLLBACK=CRITICAL_MANUAL_REVIEW")
        print("MCP_ONLY_MAINTENANCE=LEFT_ENABLED")
        return 5
    print("MCP_ONLY_MANUAL_ROLLBACK=PASS")
    print("MCP_ONLY_OAUTH_TABLES=NOT_DROPPED")
    print("MCP_ONLY_MCP_FLAGS=OFF")
    return 0


def main():
    args=sys.argv[1:]
    if args==["--install"]:mode="install"
    elif len(args)==2 and args[0]=="--rollback":mode="rollback"
    else:
        print("USAGE=--install | --rollback /root/Bambodia-MCP-LiveRollback.<id>")
        return 64
    try:
        if mode=="install":return install()
        return manual_rollback(Path(args[1]))
    except Stop as error:
        # Stop reason labels are constant, locally authored safety markers.
        # Never print generic OS exceptions, token values or .env content.
        print("MCP_ONLY_RUNTIME_BLOCKER="+str(error))
        print("MCP_ONLY_RUNTIME_ACTION=BLOCKED")
        return 1
    except (OSError,ValueError,TypeError,KeyError,RuntimeError):
        print("MCP_ONLY_RUNTIME_BLOCKER=UNCLASSIFIED_LOCAL_REVIEW_REQUIRED")
        print("MCP_ONLY_RUNTIME_ACTION=BLOCKED")
        return 1
if __name__=="__main__":sys.exit(main())
