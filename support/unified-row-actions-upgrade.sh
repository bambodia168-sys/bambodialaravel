#!/usr/bin/env bash
# Guarded Bambodia unified vertical row action menu rollout (views and frontend assets only).
# Usage: bash support/unified-row-actions-upgrade.sh --check COMMIT_SHA
#        bash support/unified-row-actions-upgrade.sh --apply COMMIT_SHA
set -Eeuo pipefail
umask 077
[[ "$#" -eq 2 ]] || { echo "Use --check|--apply FULL_COMMIT_SHA" >&2; exit 2; }
MODE="$1"
SHA="$2"
REPO="bambodia168-sys/bambodialaravel"
BASE="c5443bd37f038663f57b02b60b4e80b80d10d0b7"
SRC="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
BACKUP_DIR="/root/Bambodia-RowActions-Backups"
ARTIFACT="Bambodia-Installer-v0.0.1"
FILES=(
  resources/css/app.css
  resources/js/app.js
  resources/views/account/downloads.blade.php
  resources/views/account/licenses.blade.php
  resources/views/account/listings.blade.php
  resources/views/account/notifications.blade.php
  resources/views/account/privacy-requests.blade.php
  resources/views/account/product-versions.blade.php
  resources/views/account/products.blade.php
  resources/views/account/saved-jobs.blade.php
  resources/views/account/saved-listings.blade.php
  resources/views/components/icon.blade.php
  resources/views/contracts/index.blade.php
  resources/views/dashboard/categories.blade.php
  resources/views/dashboard/contents.blade.php
  resources/views/dashboard/contract-disputes.blade.php
  resources/views/dashboard/contracts.blade.php
  resources/views/dashboard/creator-applications.blade.php
  resources/views/dashboard/entitlements.blade.php
  resources/views/dashboard/features.blade.php
  resources/views/dashboard/jobs.blade.php
  resources/views/dashboard/knowledge-index.blade.php
  resources/views/dashboard/licenses.blade.php
  resources/views/dashboard/marketplace.blade.php
  resources/views/dashboard/policies.blade.php
  resources/views/dashboard/privacy-requests.blade.php
  resources/views/dashboard/products.blade.php
  resources/views/dashboard/reviews.blade.php
  resources/views/dashboard/site-settings.blade.php
  resources/views/dashboard/support.blade.php
  resources/views/dashboard/users.blade.php
  resources/views/dashboard/vault-versions.blade.php
  resources/views/developer/index.blade.php
  resources/views/inquiries/index.blade.php
  resources/views/jobs/mine.blade.php
  resources/views/jobs/proposals.blade.php
  resources/views/media/index.blade.php
)
NEW_FILE="resources/views/components/row-action-menu.blade.php"
stop(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || stop "Invalid mode"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop "Expected an exact 40-character SHA"
[[ "$(id -u)" == 0 ]] || stop "Run as root"
for cmd in git gh tar gzip cmp install stat runuser mktemp unzip rsync sha256sum chown; do
  command -v "$cmd" >/dev/null || stop "Missing $cmd"
done
[[ -d "$SRC/.git" && -x "$PHP" && ! -L "$APP" ]] || stop "Source, PHP or safe app path missing"
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" && -f "$APP/vendor/autoload.php" ]] || stop "Installed site identity missing"
[[ -f "$APP/public/build/manifest.json" && -f "$CONF" && ! -L "$CONF" ]] || stop "Manifest or safe Nginx config missing"
[[ ! -f "$APP/storage/framework/down" ]] || stop "Site is in maintenance mode"
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || stop "Unexpected document root"
[[ "$(git -C "$SRC" remote get-url origin)" == "https://github.com/$REPO.git" ]] || stop "Unexpected GitHub remote"
[[ -z "$(git -C "$SRC" status --porcelain)" ]] || stop "Source checkout is dirty"
gh auth status >/dev/null 2>&1 || stop "GitHub CLI is not authenticated"
git -C "$SRC" fetch origin main
git -C "$SRC" merge --ff-only origin/main
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] || stop "GitHub Main differs from expected commit"
git -C "$SRC" merge-base --is-ancestor "$BASE" "$SHA" || stop "Release base mismatch"
[[ ! -e "$APP/$NEW_FILE" && ! -L "$APP/$NEW_FILE" ]] || stop "Shared row action component already installed; refusing overwrite"
echo "========== VERIFY INSTALLED SOURCE BASELINE =========="
for file in "${FILES[@]}"; do
  [[ -f "$SRC/$file" && -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Missing or symlinked file: $file"
  cmp -s "$APP/$file" <(git -C "$SRC" show "$BASE:$file") || stop "Installed file customized since prior release: $file"
  echo "MATCH: $file"
done
echo "========== VERIFY SIX CI WORKFLOWS =========="
for workflow in quality.yml build-installer.yml installer-acceptance.yml operational-acceptance.yml browser-emulation.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$SHA&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$SHA"'" and .conclusion=="success")] | length')" || stop "CI lookup failed: $workflow"
  [[ "$count" =~ ^[0-9]+$ && "$count" -ge 1 ]] || stop "CI did not pass: $workflow"
  echo "PASS: $workflow"
done
echo "========== DOWNLOAD COMPILED FRONTEND RELEASE =========="
RUN="$(gh api "repos/$REPO/actions/workflows/build-installer.yml/runs?head_sha=$SHA&per_page=30" \
  --jq '[.workflow_runs[] | select(.head_sha=="'"$SHA"'" and .conclusion=="success")][0].id')" || stop "No release run"
[[ "$RUN" =~ ^[0-9]+$ ]] || stop "No successful build for exact SHA"
TMP="$(mktemp -d /root/bambodia-row-actions-XXXXXXXX)"
ARCHIVE=''
DOWN=0
TOUCHED=0
cleanup(){
  local rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo "ALERT: Restoring original files from $ARCHIVE" >&2
    rm -f -- "$APP/$NEW_FILE" || echo "ALERT: could not remove newly installed component" >&2
    tar -xzf "$ARCHIVE" -C "$APP" || echo "ALERT: restore failed, inspect backup" >&2
    (cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear && runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo "ALERT: manually exit maintenance mode" >&2
  fi
  rm -rf "$TMP"
  exit "$rc"
}
trap cleanup EXIT
gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP" || stop "Installer artifact download failed"
PKG="$TMP/Bambodia-Installer.zip"
[[ -s "$PKG" ]] || stop "Installer ZIP missing"
unzip -tq "$PKG" >/dev/null || stop "Invalid ZIP"
mkdir -p "$TMP/release"
unzip -q "$PKG" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" ]] && cmp -s "$RELEASE/$stable" "$APP/$stable" || stop "Release identity mismatch: $stable"
done
[[ -s "$RELEASE/public/build/manifest.json" ]] || stop "Compiled assets missing"
for file in "${FILES[@]}"; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$RELEASE/$file" "$SRC/$file" || stop "Artifact source mismatch: $file"
done
[[ -f "$RELEASE/$NEW_FILE" && ! -L "$RELEASE/$NEW_FILE" ]] || stop "Shared component missing from installer"
cmp -s "$RELEASE/$NEW_FILE" "$SRC/$NEW_FILE" || stop "Shared component does not match GitHub Main"
if [[ "$MODE" == --check ]]; then
  echo "BAMBODIA UNIFIED ROW ACTIONS PREFLIGHT PASSED: no production changes."
  exit 0
fi
echo "========== BACK UP AND APPLY =========="
install -d -m 0700 "$BACKUP_DIR"
SAVE="$(mktemp -d "$BACKUP_DIR/release-XXXXXXXX")"
ARCHIVE="$SAVE/original-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" "${FILES[@]}" public/build
tar -tzf "$ARCHIVE" >/dev/null || stop "Rollback archive invalid"
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "Rollback archive: $ARCHIVE"
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1
for file in "${FILES[@]}"; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$RELEASE/$file" "$APP/$file" || stop "Copy mismatch: $file"
done
install -o www -g www -m 0644 "$RELEASE/$NEW_FILE" "$APP/$NEW_FILE"
cmp -s "$RELEASE/$NEW_FILE" "$APP/$NEW_FILE" || stop "New component copy mismatch"
rsync -a "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$RELEASE/public/build/manifest.json" "$APP/public/build/manifest.json" || stop "Compiled manifest mismatch"
(cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear && runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || stop "Runtime configuration changed"
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_HASH" ]] || stop "Installer lock changed"
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0
echo "BAMBODIA UNIFIED ROW ACTIONS DEPLOYED SUCCESSFULLY"
echo "SHA: $SHA"
echo "Rollback archive: $ARCHIVE"
echo "Unified table-action menus deployed. No database content, permissions, licenses or policy publication states changed."
