#!/usr/bin/env bash
# Bambodia complete public creator badges and selectable artwork-only display.
set -Eeuo pipefail
umask 077
APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
FEATURE='6779d5098d5600eac630266b879de465b413968b'
BASE='3fd775e149e1ea71c25147ea591a507dad5b079e'
RUN='42662103469'
ARTIFACT='Bambodia-Installer-v0.0.1'
BACKUPS='/root/Bambodia-PublicBadges-Backups'
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
FILES='app/Http/Controllers/Dashboard/CreatorBadgeController.php app/Support/CreatorBadgeDisplay.php resources/views/dashboard/creator-badges.blade.php resources/views/components/creator-badges.blade.php resources/views/marketplace/detail.blade.php resources/css/app.css'
NEW_FILES='database/migrations/2026_11_09_004200_default_compact_badge_display.php'
MODE="$1"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || fail 'Use --check or --apply'
[[ "$(id -u)" -eq 0 ]] || fail 'Run as root on aaPanel server'
for bin in gh git curl cmp tar unzip rsync install sha256sum cut gzip runuser mktemp; do
  command -v "$bin" >/dev/null || fail "Missing dependency: $bin"
done
[[ -d "$SOURCE/.git" && -x "$PHP" && -f "$APP/artisan" ]] || fail 'Unexpected source, PHP or site path'
[[ -f "$APP/.env" && -f "$APP/vendor/autoload.php" && -f "$APP/storage/app/installed.lock" ]] || fail 'Laravel runtime missing'
[[ -f "$APP/public/build/manifest.json" && ! -L "$APP/public/build" ]] || fail 'Compiled frontend missing or symlinked'
[[ ! -L "$APP" && -f "$CONF" && ! -e "$APP/storage/framework/down" ]] || fail 'Unsafe site path or site in maintenance'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || fail 'Wrong production document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail 'Unexpected Git remote'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Uncommitted Git source changes found'
[[ -f "$DB_HELPER" ]] || fail 'Private database backup helper missing'
# Validate stdin-based PHP before production changes. aaPanel PHP does not accept 'php -'.
php_stdin="$( "$PHP" <<'PHP'
<?php echo 'PHP_STDIN_OK';
PHP
)"
[[ "$php_stdin" == 'PHP_STDIN_OK' ]] || fail 'aaPanel PHP stdin execution unavailable'

echo "========== BAMBODIA PUBLIC BADGE DISPLAY FIX ($MODE) =========="
echo '1. Verify feature source and all six CI workflows'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
git -C "$SOURCE" merge-base --is-ancestor "$FEATURE" HEAD || fail 'Verified feature not in Main'
cmp -s "$0" "$SOURCE/support/public-badge-display-upgrade.sh" || fail 'Downloaded script does not match GitHub Main'
for workflow in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$FEATURE&per_page=20" --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$count" =~ ^[0-9]+$ && "$count" -ge 1 ]] || fail "CI not successful: $workflow"
  echo "CI passed: $workflow"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq '.headSha')" == "$FEATURE" ]] || fail 'Artifact commit mismatch'
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq '.conclusion')" == success ]] || fail 'Artifact workflow did not pass'

echo '2. Verify exact installed badge baseline and refuse customizations'
for file in $FILES; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || fail "Unsafe installed file: $file"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") || fail "Existing customization found: $file"
  cmp -s "$SOURCE/$file" <(git -C "$SOURCE" show "$FEATURE:$file") || fail "Source differs from verified release: $file"
done
for file in $NEW_FILES; do
  [[ ! -e "$APP/$file" && ! -L "$APP/$file" ]] || fail "New feature already installed/customized: $file"
  cmp -s "$SOURCE/$file" <(git -C "$SOURCE" show "$FEATURE:$file") || fail "Source differs from verified feature: $file"
done

TMP="$(mktemp -d /root/bambodia-public-badges.XXXXXXXX)"
BACKUP=''
DB_BACKUP=''
APPLIED=0
MAINT=0
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && APPLIED == 1 )); then
    echo 'DEPLOYMENT FAILED: Restoring previous files and built frontend' >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -s "$BACKUP" ]]; then
      tar -xzf "$BACKUP" -C "$APP" || echo 'ALERT: File rollback requires inspection' >&2
    fi
    for file in $NEW_FILES; do
      [[ ! -L "$APP/$file" ]] && rm -f -- "$APP/$file"
    done
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache) || true
    echo "File rollback: $BACKUP" >&2
    echo "Full MySQL snapshot: $DB_BACKUP" >&2
    echo 'NOTICE: Additive database columns/tables are retained; database is not automatically restored.' >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Laravel may remain in maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$result"
}
trap cleanup EXIT

echo '2b. Inspect previous attempt migration state (read-only)'
(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$applied=\Illuminate\Support\Facades\DB::table('migrations')
 ->where('migration','2026_11_09_004200_default_compact_badge_display')
 ->exists();
$appearance=\App\Support\CreatorBadgeDisplay::settings();
echo 'MIGRATION_STATE='.($applied?'ALREADY_APPLIED':'PENDING')."\n";
echo 'CURRENT_DISPLAY_MODE='.$appearance->display_mode."\n";
PHP
) || fail 'Could not inspect previously applied migration'

echo '3. Download and inspect fixed CI installer'
gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
ZIP="$TMP/Bambodia-Installer.zip"
[[ -s "$ZIP" ]] && unzip -tq "$ZIP" >/dev/null || fail 'Installer ZIP unavailable or corrupt'
mkdir "$TMP/release"
unzip -q "$ZIP" -d "$TMP/release"
REL="$TMP/release/Bambodia"
for file in artisan composer.json VERSION; do
  [[ -f "$APP/$file" && -f "$REL/$file" ]] && cmp -s "$APP/$file" "$REL/$file" || fail "Unrecognized core runtime: $file"
done
[[ -f "$REL/public/build/manifest.json" ]] || fail 'Compiled assets missing'
for file in $FILES $NEW_FILES; do
  [[ -f "$REL/$file" ]] && cmp -s "$REL/$file" "$SOURCE/$file" || fail "Artifact source mismatch: $file"
  if [[ "$file" == *.php ]]; then "$PHP" -l "$REL/$file" >/dev/null || fail "PHP lint failed: $file"; fi
done
if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: 6 workflows, original site baseline, artifact and PHP lint.'
  echo 'No production files, database, permissions or settings changed.'
  exit 0
fi

echo '4. Create a private file backup and full MySQL snapshot'
install -d -m 0700 "$BACKUPS"
DEST="$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -m 0700 "$DEST"
BACKUP="$DEST/original.tar.gz"
tar -czf "$BACKUP" -C "$APP" $FILES public/build
chmod 0600 "$BACKUP"
tar -tzf "$BACKUP" >/dev/null || fail 'File archive invalid'
DB_BACKUP="$(bash "$DB_HELPER")" || fail 'MySQL backup failed'
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] || fail 'Invalid DB snapshot path'
gzip -t "$DB_BACKUP" || fail 'MySQL backup integrity failed'
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Files: $BACKUP"
echo "Database: $DB_BACKUP"

echo '5. Deploy scoped public badge views, settings and compiled assets'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINT=1
APPLIED=1
for file in $FILES $NEW_FILES; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$REL/$file" "$APP/$file"
  cmp -s "$REL/$file" "$APP/$file" || fail "Installed file differs: $file"
done
rsync -a --delete "$REL/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$REL/public/build/manifest.json" "$APP/public/build/manifest.json" || fail 'Compiled assets mismatch'

echo '6. Apply compact default and verify badge catalog, grants and project page'
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache)
(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings=\App\Support\CreatorBadgeDisplay::settings();
if($settings->display_mode !== 'badge' || !$settings->tooltips_enabled)exit(2);
if(!\Illuminate\Support\Facades\Route::has('dashboard.badges.index') ||
 !\Illuminate\Support\Facades\Route::has('dashboard.badges.display.update'))exit(3);
$detail=file_get_contents(resource_path('views/marketplace/detail.blade.php'));
if(str_contains($detail,'$creatorBadges->take(') || !str_contains($detail,'mode="badge"'))exit(4);
$admin=file_get_contents(resource_path('views/dashboard/creator-badges.blade.php'));
if(!str_contains($admin,'Badge Only') || !str_contains($admin,'Badge with name'))exit(5);
if(!str_contains($admin,'badge-catalog-gallery-grid') || !str_contains($admin,'data-table badge-manager-table'))exit(6);
$creator=\App\Models\User::query()->where('public_id','34302348')->first();
if(!$creator || $creator->role!=='freelancer')exit(7);
if(\App\Support\CreatorBadgeAwards::earned($creator)->count()!==16)exit(8);
echo "Creator 34302348: all 16 awards confirmed, artwork-only display set, existing catalog/table intact.\n";
PHP
) || fail 'Post-deployment badge settings and award checks failed'
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || fail '.env changed unexpectedly'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] || fail 'Installer lock changed unexpectedly'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINT=0

echo '7. Public health and guest-auth protection'
for url in 'https://bambodia.com/' 'https://bambodia.com/creators' 'https://bambodia.com/images/badges/top-rated.svg'; do
  code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' "$url")"
  [[ "$code" == 200 ]] || fail "Public page HTTP $code: $url"
  echo "HTTP 200: $url"
done
echo '8. Verify the live Samith Ton project page renders every medal twice'
PUBLIC_DETAIL='https://bambodia.com/project?=800849627-layout-test-small-business-crm-demonstration'
curl -fsSL --max-time 35 "$PUBLIC_DETAIL" -o "$TMP/public-detail.html"
PUBLIC_BADGE_HTML="$TMP/public-detail.html" "$PHP" <<'PHP'
<?php
$path=getenv('PUBLIC_BADGE_HTML');
if(!$path || !is_file($path))exit(10);
$html=file_get_contents($path);
if(!str_contains($html,'Samith Ton'))exit(11);
$sellerAt=strpos($html,'id="seller"');
$sidebarAt=strpos($html,'class="premium-side-card premium-side-creator"');
if($sellerAt===false || $sidebarAt===false || $sellerAt>=$sidebarAt) {
 fwrite(STDERR,"Cannot locate both creator sections in public detail.\n");
 exit(12);
}
$seller=substr($html,$sellerAt,$sidebarAt-$sellerAt);
$sidebar=substr($html,$sidebarAt);
foreach (['About the creator'=>$seller,'Published by'=>$sidebar] as $section=>$fragment) {
 $count=substr_count($fragment,'class="creator-badge creator-badge--');
 $tips=substr_count($fragment,'class="creator-badge-tooltip" role="tooltip"');
 if($count!==16 || $tips!==16 || !str_contains($fragment,'creator-badge-row--badge')) {
  fwrite(STDERR,"$section: medals=$count; tooltips=$tips; expected 16 each and artwork-only mode.\n");
  exit(13);
 }
}
echo "PUBLIC_DETAIL=PASS: 16 artwork-only medals and accessible hover descriptions in each creator panel.\n";
PHP
guest_code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' 'https://bambodia.com/dashboard/creator-badges')"
[[ "$guest_code" == 302 || "$guest_code" == 303 ]] || fail "Unexpected protected creator badge route HTTP $guest_code"
APPLIED=0
echo '========== PUBLIC BADGE DISPLAY FIX DEPLOYED =========='
echo "FEATURE_COMMIT=$FEATURE"
echo 'PAGINATION=10 per page, Previous/Next, numbered pages and Go To'
echo 'DISPLAY=Badge Only or Badge with name selectable on main badge catalog'
echo 'PUBLIC_PROFILE=all sixteen medals and hover descriptions in creator panels'
echo "ROLLBACK_FILES=$BACKUP"
echo "MYSQL_BACKUP=$DB_BACKUP"
echo 'ADMIN=https://bambodia.com/dashboard/creator-badges'
echo 'SETTINGS=https://bambodia.com/dashboard/creator-badges/settings'
echo 'PRESERVED=badge grants, premium catalog, user table, licenses, vault, server configuration'
