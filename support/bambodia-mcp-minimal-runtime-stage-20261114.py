#!/usr/bin/env python3
"""Build a production-safe MCP-ONLY private install candidate from the tested
live-first source. Never write live, migrate databases, generate OAuth keys,
change Nginx or activate MCP. Refuse any unreconciled live source drift.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tempfile

LIVE=Path("/www/wwwroot/bambodia-staging-qa")
CANDIDATE=Path("/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp")
VHOST=Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
VHOST_SHA="5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116"
LOCK_SHA="4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8"
OUT_PREFIX="/root/Bambodia-MCP-Only-Install."
CRITICAL=(
 "app/Http/Controllers/Api/BambodiaMcpController.php",
 "app/Http/Middleware/SetLocale.php",
 "app/Models/User.php",
 "app/Providers/AppServiceProvider.php",
 "app/Support/Mcp/BambodiaTools.php",
 "config/auth.php",
 "resources/views/developer/index.blade.php",
 "resources/views/layouts/workspace.blade.php",
 "routes/web.php",
)
# Only MCP/OAuth-specific source and reviewed integration touchpoints.
# Explicitly excludes recovery, preview-visibility, homepage, blog & market.
PATCH=(
 "app/Http/Controllers/Api/BambodiaMcpController.php",
 "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php",
 "app/Http/Controllers/Developer/McpConnectionCenterController.php",
 "app/Http/Controllers/Developer/OAuthConnectionController.php",
 "app/Http/Controllers/DeveloperController.php",
 "app/Http/Middleware/AuthenticateMcpToken.php",
 "app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php",
 "app/Http/Middleware/RequireMcpOAuthResource.php",
 "app/Http/Middleware/SetLocale.php",
 "app/Models/ApiToken.php",
 "app/Models/AuditEvent.php",
 "app/Models/User.php",
 "app/Providers/AppServiceProvider.php",
 "app/Support/Mcp/AdministrationTools.php",
 "app/Support/Mcp/BambodiaPassportAccessToken.php",
 "app/Support/Mcp/BambodiaTools.php",
 "app/Support/Mcp/DraftMediaTools.php",
 "app/Support/Mcp/McpAccessGrant.php",
 "app/Support/Mcp/McpResource.php",
 "bootstrap/app.php",
 "config/auth.php",
 "config/mcp.php",
 "routes/web.php",
 "resources/views/auth/oauth/authorize.blade.php",
 "resources/views/developer/index.blade.php",
 "resources/views/developer/mcp-connection.blade.php",
 "resources/views/developer/oauth-connections.blade.php",
 "resources/views/layouts/workspace.blade.php",
 "public/css/mcp-connection-center.css",
 "public/css/mcp-oauth-connections.css",
 "database/migrations/2026_11_11_00001_create_oauth_auth_codes_table.php",
 "database/migrations/2026_11_11_00002_create_oauth_access_tokens_table.php",
 "database/migrations/2026_11_11_00003_create_oauth_refresh_tokens_table.php",
 "database/migrations/2026_11_11_00004_create_oauth_clients_table.php",
 "database/migrations/2026_11_11_00005_create_oauth_device_codes_table.php",
 "database/migrations/2026_11_11_999999_add_bambodia_mcp_scopes_to_oauth_clients.php",
)
# Accepted PHP-8.5 physical QA captured these exact source blobs.
REVIEWED_BLOBS={
 "bootstrap/app.php":"48b931f09aa0c21cc05705396a06272f85b2be8d",
 "app/Http/Middleware/RequireMcpOAuthResource.php":"ae8922d4a1a82a5dabf11df4877d5aab8fb8093d",
 "app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php":"641e625f4a12765a3e8da54916235ccb7b337835",
 "config/mcp.php":"215d9b15714e0a6b687191bdab44af54ace10450",
 "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php":"290f3d66998315235c9c6350e3b878d458344331",
}
class Stop(Exception):
    pass

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def blob(data):
    return hashlib.sha1(f"blob {len(data)}\0".encode()+data).hexdigest()

def checked_file(root,rel,required=True):
    p=root/rel
    if p.is_symlink() or any(x.is_symlink() for x in p.parents if x==root or root in x.parents):
        raise Stop("SYMLINK_PATH")
    try:
        s=p.lstat()
    except FileNotFoundError:
        if not required:return None
        raise Stop("REQUIRED_CODE_MISSING")
    if not stat.S_ISREG(s.st_mode) or s.st_size>10*1024*1024:
        raise Stop("UNSAFE_CODE_FILE_TYPE_SIZE")
    return p.read_bytes()

def private_root(p):
    s=p.lstat()
    return stat.S_ISDIR(s.st_mode) and s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700 and not p.is_symlink()

def inventory():
    if os.geteuid()!=0:raise Stop("ROOT_REQUIRED")
    if not private_root(CANDIDATE) or not (CANDIDATE/"source/vendor/autoload.php").is_file():
        raise Stop("PRIVATE_ACCEPTED_SOURCE_MISSING")
    if not (LIVE/"artisan").is_file() or not (LIVE/"storage/app/installed.lock").is_file():
        raise Stop("LIVE_SITE_NOT_INSTALLED")
    if sha256(checked_file(VHOST.parent,VHOST.name))!=VHOST_SHA:
        raise Stop("LIVE_VHOST_CHANGED")
    if sha256(checked_file(CANDIDATE/"source","composer.lock"))!=LOCK_SHA:
        raise Stop("CANDIDATE_COMPOSER_LOCK_CHANGED")
    if not (CANDIDATE/"source/vendor/laravel/mcp").is_dir() or not (CANDIDATE/"source/vendor/laravel/passport").is_dir():
        raise Stop("PRIVATE_MCP_PASSPORT_VENDOR_MISSING")
    for path,sha in REVIEWED_BLOBS.items():
        if blob(checked_file(CANDIDATE/"source",path))!=sha:
            raise Stop("REVIEWED_OAUTH_SOURCE_CHANGED")
    review=CANDIDATE/"review/live-critical"
    for path in CRITICAL:
        if checked_file(LIVE,path)!=checked_file(review,path):
            raise Stop("LIVE_PROTECTED_MCP_SOURCE_DRIFT")
    # Global homepage/blog/Vite assets are NOT part of the MCP-only patch.
    # Protect only the nine live source files MCP actually touches.
    # Later isolated tests must still verify the resulting application pages.
    source_hashes={}
    existing_changes=[]
    for rel in PATCH:
        data=checked_file(CANDIDATE/"source",rel)
        source_hashes[rel]=sha256(data)
        old=checked_file(LIVE,rel,required=False)
        if old is not None and old!=data:
            existing_changes.append(rel)
    return source_hashes,existing_changes

def stage():
    hashes,changes=inventory()
    print("MCP_ONLY_PROTECTED_LIVE_SOURCE=UNCHANGED")
    print("MCP_ONLY_UNRELATED_GLOBAL_FRONTEND=NOT_COPIED_OR_MODIFIED")
    print("MCP_ONLY_PATCH_FILES_VERIFIED="+str(len(hashes)))
    print("MCP_ONLY_EXISTING_FILES_DIFFER="+str(len(changes)))
    # Existing-file changes are expected (e.g. routes/User). Carry evidence
    # for review; never approve or apply the diff automatically.
    destination=Path(tempfile.mkdtemp(prefix="Bambodia-MCP-Only-Install.",dir="/root"))
    os.chmod(destination,0o700)
    patchdir=destination/"patch"
    patchdir.mkdir(mode=0o700)
    try:
        for rel in PATCH:
            data=checked_file(CANDIDATE/"source",rel)
            target=patchdir/rel
            target.parent.mkdir(parents=True,exist_ok=True)
            with target.open("xb") as output:
                output.write(data)
            os.chmod(target,0o600)
            if sha256(target.read_bytes())!=hashes[rel]:
                raise Stop("PATCH_COPY_MISMATCH")
        for name in ("composer.json","composer.lock"):
            source=checked_file(CANDIDATE/"source",name)
            p=destination/name
            p.write_bytes(source)
            os.chmod(p,0o600)
        report={
          "state":"PRIVATE_STAGED_UNAPPROVED_NO_DEPLOY",
          "candidate":str(CANDIDATE),
          "live_root":str(LIVE),
          "mcp_only_patch_sha256":hashes,
          "existing_file_differences_require_code_review":changes,
          "composer_lock_sha256":LOCK_SHA,
          "vendor_source_reference":str(CANDIDATE/"source/vendor"),
          "vendor_was_copied":False,
          "production_files_changed":False,
          "production_migrations":False,
          "mcp_activated":False,
        }
        p=destination/"review.json"
        p.write_text(json.dumps(report,indent=2)+"\n")
        os.chmod(p,0o600)
        print("MCP_ONLY_PRIVATE_STAGE=CREATED")
        print("MCP_ONLY_STAGE_PATH="+str(destination))
        print("MCP_ONLY_INSTALLABLE_RELEASE=NOT_YET_APPROVED")
        print("MCP_ONLY_PRODUCTION_CHANGED=NO")
    except Exception:
        print("MCP_ONLY_PRIVATE_STAGE=INCOMPLETE_REVIEW_REQUIRED")
        raise

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--stage",action="store_true",required=True)
    args=parser.parse_args()
    if args.stage: stage()
    print("MCP_ONLY_PRODUCTION_DEPLOY=NOT_PERFORMED")
    print("MCP_ONLY_OAUTH_MIGRATIONS=NOT_PERFORMED")
    print("MCP_ONLY_FEATURE_FLAG=NOT_CHANGED")

if __name__=="__main__":
    try: main()
    except (Stop,OSError,UnicodeError,ValueError,KeyError) as e:
        reason=str(e) if isinstance(e,Stop) else "LOCAL_PRIVATE_REVIEW_REQUIRED"
        print("MCP_ONLY_RUNTIME_STAGE_BLOCKED="+reason[:100].replace(" ","_"))
        print("MCP_ONLY_PRODUCTION_CHANGED=NO")
        sys.exit(1)
