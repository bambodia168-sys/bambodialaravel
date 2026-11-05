#!/usr/bin/env bash
# Bambodia public frontend navigation update for an existing aaPanel install.
# bash support/frontend-navigation-upgrade.sh --check RUN_ID FULL_SHA
# bash support/frontend-navigation-upgrade.sh --apply RUN_ID FULL_SHA
set -Eeuo pipefail
umask 077

APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
BASE='45855e9591753f204570a143b53a33572f8a96fd'
BACKUP_PARENT='/root/Bambodia-Frontend-Backups'

MODE="${1:-}"
RUN_ID="${2:-}"
EXPECTED="${3:-}"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }

[[ "$MODE" == --check || "$MODE" == --apply ]] || fail 'Specify --check or --apply'
[[ "$RUN_ID" =~ ^[0-9]{8,15}$ ]] || fail 'Expected numerical GitHub Actions run ID'
[[ "$EXPECTED" =~ ^[a-f0-9]{40}$ ]] || fail 'Expected full 40-character Git SHA'
[[ "$(id -u)" == 0 ]] || fail 'Run as root'
for tool in gh git php unzip tar rsync cmp install sha256sum cut runuser; do
    command -v "$tool" >/dev/null || fail "Missing $tool"
done
[[ -x "$PHP" ]] || fail 'Missing aaPanel PHP 8.5'
[[ -d "$SOURCE/.git" ]] || fail 'Source clone missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || fail 'Installed application not verified'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || fail 'Laravel runtime unavailable'
[[ -f "$APP/resources/views/layouts/workspace.blade.php" ]] || fail 'Previous workspace upgrade is not installed'
[[ ! -e "$APP/storage/framework/down" ]] || fail 'Maintenance mode already active'
[[ -f "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] || fail 'Site path verification failed'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" \
    || fail 'Unexpected Nginx document root'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Source repository has local modifications'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] \
    || fail 'GitHub origin is not the expected repository'
gh auth status >/dev/null 2>&1 || fail 'GitHub CLI is not authenticated'

FILES=(
  resources/views/layouts/main.blade.php
  resources/css/app.css
  resources/js/app.js
)
# Do not overwrite customized site files or deploy over a different release.
for file in "${FILES[@]}"; do
    [[ -f "$APP/$file" ]] || fail "Installed source missing: $file"
    cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") \
      || fail "Installed $file differs from the verified prior release. Stop to review changes."
done

echo "========== BAMBODIA FRONTEND NAVIGATION UPGRADE ($MODE) =========="
echo '1. Verify exact GitHub source and successful CI build'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$EXPECTED" ]] \
  || fail 'Local source does not match requested release'
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json headSha --jq '.headSha')" == "$EXPECTED" ]] \
  || fail 'GitHub Actions build commit mismatch'
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json conclusion --jq '.conclusion')" == success ]] \
  || fail 'GitHub Actions build is not successful'

TMP="$(mktemp -d /root/bambodia-frontend.XXXXXXXX)"
BACKUP_ARCHIVE=''
MAINTENANCE_STARTED=0
FILES_APPLIED=0
cleanup(){
  status=$?
  trap - EXIT
  if ((status != 0 && FILES_APPLIED == 1)); then
    echo 'Upgrade failed; restoring original public frontend files.' >&2
    if [[ -f "$BACKUP_ARCHIVE" ]]; then
      tar -xzf "$BACKUP_ARCHIVE" -C "$APP" || echo 'ALERT: Backup restoration failed' >&2
    fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if ((MAINTENANCE_STARTED == 1)); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) \
      || echo 'ALERT: Site still in maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$status"
}
trap cleanup EXIT

echo '2. Download verified installer artifact'
gh run download "$RUN_ID" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
PACKAGE="$TMP/Bambodia-Installer.zip"
[[ -s "$PACKAGE" ]] || fail 'Missing packaged installer ZIP'
unzip -tq "$PACKAGE"
mkdir -p "$TMP/release"
unzip -q "$PACKAGE" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" ]] && cmp -s "$APP/$stable" "$RELEASE/$stable" \
    || fail "Installed $stable does not match packaged release"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || fail 'No frontend build manifest'
for file in "${FILES[@]}"; do
  [[ -f "$RELEASE/$file" && -f "$SOURCE/$file" ]] || fail "Missing $file"
  cmp -s "$SOURCE/$file" "$RELEASE/$file" || fail "Packaged file mismatch: $file"
done

if [[ "$MODE" == '--check' ]]; then
  echo 'CHECK PASSED: three existing public frontend sources and compiled assets verified.'
  echo 'Database, application files and server configurations were not changed.'
  echo "Apply using: bash $SOURCE/support/frontend-navigation-upgrade.sh --apply $RUN_ID $EXPECTED"
  exit 0
fi

echo '3. Back up installed frontend and compiled assets'
install -d -m 0700 "$BACKUP_PARENT"
BACKUP="$BACKUP_PARENT/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 0700 "$BACKUP"
BACKUP_ARCHIVE="$BACKUP/original.tar.gz"
tar -czf "$BACKUP_ARCHIVE" -C "$APP" "${FILES[@]}" public/build
tar -tzf "$BACKUP_ARCHIVE" >/dev/null
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Backup: $BACKUP_ARCHIVE"

echo '4. Apply only verified frontend changes in maintenance mode'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINTENANCE_STARTED=1
FILES_APPLIED=1
for file in "${FILES[@]}"; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || fail "Verification failed: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" \
  || fail 'Compiled frontend manifest mismatch'

echo '5. Refresh compiled Blade and check unchanged secrets'
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || fail '.env changed unexpectedly'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] || fail 'Installed lock changed unexpectedly'

echo '6. Bring the website online'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINTENANCE_STARTED=0
FILES_APPLIED=0
echo '========== FRONTEND NAVIGATION UPGRADE SUCCESS =========='
echo "Verified commit: $EXPECTED"
echo "Rollback backup: $BACKUP_ARCHIVE"
echo 'Database, .env, private vault, user accounts, routes, workspace views and Nginx: UNCHANGED'
