#!/usr/bin/env bash
# Bambodia: remove public marketplace-card excerpts; add curated Featured and product Live Preview.
# Usage: bash support/compact-marketplace-cards-upgrade.sh --check GITHUB_INSTALLER_RUN_ID FULL_COMMIT_SHA
#        bash support/compact-marketplace-cards-upgrade.sh --apply GITHUB_INSTALLER_RUN_ID FULL_COMMIT_SHA
set -Eeuo pipefail
umask 077

APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
BASE='49aab4e5df8e851540936f965e02f0b34e36634f'
BACKUP_PARENT='/root/Bambodia-CompactCards-Backups'
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
FILES='app/Models/AuditEvent.php app/Models/MarketplaceListing.php app/Models/MarketplaceJob.php app/Http/Controllers/Creator/ProductController.php routes/web.php resources/views/builder/index.blade.php resources/views/components/listing-card.blade.php resources/views/components/job-list-row.blade.php resources/views/components/related-job-card.blade.php resources/views/dashboard/marketplace.blade.php resources/views/dashboard/products.blade.php resources/views/dashboard/jobs.blade.php resources/css/app.css resources/js/app.js'
NEW_FILES='app/Support/MarketplaceCardPresentation.php app/Http/Controllers/Dashboard/MarketplaceCardFeaturesController.php database/migrations/2026_11_09_000230_add_marketplace_card_metadata.php'

MODE="$1"
RUN_ID="$2"
EXPECTED="$3"

block(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || block 'Specify --check or --apply'
[[ "$RUN_ID" =~ ^[0-9]{8,15}$ ]] || block 'Expected numeric GitHub Actions run ID'
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ ]] || block 'Expected full 40-character GitHub commit SHA'
[[ "$(id -u)" == 0 ]] || block 'Run as root on the aaPanel server'
for binary in gh git tar unzip rsync cmp install sha256sum cut runuser mktemp python3; do
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

echo "========== BAMBODIA COMPACT MARKETPLACE CARDS UPGRADE ($MODE) =========="
echo '1. Confirm exact GitHub Main and six passing CI workflows'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$EXPECTED" ]] || block 'GitHub Main differs from requested release'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  successes="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$EXPECTED&per_page=20" \
    --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$successes" =~ ^[0-9]+$ ]] && (( successes >= 1 )) || block "CI not successful: $wf"
  echo "CI pass: $wf"
done
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json headSha --jq '.headSha')" == "$EXPECTED" ]] \
  || block 'Installer run commit mismatch'
[[ "$(gh run view "$RUN_ID" -R "$REPO" --json conclusion --jq '.conclusion')" == 'success' ]] \
  || block 'Installer run is not successful'

echo '2. Verify exact installed application baseline; never overwrite customizations'
for file in $FILES; do
  [[ -f "$APP/$file" ]] || block "Installed $file is missing"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") \
    || block "Installed $file differs from verified prior release"
done
for file in $NEW_FILES; do
  [[ ! -e "$APP/$file" && ! -L "$APP/$file" ]] || block "Already customized new feature path: $file"
done
[[ -f "$APP/public/images/bambodia-logo.webp" && -f "$APP/public/images/bambodia-logo-dark.svg" ]] \
  || block 'Previously installed brand assets missing'
for logo in public/images/bambodia-logo.webp public/images/bambodia-logo-dark.svg; do
  cmp -s "$APP/$logo" <(git -C "$SOURCE" show "$BASE:$logo") \
    || block "Previously installed logo differs from verified baseline: $logo"
done
cmp -s "$APP/resources/js/app.js" <(git -C "$SOURCE" show "$BASE:resources/js/app.js") \
  || block 'Installed frontend JavaScript differs from verified baseline'

TMP="$(mktemp -d /root/bambodia-frontend.XXXXXXXX)"
BACKUP_ARCHIVE=''
MAINTENANCE=0
FILES_APPLIED=0
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && FILES_APPLIED == 1 )); then
    echo 'Upgrade failed; restoring previous application files and frontend assets.' >&2
    if [[ -f "$BACKUP_ARCHIVE" ]]; then
      tar -xzf "$BACKUP_ARCHIVE" -C "$APP" \
        || echo 'ALERT: File rollback failed; manual intervention is required' >&2
    fi
    for file in $NEW_FILES; do
      if [[ -f "$APP/$file" && ! -L "$APP/$file" ]]; then
        rm -f -- "$APP/$file"
      fi
    done
    echo 'NOTICE: Additive Featured/preview columns may remain; inspect private MySQL snapshot before manual schema rollback.' >&2
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
for marker in 'marketplace-card-creator' 'marketplace-card-live-preview' 'marketplace-card-featured' 'marketplace-card-footer' 'bambodia-job-badge--featured'; do
  grep -Fq "$marker" "$SOURCE/resources/css/app.css" || block "Compact card styles missing: $marker"
done
for file in $FILES $NEW_FILES; do
  [[ -f "$RELEASE/$file" && -f "$SOURCE/$file" ]] || block "Packaged $file is missing"
  cmp -s "$RELEASE/$file" "$SOURCE/$file" || block "Packaged $file differs from exact source"
done

if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: installed operational baseline and six verified CI workflows.'
  echo 'No installed site files, settings, database or server configuration changed.'
  echo "Apply: bash $SOURCE/support/compact-marketplace-cards-upgrade.sh --apply $RUN_ID $EXPECTED"
  exit 0
fi

echo '4. Back up every modified file and the compiled frontend'
install -d -m 0700 "$BACKUP_PARENT"
BACKUP="$BACKUP_PARENT/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 0700 "$BACKUP"
BACKUP_ARCHIVE="$BACKUP/original.tar.gz"
tar -czf "$BACKUP_ARCHIVE" -C "$APP" $FILES public/build
test -f "$APP/resources/views/components/icon.blade.php" || block 'Existing icon component missing'
tar -tzf "$BACKUP_ARCHIVE" >/dev/null
[[ -f "$DB_HELPER" ]] || block 'Trusted database backup helper missing'
DB_BACKUP="$(bash "$DB_HELPER")" || block 'Fresh MySQL snapshot failed'
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] || block 'Unexpected database backup path'
gzip -t "$DB_BACKUP" || block 'MySQL snapshot failed gzip integrity check'
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Backup: $BACKUP_ARCHIVE"

echo '5. Deploy verified compact cards, product previews, administrator Featured controls and compiled assets'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINTENANCE=1
FILES_APPLIED=1
for file in $FILES; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || block "Installed file failed verification: $file"
done
for file in $NEW_FILES; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || block "New feature file failed verification: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" \
  || block 'Deployed Vite manifest does not match verified release'

echo '6. Apply additive Featured / product preview migration, refresh route and Blade caches'
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction)
(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || block '.env changed unexpectedly'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] \
  || block 'Installed lock unexpectedly changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINTENANCE=0
echo '7. Smoke-check summary-free cards, five detail routes, job rows, licensing and existing content'
for url in \
 'https://bambodia.com/project?=800849627-layout-test-small-business-crm-demonstration' \
 'https://bambodia.com/product?=140274590-layout-test-real-estate-property-ui-bundle' \
 'https://bambodia.com/service?=958904265-layout-test-responsive-ui-and-ux-evaluation' \
 'https://bambodia.com/freelance?=261182035-layout-test-digital-marketing-campaign-planner' \
 'https://bambodia.com/jobs/456265990-layout-test-write-website-service-pages'; do
  html="$(curl -fsSLsS --max-time 25 "$url")" || block "Detail unavailable: $url"
  grep -Fq 'class="premium-detail-floating-type"' <<<"$html" || block "Missing floating label: $url"
  grep -Fq 'class="premium-detail-below-title"' <<<"$html" || block "Missing title below feature: $url"
  grep -Fq 'data-detail-root' <<<"$html" || block "Shared tab shell missing: $url"
  grep -Fq 'premium-detail-tabs--shared' <<<"$html" || block "Shared tabs missing: $url"
  grep -Fq 'premium-offer-v3' <<<"$html" || block "Premium offer card missing: $url"
  grep -Fq 'premium-feature-checklist' <<<"$html" || block "Factual checkmark list missing: $url"
  grep -Fq 'premium-detail-media--' <<<"$html" || block "Unified featured-media frame missing: $url"
  grep -Fq 'data-featured-stage' <<<"$html" || block "Featured-media stage marker missing: $url"
  if grep -Fq 'LAYOUT TEST ONLY — no purchases' <<<"$html"; then
    block "Old unrelated demo notice remains in the sidebar: $url"
  fi
  if grep -Fq 'class="marketplace-breadcrumb"' <<<"$html"; then
    block "Old breadcrumb still visible: $url"
  fi
  echo "Detail verified: $url"
done
# The reference product must show the real license selector, not a fake Buy Now.
PRODUCT="$(curl -fsSLsS --max-time 25 'https://bambodia.com/product?=140274590-layout-test-real-estate-property-ui-bundle')" || block 'Product detail unavailable'
grep -Fq 'data-detail-license-tier' <<<"$PRODUCT" || block 'Product license selector missing'
grep -Fq 'premium-detail-media--product' <<<"$PRODUCT" || block 'Product media frame missing'
grep -Fq 'premium-license-scope' <<<"$PRODUCT" || block 'Product license scope disclosure missing'
grep -Fq 'View license details' <<<"$PRODUCT" || block 'Product terms action missing'
grep -Fq 'Direct checkout is not enabled' <<<"$PRODUCT" || block 'Checkout restriction missing'
if grep -qi '>Buy Now<' <<<"$PRODUCT"; then block 'Unverified purchase button in disabled checkout'; fi
for route in \
 '/detail/project/800849627-layout-test-small-business-crm-demonstration/comments' \
 '/detail/job/456265990-layout-test-write-website-service-pages/support'; do
  code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' "https://bambodia.com$route")"
  [[ "$code" == '302' ]] || block "Guest access not protected: $route HTTP $code"
done
HOME="$(curl -fsSLsS --max-time 25 https://bambodia.com/)" || block 'Homepage unavailable'
if grep -Fq 'class="marketplace-card-summary"' <<<"$HOME"; then
  block 'Marketplace card summary was not removed'
fi
if grep -Fq 'bambodia-job-excerpt' <<<"$HOME"; then
  block 'Job card excerpt was not removed'
fi
grep -Fq 'class="marketplace-card-price"' <<<"$HOME" || block 'Compact listing card price missing'
for marker in 'id="bambodia-blog"' 'id="bambodia-jobs"' 'id="bambodia-advantage"' 'id="bambodia-join"'; do
  grep -Fq "$marker" <<<"$HOME" || block "Existing homepage section missing: $marker"
done
FILES_APPLIED=0
echo '========== BAMBODIA COMPACT MARKETPLACE CARDS UPGRADE SUCCESS =========='
echo "Verified commit: $EXPECTED"
echo "Rollback archive: $BACKUP_ARCHIVE"
echo "Fresh MySQL snapshot: $DB_BACKUP"
echo 'Updated: Summary-free Product/Project/Service/Freelance/Job cards, currency symbols, curator-controlled Featured badges and optional secure HTTPS product Live Preview.'
echo 'Retained: Responsive grids, detail navigation, sidebars, license features, comments, support and disabled-checkout safeguards.'
echo 'Checkout remains disabled. Additive columns default to no Featured and no Live Preview; existing records remain unchanged.'
echo 'Preserved: Existing records, content routes, private vault, checkout gates, Nginx and PHP-FPM.'
