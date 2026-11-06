#!/usr/bin/env bash
# Bambodia role-scoped operational dashboard release for existing aaPanel installations.
# Usage: bash support/dashboard-upgrade.sh --check GITHUB_INSTALLER_RUN_ID FULL_COMMIT_SHA
#        bash support/dashboard-upgrade.sh --apply GITHUB_INSTALLER_RUN_ID FULL_COMMIT_SHA
set -Eeuo pipefail
umask 077

APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
BASE='624b680b08b7a8d0702ed587c6c6c654839594e7'
BACKUP_PARENT='/root/Bambodia-Dashboard-Backups'
NEW_COMPONENT='resources/views/components/workspace-metric.blade.php'
FILES='app/Http/Controllers/Dashboard/DashboardController.php app/Http/Controllers/AccountController.php app/Http/Controllers/DeveloperController.php resources/views/dashboard/index.blade.php resources/views/account/studio.blade.php resources/views/account/index.blade.php resources/views/developer/index.blade.php resources/css/app.css'
MODE="$1"
RUN_ID="$2"
EXPECTED="$3"

block(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || block 'Specify --check or --apply'
[[ "$RUN_ID" =~ ^[0-9]{8,15}$ ]] || block 'Expected numeric GitHub Actions run ID'
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ ]] || block 'Expected full 40-character GitHub commit SHA'
[[ "$(id -u)" == 0 ]] || block 'Run as root on the aaPanel server'
for binary in gh git tar unzip rsync cmp install sha256sum cut runuser mktemp; do
  command -v "$binary" >/dev/null || block "Missing tool: $binary"
done
[[ -x "$PHP" && -d "$SOURCE/.git" ]] || block 'aaPanel PHP 8.5 or source Git checkout is missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || block 'Installed Laravel site not verified'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || block 'Installed Laravel runtime/compiled assets missing'
[[ -f "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] || block 'Unsafe production paths'
[[ ! -e "$APP/storage/framework/down" ]] || block 'Site is already in maintenance mode'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" \
  || block 'Unexpected Bambodia Nginx document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || block 'Unexpected Git remote'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || block 'Uncommitted source modifications present'

echo "========== BAMBODIA WORKSPACE DASHBOARD UPGRADE ($MODE) =========="
echo '1. Confirm exact GitHub Main and four passing CI workflows'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$EXPECTED" ]] || block 'GitHub Main differs from requested release'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml; do
  successes="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$EXPECTED&per_page=20" \
    --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$successes" =~ ^[0-9]+$ ]] && (( successes >= 1 )) || block "CI not successful: $wf"
  echo "CI pass: $wf"
done
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json headSha --jq '.headSha')" == "$EXPECTED" ]] \
  || block 'Installer run commit mismatch'
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json conclusion --jq '.conclusion')" == 'success' ]] \
  || block 'Installer run is not successful'

echo '2. Verify exact installed previous release; never overwrite customizations'
for file in $FILES; do
  [[ -f "$APP/$file" ]] || block "Installed $file is missing"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") \
    || block "Installed $file differs from verified prior release"
done
[[ ! -e "$APP/$NEW_COMPONENT" ]] || block 'Dashboard metric component already exists; refusing to overwrite it'
[[ -f "$APP/public/images/bambodia-logo.webp" && -f "$APP/public/images/bambodia-logo-dark.svg" ]] \
  || block 'Previously installed brand assets missing'
for logo in public/images/bambodia-logo.webp public/images/bambodia-logo-dark.svg; do
  cmp -s "$APP/$logo" <(git -C "$SOURCE" show "$BASE:$logo") \
    || block "Previously installed logo differs from verified baseline: $logo"
done
cmp -s "$APP/resources/js/app.js" <(git -C "$SOURCE" show "$BASE:resources/js/app.js") \
  || block 'Installed frontend JavaScript differs from verified baseline'

TMP="$(mktemp -d /root/bambodia-dashboard.XXXXXXXX)"
BACKUP_ARCHIVE=''
MAINTENANCE=0
FILES_APPLIED=0
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && FILES_APPLIED == 1 )); then
    echo 'Upgrade failed; restoring original dashboards, controllers, CSS and compiled assets.' >&2
    if [[ -f "$BACKUP_ARCHIVE" ]]; then
      tar -xzf "$BACKUP_ARCHIVE" -C "$APP" \
        || echo 'ALERT: Could not fully restore backup archive' >&2
    fi
    rm -f -- "$APP/$NEW_COMPONENT"
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if (( MAINTENANCE == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) \
      || echo 'ALERT: Application remains in maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$result"
}
trap cleanup EXIT

echo '3. Download and inspect the immutable CI installer artifact'
gh run download "$RUN_ID" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
PACKAGE="$TMP/Bambodia-Installer.zip"
[[ -s "$PACKAGE" ]] || block 'Expected GitHub installer archive missing'
unzip -tq "$PACKAGE" >/dev/null || block 'Installer ZIP checksum/structure verification failed'
mkdir -p "$TMP/release"
unzip -q "$PACKAGE" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" && -f "$APP/$stable" ]] || block "Missing $stable"
  cmp -s "$APP/$stable" "$RELEASE/$stable" || block "$stable does not match installed Bambodia"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || block 'Compiled Vite manifest missing from installer'
for file in $FILES "$NEW_COMPONENT" resources/js/app.js; do
  [[ -f "$RELEASE/$file" && -f "$SOURCE/$file" ]] || block "Packaged $file is missing"
  cmp -s "$RELEASE/$file" "$SOURCE/$file" || block "Packaged $file differs from exact source"
done

if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: previous header release and four CI workflows verified.'
  echo 'No installed site files, settings, database or server configuration changed.'
  echo "Apply: bash $SOURCE/support/dashboard-upgrade.sh --apply $RUN_ID $EXPECTED"
  exit 0
fi

echo '4. Back up every modified file and the compiled frontend'
install -d -m 0700 "$BACKUP_PARENT"
BACKUP="$BACKUP_PARENT/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 0700 "$BACKUP"
BACKUP_ARCHIVE="$BACKUP/original.tar.gz"
tar -czf "$BACKUP_ARCHIVE" -C "$APP" $FILES public/build
tar -tzf "$BACKUP_ARCHIVE" >/dev/null
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Backup: $BACKUP_ARCHIVE"

echo '5. Enter maintenance mode; deploy allowlisted dashboard code, components and compiled CSS'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINTENANCE=1
FILES_APPLIED=1
for file in $FILES "$NEW_COMPONENT"; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || block "Installed file failed verification: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" \
  || block 'Deployed Vite manifest does not match verified release'

echo '6. Refresh Blade; verify immutable configuration; restore online service'
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || block '.env changed unexpectedly'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] \
  || block 'Installed lock unexpectedly changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINTENANCE=0
FILES_APPLIED=0

echo '========== BAMBODIA WORKSPACE DASHBOARD UPGRADE SUCCESS =========='
echo "Verified commit: $EXPECTED"
echo "Rollback archive: $BACKUP_ARCHIVE"
echo 'Updated: Admin, Creator, Account, Developer dashboard modules and shared CSS.'
echo 'Unchanged: database, accounts, secrets, private vault, routes, logos, Nginx and PHP-FPM.'
