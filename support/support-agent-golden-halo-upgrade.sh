#!/usr/bin/env bash
# Guarded Bambodia golden support-agent backdrop, stylesheet-only, no database writes.
# Usage: bash support/support-agent-golden-halo-upgrade.sh --check COMMIT_SHA
#        bash support/support-agent-golden-halo-upgrade.sh --apply COMMIT_SHA
set -Eeuo pipefail
umask 077
[[ "$#" -eq 2 ]] || { echo "Use --check|--apply FULL_COMMIT_SHA" >&2; exit 2; }
MODE="$1"
SHA="$2"
REPO="bambodia168-sys/bambodialaravel"
BASE_CSS="3cec64acdf69c5a03c04fc33ac5a531bc9a66e45"
BASE_VIEWS="3cec64acdf69c5a03c04fc33ac5a531bc9a66e45"
SRC="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
BACKUP_DIR="/root/Bambodia-SupportAgentGoldenHalo-Backups"
ARTIFACT="Bambodia-Installer-v0.0.1"
FILES=(resources/css/app.css)
PORTRAIT="public/images/support-agent-photo.webp"
DARK="public/images/support-agent-photo-dark.webp"
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
[[ -f "$APP/$PORTRAIT" && ! -L "$APP/$PORTRAIT" ]] || stop "Current support portrait absent or symlinked"
[[ -f "$APP/$DARK" && ! -L "$APP/$DARK" ]] || stop "Current dark support portrait absent or symlinked"
PORTRAIT_SHA="$(sha256sum "$APP/$PORTRAIT" | cut -d' ' -f1)"
DARK_SHA="$(sha256sum "$APP/$DARK" | cut -d' ' -f1)"
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || stop "Unexpected document root"
[[ "$(git -C "$SRC" remote get-url origin)" == "https://github.com/$REPO.git" ]] || stop "Unexpected GitHub remote"
[[ -z "$(git -C "$SRC" status --porcelain)" ]] || stop "Source checkout is dirty"
gh auth status >/dev/null 2>&1 || stop "GitHub CLI is not authenticated"
git -C "$SRC" fetch origin main
git -C "$SRC" merge --ff-only origin/main
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] || stop "GitHub Main differs from expected commit"
git -C "$SRC" merge-base --is-ancestor "$BASE_CSS" "$SHA" || stop "Release base mismatch"
echo "========== VERIFY INSTALLED SOURCE BASELINE =========="
for file in "${FILES[@]}"; do
  [[ -f "$SRC/$file" && -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Missing or symlinked file: $file"
  base="$BASE_VIEWS"
  if [[ "$file" == "resources/css/app.css" ]]; then base="$BASE_CSS"; fi
  cmp -s "$APP/$file" <(git -C "$SRC" show "$base:$file") || stop "Installed file customized since prior release: $file"
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
TMP="$(mktemp -d /root/bambodia-support-agent-golden-XXXXXXXX)"
ARCHIVE=''
DOWN=0
TOUCHED=0
cleanup(){
  local rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo "ALERT: Restoring original files from $ARCHIVE" >&2
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
if [[ "$MODE" == --check ]]; then
  echo "BAMBODIA SUPPORT AGENT GOLDEN HALO PREFLIGHT PASSED: no production changes."
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
rsync -a "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$RELEASE/public/build/manifest.json" "$APP/public/build/manifest.json" || stop "Compiled manifest mismatch"
(cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear && runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || stop "Runtime configuration changed"
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_HASH" ]] || stop "Installer lock changed"
[[ "$(sha256sum "$APP/$PORTRAIT" | cut -d' ' -f1)" == "$PORTRAIT_SHA" ]] || stop "Unexpected portrait asset mutation"
[[ "$(sha256sum "$APP/$DARK" | cut -d' ' -f1)" == "$DARK_SHA" ]] || stop "Unexpected dark portrait asset mutation"
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0
echo "BAMBODIA SUPPORT AGENT GOLDEN HALO DEPLOYED SUCCESSFULLY"
echo "SHA: $SHA"
echo "Rollback archive: $ARCHIVE"
echo "Only hero CSS changed. Both portraits, article records, tickets, licenses and policies unchanged."
