#!/usr/bin/env bash
# Recover ONLY file-based isolated OAuth QA in an existing checkout.
# Deliberately no MySQL, Artisan, Passport keys, or deployment operations.
set -Eeuo pipefail
umask 077
APP="/var/lib/bambodia-oauth-qa-acceptance"
EXPECTED_SHA="${BAMBODIA_QA_CHECKOUT_SHA:?Supply exact verified existing QA checkout SHA}"
PHP="/www/server/php/84/bin/php"
fail(){ printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || fail 'Requires root.'
[[ "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] || fail 'Invalid pinned SHA.'
[[ -d "$APP" && ! -L "$APP" ]] || fail 'Existing QA directory missing or symlink.'
[[ "$(realpath -e "$APP")" == "$APP" ]] || fail 'Unexpected QA directory resolution.'
[[ -d "$APP/.git" && -f "$APP/artisan" && -f "$APP/.env.example" ]] || fail 'QA checkout incomplete.'
[[ "$(git -C "$APP" rev-parse HEAD)" == "$EXPECTED_SHA" ]] || fail 'QA checkout SHA mismatch.'
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] || fail 'QA tracked source modified.'
[[ -x "$PHP" ]] || fail 'Expected PHP 8.4 not found.'
id www >/dev/null || fail 'www account unavailable.'
for d in storage bootstrap/cache .qa-tools vendor; do
  [[ ! -L "$APP/$d" ]] || fail "QA directory is a symlink: $d"
done
[[ -z "$(find "$APP/storage" -type l -print -quit 2>/dev/null)" ]] || fail 'QA storage has symlinks.'
[[ ! -e "$APP/.env" && ! -L "$APP/.env" ]] || fail 'QA .env exists; refuse overwrite.'
[[ ! -e "$APP/vendor" && ! -L "$APP/vendor" ]] || fail 'QA vendor exists; refuse partial overwrite.'
[[ ! -e "$APP/composer.lock" && ! -L "$APP/composer.lock" ]] || fail 'QA composer.lock exists; refuse partial overwrite.'
[[ ! -e "$APP/storage/oauth-private.key" && ! -e "$APP/storage/oauth-public.key" ]] || fail 'QA Passport keys already exist.'
[[ ! -e "$APP/bootstrap/cache/config.php" ]] || fail 'QA cached config already exists.'
for bin in curl openssl python3 sha384sum; do command -v "$bin" >/dev/null || fail "Missing $bin"; done

echo "QA_APP=$APP"
echo "QA_CHECKOUT_SHA=$EXPECTED_SHA"
echo 'TARGET_CHECK=PASS'
echo 'DATABASE_OPERATIONS=DISABLED'
echo '========== CREATE QA-ONLY ENVIRONMENT FILE =========='

QA_APP_KEY="base64:$(openssl rand -base64 32)"
export QA_APP_KEY
python3 - "$APP/.env.example" "$APP/.env" <<'PY'
from pathlib import Path
import os, sys
src, dst = (Path(s) for s in sys.argv[1:])
assert src.is_file() and not dst.exists()
settings = {
 'APP_NAME': '"Bambodia OAuth QA"', 'APP_ENV':'staging',
 'APP_KEY':os.environ['QA_APP_KEY'], 'APP_DEBUG':'false',
 'APP_URL':'https://oauth-qa.invalid',
 'DB_CONNECTION':'mysql', 'DB_HOST':'127.0.0.1', 'DB_PORT':'3306',
 'DB_DATABASE':'bambodia_oauthqa_acceptance', 'DB_USERNAME':'bambodiaqa_acceptance',
 'DB_PASSWORD':'NOT_CONFIGURED',
 'MAIL_MAILER':'log', 'MAIL_FROM_ADDRESS':'noreply@oauth-qa.invalid',
 'QUEUE_CONNECTION':'database', 'SESSION_DRIVER':'file', 'SESSION_DOMAIN':'null',
 'SESSION_COOKIE':'bambodia_oauthqa_acceptance', 'CACHE_STORE':'file',
 'FILESYSTEM_DISK':'local', 'MEDIA_LIBRARY_DISK':'local'
}
lines=[]; seen=set()
for line in src.read_text().splitlines():
    key=line.split('=',1)[0]
    if key in settings and line.startswith(key+'='):
        lines.append(f'{key}={settings[key]}'); seen.add(key)
    elif line.startswith(('R2_', 'AWS_', 'PASSPORT_')) and '=' in line:
        lines.append(key+'=')
    else:
        lines.append(line)
for key, value in settings.items():
    if key not in seen: lines.append(f'{key}={value}')
with dst.open('x') as f:
    f.write('\n'.join(lines)+'\n')
PY

mkdir -p "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/bootstrap/cache" "$APP/.qa-tools"
[[ ! -L "$APP/.qa-tools" ]] || fail 'QA tools directory is symlinked.'
INSTALLER="$APP/.qa-tools/composer-setup.php"
curl -fsSL --proto '=https' --tlsv1.2 https://getcomposer.org/installer -o "$INSTALLER"
EXPECTED_SIG="$(curl -fsSL --proto '=https' --tlsv1.2 https://composer.github.io/installer.sig)"
ACTUAL_SIG="$(sha384sum "$INSTALLER" | cut -d ' ' -f 1)"
[[ "$EXPECTED_SIG" == "$ACTUAL_SIG" ]] || fail 'Composer installer SHA384 check failed.'
"$PHP" "$INSTALLER" --install-dir="$APP/.qa-tools" --filename=composer.phar --2

(
 cd "$APP"
 COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$APP/.qa-tools/composer.phar" install \
  --no-dev --no-plugins --no-scripts --prefer-dist --no-interaction --no-progress
)
[[ -f "$APP/vendor/autoload.php" ]] || fail 'Composer dependencies did not install.'
chown -R root:www "$APP"
chmod 750 "$APP"
chmod -R g+rX "$APP"
chown -R www:www "$APP/storage" "$APP/bootstrap/cache"
chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"
chown root:www "$APP/.env"
chmod 640 "$APP/.env"
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] || fail 'Tracked files changed.'

echo 'QA_FILE_PREPARATION=PASS'
echo 'QA_DB_PASSWORD=NOT_CONFIGURED'
echo 'QA_DATABASE=NOT_CREATED'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'MIGRATIONS=NOT_RUN'
echo 'PREFLIGHT=BLOCKED_UNTIL_RESTRICTED_QA_MYSQL_USER_EXISTS'
