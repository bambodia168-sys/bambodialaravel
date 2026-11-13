#!/usr/bin/env bash
# Private-only MCP runtime route acceptance. No DB migrations or live writes.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--verify" ]] || exit 64
APP=/root/Bambodia-MCP-Main-Overlay.ApguAhIY/source
REPORT=/root/Bambodia-MCP-Main-Overlay.ApguAhIY/review/mcp-private-critical-reconciliation.json
PHP=/www/server/php/85/bin/php
fail() { echo "PRIVATE_MCP_ROUTE_BLOCKED=$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
[[ -d "$APP" && ! -L "$APP" && ! -L "$APP/storage" && ! -L "$APP/bootstrap" ]] || fail PRIVATE_PATH_UNSAFE
[[ -f "$APP/artisan" && ! -L "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$REPORT" && ! -L "$REPORT" ]] || fail PRIVATE_SOURCE_MISSING
[[ ! -e "$APP/.env" && ! -e "$APP/.env.testing" && ! -e "$APP/bootstrap/cache/config.php" ]] || fail PRIVATE_ENV_UNSAFE
if find "$APP/bootstrap/cache" -maxdepth 1 -name "routes-*.php" -print -quit | grep -q .; then fail PRIVATE_CACHED_ROUTES_PRESENT; fi
[[ -x "$PHP" ]] || fail PHP_85_MISSING
python3 - "$REPORT" <<'PY'
import json, pathlib, sys, hashlib
p=pathlib.Path(sys.argv[1]);r=json.loads(p.read_text())
source=p.parent.parent/"source"; review=p.parent/"live-critical";live=pathlib.Path("/www/wwwroot/bambodia-staging-qa")
addition=(
 b"        Route::get('/developer/mcp-connection', [\\App\\Http\\Controllers\\Developer\\McpConnectionCenterController::class, 'index'])\n"
 b"            ->name('developer.mcp.index');\n"
 b"        Route::get('/developer/oauth-connections', [\\App\\Http\\Controllers\\Developer\\OAuthConnectionController::class, 'index'])\n"
 b"            ->name('developer.oauth.index');\n"
 b"        Route::delete('/developer/oauth-connections/{token}', [\\App\\Http\\Controllers\\Developer\\OAuthConnectionController::class, 'destroy'])\n"
 b"            ->name('developer.oauth.revoke')->middleware('throttle:8,1');\n"
)
extra=p.parent/"mcp-private-developer-routes.json"
assert extra.is_file() and not extra.is_symlink()
x=json.loads(extra.read_text())
route=source/"routes/web.php"
assert route.is_file() and not route.is_symlink()
data=route.read_bytes()
sha=lambda b:hashlib.sha256(b).hexdigest()
assert x.get("private_overlay_only") is True
assert x.get("production_activation") is False and x.get("production_deployment") is False
assert x.get("inserted_developer_routes")==3 and x.get("all_other_route_bytes_preserved") is True
assert x.get("base_route_sha256")==r.get("route_sha256")
assert x.get("addition_sha256")==sha(addition)
assert data.count(addition)==1 and x.get("route_sha256")==sha(data)
assert sha(data.replace(addition,b"",1))==r.get("route_sha256")
assert (live/"routes/web.php").read_bytes()==(review/"routes/web.php").read_bytes()
for rel,hash_key in [("app/Http/Controllers/Api/BambodiaMcpController.php","controller_sha256"),("app/Support/Mcp/BambodiaTools.php","draft_tools_sha256")]:
 f=source/rel; l=live/rel; snapshot=review/rel
 assert f.is_file() and not f.is_symlink()
 assert hashlib.sha256(f.read_bytes()).hexdigest()==r.get(hash_key),rel
 assert l.is_file() and not l.is_symlink() and snapshot.is_file()
 assert l.read_bytes()==snapshot.read_bytes(),rel
layout="resources/views/layouts/workspace.blade.php"
assert hashlib.sha256((source/layout).read_bytes()).hexdigest()=="01154b97c1418441ddf0fcf60e50e81bbf5208a9265ae428550c2c7a2749beda"
assert (live/layout).read_bytes()==(review/layout).read_bytes()
assert r.get("private_overlay_only") is True
assert r.get("production_deployment") is False
assert r.get("production_activation") is False
print("PRIVATE_RECONCILIATION_REPORT=VERIFIED")
print("LIVE_AND_PRIVATE_MCP_SOURCE_INTEGRITY=PASS")
PY
cd "$APP"
KEY="base64:$(openssl rand -base64 32)"
ENV=(env -i "PATH=$PATH" "HOME=/root" "APP_ENV=testing" "APP_DEBUG=false"
 "APP_URL=https://bambodia.com" "APP_KEY=$KEY"
 "BAMBODIA_MCP_ENABLED=false" "BAMBODIA_MCP_WRITES_ENABLED=false"
 "DB_CONNECTION=sqlite" "DB_DATABASE=:memory:" "DB_URL="
 "CACHE_STORE=array" "SESSION_DRIVER=array" "QUEUE_CONNECTION=sync"
 "MAIL_MAILER=array" "LOG_CHANNEL=stderr")
unset KEY
"${ENV[@]}" "$PHP" -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("mcp.production_enabled")!==false ||
   config("mcp.production_writes_enabled")!==false ||
   \App\Support\Mcp\McpResource::uri()!=="https://bambodia.com/mcp") exit(7);
echo "MCP_RUNTIME_GATES=DISABLED\n";
'
ROUTES=$(mktemp /root/Bambodia-MCP-Main-Overlay.ApguAhIY/review/.routes.XXXXXXXX)
trap 'rm -f -- "$ROUTES"' EXIT
"${ENV[@]}" "$PHP" artisan route:list --json --no-ansi > "$ROUTES"
python3 - "$ROUTES" <<'PY'
import json,sys
a=json.load(open(sys.argv[1]))
def route(method,uri):
 return [r for r in a if r.get("uri","").strip("/")==uri and method in r.get("method","").split("|")]
m=route("POST","mcp")
assert len(m)==1 and m[0].get("name")=="mcp"
assert "mcp.auth" in str(m[0].get("middleware"))
for verb,uri in [("GET",".well-known/oauth-protected-resource"),("GET",".well-known/oauth-protected-resource/mcp"),("GET",".well-known/oauth-authorization-server"),("GET","oauth/authorize"),("POST","oauth/register"),("POST","oauth/token")]:
 assert len(route(verb,uri))==1,uri
assert not route("GET","mcp")
for method,uri,name in [
 ("GET","developer/mcp-connection","developer.mcp.index"),
 ("GET","developer/oauth-connections","developer.oauth.index"),
 ("DELETE","developer/oauth-connections/{token}","developer.oauth.revoke")
]:
 rows=route(method,uri)
 assert len(rows)==1 and rows[0].get("name")==name,uri
 assert "role:super_admin" in str(rows[0].get("middleware")),uri
print("MCP_DEVELOPER_OAUTH_ADMIN_ROUTES=PASS")
print("PRIVATE_ROUTE_COUNT="+str(len(a)))
print("MCP_AND_OAUTH_ROUTE_REGISTRATION=PASS")
PY
echo "MCP_PRIVATE_RUNTIME_ACCEPTANCE=PASS"
echo "OAUTH_DATABASE_AND_LIVE_CHATGPT_ACCEPTANCE=NOT_STARTED"
echo "PRODUCTION_MCP_ACTIVATION=NOT_STARTED"
