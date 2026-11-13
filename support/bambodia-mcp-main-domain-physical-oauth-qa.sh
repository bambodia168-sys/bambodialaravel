#!/usr/bin/env bash
# Disposable PHP 8.5 OAuth acceptance. Never touches production.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--isolated-qa" ]] || { echo "Usage: --isolated-qa" >&2; exit 64; }
OVERLAY=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
SOURCE="$OVERLAY/source"
LIVE=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
PHP=/www/server/php/85/bin/php
fail() { echo "MCP_OAUTH_QA_BLOCKED=$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in python3 git sha256sum stat cp du df awk openssl composer; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
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
for name,field in [
 ("routes/web.php","route_sha256"),
 ("app/Http/Controllers/Api/BambodiaMcpController.php","controller_sha256"),
 ("app/Support/Mcp/BambodiaTools.php","draft_tools_sha256")]:
 source=o/"source"/name
 live=l/name
 snap=o/"review/live-critical"/name
 if not source.is_file() or source.is_symlink() or hashlib.sha256(source.read_bytes()).hexdigest()!=r.get(field) or not live.is_file() or live.is_symlink() or not snap.is_file() or live.read_bytes()!=snap.read_bytes():
  raise SystemExit("MCP_OAUTH_QA_BLOCKED=SOURCE_DRIFT_"+field)
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
QA="$(mktemp -d /root/Bambodia-MCP-OAuthQA.XXXXXXXX)"
chmod 700 "$QA"
cleanup() {
 if [[ "$QA" == /root/Bambodia-MCP-OAuthQA.* && -d "$QA" && ! -L "$QA" ]]; then
   rm -rf --one-file-system -- "$QA"
 fi
}
trap cleanup EXIT
cp -a --reflink=auto -- "$SOURCE/." "$QA/"
[[ -f "$QA/artisan" && -f "$QA/vendor/autoload.php" ]] || fail QA_COPY_INCOMPLETE
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
COMPOSER_VERSION="$(qa_php -d "include_path=/usr/share/php:." \
 "$COMPOSER_ENTRY" --version --no-ansi)" ||
 fail COMPOSER_PHP85_BOOT_FAILED
[[ "$COMPOSER_VERSION" == "Composer version "* ]] ||
 fail COMPOSER_VERSION_NOT_EXECUTED
echo "$COMPOSER_VERSION"
echo "COMPOSER_INTERPRETER=VERIFIED_PHP85_ENTRYPOINT"
qa_php -d "include_path=/usr/share/php:." \
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
echo "QA_COPY_AND_EPHEMERAL_KEYS=REMOVED_AT_EXIT"
echo "PRODUCTION_SOURCE_DATABASE_KEYS_NGINX=UNCHANGED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
echo "EXTERNAL_CHATGPT_OAUTH_CONNECTION=NOT_STARTED"
