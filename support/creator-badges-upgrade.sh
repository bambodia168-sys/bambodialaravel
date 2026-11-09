#!/usr/bin/env bash
# Bambodia: verified 16 premium creator badge upgrade for aaPanel.
# Use --check then --apply. Refuses to overwrite unknown site customizations.
set -Eeuo pipefail
umask 077

APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
RELEASE='5bb8f14eab6f4b67de4a352adb346ed20345e10a'
BASE='14820b3441be44fb101e593900c16a1458abe69e'
RUN='42557534735'
ARTIFACT='Bambodia-Installer-v0.0.1'
BACKUPS='/root/Bambodia-CreatorBadges-Backups'
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
FILES='app/Models/AuditEvent.php app/Http/Controllers/Creator/CreatorProfileController.php routes/web.php resources/views/layouts/workspace.blade.php resources/views/creators/show.blade.php resources/views/creators/index.blade.php resources/views/account/creator-profile.blade.php resources/views/components/icon.blade.php resources/css/app.css'
NEW_FILES='app/Models/CreatorBadge.php app/Models/CreatorBadgeGrant.php app/Support/CreatorBadgeAwards.php app/Http/Controllers/Dashboard/CreatorBadgeController.php resources/views/components/creator-badges.blade.php resources/views/dashboard/creator-badges.blade.php database/migrations/2026_11_09_001000_create_creator_badges.php'
BADGES='top-rated rising-talent expert-vetted fast-responder identity-verified job-success-100 repeat-clients top-rated-plus on-time-delivery pro-verified super-seller-level-two top-collaborator skill-certified high-earner community-leader vip-client-favorite'
MODE="$1"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail 'Use --check or --apply'
[[ "$(id -u)" == 0 ]] || fail 'Run as root on aaPanel'
for bin in gh git curl tar unzip rsync cmp install sha256sum cut runuser mktemp gzip; do
  command -v "$bin" >/dev/null || fail "Missing binary: $bin"
done
[[ -x "$PHP" && -d "$SOURCE/.git" && -d "$APP" && ! -L "$APP" ]] || fail 'Unexpected app/source/PHP paths'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" && -f "$APP/vendor/autoload.php" ]] || fail 'Bambodia runtime or installer lock missing'
[[ -f "$APP/public/build/manifest.json" && ! -L "$APP/public/build" ]] || fail 'Unexpected compiled build'
[[ -f "$CONF" && ! -e "$APP/storage/framework/down" ]] || fail 'Vhost missing or app already in maintenance'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || fail 'Unexpected Nginx document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail 'Unexpected Git remote'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Dirty source checkout'

echo "========== BAMBODIA CREATOR BADGES ($MODE) =========="
echo '1. Verify GitHub Main, fixed release and all six CI workflows'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
git -C "$SOURCE" merge-base --is-ancestor "$RELEASE" HEAD || fail 'Validated release is not an ancestor of Main'
cmp -s "$0" "$SOURCE/support/creator-badges-upgrade.sh" || fail 'Downloaded script differs from Github Main'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  passes="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$RELEASE&per_page=20" --jq '[.workflow_runs[] | select(.status == "completed" and .conclusion == "success")] | length')"
  [[ "$passes" =~ ^[0-9]+$ && "$passes" -ge 1 ]] || fail "CI not successful: $wf"
  echo "CI passed: $wf"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq '.headSha')" == "$RELEASE" ]] || fail 'Installer run SHA mismatch'
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq '.conclusion')" == 'success' ]] || fail 'Installer run not successful'

echo '2. Confirm exact installed previous release; never overwrite customized files'
for file in $FILES; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || fail "Missing installed file: $file"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") || fail "Installed file differs from last production baseline: $file"
done
for file in $NEW_FILES; do
  [[ ! -e "$APP/$file" && ! -L "$APP/$file" ]] || fail "Badge feature already installed/customized: $file"
done
[[ ! -e "$APP/public/images/badges" && ! -L "$APP/public/images/badges" ]] || fail 'Existing badge asset directory requires manual review'
[[ -f "$DB_HELPER" ]] || fail 'Private MySQL backup helper missing'
for file in $FILES $NEW_FILES; do
  cmp -s "$SOURCE/$file" <(git -C "$SOURCE" show "$RELEASE:$file") || fail "Current source differs from verified release: $file"
done
for badge in $BADGES; do
  file="public/images/badges/$badge.svg"
  cmp -s "$SOURCE/$file" <(git -C "$SOURCE" show "$RELEASE:$file") || fail "Unverified badge artwork: $file"
done

TMP="$(mktemp -d /root/bambodia-badges.XXXXXXXX)"
ARCHIVE=''
DB_BACKUP=''
IN_MAINTENANCE=0
APPLIED=0
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && APPLIED == 1 )); then
    echo 'DEPLOYMENT FAILED: restoring previous files and compiled frontend' >&2
    if (( IN_MAINTENANCE == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      IN_MAINTENANCE=1
    fi
    if [[ -f "$ARCHIVE" ]]; then
      tar -xzf "$ARCHIVE" -C "$APP" || echo 'ALERT: Manual rollback inspection needed' >&2
    fi
    for file in $NEW_FILES; do
      [[ ! -L "$APP/$file" ]] && rm -f -- "$APP/$file"
    done
    for badge in $BADGES; do
      [[ ! -L "$APP/public/images/badges/$badge.svg" ]] && rm -f -- "$APP/public/images/badges/$badge.svg"
    done
    rmdir "$APP/public/images/badges" 2>/dev/null || true
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache) || true
    echo 'NOTICE: Additive badge tables may remain; do not automatically restore the full DB.' >&2
    echo "Files backup: $ARCHIVE" >&2
    echo "Database backup: $DB_BACKUP" >&2
  fi
  if (( IN_MAINTENANCE == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Laravel remains in maintenance; check the site.' >&2
  fi
  rm -rf -- "$TMP"
  exit "$result"
}
trap cleanup EXIT

echo '3. Download and inspect verified GitHub installer'
gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
ZIP="$TMP/Bambodia-Installer.zip"
[[ -s "$ZIP" ]] || fail 'Installer ZIP missing'
unzip -tq "$ZIP" >/dev/null || fail 'Installer ZIP invalid'
mkdir -m 0700 "$TMP/release"
unzip -q "$ZIP" -d "$TMP/release"
REL="$TMP/release/Bambodia"
for file in artisan composer.json VERSION; do
  [[ -f "$REL/$file" && -f "$APP/$file" ]] || fail "Runtime file missing: $file"
  cmp -s "$APP/$file" "$REL/$file" || fail "Unexpected runtime change: $file"
done
[[ -s "$REL/public/build/manifest.json" ]] || fail 'Built frontend missing'
for file in $FILES $NEW_FILES; do
  [[ -f "$REL/$file" && -f "$SOURCE/$file" ]] || fail "Release file missing: $file"
  cmp -s "$REL/$file" "$SOURCE/$file" || fail "Release file mismatch: $file"
  if [[ "$file" == *.php ]]; then "$PHP" -l "$REL/$file" >/dev/null || fail "PHP syntax failure: $file"; fi
done
for badge in $BADGES; do
  file="public/images/badges/$badge.svg"
  [[ -s "$REL/$file" ]] && cmp -s "$REL/$file" "$SOURCE/$file" && grep -Fq '<svg' "$REL/$file" || fail "Invalid or missing SVG: $file"
done
if [[ "$MODE" == '--check' ]]; then
  echo 'CHECK PASSED: verified existing production, 16 SVG medals, installer and six CI workflows.'
  echo 'No production files, database or server config changed.'
  echo 'Next: bash /root/bambodia-creator-badges-upgrade.sh --apply'
  exit 0
fi

echo '4. Create private file archive and full database snapshot'
install -d -m 0700 "$BACKUPS"
DEST="$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -m 0700 "$DEST"
ARCHIVE="$DEST/original.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" $FILES public/build
chmod 0600 "$ARCHIVE"
tar -tzf "$ARCHIVE" >/dev/null || fail 'File backup invalid'
DB_BACKUP="$(bash "$DB_HELPER")" || fail 'MySQL backup failed'
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] || fail 'Unverified DB snapshot path'
gzip -t "$DB_BACKUP" || fail 'Invalid DB backup gzip'
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Private file backup: $ARCHIVE"
echo "Private MySQL backup: $DB_BACKUP"

echo '5. Install scoped badge changes while Laravel is in maintenance mode'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
IN_MAINTENANCE=1
APPLIED=1
for file in $FILES $NEW_FILES; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$REL/$file" "$APP/$file"
  cmp -s "$APP/$file" "$REL/$file" || fail "Installed file mismatch: $file"
done
install -d -o www -g www -m 0755 "$APP/public/images/badges"
for badge in $BADGES; do
  file="public/images/badges/$badge.svg"
  install -o www -g www -m 0644 "$REL/$file" "$APP/$file"
  cmp -s "$APP/$file" "$REL/$file" || fail "Installed badge mismatch: $file"
done
rsync -a --delete "$REL/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$REL/public/build/manifest.json" || fail 'Compiled assets mismatch'

echo '6. Run additive badge migration and verify Blade, routes and catalog'
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache)
(cd "$APP" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (\App\Models\CreatorBadge::query()->count() !== 16) exit(2);
if (! \Illuminate\Support\Facades\Route::has("dashboard.badges.index")) exit(3);
echo "VERIFIED: 16 badge definitions and Admin Badge Manager route.\n";
') || fail 'Badge catalog or route verification failed'
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail 'Unexpected .env change'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_HASH" ]] || fail 'Unexpected install lock change'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
IN_MAINTENANCE=0

echo '7. Final public HTTP smoke tests'
for url in 'https://bambodia.com/' 'https://bambodia.com/creators' 'https://bambodia.com/images/badges/top-rated.svg' 'https://bambodia.com/images/badges/on-time-delivery.svg'; do
  code="$(curl -sSL --max-time 25 -o /dev/null -w '%{http_code}' "$url")"
  [[ "$code" == 200 ]] || fail "HTTP check failed: $url returned $code"
  echo "HTTP 200: $url"
done
APPLIED=0
echo '========== BAMBODIA PREMIUM CREATOR BADGES SUCCESS =========='
echo "Release: $RELEASE"
echo "Rollback archive: $ARCHIVE"
echo "MySQL backup: $DB_BACKUP"
echo 'Changed: badge PHP, Blade, CSS, icon component, 16 SVGs and compiled assets.'
echo 'Preserved: listings, private vault, checkout gates, Nginx, PHP-FPM and installed configuration.'
