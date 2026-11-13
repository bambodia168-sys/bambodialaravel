#!/usr/bin/env bash
# Reconcile three Developer routes into the existing, live-first PRIVATE MCP overlay.
# Never writes to live Bambodia, production keys, Nginx or the original report.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--private-only" ]] || {
  echo "Usage: bash script --private-only" >&2; exit 64;
}
OVERLAY=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
SOURCE="$OVERLAY/source"
REVIEW="$OVERLAY/review"
LIVE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
fail(){ echo "MCP_PRIVATE_DEVELOPER_ROUTES_BLOCKED=$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
[[ -x "$PHP" && -d "$OVERLAY" && ! -L "$OVERLAY" ]] || fail PRIVATE_OVERLAY_MISSING
[[ "$(stat -c '%u:%a' "$OVERLAY")" == "0:700" ]] || fail PRIVATE_OVERLAY_PERMISSIONS
[[ -d "$SOURCE" && ! -L "$SOURCE" && -d "$REVIEW" && ! -L "$REVIEW" ]] || fail PRIVATE_PATH_UNSAFE
[[ -f "$SOURCE/artisan" && -f "$SOURCE/vendor/autoload.php" ]] || fail PRIVATE_SOURCE_INCOMPLETE
[[ ! -e "$SOURCE/.env" && ! -e "$SOURCE/.env.testing" && ! -e "$SOURCE/bootstrap/cache/config.php" ]] || fail PRIVATE_RUNTIME_SECRETS_OR_CACHE
[[ -f "$LIVE/storage/app/installed.lock" && ! -L "$LIVE" ]] || fail LIVE_INSTALL_CHANGED
[[ "$(sha256sum /www/server/panel/vhost/nginx/bambodia.com.conf | cut -d' ' -f1)" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] || fail LIVE_VHOST_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | cut -d' ' -f1)" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] || fail LIVE_LOCK_CHANGED
echo "========== RECONCILE ONLY PRIVATE DEVELOPER OAUTH ROUTES =========="
python3 - "$OVERLAY" "$LIVE" <<'PY'
import hashlib,json,os,pathlib,stat,sys,tempfile,subprocess
overlay,live=map(pathlib.Path,sys.argv[1:])
source=overlay/"source";review=overlay/"review"
primary=review/"mcp-private-critical-reconciliation.json"
supplement=review/"mcp-private-developer-routes.json"
route="routes/web.php"
anchor=b"        Route::get('/developer/docs', [DeveloperController::class, 'documentation'])->name('developer.docs');\n"
addition=(
 b"        Route::get('/developer/mcp-connection', [\\App\\Http\\Controllers\\Developer\\McpConnectionCenterController::class, 'index'])\n"
 b"            ->name('developer.mcp.index');\n"
 b"        Route::get('/developer/oauth-connections', [\\App\\Http\\Controllers\\Developer\\OAuthConnectionController::class, 'index'])\n"
 b"            ->name('developer.oauth.index');\n"
 b"        Route::delete('/developer/oauth-connections/{token}', [\\App\\Http\\Controllers\\Developer\\OAuthConnectionController::class, 'destroy'])\n"
 b"            ->name('developer.oauth.revoke')->middleware('throttle:8,1');\n"
)
def fail(msg):raise SystemExit("MCP_PRIVATE_DEVELOPER_ROUTES_BLOCKED="+msg)
def safe(path):
 if not path.is_file() or path.is_symlink():fail("MISSING_OR_UNSAFE_"+str(path))
 return path.read_bytes()
def sha(data):return hashlib.sha256(data).hexdigest()
def git_blob(data):
 return hashlib.sha1(b"blob "+str(len(data)).encode()+b"\x00"+data).hexdigest()
p=json.loads(safe(primary))
if (p.get("private_overlay_only") is not True or p.get("production_deployment") is not False
 or p.get("production_activation") is not False):fail("PRIMARY_REPORT_UNSAFE")
expect={
 "app/Http/Controllers/Developer/OAuthConnectionController.php":"77c4ff4393ad4a6e0efbfc0e90a32515aa545465",
 "app/Http/Controllers/Developer/McpConnectionCenterController.php":"5b6bd7a594bcf91a86bfe1aac86894f5c7516634",
 "resources/views/developer/oauth-connections.blade.php":"a133fcc2ef8d02ea5f0e99a25424fefb4c85df98",
 "resources/views/developer/mcp-connection.blade.php":"b9f4f22c7bc19c2363ab55735c536ffa3c3b8f98",
 "public/css/mcp-oauth-connections.css":"4afb56e9084180ef044256a2d264b4b5c5e45eef",
 "public/css/mcp-connection-center.css":"c63fe6236b95d13b563ed43081ad03ab3a3f62cf"
}
for name,blob in expect.items():
 if git_blob(safe(source/name))!=blob:fail("DEPENDENCY_BLOB_DRIFT_"+name)
for name,key in [
 ("app/Http/Controllers/Api/BambodiaMcpController.php","controller_sha256"),
 ("app/Support/Mcp/BambodiaTools.php","draft_tools_sha256")
]:
 if sha(safe(source/name))!=p.get(key):fail("PRIVATE_CRITICAL_DRIFT_"+key)
 if safe(live/name)!=safe(review/"live-critical"/name):fail("LIVE_CRITICAL_DRIFT_"+key)
if safe(live/route)!=safe(review/"live-critical"/route):fail("LIVE_ROUTE_DRIFT")
layout="resources/views/layouts/workspace.blade.php"
if sha(safe(source/layout))!="01154b97c1418441ddf0fcf60e50e81bbf5208a9265ae428550c2c7a2749beda":fail("PRIVATE_LAYOUT_DRIFT")
if safe(live/layout)!=safe(review/"live-critical"/layout):fail("LIVE_LAYOUT_DRIFT")
old=safe(source/route)
if old.count(anchor)!=1:fail("DEVELOPER_DOCS_ANCHOR_NOT_UNIQUE")
if old.count(addition)>1:fail("DEVELOPER_ROUTES_DUPLICATE")
if old.count(b"Route::middleware('role:super_admin')->group(function (): void {")<1:fail("ADMIN_GROUP_MISSING")
section=old[:old.index(anchor)]
if section.rfind(b"Route::middleware('role:super_admin')->group(function (): void {") < section.rfind(b"Route::middleware('auth')->group(function (): void {"):
 # The super-admin guard must be nested inside the relevant auth group.
 pass
else:fail("ADMIN_ROLE_GROUP_NOT_NESTED")
for marker in (b"Route::get('/developer/mcp-connection'",b"Route::get('/developer/oauth-connections'",b"Route::delete('/developer/oauth-connections/"):
 if old.count(marker)!=(1 if addition in old else 0):fail("UNEXPECTED_EXISTING_DEVELOPER_ROUTE")
if addition in old:
 candidate=old
 base=old.replace(addition,b"",1)
else:
 candidate=old.replace(anchor,anchor+addition,1)
 base=old
if sha(base)!=p.get("route_sha256"):fail("PRIVATE_BASE_ROUTE_DRIFT")
if candidate.replace(addition,b"",1)!=base:fail("UNRELATED_PRIVATE_ROUTE_BYTES_CHANGED")
if candidate.count(addition)!=1:fail("INSERTION_NOT_EXACTLY_ONCE")
result={
 "base_route_sha256":p["route_sha256"],
 "route_sha256":sha(candidate),
 "inserted_developer_routes":3,
 "addition_sha256":sha(addition),
 "private_overlay_only":True,
 "production_deployment":False,
 "production_activation":False,
 "all_other_route_bytes_preserved":True
}
encoded=(json.dumps(result,indent=2,sort_keys=True)+"\n").encode()
if supplement.exists() or supplement.is_symlink():
 if safe(supplement)!=encoded:fail("SUPPLEMENT_REPORT_DRIFT")
if old!=candidate:
 target=source/route
 if target.is_symlink():fail("PRIVATE_ROUTE_SYMLINK")
 fd,temp=tempfile.mkstemp(prefix=".mcp-developer-routes-",dir=target.parent)
 try:
  os.fchmod(fd,target.stat().st_mode&0o777)
  with os.fdopen(fd,"wb") as output:
   output.write(candidate);output.flush();os.fsync(output.fileno())
  os.replace(temp,target)
 finally:
  if os.path.exists(temp):os.unlink(temp)
if safe(source/route)!=candidate:fail("PRIVATE_ROUTE_POSTWRITE_DRIFT")
# Keep the primary report immutable. A separate review report attests the
# strictly additive route reconciliation; no production file was written.
if not supplement.exists():
 fd,temp=tempfile.mkstemp(prefix=".mcp-developer-report-",dir=review)
 try:
  os.fchmod(fd,0o600)
  with os.fdopen(fd,"wb") as output:
   output.write(encoded);output.flush();os.fsync(output.fileno())
  os.replace(temp,supplement)
 finally:
  if os.path.exists(temp):os.unlink(temp)
if safe(supplement)!=encoded:fail("SUPPLEMENT_WRITE_VERIFICATION_FAILED")
print("MCP_DEVELOPER_PRIVATE_ROUTE_RECONCILIATION=PASS")
print("MCP_DEVELOPER_ADMIN_ROUTES_ADDED=3")
print("ORIGINAL_PRIVATE_CRITICAL_REPORT=UNCHANGED")
print("PRIVATE_ROUTE_NON_DEVELOPER_BYTES=PRESERVED_EXACTLY")
print("LIVE_ROUTE_BYTES=UNCHANGED_VERIFIED")
print("MCP_DEVELOPER_SUPPLEMENT_REPORT="+str(supplement))
PY
"$PHP" -l "$SOURCE/routes/web.php" >/dev/null
echo "MCP_PRIVATE_DEVELOPER_PHP_SYNTAX=PASS"
echo "PRODUCTION_SOURCE_DATABASE_NGINX=UNCHANGED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
