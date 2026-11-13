#!/usr/bin/env bash
# Disposable PHP 8.5 OAuth acceptance. Never touches production.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 2 && "$1" == "--isolated-loopback-qa" ]] || {
 echo "Usage: --isolated-loopback-qa /root/verified-client.py" >&2; exit 64;
}
CLIENT_SOURCE="$2"
CLIENT_BLOB="5ed0d6255b064929f0848d7776b4a80090dbffd3"
[[ "$CLIENT_SOURCE" == /root/* && -f "$CLIENT_SOURCE" && ! -L "$CLIENT_SOURCE" ]] || {
 echo "MCP_CHATGPT_QA_BLOCKED=CLIENT_PATH_UNSAFE" >&2; exit 1;
}
[[ "$(stat -c '%u' "$CLIENT_SOURCE")" == 0 ]] || {
 echo "MCP_CHATGPT_QA_BLOCKED=CLIENT_NOT_ROOT_OWNED" >&2; exit 1;
}
[[ "$(git hash-object "$CLIENT_SOURCE")" == "$CLIENT_BLOB" ]] || {
 echo "MCP_CHATGPT_QA_BLOCKED=CLIENT_HASH_MISMATCH" >&2; exit 1;
}
python3 - "$CLIENT_SOURCE" <<'PY'
import ast,pathlib,sys
ast.parse(pathlib.Path(sys.argv[1]).read_text())
print("CHATGPT_LOOPBACK_PYTHON_INTEGRITY=PASS")
PY
OVERLAY=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
SOURCE="$OVERLAY/source"
LIVE=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
PHP=/www/server/php/85/bin/php
fail() { echo "MCP_OAUTH_QA_BLOCKED=$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in python3 git sha256sum stat cp du df awk openssl composer curl gh; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
[[ -x "$PHP" ]] || fail PHP85_MISSING
[[ -d "$OVERLAY" && ! -L "$OVERLAY" && "$(stat -c '%u:%a' "$OVERLAY")" == "0:700" ]] || fail OVERLAY_UNSAFE
[[ -d "$SOURCE" && ! -L "$SOURCE" && -f "$SOURCE/artisan" && -f "$SOURCE/vendor/autoload.php" ]] || fail PRIVATE_SOURCE_MISSING
[[ -f "$LIVE/storage/app/installed.lock" && ! -L "$LIVE" ]] || fail LIVE_INSTALL_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail VHOST_UNSAFE
[[ "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] || fail VHOST_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] || fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | cut -d' ' -f1)" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] || fail LIVE_LOCK_CHANGED
[[ "$(sha256sum "$SOURCE/composer.lock" | cut -d' ' -f1)" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] || fail PRIVATE_LOCK_CHANGED
[[ "$(git hash-object "$SOURCE/composer.json")" == "fc687c2f0f82e034af38b6183b0c58502a0c7948" ]] || fail PRIVATE_COMPOSER_MANIFEST_CHANGED
for path in "$SOURCE/.env" "$SOURCE/.env.testing" "$SOURCE/storage/oauth-private.key" "$SOURCE/storage/oauth-public.key" "$SOURCE/bootstrap/cache/config.php"; do
 [[ ! -e "$path" && ! -L "$path" ]] || fail PRIVATE_SECRETS_PRESENT
done
echo "========== VERIFY APPROVED RECONCILIATION =========="
python3 - "$OVERLAY" "$LIVE" <<'PY'
import hashlib,json,pathlib,sys
o,l=map(pathlib.Path,sys.argv[1:])
p=o/"review/mcp-private-critical-reconciliation.json"
assert p.is_file() and not p.is_symlink()
r=json.loads(p.read_text())
assert r.get("private_overlay_only") is True
assert r.get("production_activation") is False
assert r.get("production_deployment") is False
# The primary reconciliation remains immutable. An independently verified
# supplement records the minimal three-route developer addendum.
extra=o/"review/mcp-private-developer-routes.json"
assert extra.is_file() and not extra.is_symlink()
x=json.loads(extra.read_text())
addition=(
 b"        Route::get('/developer/mcp-connection', [\\App\\Http\\Controllers\\Developer\\McpConnectionCenterController::class, 'index'])\n"
 b"            ->name('developer.mcp.index');\n"
 b"        Route::get('/developer/oauth-connections', [\\App\\Http\\Controllers\\Developer\\OAuthConnectionController::class, 'index'])\n"
 b"            ->name('developer.oauth.index');\n"
 b"        Route::delete('/developer/oauth-connections/{token}', [\\App\\Http\\Controllers\\Developer\\OAuthConnectionController::class, 'destroy'])\n"
 b"            ->name('developer.oauth.revoke')->middleware('throttle:8,1');\n"
)
route=o/"source/routes/web.php"
assert route.is_file() and not route.is_symlink()
data=route.read_bytes()
def sha(b): return hashlib.sha256(b).hexdigest()
assert x.get("private_overlay_only") is True
assert x.get("production_activation") is False
assert x.get("production_deployment") is False
assert x.get("inserted_developer_routes")==3
assert x.get("all_other_route_bytes_preserved") is True
assert x.get("base_route_sha256")==r.get("route_sha256")
assert x.get("addition_sha256")==sha(addition)
assert x.get("route_sha256")==sha(data)
assert data.count(addition)==1
assert sha(data.replace(addition,b"",1))==r.get("route_sha256")
assert (l/"routes/web.php").read_bytes()==(o/"review/live-critical/routes/web.php").read_bytes()
for name,field in [
 ("app/Http/Controllers/Api/BambodiaMcpController.php","controller_sha256"),
 ("app/Support/Mcp/BambodiaTools.php","draft_tools_sha256")]:
 source=o/"source"/name
 live=l/name
 snap=o/"review/live-critical"/name
 if not source.is_file() or source.is_symlink() or hashlib.sha256(source.read_bytes()).hexdigest()!=r.get(field) or not live.is_file() or live.is_symlink() or not snap.is_file() or live.read_bytes()!=snap.read_bytes():
  raise SystemExit("MCP_OAUTH_QA_BLOCKED=SOURCE_DRIFT_"+field)
print("MCP_PRIVATE_DEVELOPER_ROUTE_SUPPLEMENT=VERIFIED")
layout="resources/views/layouts/workspace.blade.php"
assert hashlib.sha256((o/"source"/layout).read_bytes()).hexdigest()=="01154b97c1418441ddf0fcf60e50e81bbf5208a9265ae428550c2c7a2749beda"
assert (l/layout).read_bytes()==(o/"review/live-critical"/layout).read_bytes()
print("LIVE_AND_PRIVATE_MCP_SOURCE=VERIFIED")
PY
echo "========== CHECK PHP EXTENSIONS AND QA DISK =========="
"$PHP" -r '
if (PHP_VERSION_ID < 80500 || !extension_loaded("pdo_sqlite") || !extension_loaded("openssl") || !extension_loaded("mbstring")) {
 fwrite(STDERR,"MCP_OAUTH_QA_BLOCKED=PHP85_SQLITE_OR_CRYPTO_EXTENSION_MISSING\n"); exit(42);
}
echo "PHP85_REQUIRED_EXTENSIONS=PASS\n";
'
SOURCE_KB="$(du -sk "$SOURCE" | awk '{print $1}')"
FREE_KB="$(df -Pk /root | awk 'END {print $4}')"
[[ "$SOURCE_KB" =~ ^[0-9]+$ && "$FREE_KB" =~ ^[0-9]+$ ]] || fail DISK_METRICS_INVALID
(( SOURCE_KB < 2100000 && FREE_KB > SOURCE_KB + 524288 )) || fail DISK_CAPACITY_INSUFFICIENT

echo "========== BUILD DISPOSABLE QA COPY =========="
QA="$(mktemp -d /root/Bambodia-MCP-ChatGPTQA.XXXXXXXX)"
SERVER_PID=""
chmod 700 "$QA"
cleanup() {
 if [[ -n "$SERVER_PID" ]]; then
   kill "$SERVER_PID" 2>/dev/null || true
   wait "$SERVER_PID" 2>/dev/null || true
 fi
 if [[ "$QA" == /root/Bambodia-MCP-ChatGPTQA.* && -d "$QA" && ! -L "$QA" ]]; then
   rm -rf --one-file-system -- "$QA"
 fi
}
trap cleanup EXIT
cp -a --reflink=auto -- "$SOURCE/." "$QA/"
[[ -f "$QA/artisan" && -f "$QA/vendor/autoload.php" ]] || fail QA_COPY_INCOMPLETE
# The on-disk private overlay is an immutable, previously reviewed baseline.
# Add the GitHub-reviewed session-aware consent fix only in the TEMP QA COPY.
# No file beneath SOURCE, LIVE or Nginx is ever replaced.
QA_SOURCE_RELEASE="139c057cec0a9fa1fda6383d375a8bb93514ef25"
REPO="bambodia168-sys/bambodialaravel"
qa_patch_php() {
 local rel="$1" old="$2" expected="$3" destination="$QA/$1"
 if [[ "$old" == "absent" ]]; then
  [[ ! -e "$destination" && ! -L "$destination" ]] || fail "QA_PATCH_UNEXPECTED_EXISTING_$rel"
 else
  [[ -f "$destination" && ! -L "$destination" ]] || fail "QA_PATCH_UNSAFE_TARGET_$rel"
  [[ "$(git hash-object "$destination")" == "$old" ]] || fail "QA_PATCH_BASE_MISMATCH_$rel"
 fi
 local temporary
 temporary="$(mktemp "$QA/.mcp-reviewed-php.XXXXXXXX")"
 gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/$rel?ref=$QA_SOURCE_RELEASE" > "$temporary" ||
  fail "QA_PATCH_GITHUB_FETCH_FAILED_$rel"
 [[ "$(git hash-object "$temporary")" == "$expected" ]] ||
  fail "QA_PATCH_GITHUB_HASH_MISMATCH_$rel"
 "$PHP" -l "$temporary" >/dev/null || fail "QA_PATCH_PHP_LINT_FAILED_$rel"
 chmod 600 "$temporary"
 mv -f -- "$temporary" "$destination"
 [[ "$(git hash-object "$destination")" == "$expected" ]] ||
  fail "QA_PATCH_INSTALL_FAILED_$rel"
}
echo "========== APPLY VERIFIED CONSENT FIX TO DISPOSABLE QA ONLY =========="
qa_patch_php "bootstrap/app.php" \
 "c8c1b587659260508cdcc6b30c90a2ffca21e562" \
 "48b931f09aa0c21cc05705396a06272f85b2be8d"
qa_patch_php "app/Http/Middleware/RequireMcpOAuthResource.php" \
 "8be5e72d7e25e21176fb6364273741af2d1104f6" \
 "ae8922d4a1a82a5dabf11df4877d5aab8fb8093d"
qa_patch_php "app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php" \
 "absent" \
 "641e625f4a12765a3e8da54916235ccb7b337835"
qa_patch_php "tests/Feature/BambodiaMcpOAuthTest.php" \
 "2c67651511f99eee3d6e3b1c3bad2364860912ad" \
 "4a370bd74785584a609cb98254621968ad91512a"
echo "QA_CONSENT_FIX=VERIFIED_ONLY_IN_DISPOSABLE_COPY"
# Public index.php is fail-closed until installed.lock exists.
# Create that flag only inside the disposable QA clone, never in SOURCE/LIVE.
mkdir -p "$QA/storage/app"
if [[ ! -e "$QA/storage/app/installed.lock" && ! -L "$QA/storage/app/installed.lock" ]]; then
 touch "$QA/storage/app/installed.lock"
 chmod 600 "$QA/storage/app/installed.lock"
fi
[[ -f "$QA/storage/app/installed.lock" && ! -L "$QA/storage/app/installed.lock" ]] ||
 fail QA_INSTALL_MARKER_UNSAFE
echo "QA_HTTP_INSTALLED_MARKER=DISPOSABLE_COPY_ONLY"
for path in "$QA/.env" "$QA/.env.testing" "$QA/storage/oauth-private.key" "$QA/storage/oauth-public.key"; do
 [[ ! -e "$path" && ! -L "$path" ]] || fail QA_COPY_HAS_UNEXPECTED_SECRETS
done
cd "$QA"
KEY="base64:$(openssl rand -base64 32)"
qa_php() {
 env -i "PATH=$PATH" "HOME=$QA" "APP_ENV=testing" "APP_DEBUG=false" \
  "APP_URL=https://bambodia.com" "APP_KEY=$KEY" \
  "BAMBODIA_MCP_ENABLED=false" "BAMBODIA_MCP_WRITES_ENABLED=false" \
  "DB_CONNECTION=sqlite" "DB_DATABASE=:memory:" "DB_URL=" \
  "CACHE_STORE=array" "SESSION_DRIVER=array" "QUEUE_CONNECTION=sync" \
  "MAIL_MAILER=array" "LOG_CHANNEL=stderr" \
  "COMPOSER_HOME=$QA/.composer-home" "COMPOSER_CACHE_DIR=$QA/.composer-cache" \
  "COMPOSER_ALLOW_SUPERUSER=1" "COMPOSER_NO_INTERACTION=1" \
  "$PHP" "$@"
}
echo "========== VERIFY QA DATABASE IS SQLITE MEMORY =========="
qa_php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("database.default")!=="sqlite" ||
    config("database.connections.sqlite.database")!==":memory:" ||
    config("mcp.production_enabled")!==false ||
    config("mcp.production_writes_enabled")!==false ||
    \App\Support\Mcp\McpResource::uri()!=="https://bambodia.com/mcp") {
 fwrite(STDERR,"MCP_OAUTH_QA_BLOCKED=QA_RUNTIME_NOT_ISOLATED\n"); exit(7);
}
echo "SQLITE_IN_MEMORY_AND_DISABLED_MCP=PASS\n";
'
echo "========== INSTALL PINNED DEV TEST DEPENDENCIES IN QA COPY =========="
# Source includes a production-only vendor/ tree. The locked PHPUnit tools
# must be installed in the disposable copy, never in the live or private source.
# The server's "composer" shell wrapper explicitly execs /usr/bin/php83.
# Never pass that shell script to PHP: PHP will merely print the shell text
# and return success. Call the PHP Composer entrypoint it wraps, directly,
# via the known PHP 8.5 CLI and the system Composer include_path.
COMPOSER_ENTRY=/usr/bin/composer
[[ -f "$COMPOSER_ENTRY" && ! -L "$COMPOSER_ENTRY" && -r "$COMPOSER_ENTRY" ]] ||
  fail COMPOSER_PHP_ENTRYPOINT_MISSING
"$PHP" -r '
 $head=file_get_contents($argv[1],false,null,0,1024);
 if (!is_string($head) || !preg_match("/(?:\A|\n)<\?php\b/", $head)) {
   fwrite(STDERR,"MCP_OAUTH_QA_BLOCKED=COMPOSER_ENTRY_IS_NOT_PHP\n"); exit(2);
 }
' "$COMPOSER_ENTRY" || fail COMPOSER_ENTRY_IS_NOT_PHP
mkdir -m 700 -p "$QA/.composer-home" "$QA/.composer-cache"
echo "========== VERIFY COMPOSER ON PHP 8.5 =========="
# Debian's Composer prints "Composer 2.x", while newer builds print
# "Composer version 2.x". Require a legitimate version line rather than
# assuming one exact spelling; ignore only PHP deprecation warnings.
COMPOSER_VERSION_OUTPUT="$(qa_php -d "include_path=/usr/share/php:." \
 -d "error_reporting=8191" "$COMPOSER_ENTRY" --version --no-ansi 2>&1)" ||
 fail COMPOSER_PHP85_BOOT_FAILED
COMPOSER_VERSION="$(printf '%s\n' "$COMPOSER_VERSION_OUTPUT" |
 grep -m1 -E '^Composer( version)? [0-9]+\.[0-9]+(\.[0-9]+)?([[:space:]]|$)' || true)"
if [[ -z "$COMPOSER_VERSION" ]]; then
 printf '%s\n' "$COMPOSER_VERSION_OUTPUT" >&2
 fail COMPOSER_VERSION_NOT_DETECTED
fi
printf 'COMPOSER_VERIFIED_VERSION=%s\n' "$COMPOSER_VERSION"
echo "COMPOSER_INTERPRETER=VERIFIED_PHP85_ENTRYPOINT"
qa_php -d "include_path=/usr/share/php:." -d "error_reporting=8191" \
 "$COMPOSER_ENTRY" install \
 --no-scripts --no-plugins \
 --prefer-dist --no-interaction --no-progress
[[ "$(sha256sum "$QA/composer.lock" | cut -d' ' -f1)" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] || fail QA_LOCK_CHANGED_DURING_DEV_INSTALL
[[ "$(git hash-object "$QA/composer.json")" == "fc687c2f0f82e034af38b6183b0c58502a0c7948" ]] || fail QA_MANIFEST_CHANGED_DURING_DEV_INSTALL
[[ -f "$QA/vendor/bin/phpunit" && -f "$QA/vendor/phpunit/phpunit/phpunit" ]] || fail QA_PHPUNIT_DEPENDENCIES_MISSING
qa_php "$QA/vendor/bin/phpunit" --version
echo "QA_PHPUNIT_FROM_LOCKED_DEV_DEPENDENCIES=PASS"

echo "========== CREATE EPHEMERAL QA PASSPORT KEYS =========="
qa_php artisan passport:keys --no-interaction --no-ansi > /dev/null
[[ -s "$QA/storage/oauth-private.key" && -s "$QA/storage/oauth-public.key" ]] || fail QA_OAUTH_KEYS_NOT_GENERATED
chmod 600 "$QA/storage/oauth-private.key" "$QA/storage/oauth-public.key"
echo "EPHEMERAL_PASSPORT_KEYS=GENERATED_IN_DISPOSABLE_QA"
echo "========== RUN ISOLATED MCP OAUTH FEATURE TESTS =========="
# Direct PHPUnit invocation avoids depending on Laravel's artisan test
# registration, which is not installed in production-only vendor trees.
qa_php "$QA/vendor/bin/phpunit" --colors=never --filter=BambodiaMcp
echo "PHYSICAL_PHP85_MCP_OAUTH_TESTS=PASS"

echo "========== CREATE ISOLATED SQLITE DATABASE FOR REAL LOOPBACK HTTP =========="
HTTP_DB="$QA/storage/chatgpt-loopback.sqlite"
[[ ! -e "$HTTP_DB" && ! -L "$HTTP_DB" ]] || fail QA_HTTP_DATABASE_ALREADY_EXISTS
touch "$HTTP_DB"
chmod 600 "$HTTP_DB"
mkdir -p "$QA/storage/framework/sessions" "$QA/storage/framework/views" \
 "$QA/storage/framework/cache/data"
chmod 700 "$QA/storage/framework/sessions" "$QA/storage/framework/views" \
 "$QA/storage/framework/cache/data"
http_php() {
 env -i "PATH=$PATH" "HOME=$QA" "APP_ENV=testing" "APP_DEBUG=false" \
  "APP_URL=https://bambodia.com" "APP_KEY=$KEY" \
  "BAMBODIA_MCP_ENABLED=true" "BAMBODIA_MCP_WRITES_ENABLED=true" \
  "DB_CONNECTION=sqlite" "DB_DATABASE=$HTTP_DB" "DB_URL=" \
  "CACHE_STORE=array" "SESSION_DRIVER=file" \
  "SESSION_SECURE_COOKIE=false" "QUEUE_CONNECTION=sync" \
  "MAIL_MAILER=array" "LOG_CHANNEL=stderr" "BCRYPT_ROUNDS=4" \
  "COMPOSER_HOME=$QA/.composer-home" "COMPOSER_CACHE_DIR=$QA/.composer-cache" \
  "$PHP" "$@"
}
# The activation flags above exist ONLY within this root-private QA copy.
# No .env is written to the app served by bambodia.com.
http_php artisan migrate --force --no-interaction --no-ansi \
 > "$QA/chatgpt-http-migrate.log" 2>&1 ||
 fail ISOLATED_HTTP_MIGRATION_FAILED
echo "CHATGPT_HTTP_DATABASE=DISPOSABLE_SQLITE_MIGRATED"

echo "========== VERIFY QA ROUTE CACHE AND OAUTH DISCOVERY ROUTES =========="
CACHE_PATH="$QA/bootstrap/cache"
[[ -d "$CACHE_PATH" && ! -L "$CACHE_PATH" && "$(realpath "$CACHE_PATH")" == "$CACHE_PATH" ]] ||
 fail QA_BOOTSTRAP_CACHE_DIRECTORY_UNSAFE
for route_cache in "$CACHE_PATH"/routes-*.php "$CACHE_PATH"/routes.php; do
 [[ ! -e "$route_cache" && ! -L "$route_cache" ]] && continue
 [[ -f "$route_cache" && ! -L "$route_cache" ]] || fail QA_ROUTE_CACHE_ENTRY_UNSAFE
 echo "QA_PREEXISTING_ROUTE_CACHE=FOUND_IN_DISPOSABLE_COPY"
done
# The reviewed private overlay may retain a cached route table from before
# the OAuth protected-resource routes were merged. Clear ONLY inside $QA.
http_php artisan route:clear --no-interaction --no-ansi \
 > "$QA/chatgpt-http-route-clear.log" 2>&1 ||
 fail DISPOSABLE_ROUTE_CLEAR_FAILED
http_php artisan route:list --json --no-ansi \
 > "$QA/chatgpt-http-routes.json" 2>&1 ||
 fail DISPOSABLE_ROUTE_LIST_FAILED
python3 - "$QA/chatgpt-http-routes.json" <<'PY'
import json,sys
try:
 routes=json.load(open(sys.argv[1],encoding="utf-8"))
 if not isinstance(routes,list): raise ValueError("unexpected route table")
 for route_path in (".well-known/oauth-protected-resource",
                    ".well-known/oauth-protected-resource/mcp"):
  matches=[route for route in routes
           if route.get("uri")==route_path and
           "GET" in str(route.get("method","")).split("|")]
  if len(matches)!=1:
   print("QA_ROUTE_TABLE_DIAGNOSTIC="+route_path.replace("/","_")+
         "|get_routes="+str(len(matches)))
   raise ValueError("missing or ambiguous resource discovery route")
  print("QA_RESOURCE_ROUTE_VERIFIED="+route_path.replace("/","_"))
except (ValueError,TypeError,KeyError) as exc:
 print("MCP_OAUTH_QA_BLOCKED=DISPOSABLE_RESOURCE_ROUTE_TABLE_MISMATCH",
       file=sys.stderr)
 sys.exit(1)
PY
echo "DISPOSABLE_QA_ROUTE_CACHE=REFRESHED"

BAMBODIA_QA_ADMIN_EMAIL="mcp-qa-$(openssl rand -hex 12)@example.test"
BAMBODIA_QA_ADMIN_PASSWORD="$(openssl rand -hex 24)"
BAMBODIA_QA_ALT_EMAIL="mcp-alt-$(openssl rand -hex 12)@example.test"
export BAMBODIA_QA_ADMIN_EMAIL BAMBODIA_QA_ADMIN_PASSWORD BAMBODIA_QA_ALT_EMAIL
# The PHP setup below is intentionally explicit, rather than executing a
# database seeder or importing any real administrator/customer information.
BAMBODIA_QA_FOREIGN_ID="$(env \
 "PATH=$PATH" "HOME=$QA" "APP_ENV=testing" "APP_DEBUG=false" \
 "APP_URL=https://bambodia.com" "APP_KEY=$KEY" \
 "DB_CONNECTION=sqlite" "DB_DATABASE=$HTTP_DB" "DB_URL=" \
 "BAMBODIA_MCP_ENABLED=true" "BAMBODIA_MCP_WRITES_ENABLED=true" \
 "CACHE_STORE=array" "SESSION_DRIVER=file" "QUEUE_CONNECTION=sync" \
 "MAIL_MAILER=array" "LOG_CHANNEL=stderr" "BCRYPT_ROUNDS=4" \
 "BAMBODIA_QA_ADMIN_EMAIL=$BAMBODIA_QA_ADMIN_EMAIL" \
 "BAMBODIA_QA_ADMIN_PASSWORD=$BAMBODIA_QA_ADMIN_PASSWORD" \
 "BAMBODIA_QA_ALT_EMAIL=$BAMBODIA_QA_ALT_EMAIL" \
 "$PHP" -r '
 require "vendor/autoload.php";
 $app=require "bootstrap/app.php";
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $owner=\App\Models\User::query()->create([
  "name"=>"Disposable Loopback Admin",
  "email"=>getenv("BAMBODIA_QA_ADMIN_EMAIL"),
  "password"=>getenv("BAMBODIA_QA_ADMIN_PASSWORD"),
  "role"=>"super_admin","account_status"=>"active",
 ]);
 $other=\App\Models\User::query()->create([
  "name"=>"Disposable Other Admin",
  "email"=>getenv("BAMBODIA_QA_ALT_EMAIL"),
  "password"=>getenv("BAMBODIA_QA_ADMIN_PASSWORD"),
  "role"=>"super_admin","account_status"=>"active",
 ]);
 $foreign=\App\Models\MarketplaceListing::query()->create([
  "user_id"=>$other->id,
  "type"=>"service",
  "title"=>"Another QA admin draft",
  "description"=>"Ownership checks must protect this disposable foreign draft.",
  "status"=>"draft",
 ]);
 echo (string)$foreign->id;
 ')" || fail CHATGPT_HTTP_QA_FIXTURE_FAILED
[[ "$BAMBODIA_QA_FOREIGN_ID" =~ ^[0-9]+$ ]] || fail QA_FOREIGN_DRAFT_ID_INVALID
export BAMBODIA_QA_FOREIGN_ID
echo "CHATGPT_HTTP_FIXTURES=EPHEMERAL_OWNERS_ONLY"

echo "========== START LOOPBACK-ONLY LARAVEL HTTP SERVER =========="
PORT="$(python3 - <<'PY'
import socket
with socket.socket() as s:
 s.bind(("127.0.0.1",0))
 print(s.getsockname()[1])
PY
)"
[[ "$PORT" =~ ^[0-9]+$ && "$PORT" -ge 1024 ]] || fail QA_LOCAL_PORT_INVALID
http_php -S "127.0.0.1:$PORT" -t "$QA/public" "$QA/public/index.php" \
 > "$QA/chatgpt-loopback-http.log" 2>&1 &
SERVER_PID=$!
READY=0
for ((attempt=0; attempt<50; attempt++)); do
 if curl -fsS --max-time 2 -H 'Host: bambodia.com' \
  "http://127.0.0.1:$PORT/up" >/dev/null 2>&1; then
  READY=1
  break
 fi
 if ! kill -0 "$SERVER_PID" 2>/dev/null; then break; fi
 sleep 0.2
done
[[ "$READY" == 1 ]] || fail QA_LOOPBACK_SERVER_NOT_READY
echo "CHATGPT_LOOPBACK_HTTP=BOUND_TO_127_0_0_1"

echo "========== INDEPENDENT CHATGPT-STYLE HTTP OAUTH ACCEPTANCE =========="
if env -i "PATH=$PATH" "HOME=$QA" \
 "BAMBODIA_LOOPBACK_PORT=$PORT" \
 "BAMBODIA_QA_ADMIN_EMAIL=$BAMBODIA_QA_ADMIN_EMAIL" \
 "BAMBODIA_QA_ADMIN_PASSWORD=$BAMBODIA_QA_ADMIN_PASSWORD" \
 "BAMBODIA_QA_FOREIGN_ID=$BAMBODIA_QA_FOREIGN_ID" \
 python3 -I "$CLIENT_SOURCE"; then
 echo "CHATGPT_LOOPBACK_INDEPENDENT_ACCEPTANCE=PASS"
else
 # APP_DEBUG stays false. Read only the private disposable server log and
 # expose allowlisted diagnostic classes/location, never exception payload,
 # request bodies, user credentials, cookies, bearer values or DB content.
 python3 - "$QA/chatgpt-loopback-http.log" "$QA" <<'PY'
import hashlib
from pathlib import Path
import re
import sys

log = Path(sys.argv[1])
qa = Path(sys.argv[2])
if not log.is_file() or log.is_symlink():
    print("QA_HTTP_EXCEPTION_DIAGNOSTIC=LOG_MISSING")
    sys.exit(0)

raw = log.read_bytes()[-1_500_000:]
lines = raw.decode("utf-8", "replace").splitlines()

# The final logged ErrorException can be secondary during response flushing.
# Summarize first and final errors without exposing exception messages.
error_lines = [
    item for item in lines
    if any(tag in item for tag in (
        "testing.ERROR:", "local.ERROR:", "PHP Fatal error:",
        "Uncaught ", "PHP Warning:", "PHP Deprecated:"
    ))
]
print("QA_HTTP_ERROR_ENTRY_COUNT=" + str(len(error_lines)))
def safe_origin(item):
    origin = re.search(
        r"\bat\s+" + re.escape(str(qa)) +
        r"/((?:app|routes|bootstrap|config|lang|public|vendor)/"
        r"[a-zA-Z0-9_./-]{1,160}):(\d{1,6})\b",
        item
    )
    if origin:
        return origin.group(1) + ":" + origin.group(2)
    return "NOT_FOUND"
def safe_kind(item):
    rules = (
        ("OUTPUT_HANDLER", r"ob_end_flush\(\)|user output handler"),
        ("HEADERS_ALREADY_SENT", r"Cannot modify header information|headers already sent"),
        ("UNDEFINED_ARRAY_KEY", r"Undefined array key"),
        ("UNDEFINED_VARIABLE", r"Undefined variable"),
        ("NULL_PROPERTY", r"Attempt to read property"),
        ("FILE_MISSING", r"Failed opening required|No such file or directory"),
        ("DEPRECATED", r"\bdeprecated\b|\bdeprecation\b"),
        ("PHP_WARNING", r"PHP Warning:"),
        ("SQL", r"SQLSTATE\["),
    )
    return next((label for label, pattern in rules
                 if re.search(pattern, item, re.IGNORECASE)), "OTHER")
def safe_class(item):
    classes = (
        "ErrorException", "QueryException", "PDOException", "TypeError",
        "RuntimeException", "LogicException", "ParseError", "Error"
    )
    return next((c for c in classes if c in item), "OTHER")
for slot, index in (
    ("FIRST", 0), ("SECOND", 1), ("THIRD", 2),
    ("LAST", len(error_lines) - 1),
):
    if index < 0 or index >= len(error_lines):
        continue
    entry = error_lines[index]
    print("QA_HTTP_ERROR_CHAIN_" + slot + "=" +
          "class:" + safe_class(entry) + "|kind:" + safe_kind(entry) +
          "|origin:" + safe_origin(entry))
candidate = next((
    line for line in reversed(lines)
    if ("testing.ERROR:" in line or "local.ERROR:" in line
        or "PHP Fatal error:" in line or "Uncaught " in line)
), None)
if candidate is None:
    print("QA_HTTP_EXCEPTION_DIAGNOSTIC=NO_ERROR_ENTRY")
    print("QA_HTTP_LOG_FINGERPRINT=" + hashlib.sha256(raw).hexdigest()[:16])
    sys.exit(0)

# Never print the exception message verbatim. It can include sensitive SQL
# bindings, arbitrary headers or session data. Only fixed-structure fields
# and code/path identifiers are emitted.
print("QA_HTTP_EXCEPTION_DIAGNOSTIC=FOUND")
print("QA_HTTP_EXCEPTION_FINGERPRINT=" +
      hashlib.sha256(candidate.encode()).hexdigest()[:16])
classes = [
    "Illuminate\\Database\\QueryException",
    "PDOException",
    "Illuminate\\Database\\Eloquent\\ModelNotFoundException",
    "Illuminate\\Routing\\Exceptions\\UrlGenerationException",
    "Symfony\\Component\\Routing\\Exception\\RouteNotFoundException",
    "Illuminate\\Session\\TokenMismatchException",
    "Illuminate\\Encryption\\MissingAppKeyException",
    "Illuminate\\Encryption\\DecryptException",
    "Illuminate\\Filesystem\\FileNotFoundException",
    "Illuminate\\View\\ViewException",
    "ErrorException",
    "TypeError",
    "ParseError",
    "Error",
]
# JSON-escaped PHP class names contain double backslashes.
unescaped = candidate.replace("\\\\", "\\")
detected = next((v for v in classes if v in unescaped), "OTHER")
print("QA_HTTP_EXCEPTION_CLASS=" + detected.replace("\\", "."))
state = re.search(r"SQLSTATE\[([0-9A-Z]{3,8})\]", unescaped)
if state:
    print("QA_HTTP_SQLSTATE=" + state.group(1))
table = re.search(r"no such table:\s*([a-zA-Z_][a-zA-Z_0-9]{0,79})", unescaped)
if table:
    print("QA_HTTP_SQLITE_MISSING_TABLE=" + table.group(1))
permission = re.search(r"(Permission denied|Read-only file system|No space left on device)", unescaped, re.I)
if permission:
    print("QA_HTTP_FILESYSTEM_ERROR=YES")
badfn = re.search(r"Call to undefined (?:function|method)\s+([a-zA-Z_\\][a-zA-Z_0-9\\:]{0,90})", unescaped)
if badfn:
    print("QA_HTTP_UNDEFINED_CALL=" + badfn.group(1).replace("\\", "."))
# Identify the ORIGINAL exception site from Laravel's exception header.
# HandleExceptions.php:267 is the framework's error-to-exception adapter,
# not necessarily the code that emitted the warning.
allowed = r"(?:app|routes|bootstrap|config|lang|public|vendor)/[A-Za-z0-9_./-]{1,160}"
site = re.search(
    r"\bat\s+" + re.escape(str(qa)) + r"/(" + allowed + r"):(\d{1,6})\b",
    unescaped,
)
if site:
    print("QA_HTTP_THROW_SITE=" + site.group(1) + ":" + site.group(2))
else:
    print("QA_HTTP_THROW_SITE=NOT_FOUND")

# Restrict diagnostics to fixed categories. Do not print the message text:
# it may contain query values, bearer tokens or sensitive application data.
categories = [
    ("UNDEFINED_ARRAY_KEY", r"Undefined array key"),
    ("UNDEFINED_VARIABLE", r"Undefined variable"),
    ("UNDEFINED_PROPERTY", r"Undefined property"),
    ("NULL_PROPERTY_ACCESS", r"Attempt to read property"),
    ("ARRAY_OFFSET", r"Trying to access array offset"),
    ("UNDEFINED_CALL", r"Call to undefined (?:function|method)"),
    ("MISSING_REQUIRE", r"Failed opening required|Failed to open stream"),
    ("MISSING_FILE", r"No such file or directory"),
    ("PERMISSION", r"Permission denied|Read-only file system"),
    ("DEPRECATED", r"\bDeprecated\b|\bdeprecation\b"),
    ("TYPE", r"TypeError|must be of type"),
]
kind = next((name for name, expr in categories
             if re.search(expr, unescaped, re.IGNORECASE)), "OTHER")
print("QA_HTTP_ERROR_KIND=" + kind)

# Show the first application frame, if any, rather than a generic vendor
# bootstrap frame. Use only paths INSIDE the disposable QA tree.
stack = "\n".join(lines[-1200:])
app_frame = re.search(
    r"#\d+\s+" + re.escape(str(qa)) +
    r"/((?:app|routes|bootstrap|config|lang|public)/[A-Za-z0-9_./-]{1,160})"
    r"\((\d{1,6})\)",
    stack,
)
if app_frame:
    print("QA_HTTP_FIRST_APP_FRAME=" +
          app_frame.group(1) + ":" + app_frame.group(2))
else:
    print("QA_HTTP_FIRST_APP_FRAME=NOT_FOUND")

# A framework/vendor throw site can still locate a dependency mismatch.
# Prefer the origin from the exception header, never a handler-only frame.
if not site:
    vendor_frame = re.search(
        r"#\d+\s+" + re.escape(str(qa)) +
        r"/(vendor/[A-Za-z0-9_./-]{1,160})\((\d{1,6})\)",
        stack,
    )
    if vendor_frame:
        print("QA_HTTP_VENDOR_FRAME=" +
              vendor_frame.group(1) + ":" + vendor_frame.group(2))
PY
# Secondary probes stay on the same private 127.0.0.1 server.
# Discard bodies and emit only HTTP status numbers.
for probe in "NESTED|/.well-known/oauth-protected-resource/mcp" "LOGIN|/login"; do
  probe_name="${probe%%|*}"
  probe_path="${probe#*|}"
  probe_status="$(curl -s --max-time 8 -o /dev/null -w '%{http_code}' \
    -H 'Host: bambodia.com' \
    -H 'Accept: application/json, text/html, text/event-stream' \
    "http://127.0.0.1:$PORT$probe_path" || true)"
  [[ "$probe_status" =~ ^[0-9]{3}$ ]] || probe_status=UNREACHABLE
  echo "QA_HTTP_ROUTE_PROBE_${probe_name}=$probe_status"
done
 fail CHATGPT_LOOPBACK_HTTP_ACCEPTANCE_FAILED
fi
echo "QA_COPY_AND_EPHEMERAL_KEYS=REMOVED_AT_EXIT"
echo "PRODUCTION_SOURCE_DATABASE_KEYS_NGINX=UNCHANGED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
echo "EXTERNAL_CHATGPT_ACCOUNT_LINKING=NOT_ATTEMPTED"
