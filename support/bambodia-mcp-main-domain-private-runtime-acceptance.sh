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
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$REPORT" ]] || fail PRIVATE_SOURCE_MISSING
[[ ! -f "$APP/.env" && ! -f "$APP/.env.testing" && ! -f "$APP/bootstrap/cache/config.php" ]] || fail PRIVATE_ENV_UNSAFE
[[ -x "$PHP" ]] || fail PHP_85_MISSING
python3 - "$REPORT" <<'PY'
import json, pathlib, sys
r=json.loads(pathlib.Path(sys.argv[1]).read_text())
assert r.get("private_overlay_only") is True
assert r.get("production_deployment") is False
assert r.get("production_activation") is False
print("PRIVATE_RECONCILIATION_REPORT=VERIFIED")
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
print("PRIVATE_ROUTE_COUNT="+str(len(a)))
print("MCP_AND_OAUTH_ROUTE_REGISTRATION=PASS")
PY
echo "MCP_PRIVATE_RUNTIME_ACCEPTANCE=PASS"
echo "OAUTH_DATABASE_AND_LIVE_CHATGPT_ACCEPTANCE=NOT_STARTED"
echo "PRODUCTION_MCP_ACTIVATION=NOT_STARTED"
