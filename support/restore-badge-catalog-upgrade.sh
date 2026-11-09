#!/usr/bin/env bash
# Bambodia scoped badge-catalog restoration; guarded aaPanel upgrade.
set -Eeuo pipefail
umask 077
APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
FEATURE='31f70478c953b2ca3300e5224eec1616a12b2bc0'
BASE='c357d9bffed61e17508d98ceea045a35b8335299'
RUN='42648105589'
ARTIFACT='Bambodia-Installer-v0.0.1'
BACKUPS='/root/Bambodia-BadgeCatalogRestore-Backups'
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
FILES='app/Http/Controllers/Dashboard/CreatorBadgeController.php app/Models/CreatorBadge.php app/Support/CreatorBadgeAwards.php resources/views/dashboard/creator-badges.blade.php resources/css/app.css'
NEW_FILES='database/migrations/2026_11_09_004100_add_creator_badge_auto_conditions.php'
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

echo "========== BAMBODIA RESTORE BADGE CATALOG ($MODE) =========="
echo '1. Verify feature source and all six CI workflows'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
git -C "$SOURCE" merge-base --is-ancestor "$FEATURE" HEAD || fail 'Verified feature not in Main'
cmp -s "$0" "$SOURCE/support/restore-badge-catalog-upgrade.sh" || fail 'Downloaded script does not match GitHub Main'
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

TMP="$(mktemp -d /root/bambodia-badge-catalog.XXXXXXXX)"
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

echo '5. Restore catalog cards without changing the existing user table or other routes'
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

echo '6. Add configurable auto-conditions and refresh cached Blade templates'
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache)
(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\CreatorBadge;
foreach(['auto_rules','eligibility_criteria'] as $column) {
 if(!Schema::hasColumn('creator_badges',$column))exit(3);
}
if(CreatorBadge::query()->count()<16)exit(4);
foreach(['rising-talent','repeat-clients','on-time-delivery'] as $slug){
 $badge=CreatorBadge::query()->where('slug',$slug)->first();
 if(!$badge || !is_array($badge->auto_rules) || !$badge->auto_rules)exit(5);
}
if(!Route::has('dashboard.badges.index') || !Route::has('dashboard.badges.grant'))exit(6);
$blade=resource_path('views/dashboard/creator-badges.blade.php');
$html=file_get_contents($blade);
if(strpos($html,'id="badge-catalog"')===false
 || strpos($html,'data-table badge-manager-table')===false
 || strpos($html,'id="badge-catalog"')>strpos($html,'data-table badge-manager-table'))exit(7);
echo "Verified: original medal catalog above preserved grant table; auto rules migrated.\n";
PHP
) || fail 'Restored badge catalog/conditions verification failed'
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
guest_code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' 'https://bambodia.com/dashboard/creator-badges')"
[[ "$guest_code" == 302 || "$guest_code" == 303 ]] || fail "Unexpected protected creator badge route HTTP $guest_code"
APPLIED=0
echo '========== ORIGINAL BADGE CATALOG RESTORED =========='
echo "FEATURE_COMMIT=$FEATURE"
echo 'PAGINATION=10 per page, Previous/Next, numbered pages and Go To'
echo 'SECTION_1=original badge catalog, all 16 medal cards, inline edit/enable/disable and eligibility conditions'
echo 'SECTION_2=existing Grant badges to creators table and per-user popup, unchanged'
echo "ROLLBACK_FILES=$BACKUP"
echo "MYSQL_BACKUP=$DB_BACKUP"
echo 'ADMIN=https://bambodia.com/dashboard/creator-badges'
echo 'SETTINGS=https://bambodia.com/dashboard/creator-badges/settings'
echo 'PRESERVED=existing badge grants, all 16 SVG medals, user table, licenses, private vault and server settings'
