#!/usr/bin/env bash
# Safe Bambodia typography + compiled Vite frontend upgrade on the existing aaPanel site.
# Usage: bash support/typography-upgrade.sh --check GITHUB_RUN_ID FULL_COMMIT_SHA
#        bash support/typography-upgrade.sh --apply GITHUB_RUN_ID FULL_COMMIT_SHA
set -Eeuo pipefail
umask 077

APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
PHP='/www/server/php/85/bin/php'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
BACKUPS='/root/Bambodia-Typography-Backups'
BASE_NAV='982dd3b6f88a3be6ff54e33381ba1cffac6df300'
BASE_DISCOVERY='71f787e769a6a17dd79ad960c326348bd119c495'
FILES='resources/css/app.css resources/views/marketplace/search.blade.php'

MODE="$1"
RUN_ID="$2"
EXPECTED="$3"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || fail 'Specify --check or --apply'
[[ "$RUN_ID" =~ ^[0-9]{8,15}$ ]] || fail 'Expected GitHub Actions run ID'
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ ]] || fail 'Expected full Git SHA'
[[ "$(id -u)" == 0 ]] || fail 'Must be run as root on the aaPanel host'
for binary in gh git tar unzip cmp rsync install sha256sum cut runuser mktemp; do
  command -v "$binary" >/dev/null || fail "Missing $binary"
done
[[ -x "$PHP" && -d "$SOURCE/.git" ]] || fail 'aaPanel PHP or Bambodia source checkout missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || fail 'Installed Laravel app missing'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || fail 'Laravel runtime or build manifest missing'
[[ -f "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] || fail 'Unsafe site path'
[[ ! -e "$APP/storage/framework/down" ]] || fail 'Maintenance mode already active'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" \
  || fail 'Unexpected Nginx document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail 'Wrong Git remote'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Uncommitted source changes present'

echo "========== BAMBODIA TYPOGRAPHY UPGRADE ($MODE) =========="
echo '1. Verify pinned GitHub source and all four release checks'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$EXPECTED" ]] || fail 'Source SHA does not match release'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$EXPECTED&per_page=20" \
    --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$count" =~ ^[0-9]+$ ]] && (( count > 0 )) || fail "CI not passed: $wf"
  echo "CI passed: $wf"
done
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json headSha --jq '.headSha')" == "$EXPECTED" ]] \
  || fail 'Installer run does not belong to requested commit'
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json conclusion --jq '.conclusion')" == success ]] \
  || fail 'Installer run failed or remains incomplete'

echo '2. Confirm existing source files were not customized'
for file in $FILES; do
  [[ -f "$APP/$file" ]] || fail "Installed $file missing"
  if cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE_NAV:$file"); then
    echo "Approved navigation baseline: $file"
  elif cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE_DISCOVERY:$file"); then
    echo "Approved discovery baseline: $file"
  else
    fail "Unexpected installed changes in $file: stop for review"
  fi
done
[[ -f "$APP/resources/js/app.js" ]] || fail 'Installed JavaScript missing'
cmp -s "$APP/resources/js/app.js" "$SOURCE/resources/js/app.js" \
  || fail 'Installed JavaScript differs; cannot safely replace Vite bundle'

TEMP="$(mktemp -d /root/bambodia-type.XXXXXXXX)"
ARCHIVE=''
MAINTENANCE=0
APPLIED=0
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && APPLIED == 1 )); then
    echo 'Error: restoring the original CSS, search view and Vite bundle.' >&2
    if [[ -f "$ARCHIVE" ]]; then
      tar -xzf "$ARCHIVE" -C "$APP" || echo 'ALERT: Backup restoration failed' >&2
    fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if (( MAINTENANCE == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Website still in maintenance mode' >&2
  fi
  rm -rf -- "$TEMP"
  exit "$result"
}
trap cleanup EXIT

echo '3. Verify GitHub installer artifact against exact local source'
gh run download "$RUN_ID" -R "$REPO" -n "$ARTIFACT" -D "$TEMP"
PACKAGE="$TEMP/Bambodia-Installer.zip"
[[ -s "$PACKAGE" ]] || fail 'Installer ZIP missing'
unzip -tq "$PACKAGE" >/dev/null || fail 'Installer ZIP corrupt'
mkdir -p "$TEMP/release"
unzip -q "$PACKAGE" -d "$TEMP/release"
RELEASE="$TEMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" && -f "$APP/$stable" ]] || fail "Missing $stable"
  cmp -s "$APP/$stable" "$RELEASE/$stable" || fail "$stable differs from installed app"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || fail 'Compiled assets missing'
for file in $FILES resources/js/app.js; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$SOURCE/$file" "$RELEASE/$file" \
    || fail "Packaged source mismatch: $file"
done

if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: verified source, compiled assets and prior installed baseline.'
  echo 'No site, database, .env, private storage or Nginx changes were made.'
  echo "Apply: bash $SOURCE/support/typography-upgrade.sh --apply $RUN_ID $EXPECTED"
  exit 0
fi

echo '4. Create rollback backup'
install -d -m 0700 "$BACKUPS"
BACKUP="$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 0700 "$BACKUP"
ARCHIVE="$BACKUP/original.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" $FILES public/build
tar -tzf "$ARCHIVE" >/dev/null
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Backup: $ARCHIVE"

echo '5. Deploy verified CSS, search view and compiled assets under maintenance mode'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINTENANCE=1
APPLIED=1
for file in $FILES; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || fail "Copy mismatch: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" \
  || fail 'Deployed asset manifest differs from CI release'
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || fail '.env unexpectedly changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] \
  || fail 'Installed lock unexpectedly changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINTENANCE=0
APPLIED=0

echo '========== BAMBODIA TYPOGRAPHY UPGRADE SUCCESS =========='
echo "Verified GitHub commit: $EXPECTED"
echo "Rollback archive: $ARCHIVE"
echo 'Updated: CSS, marketplace search and Vite assets.'
echo 'Preserved: branding, accounts, database, .env, private vault, layouts and Nginx.'
