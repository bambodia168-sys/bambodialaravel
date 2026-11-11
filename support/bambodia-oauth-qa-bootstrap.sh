#!/usr/bin/env bash
# Operator-run: isolated Bambodia OAuth QA bootstrap, NO migrations/keys/imports.
set -Eeuo pipefail
umask 077

REPO="bambodia168-sys/bambodialaravel"
SHA="${BAMBODIA_QA_EXPECTED_SHA:?Set the reviewed PR head SHA}"
APP="/var/lib/bambodia-oauth-qa-acceptance"
DB="bambodia_oauthqa_acceptance"
DB_USER="bambodiaqa_acceptance"
CNF="/root/bambodia-oauth-qa.cnf"
PHP="/www/server/php/84/bin/php"
MYSQL="/www/server/mysql/bin/mysql"
export PATH="/www/server/mysql/bin:$PATH"

fail(){ printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail 'Must be run as root on the authorized aaPanel host.'
for cmd in gh git openssl curl python3 sha384sum; do command -v "$cmd" >/dev/null || fail "Missing $cmd"; done
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'Expected SHA must be 40 hex characters.'
[[ -x "$PHP" && -x "$MYSQL" ]] || fail 'aaPanel PHP 8.4 or MySQL executable missing.'
id www >/dev/null || fail 'www service account missing.'
[[ ! -e "$APP" && ! -L "$APP" ]] || fail 'QA app location already exists; refusing overwrite.'
[[ ! -e "$CNF" && ! -L "$CNF" ]] || fail 'QA MySQL credential file exists; refusing overwrite.'
[[ "$(gh api "repos/$REPO/pulls/15" --jq .head.sha)" == "$SHA" ]] || fail 'PR head changed.'
[[ "$(gh api "repos/$REPO/pulls/15" --jq .draft)" == true ]] || fail 'PR is no longer draft.'

# Check local DBA authorization before touching any data or filesystem.
if ! "$MYSQL" --protocol=SOCKET --user=root --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1; then
  echo 'BLOCKED: No passwordless local MySQL root administration session.'
  echo 'No new QA resources have been created. Use an approved DBA credential method; do not paste passwords into chat.'
  exit 0
fi
admin(){ "$MYSQL" --protocol=SOCKET --user=root --batch --skip-column-names "$@"; }
[[ "$(admin -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")" == 0 ]] || fail 'QA database already exists.'
[[ "$(admin -e "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER'")" == 0 ]] || fail 'QA database user already exists.'

printf 'APPROVED_PR_HEAD=%s\n' "$SHA"
echo 'MYSQL_ADMIN_ACCESS=VERIFIED'
echo '========== CREATE ISOLATED QA CHECKOUT =========='
gh repo clone "$REPO" "$APP" -- --no-checkout
git -C "$APP" checkout --detach "$SHA"
[[ "$(git -C "$APP" rev-parse HEAD)" == "$SHA" ]] || fail 'QA checkout SHA mismatch.'
for p in storage bootstrap/cache .qa-tools; do
  [[ ! -L "$APP/$p" ]] || fail "QA path is a symlink: $p"
done
[[ ! -e "$APP/.env" ]] || fail 'Unexpected QA environment file.'

# New random QA secrets; not copied from production or existing staging.
DB_PASSWORD="$(openssl rand -hex 32)"
QA_APP_KEY="base64:$(openssl rand -base64 32)"
export DB_PASSWORD QA_APP_KEY

# Create the isolated database and schema-only user; no existing DB is accessed for writes.
admin <<SQL
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, LOCK TABLES
ON \`$DB\`.* TO '$DB_USER'@'127.0.0.1';
SQL

cat > "$CNF" <<EOF
[client]
user=$DB_USER
password=$DB_PASSWORD
host=127.0.0.1
protocol=TCP
EOF
chmod 600 "$CNF"
chown root:root "$CNF"
"$MYSQL" --defaults-extra-file="$CNF" -N -B "$DB" -e 'SELECT 1' >/dev/null || fail 'Dedicated QA database login failed.'

# Generate isolated QA app settings, with no production secrets or network integrations.
python3 - "$APP/.env.example" "$APP/.env" <<'PY'
import os, sys
from pathlib import Path
src, dst = map(Path, sys.argv[1:])
assert src.is_file() and not dst.exists()
settings = {
 'APP_NAME': '"Bambodia OAuth QA"', 'APP_ENV': 'staging',
 'APP_KEY': os.environ['QA_APP_KEY'], 'APP_DEBUG':'false',
 'APP_URL':'https://oauth-qa.invalid',
 'DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1', 'DB_PORT':'3306',
 'DB_DATABASE':'bambodia_oauthqa_acceptance',
 'DB_USERNAME':'bambodiaqa_acceptance','DB_PASSWORD':os.environ['DB_PASSWORD'],
 'MAIL_MAILER':'log','MAIL_FROM_ADDRESS':'noreply@oauth-qa.invalid',
 'QUEUE_CONNECTION':'database','SESSION_DRIVER':'file','CACHE_STORE':'file',
 'SESSION_COOKIE':'bambodia_oauthqa_acceptance', 'SESSION_DOMAIN':'null',
 'FILESYSTEM_DISK':'local','MEDIA_LIBRARY_DISK':'local'
}
lines=[]; found=set()
for line in src.read_text().splitlines():
    key=line.split('=',1)[0]
    if key in settings and line.startswith(key+'='):
        lines.append(f'{key}={settings[key]}'); found.add(key)
    elif key.startswith(('R2_', 'AWS_', 'PASSPORT_')):
        lines.append(f'{key}=')
    else:
        lines.append(line)
for key, value in settings.items():
    if key not in found: lines.append(f'{key}={value}')
dst.write_text('\n'.join(lines)+'\n')
PY
chown root:www "$APP/.env"
chmod 640 "$APP/.env"
mkdir -p "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" "$APP/storage/framework/views" "$APP/bootstrap/cache" "$APP/.qa-tools"
chown -R www:www "$APP/storage" "$APP/bootstrap/cache"

# Composer 2.2.6 is old; download a QA-local Composer PHAR, checking installer SHA-384.
INSTALLER="$APP/.qa-tools/composer-setup.php"
curl -fsSL --proto '=https' --tlsv1.2 https://getcomposer.org/installer -o "$INSTALLER"
EXPECTED_SIG="$(curl -fsSL --proto '=https' --tlsv1.2 https://composer.github.io/installer.sig)"
ACTUAL_SIG="$(sha384sum "$INSTALLER" | cut -d ' ' -f 1)"
[[ "$EXPECTED_SIG" == "$ACTUAL_SIG" ]] || fail 'Composer installer checksum mismatch.'
"$PHP" "$INSTALLER" --install-dir="$APP/.qa-tools" --filename=composer.phar --2
"$PHP" "$APP/.qa-tools/composer.phar" --version

# No Composer scripts or plugins; no Artisan migrations, keys, or queued work.
(
  cd "$APP"
  COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$APP/.qa-tools/composer.phar" install \
    --no-dev --no-plugins --no-scripts --prefer-dist --no-interaction --no-progress
)
[[ -f "$APP/vendor/autoload.php" ]] || fail 'Composer dependencies missing.'
# The private bootstrap umask protects .env; grant the www runtime traversal
# and read access to QA code, but no write permissions on app source.
chown -R root:www "$APP"
chmod -R g+rX "$APP"
chown -R www:www "$APP/storage" "$APP/bootstrap/cache"
chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"
chmod 640 "$APP/.env"
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] || fail 'Tracked files modified unexpectedly.'

echo '========== RUN READ-ONLY OAUTH QA PREFLIGHT =========='
export BAMBODIA_QA_APP="$APP"
export BAMBODIA_QA_DB="$DB"
export BAMBODIA_QA_MYSQL_CNF="$CNF"
export BAMBODIA_QA_EXPECTED_SHA="$SHA"
export BAMBODIA_QA_PHP="$PHP"
bash "$APP/support/mcp-oauth-isolated-aapanel-rehearsal.sh" --check

echo 'QA_BOOTSTRAP=COMPLETED'
echo 'PRODUCTION_APPLICATION_DATA=NOT_TOUCHED'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'OAUTH_MIGRATIONS=NOT_RUN'
echo 'QA_SCHEMA=EMPTY'
