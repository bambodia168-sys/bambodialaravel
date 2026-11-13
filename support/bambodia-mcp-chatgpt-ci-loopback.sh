#!/usr/bin/env bash
# Independent ChatGPT-style real HTTP acceptance; GitHub Actions ONLY.
# Never runs against a live database, Nginx, production keys or public HTTPS.
set -Eeuo pipefail
set +x
umask 077
if (( $# != 1 )) || [[ "$1" != "--ci-only" ]] || [[ "$GITHUB_ACTIONS" != true ]]; then
 echo "CHATGPT_CI_LOOPBACK_BLOCKED=GITHUB_ACTIONS_ONLY" >&2; exit 64
fi
[[ "$PWD" == "$(git rev-parse --show-toplevel)" ]] || exit 72
[[ -f artisan && -s vendor/autoload.php && -s storage/oauth-private.key && -s storage/oauth-public.key ]] || exit 72
[[ ! -e storage/app/installed.lock ]] || exit 72
fail(){ echo "CHATGPT_CI_LOOPBACK_BLOCKED=$*" >&2; exit 1; }
for cmd in php python3 curl openssl git; do command -v "$cmd" >/dev/null; done
PHP="$(command -v php)"
CLIENT_SOURCE="$PWD/support/bambodia-mcp-chatgpt-loopback-client.py"
[[ -f "$CLIENT_SOURCE" && ! -L "$CLIENT_SOURCE" ]] || exit 72
python3 -m py_compile "$CLIENT_SOURCE"
QA="$(mktemp -d "$RUNNER_TEMP/bambodia-chatgpt-ci.XXXXXXXX")"
chmod 700 "$QA"
mkdir -p "$QA/storage" storage/framework/sessions storage/framework/views storage/framework/cache/data
SERVER_PID=""
cleanup(){
 if [[ -n "$SERVER_PID" ]]; then
   kill "$SERVER_PID" 2>/dev/null || true
   wait "$SERVER_PID" 2>/dev/null || true
 fi
 if [[ -f storage/app/installed.lock && ! -L storage/app/installed.lock ]]; then
  rm -f -- storage/app/installed.lock
 fi
 if [[ "$QA" == "$RUNNER_TEMP"/bambodia-chatgpt-ci.* && -d "$QA" && ! -L "$QA" ]]; then
   rm -rf --one-file-system -- "$QA"
 fi
}
trap cleanup EXIT
KEY="base64:$(openssl rand -base64 32)"
mkdir -p storage/app
[[ ! -e storage/app/installed.lock && ! -L storage/app/installed.lock ]] || fail INSTALLED_MARKER_ALREADY_PRESENT
touch storage/app/installed.lock
chmod 600 storage/app/installed.lock
echo "CHATGPT_CI_HOST=DISPOSABLE_GITHUB_ACTIONS"
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
http_php -S "127.0.0.1:$PORT" -t "$PWD/public" "$PWD/public/index.php" \
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
env -i "PATH=$PATH" "HOME=$QA" \
 "BAMBODIA_LOOPBACK_PORT=$PORT" \
 "BAMBODIA_QA_ADMIN_EMAIL=$BAMBODIA_QA_ADMIN_EMAIL" \
 "BAMBODIA_QA_ADMIN_PASSWORD=$BAMBODIA_QA_ADMIN_PASSWORD" \
 "BAMBODIA_QA_FOREIGN_ID=$BAMBODIA_QA_FOREIGN_ID" \
 python3 -I "$CLIENT_SOURCE" ||
 fail CHATGPT_LOOPBACK_HTTP_ACCEPTANCE_FAILED

echo "CHATGPT_CI_HTTP_OAUTH_AND_SCOPE_ACCEPTANCE=PASS"
echo "QA_DISPOSABLE_DATABASE=REMOVED_AT_EXIT"
echo "PRODUCTION_SOURCE_DATABASE_KEYS_NGINX=UNCHANGED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
echo "EXTERNAL_CHATGPT_ACCOUNT_LINKING=NOT_ATTEMPTED"
