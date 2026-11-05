#!/usr/bin/env bash
# Safe upgrade for an ALREADY INSTALLED Bambodia instance on aaPanel.
# bash support/workspace-upgrade.sh --check RUN_ID FULL_40_CHARACTER_SHA
# bash support/workspace-upgrade.sh --apply RUN_ID FULL_40_CHARACTER_SHA
set -Eeuo pipefail
umask 077
APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
BACKUP_PARENT='/root/Bambodia-Workspace-Backups'
MODE="${1:-}"
RUN_ID="${2:-}"
EXPECTED_SHA="${3:-}"
stop(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || stop 'Use --check or --apply'
[[ "$RUN_ID" =~ ^[0-9]{8,15}$ ]] || stop 'Expected a numeric GitHub Actions run ID'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || stop 'Expected a full 40-character Git SHA'
[[ "$(id -u)" == '0' ]] || stop 'Run as root on the aaPanel server'
for tool in gh git tar unzip rsync cmp curl install sha256sum runuser cut; do
  command -v "$tool" >/dev/null || stop "Missing $tool"
done
[[ -x "$PHP" ]] || stop 'PHP 8.5 missing'
[[ -d "$SOURCE/.git" ]] || stop 'GitHub source checkout missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || stop 'Existing installation or lock missing'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || stop 'Runtime missing'
[[ -f "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] || stop 'Invalid target paths'
[[ ! -e "$APP/resources/views/layouts/workspace.blade.php" ]] || stop 'Workspace layout already installed; refusing reapplication'
[[ ! -f "$APP/storage/framework/down" ]] || stop 'Site was already in maintenance mode'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" \
  || stop 'bambodia.com is not pointing at the expected Laravel public directory'
ORIGIN="$(git -C "$SOURCE" remote get-url origin)"
case "$ORIGIN" in
  "https://github.com/$REPO.git"|"git@github.com:$REPO.git") ;;
  *) stop "Incorrect Git remote: $ORIGIN" ;;
esac
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || stop 'Git source is modified; do not overwrite local work'
gh auth status >/dev/null 2>&1 || stop 'GitHub CLI is not authenticated'

echo '========== BAMBODIA WORKSPACE UPGRADE =========='
echo "1. Synchronizing verified GitHub main ($MODE)"
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$EXPECTED_SHA" ]] || stop 'Main SHA differs from requested release'
RUN_SHA="$(gh run view "$RUN_ID" -R "$REPO" --json headSha --jq '.headSha')"
RUN_RESULT="$(gh run view "$RUN_ID" -R "$REPO" --json conclusion --jq '.conclusion')"
[[ "$RUN_SHA" == "$EXPECTED_SHA" && "$RUN_RESULT" == success ]] || stop 'Installer package run has not passed for requested SHA'

TMP="$(mktemp -d /root/bambodia-upgrade.XXXXXXXX)"
BACKUP_ARCHIVE=''
MAINTENANCE_STARTED=0
FILES_APPLIED=0
NEW_FILE='resources/views/layouts/workspace.blade.php'
cleanup(){
  result=$?
  trap - EXIT
  if ((result != 0 && FILES_APPLIED == 1)); then
    echo 'Upgrade failed. Attempting file rollback.' >&2
    if [[ -f "$BACKUP_ARCHIVE" ]]; then
      tar -xzf "$BACKUP_ARCHIVE" -C "$APP" || echo 'ERROR: Could not restore backup' >&2
    fi
    rm -f -- "$APP/$NEW_FILE"
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && \
      runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if ((MAINTENANCE_STARTED == 1)); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) \
      || echo 'ERROR: Check Laravel maintenance mode immediately' >&2
  fi
  rm -rf -- "$TMP"
  exit "$result"
}
trap cleanup EXIT

echo '2. Downloading exact CI installer artifact'
gh run download "$RUN_ID" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
PACKAGE="$TMP/Bambodia-Installer.zip"
[[ -s "$PACKAGE" ]] || stop 'Installer ZIP not found'
unzip -tq "$PACKAGE"
mkdir -p "$TMP/release"
unzip -q "$PACKAGE" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" ]] || stop "Package missing $stable"
  cmp -s "$APP/$stable" "$RELEASE/$stable" || stop "Installed $stable differs; full installer upgrade needed"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || stop 'Missing compiled frontend'
cmp -s "$RELEASE/routes/web.php" "$SOURCE/routes/web.php" || stop 'Release and Git source differ'

# Strictly allowlisted existing files — no .env, DB, vendor or private storage.
FILES=(
  app/Http/Controllers/AccountController.php
  app/Http/Controllers/LocaleController.php
  app/Http/Middleware/SetLocale.php
  routes/web.php
  resources/css/app.css
  resources/js/app.js
  resources/views/components/icon.blade.php
  resources/views/layouts/main.blade.php
  resources/views/layouts/dashboard.blade.php
  resources/views/account/creator-application.blade.php
  resources/views/account/creator-profile.blade.php
  resources/views/account/downloads.blade.php
  resources/views/account/index.blade.php
  resources/views/account/listings.blade.php
  resources/views/account/notifications.blade.php
  resources/views/account/policy-receipts.blade.php
  resources/views/account/privacy-request.blade.php
  resources/views/account/privacy-requests.blade.php
  resources/views/account/product-versions.blade.php
  resources/views/account/products.blade.php
  resources/views/account/resume.blade.php
  resources/views/account/saved-jobs.blade.php
  resources/views/account/saved-listings.blade.php
  resources/views/account/studio.blade.php
  resources/views/developer/docs.blade.php
  resources/views/developer/index.blade.php
)
for file in "${FILES[@]}" "$NEW_FILE"; do
  [[ -f "$RELEASE/$file" && -f "$SOURCE/$file" ]] || stop "Source missing $file"
  cmp -s "$RELEASE/$file" "$SOURCE/$file" || stop "Package mismatch $file"
done
for file in "${FILES[@]}"; do
  [[ -f "$APP/$file" ]] || stop "Installed original missing $file"
done
echo "Preflight passed: ${#FILES[@]} existing files, one new layout, compiled assets"
if [[ "$MODE" == '--check' ]]; then
  echo 'CHECK PASSED. No application, database or server configuration changes made.'
  echo "To apply: bash $SOURCE/support/workspace-upgrade.sh --apply $RUN_ID $EXPECTED_SHA"
  exit 0
fi

echo '3. Backing up installed files and frontend assets'
install -d -m 0700 "$BACKUP_PARENT"
BACKUP="$BACKUP_PARENT/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 0700 "$BACKUP"
BACKUP_ARCHIVE="$BACKUP/original.tar.gz"
tar -czf "$BACKUP_ARCHIVE" -C "$APP" "${FILES[@]}" public/build
tar -tzf "$BACKUP_ARCHIVE" >/dev/null
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Backup: $BACKUP_ARCHIVE"

echo '4. Entering maintenance mode and applying verified source'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINTENANCE_STARTED=1
FILES_APPLIED=1
for file in "${FILES[@]}" "$NEW_FILE"; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || stop "Copy verification failed: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" || stop 'Frontend manifest mismatch'
echo '5. Refreshing compiled routes and Blade views'
(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && \
  runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || stop 'Environment file unexpectedly changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] || stop 'Installer lock unexpectedly changed'
echo '6. Bringing application online'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINTENANCE_STARTED=0
FILES_APPLIED=0
echo '========== WORKSPACE UPGRADE SUCCESS =========='
echo "GitHub SHA: $EXPECTED_SHA"
echo "Backup: $BACKUP_ARCHIVE"
echo 'Database, .env, installed.lock, vendor, Nginx and PHP-FPM: UNCHANGED'
echo 'If PHP controller changes remain stale, reload PHP-FPM 8.5 in aaPanel.'
