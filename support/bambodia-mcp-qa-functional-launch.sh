#!/usr/bin/env bash
# ACTION: install and configure only the private oauth-qa.bambodia.com QA app.
# Safe boundary: no Nginx, production, existing QA source, or shared DB changes.
# Do NOT rerun after partial failure. PHP 8.5 Composer, assets and migrations
# may write in this private QA directory and its dedicated empty QA database.
set -Eeuo pipefail
set +x
umask 077
[[ "${1:-}" == --launch ]] || { echo "Usage: bash script --launch" >&2; exit 64; }
APP=/var/lib/bambodia-oauth-https-qa
PHP=/www/server/php/85/bin/php
MYSQL=/www/server/mysql/bin/mysql
CNF=/root/bambodia-oauth-https-mysql.cnf
VHOST=/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf
VHASH=c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94
LOCKHASH=01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3
REPO=bambodia168-sys/bambodialaravel
FEATURE=b799d74c81bda133c351d792540d58eb46923a55
fail(){ echo "QA_LAUNCH_BLOCKED=$*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || fail ROOT_REQUIRED
for cmd in gh git python3 sha256sum npm node find; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
id www >/dev/null || fail WWW_MISSING
[[ -x "$PHP" && -x "$MYSQL" ]] || fail RUNTIME_MISSING
[[ -d "$APP" && ! -L "$APP" && "$(stat -c %u:%a "$APP")" == 0:700 ]] || fail QA_ROOT_CHANGED
[[ -f "$VHOST" && "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHASH" ]] || fail ORIGINAL_VHOST_CHANGED
[[ -f "$APP/composer.lock" && "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LOCKHASH" ]] || fail LOCK_CHANGED
[[ ! -e "$APP/.env" && ! -L "$APP/.env" && ! -e "$APP/vendor" && ! -L "$APP/vendor" ]] || fail QA_ALREADY_INITIALIZED_OR_PARTIAL
[[ ! -e "$APP/.git" ]] || fail QA_SNAPSHOT_MUST_HAVE_NO_GIT
[[ "$(git hash-object "$APP/routes/web.php")" == e3c40a6256d65f37b52e574b0b8ac47a3f5c938d ]] || fail ORIGINAL_ROUTES_CHANGED
[[ "$(git hash-object "$APP/resources/views/developer/index.blade.php")" == 2e6c72d1b0dd14d32f6b464a7c6fa7bf5a07376d ]] || fail ORIGINAL_DASHBOARD_CHANGED
for path in app/Http/Controllers/Developer/McpConnectionCenterController.php resources/views/developer/mcp-connection.blade.php; do
 [[ ! -e "$APP/$path" && ! -L "$APP/$path" ]] || fail CONNECTION_CENTER_ALREADY_PRESENT
done
[[ "$(gh api "repos/$REPO/pulls/15" --jq .draft)" == true ]] || fail PR_NOT_DRAFT
RESULT="$("$MYSQL" --defaults-file="$CNF" -N -B --execute "SELECT CONCAT(CURRENT_USER(),':',(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bambodia_oauthqa_https'))")"
[[ "$RESULT" == "bambodiaqa_https@127.0.0.1:0" ]] || fail DEDICATED_QA_DB_NOT_EMPTY

echo "========== PREPARE REVIEWED USER-FACING QA FEATURES =========="
WORK="$(mktemp -d /root/bambodia-mcp-qa-launch.XXXXXXXX)"
chmod 700 "$WORK"
echo "PRIVATE_RECOVERY_DIRECTORY=$WORK"
# These four blobs come from the immutable feature commit, not moving main.
items=(
 "routes/web.php:239c54f83101773a4ad043fae5560911930aaa80"
 "resources/views/developer/index.blade.php:cc9d52197884e4cde1682025810673c0ae445933"
 "app/Http/Controllers/Developer/McpConnectionCenterController.php:448202851cb78b37ac04da55b9d0c4e716f2fcad"
 "resources/views/developer/mcp-connection.blade.php:3e8fecf6c045c0cb64ece39470c5764a6b400228"
)
for item in "${items[@]}"; do
 path="${item%%:*}"; expected="${item##*:}"
 mkdir -p "$WORK/$(dirname "$path")"
 gh api -H "Accept: application/vnd.github.raw+json" \
   "repos/$REPO/contents/$path?ref=$FEATURE" > "$WORK/$path" || fail OVERLAY_DOWNLOAD
 [[ "$(git hash-object "$WORK/$path")" == "$expected" ]] || fail OVERLAY_BLOB_MISMATCH
done
echo "QA_FUNCTIONAL_CODE=VERIFIED"

echo "========== INSTALL LOCKED PRIVATE PHP PACKAGES =========="
for name in .composer-home .composer-cache .composer-tmp; do
 [[ -d "$APP/$name" && ! -L "$APP/$name" && "$(stat -c %u:%a "$APP/$name")" == 0:700 &&
   -z "$(find "$APP/$name" -mindepth 1 -maxdepth 1 -print -quit)" ]] || fail COMPOSER_DIR_NOT_EMPTY
done
(
 cd "$APP"
 env -i HOME=/root PATH=/usr/bin:/bin COMPOSER_ALLOW_SUPERUSER=1 \
   COMPOSER_HOME="$APP/.composer-home" COMPOSER_CACHE_DIR="$APP/.composer-cache" TMPDIR="$APP/.composer-tmp" \
   "$PHP" -d include_path=/usr/share/php:. /usr/bin/composer \
   install --dry-run --no-dev --no-scripts --no-plugins --no-ansi --no-interaction
 env -i HOME=/root PATH=/usr/bin:/bin COMPOSER_ALLOW_SUPERUSER=1 \
   COMPOSER_HOME="$APP/.composer-home" COMPOSER_CACHE_DIR="$APP/.composer-cache" TMPDIR="$APP/.composer-tmp" \
   "$PHP" -d include_path=/usr/share/php:. /usr/bin/composer \
   install --no-dev --no-scripts --no-plugins --no-ansi --no-interaction --no-progress --prefer-dist
)
[[ -s "$APP/vendor/autoload.php" ]] || fail VENDOR_INSTALL_FAILED
[[ "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LOCKHASH" ]] || fail LOCK_MODIFIED

echo "========== INSTALL MCP CONNECTION CENTER =========="
for item in "${items[@]}"; do
 path="${item%%:*}"
 mkdir -p "$WORK/original/$(dirname "$path")"
 [[ ! -f "$APP/$path" ]] || cp "$APP/$path" "$WORK/original/$path"
 install -D -m 600 "$WORK/$path" "$APP/$path"
done

echo "========== BUILD QA WEBSITE ASSETS =========="
( cd "$APP"; npm install --ignore-scripts --no-audit --no-fund && npm run build )
[[ -s "$APP/public/build/manifest.json" ]] || fail ASSETS_MISSING

echo "========== CREATE INDEPENDENT QA CONFIGURATION =========="
python3 - "$CNF" "$APP/.env" <<'PY'
import base64,configparser,os,pathlib,secrets,sys
cnf,dst=map(pathlib.Path,sys.argv[1:])
if dst.exists() or dst.is_symlink(): raise SystemExit("BLOCKED=ENV_EXISTS")
c=configparser.RawConfigParser(interpolation=None);c.read(cnf)
assert c.get("client","user")=="bambodiaqa_https"
password=c.get("client","password")
assert password and all(ch not in password for ch in "\r\n\0")
def quote(v): return '"'+v.replace("\\","\\\\").replace('"','\\"').replace("$","\\$")+'"'
params=dict(
 APP_NAME=quote("Bambodia OAuth QA"),APP_ENV="staging",APP_DEBUG="false",
 APP_KEY="base64:"+base64.b64encode(secrets.token_bytes(32)).decode(),
 APP_URL="https://oauth-qa.bambodia.com",DB_CONNECTION="mysql",
 DB_HOST="127.0.0.1",DB_PORT="3306",DB_DATABASE="bambodia_oauthqa_https",
 DB_USERNAME="bambodiaqa_https",DB_PASSWORD=quote(password),
 SESSION_DRIVER="file",SESSION_DOMAIN="null",SESSION_COOKIE="bambodia_oauthqa_https",
 SESSION_SECURE_COOKIE="true",CACHE_STORE="file",QUEUE_CONNECTION="sync",
 FILESYSTEM_DISK="local",MEDIA_LIBRARY_DISK="local",MAIL_MAILER="log",
 LOG_CHANNEL="single",
)
fd=os.open(dst,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
with os.fdopen(fd,"w") as output:
 for key,value in params.items():output.write(f"{key}={value}\n")
print("QA_ENV_AND_NEW_APP_KEY=CREATED_PRIVATE")
PY
mkdir -p "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" \
 "$APP/storage/framework/views" "$APP/storage/logs" "$APP/bootstrap/cache"
( cd "$APP";
 env -i HOME=/root PATH=/usr/bin:/bin "$PHP" artisan package:discover --no-ansi
 env -i HOME=/root PATH=/usr/bin:/bin "$PHP" artisan migrate --force --no-interaction --no-ansi
 env -i HOME=/root PATH=/usr/bin:/bin "$PHP" artisan passport:keys --no-ansi --no-interaction
)
[[ -s "$APP/storage/oauth-private.key" ]] || fail PASSPORT_KEYS_MISSING
echo "QA_DATABASE_AND_OAUTH_KEYS=INITIALIZED"

echo "========== CREATE ONE TEST ADMIN TO SIGN IN PHYSICALLY =========="
printf "New QA-only administrator email: " > /dev/tty
IFS= read -r ADMIN_EMAIL < /dev/tty
printf "New QA-only administrator password (hidden, 14+ chars): " > /dev/tty
IFS= read -r -s ADMIN_PASSWORD < /dev/tty
printf "\n" > /dev/tty
[[ "${#ADMIN_PASSWORD}" -ge 14 ]] || fail QA_PASSWORD_TOO_SHORT
(
cd "$APP"
printf '%s\n%s\n' "$ADMIN_EMAIL" "$ADMIN_PASSWORD" |
env -i HOME=/root PATH=/usr/bin:/bin "$PHP" -r '
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$email=trim(fgets(STDIN));$pass=rtrim(fgets(STDIN),"\r\n");
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<14) exit(2);
if (\App\Models\User::query()->count()>0) exit(3);
$u=\App\Models\User::create(["name"=>"QA Administrator",
"email"=>$email,"password"=>$pass,"role"=>"super_admin","account_status"=>"active"]);
$u->forceFill(["email_verified_at"=>now()])->save();
echo "QA_ADMIN_CREATED=YES\n";'
) || fail QA_ADMIN_CREATION_FAILED
unset ADMIN_EMAIL ADMIN_PASSWORD

echo "========== ALLOW PHP-FPM TO SERVE ONLY THIS QA APP =========="
chown -R root:www "$APP"
find "$APP" -type d -exec chmod 750 {} +
find "$APP" -type f -exec chmod 640 {} +
for path in storage bootstrap/cache; do
 chown -R www:www "$APP/$path"
 find "$APP/$path" -type d -exec chmod 770 {} +
 find "$APP/$path" -type f -exec chmod 660 {} +
done
for path in .composer-home .composer-cache .composer-tmp; do
 chown -R root:root "$APP/$path"
 chmod 700 "$APP/$path"
done
# Storage's top directory must not be writable by PHP-FPM: Passport keys
# live directly beneath it. Only nested cache/session/log folders are writable.
chown root:www "$APP/storage"
chmod 750 "$APP/storage"
chown root:www "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key"
chmod 640 "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key"
[[ "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHASH" ]] || fail VHOST_CHANGED
echo "PRIVATE_QA_RUNTIME=READY_FOR_HTTPS_ACTIVATION"
echo "FUNCTIONAL_QA_UI=/developer/mcp-connection"
echo "QA_PUBLIC_NGINX=UNCHANGED_NOT_YET_LIVE"
echo "PRODUCTION=UNCHANGED"
