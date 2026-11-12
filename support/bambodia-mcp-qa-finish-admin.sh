#!/usr/bin/env bash
# Finish existing partial QA install ONLY. No Composer, npm, migrations,
# key generation, Nginx or production operations.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == --finish ]] || { echo 'Usage: bash script --finish' >&2; exit 64; }
APP=/var/lib/bambodia-oauth-https-qa
PHP=/www/server/php/85/bin/php
MYSQL=/www/server/mysql/bin/mysql
CNF=/root/bambodia-oauth-https-mysql.cnf
VHOST=/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf
VHASH=c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94
LOCKHASH=01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3
fail(){ echo "QA_FINISH_BLOCKED=$*" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
[[ -d "$APP" && ! -L "$APP" && "$(stat -c %u:%a "$APP")" == 0:700 ]] || fail PRIVATE_QA_ROOT_CHANGED
[[ -x "$PHP" && -x "$MYSQL" ]] || fail SERVER_RUNTIME_UNAVAILABLE
id www >/dev/null || fail WWW_USER_MISSING
[[ -f "$VHOST" && ! -L "$VHOST" && "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHASH" ]] || fail ORIGINAL_NGINX_CHANGED
[[ -f "$CNF" && ! -L "$CNF" && "$(stat -c %u:%a "$CNF")" == 0:600 ]] || fail QA_DB_CREDENTIAL_FILE_UNSAFE
[[ -f "$APP/.env" && ! -L "$APP/.env" && "$(stat -c %u "$APP/.env")" == 0 ]] || fail QA_ENV_NOT_PRIVATE
[[ "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LOCKHASH" ]] || fail COMPOSER_LOCK_CHANGED
for file in vendor/autoload.php public/build/manifest.json \
 storage/oauth-private.key storage/oauth-public.key \
 app/Http/Controllers/Developer/McpConnectionCenterController.php \
 resources/views/developer/mcp-connection.blade.php; do
 [[ -s "$APP/$file" && ! -L "$APP/$file" ]] || fail "QA_FILE_MISSING_$file"
done
result="$("$MYSQL" --defaults-file="$CNF" --database=bambodia_oauthqa_https \
 --batch --skip-column-names --silent --execute \
 'SELECT CONCAT(CURRENT_USER(),":",(SELECT COUNT(*) FROM users));')" || fail QA_DB_UNAVAILABLE
[[ "$result" == 'bambodiaqa_https@127.0.0.1:0' ]] || fail QA_DB_UNEXPECTED_OR_ADMIN_ALREADY_EXISTS
echo 'QA_PRIVATE_PACKAGES_ASSETS_MIGRATIONS_KEYS=RETAINED'
echo 'QA_DEDICATED_DATABASE=READY_FOR_ONE_ADMIN'
[[ -c /dev/tty ]] || fail TTY_REQUIRED
printf 'New QA-only administrator email: ' > /dev/tty
IFS= read -r EMAIL < /dev/tty
printf 'New QA-only password (hidden, 14+ characters): ' > /dev/tty
IFS= read -r -s PASSWORD < /dev/tty
printf '\n' > /dev/tty

echo '========== CREATE ONE QA ADMIN WITH CORRECT LARAVEL BOOTSTRAP =========='
(
 cd "$APP"
 printf '%s\n%s\n' "$EMAIL" "$PASSWORD" |
 env -i HOME=/root PATH=/usr/bin:/bin "$PHP" -r '
 require "vendor/autoload.php";
 $app = require "bootstrap/app.php";
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if (config("app.env") !== "staging"
    || rtrim((string)config("app.url"),"/") !== "https://oauth-qa.bambodia.com"
    || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== "bambodia_oauthqa_https") exit(12);
 $email=trim(fgets(STDIN));
 $password=rtrim(fgets(STDIN),"\r\n");
 if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<14) exit(13);
 if (\App\Models\User::query()->count()!==0) exit(14);
 \Illuminate\Support\Facades\DB::transaction(function() use ($email,$password) {
   $u=\App\Models\User::query()->create([
     "name"=>"Bambodia QA Administrator",
     "email"=>$email, "password"=>$password,
     "role"=>"super_admin", "account_status"=>"active"
   ]);
   $u->forceFill(["email_verified_at"=>now()])->save();
 });
 echo "QA_ADMIN_CREATED=YES\n";
 '
) || fail QA_ADMIN_CREATION_FAILED_PRESERVE_PRIVATE_STATE
unset EMAIL PASSWORD

echo '========== FINISH PRIVATE PHP-FPM FILE ACCESS =========='
chown -R root:www "$APP"
find "$APP" -type d -exec chmod 750 {} +
find "$APP" -type f -exec chmod 640 {} +
for sub in storage bootstrap/cache; do
 chown -R www:www "$APP/$sub"
 find "$APP/$sub" -type d -exec chmod 770 {} +
 find "$APP/$sub" -type f -exec chmod 660 {} +
done
chown root:www "$APP/storage"
chmod 750 "$APP/storage"
chown root:www "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key"
chmod 640 "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key"
chmod 640 "$APP/.env"
for sub in .composer-home .composer-cache .composer-tmp; do
 chown -R root:root "$APP/$sub"
 chmod 700 "$APP/$sub"
done
[[ "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHASH" ]] || fail ORIGINAL_NGINX_CHANGED_DURING_FINISH
echo 'QA_ADMIN_FINISH=PASS'
echo 'QA_PRIVATE_RUNTIME=READY_FOR_HTTPS_ACTIVATION'
echo 'QA_CONNECTOR_UI=/developer/mcp-connection'
echo 'MIGRATIONS=NOT_REPEATED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
