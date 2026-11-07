#!/usr/bin/env bash
# Guarded lifetime-license and support upgrade. New support schema requires a verified full MySQL snapshot.
# bash support/license-definitions-upgrade.sh --check INSTALLER_RUN_ID COMMIT_SHA
# bash support/license-definitions-upgrade.sh --apply INSTALLER_RUN_ID COMMIT_SHA
set -Eeuo pipefail
umask 077
SRC='/root/bambodia-release-audit'
APP='/www/wwwroot/bambodia-staging-qa'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
BASE='7cb49d518ba4e5e7235753b5192f9a8508ca1778'
BACKUPS='/root/Bambodia-LicenseDefinitions-Backups'
MODE="${1:-}"
RUN="${2:-}"
SHA="${3:-}"
stop(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || stop 'Use --check or --apply'
[[ "$RUN" =~ ^[0-9]{8,15}$ && "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop 'Expected installer run ID and full Git SHA'
[[ "$(id -u)" == 0 ]] || stop 'Run as root on aaPanel'
for tool in gh git tar unzip rsync cmp install sha256sum runuser gzip stat date; do
  command -v "$tool" >/dev/null || stop "Missing tool: $tool"
done
[[ -d "$SRC/.git" && -x "$PHP" ]] || stop 'PHP or source clone missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || stop 'Installed Bambodia identity missing'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || stop 'Runtime or compiled frontend missing'
[[ -f "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] || stop 'Unsafe site path'
[[ ! -e "$APP/storage/framework/down" ]] || stop 'Site already in maintenance'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" \
  || stop 'Unexpected Nginx root'
[[ "$(git -C "$SRC" remote get-url origin)" == "https://github.com/$REPO.git" ]] || stop 'Wrong remote'
[[ -z "$(git -C "$SRC" status --porcelain)" ]] || stop 'Source checkout is modified'
gh auth status >/dev/null 2>&1 || stop 'GitHub CLI unauthenticated'

EXISTING=(
 app/Models/LicenseDefinition.php
 app/Http/Controllers/Dashboard/LicenseManagerController.php
 app/Http/Controllers/BuilderController.php
 app/Http/Controllers/Creator/ProductController.php
 app/Support/ProductReadiness.php
 app/Support/ProductLicenseLifecycle.php
 resources/views/dashboard/licenses.blade.php
 resources/views/builder/index.blade.php
 resources/views/licenses/show.blade.php
 resources/js/app.js
 resources/css/app.css
 app/Http/Controllers/Account/ProductLicenseController.php
 app/Http/Controllers/Creator/ProductLicensesController.php
 resources/views/account/licenses.blade.php
 resources/views/account/creator-licenses.blade.php
 resources/views/account/products.blade.php
)
NEW=(
 database/migrations/2026_11_07_000080_add_license_definition_tier.php
 resources/views/components/license-definition-readonly.blade.php
)
ALL=("${EXISTING[@]}" "${NEW[@]}")
echo "========== LINKED REGULAR AND EXTENDED LICENSE DEFINITIONS ($MODE) =========="
git -C "$SRC" fetch origin main
git -C "$SRC" merge --ff-only origin/main
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] || stop 'Source does not match requested commit'
git -C "$SRC" cat-file -e "$BASE^{commit}" || stop 'Previous release commit unavailable'

echo '1. Compare installed sources with verified Issued License Manager release'
for file in "${EXISTING[@]}"; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Missing installed $file"
  cmp -s "$APP/$file" <(git -C "$SRC" show "$BASE:$file") \
    || stop "Customized installed file differs: $file"
  echo "MATCH: $file"
done
for file in "${NEW[@]}"; do
  [[ -f "$SRC/$file" ]] || stop "New file missing $file"
  [[ ! -e "$APP/$file" && ! -L "$APP/$file" ]] || stop "Already installed: $file"
done
echo '2. Verify six CI workflows and immutable package'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  result="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$SHA&per_page=20" \
    --jq '[.workflow_runs[] | select(.conclusion=="success")] | length')"
  [[ "$result" =~ ^[0-9]+$ ]] && (( result >= 1 )) || stop "CI missing: $wf"
  echo "PASS: $wf"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq .headSha)" == "$SHA" ]] || stop 'Installer SHA mismatch'
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq .conclusion)" == success ]] || stop 'Installer run failed'

TMP="$(mktemp -d /root/bambodia-studio.XXXXXXXX)"
ARCHIVE=''
BACKUP=''
DOWN=0
TOUCHED=0
MIGRATED=0
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo 'ALERT: Restoring original application files.' >&2
    [[ -f "$ARCHIVE" ]] && tar -xzf "$ARCHIVE" -C "$APP" || true
    for file in "${NEW[@]}"; do rm -f -- "$APP/$file"; done
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && \
      runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if (( rc != 0 && MIGRATED == 1 )); then
    echo 'ALERT: The new additive schema may still be present; do not auto-drop database columns.' >&2
    echo "Database snapshot was supplied by operator: ${BAMBODIA_DB_BACKUP_FILE:-none}" >&2
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Check maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
PKG="$TMP/Bambodia-Installer.zip"
[[ -s "$PKG" ]] || stop 'Installer ZIP missing'
unzip -tq "$PKG" >/dev/null || stop 'Installer archive corrupted'
mkdir -p "$TMP/release"
unzip -q "$PKG" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" ]] && cmp -s "$APP/$stable" "$RELEASE/$stable" \
    || stop "Runtime identity mismatch $stable"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || stop 'Compiled frontend missing'
for file in "${ALL[@]}"; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$SRC/$file" "$RELEASE/$file" \
    || stop "Installer package differs from Git source $file"
done
if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: Installed baseline, sources, CI and installer artifact verified.'
  echo 'No live site files or database were changed.'
  echo 'Apply requires a verified, fresh, root-private FULL MySQL dump in BAMBODIA_DB_BACKUP_FILE.'
  exit 0
fi

# Schema migrations are not automatically rolled back after failed deployment.
# Require a tested database snapshot rather than assuming files-only rollback is enough.
DB_BACKUP="${BAMBODIA_DB_BACKUP_FILE:-}"
[[ -n "$DB_BACKUP" && -f "$DB_BACKUP" && -s "$DB_BACKUP" ]] || stop 'Set BAMBODIA_DB_BACKUP_FILE to a recent verified full MySQL .sql.gz backup'
[[ "$DB_BACKUP" == /root/* && ! -L "$DB_BACKUP" ]] || stop 'Database backup must be a root-private regular file'
(( $(date +%s) - $(stat -c %Y "$DB_BACKUP") < 3600 )) || stop 'Database backup must be less than one hour old'
gzip -t "$DB_BACKUP" || stop 'Database backup gzip integrity failed'
BACKUP_MODE="$(stat -c %a "$DB_BACKUP")"
(( (8#$BACKUP_MODE & 077) == 0 )) || stop 'Database snapshot must not be accessible by group or others'

install -d -m 0700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/release-XXXXXXXX")"
ARCHIVE="$BACKUP/original-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" "${EXISTING[@]}" public/build
tar -tzf "$ARCHIVE" >/dev/null || stop 'Application file backup invalid'
ENV_SHA="$(sha256sum "$APP/.env" | cut -d ' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)"
echo "Rollback files: $ARCHIVE"
echo "Full database backup: $DB_BACKUP"

(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1
for file in "${NEW[@]}"; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
done
MIGRATED=1
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction)
for file in "${EXISTING[@]}"; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || stop "Copy verification failed: $file"
done
rsync -a "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build/"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" || stop 'Compiled frontend verification failed'
(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && \
  runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache && \
  runuser -u www -- "$PHP" artisan route:list --path=licenses)
[[ "$(sha256sum "$APP/.env" | cut -d ' ' -f1)" == "$ENV_SHA" ]] || stop '.env changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] || stop 'Installer lock changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0
MIGRATED=0
echo '========== LINKED REGULAR AND EXTENDED LICENSE DEFINITIONS DEPLOYED SUCCESSFULLY =========='
echo "Commit: $SHA"
echo "File backup: $ARCHIVE"
echo "Database snapshot: $DB_BACKUP"
echo 'Only additive license-definition type metadata migrated. No existing issued keys, product originals or entitlements changed.'
