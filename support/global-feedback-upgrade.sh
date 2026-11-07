#!/usr/bin/env bash
# File-only global feedback upgrade. Never runs migrations or publishes policies.
set -Eeuo pipefail
umask 077
MODE="${1:-}"
SHA="${2:-}"
BASE="f2775e710bcf314ae6a0f73f8d677fe720b71317"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
REPO="bambodia168-sys/bambodialaravel"
VHOST="/www/server/panel/vhost/nginx/bambodia.com.conf"
ARTIFACT="Bambodia-Installer-v0.0.1"
stop(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || stop "Usage: --check|--apply FULL_SHA"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop "Invalid SHA"
[[ "$(id -u)" == 0 ]] || stop "Run as root on aaPanel"
for tool in git gh tar gzip cmp unzip rsync mktemp install runuser stat sha256sum; do
  command -v "$tool" >/dev/null || stop "Missing $tool"
done
[[ -d "$SOURCE/.git" && -x "$PHP" ]] || stop "Missing source or PHP"
[[ ! -L "$APP" && ! -L "$VHOST" && -f "$VHOST" ]] || stop "Unsafe site paths"
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" && -f "$APP/vendor/autoload.php" &&
   -f "$APP/public/build/manifest.json" ]] || stop "Installed Laravel site not verified"
[[ ! -f "$APP/storage/framework/down" ]] || stop "Site is already in maintenance mode"
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$VHOST" ||
  stop "Unexpected production document root"
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  stop "Unexpected GitHub remote"
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || stop "Source has local edits"
gh auth status >/dev/null 2>&1 || stop "Missing GitHub authentication"
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] || stop "GitHub Main SHA mismatch"
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$SHA" || stop "Expected baseline not an ancestor"
EXISTING=(
  resources/views/layouts/workspace.blade.php
  resources/views/layouts/main.blade.php
  resources/views/layouts/auth.blade.php
  resources/css/app.css
  resources/js/app.js
  resources/views/account/creator-application.blade.php
  resources/views/account/creator-profile.blade.php
  resources/views/account/listings.blade.php
  resources/views/account/product-versions.blade.php
  resources/views/account/products.blade.php
  resources/views/account/resume.blade.php
  resources/views/account/saved-jobs.blade.php
  resources/views/account/saved-listings.blade.php
  resources/views/account/index.blade.php
  resources/views/account/notifications.blade.php
  resources/views/account/policy-receipts.blade.php
  resources/views/account/privacy-request.blade.php
  resources/views/account/privacy-requests.blade.php
  resources/views/dashboard/contract-dispute-detail.blade.php
  resources/views/dashboard/entitlements.blade.php
  resources/views/dashboard/privacy-request.blade.php
  resources/views/dashboard/vault-versions.blade.php
  resources/views/dashboard/policies.blade.php
  resources/views/dashboard/policy-builder.blade.php
  resources/views/support/index.blade.php
  resources/views/support/show.blade.php
  resources/views/contracts/show.blade.php
  resources/views/inquiries/show.blade.php
  resources/views/jobs/index.blade.php
  resources/views/jobs/mine.blade.php
  resources/views/jobs/proposals.blade.php
  resources/views/jobs/show.blade.php
  resources/views/media/index.blade.php
  resources/views/marketplace/detail.blade.php
  resources/views/marketplace/search.blade.php
  resources/views/builder/index.blade.php
)
NEW=(resources/views/components/flash-feedback.blade.php)
ALL=("${EXISTING[@]}" "${NEW[@]}")
echo "========== VERIFY PRODUCTION FILE BASELINE =========="
for file in "${EXISTING[@]}"; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Missing production file: $file"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") ||
    stop "Production file changed outside upgrade: $file"
done
[[ ! -e "$APP/${NEW[0]}" && -f "$SOURCE/${NEW[0]}" ]] || stop "New feedback component is already installed or missing"
[[ -f "$SOURCE/tests/Feature/GlobalFeedbackTest.php" ]] || stop "Missing feedback tests"
echo "========== VERIFY SIX EXACT-COMMIT CI WORKFLOWS =========="
for workflow in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$SHA&per_page=30" \
      --jq '[.workflow_runs[] | select(.head_sha=="'$SHA'" and .conclusion=="success")] | length')" ||
      stop "Cannot query CI: $workflow"
  [[ "$count" =~ ^[0-9]+$ && "$count" -ge 1 ]] || stop "CI not passed: $workflow"
  echo "PASS: $workflow"
done
echo "========== VERIFY EXACT RELEASE FRONTEND =========="
RUN="$(gh api "repos/$REPO/actions/workflows/build-installer.yml/runs?head_sha=$SHA&per_page=30" \
  --jq '[.workflow_runs[] | select(.head_sha=="'$SHA'" and .conclusion=="success")][0].id')"
[[ "$RUN" =~ ^[0-9]+$ ]] || stop "Installer workflow not found"
TMP="$(mktemp -d /root/bambodia-feedback.XXXXXXXX)"
DOWN=0
TOUCHED=0
ARCHIVE=""
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo "ROLLBACK: original files and compiled assets" >&2
    tar -xzf "$ARCHIVE" -C "$APP" || true
    rm -f -- "$APP/${NEW[0]}"
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear) || true
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
      echo "ALERT: manually restore application live mode!" >&2
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT
gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP" ||
  stop "Failed to download release artifact"
ZIP="$TMP/Bambodia-Installer.zip"
[[ -s "$ZIP" ]] || stop "Missing installer ZIP"
unzip -tq "$ZIP" >/dev/null || stop "Corrupt installer ZIP"
mkdir -p "$TMP/pkg"
unzip -q "$ZIP" -d "$TMP/pkg"
RELEASE="$TMP/pkg/Bambodia"
for file in artisan composer.json VERSION; do
  [[ -f "$APP/$file" && -f "$RELEASE/$file" ]] &&
     cmp -s "$APP/$file" "$RELEASE/$file" || stop "Application identity differs: $file"
done
[[ -s "$RELEASE/public/build/manifest.json" ]] || stop "Missing compiled assets"
for file in "${ALL[@]}"; do
  [[ -f "$RELEASE/$file" && -f "$SOURCE/$file" ]] &&
    cmp -s "$RELEASE/$file" "$SOURCE/$file" || stop "Artifact differs from GitHub: $file"
done
if [[ "$MODE" == --check ]]; then
  echo "GLOBAL FEEDBACK PREFLIGHT PASSED — no production or database changes"
  exit 0
fi
DB_BACKUP="${BAMBODIA_DB_BACKUP_FILE:-}"
[[ "$DB_BACKUP" == /root/*.sql.gz && -f "$DB_BACKUP" && -s "$DB_BACKUP" && ! -L "$DB_BACKUP" ]] ||
   stop "Protected compressed MySQL backup required under /root"
(( $(date +%s) - $(stat -c %Y "$DB_BACKUP") < 3600 )) || stop "MySQL backup is older than one hour"
BITS="$(stat -c %a "$DB_BACKUP")"
(( (8#$BITS & 077) == 0 )) || stop "MySQL backup must be private"
gzip -t "$DB_BACKUP" || stop "Invalid MySQL backup"
install -d -m 0700 /root/Bambodia-Feedback-Backups
SAVE="$(mktemp -d /root/Bambodia-Feedback-Backups/release-XXXXXXXX)"
ARCHIVE="$SAVE/previous-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" "${EXISTING[@]}" public/build
tar -tzf "$ARCHIVE" >/dev/null || stop "Invalid file rollback archive"
ENV_HASH="$(sha256sum "$APP/.env" | cut -d ' ' -f1)"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)"
echo "File recovery: $ARCHIVE"
echo "Database recovery: $DB_BACKUP"
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1
for file in "${ALL[@]}"; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || stop "Installed file mismatch: $file"
done
rsync -a "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" ||
  stop "Installed build manifest mismatch"
(cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear &&
 runuser -u www -- "$PHP" artisan view:cache &&
 runuser -u www -- "$PHP" artisan route:list --path=policy-center >/dev/null)
[[ "$(sha256sum "$APP/.env" | cut -d ' ' -f1)" == "$ENV_HASH" ]] || stop "Unexpected .env change"
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)" == "$LOCK_HASH" ]] ||
  stop "Unexpected install lock change"
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0
echo "BAMBODIA GLOBAL FEEDBACK DEPLOYED SUCCESSFULLY"
echo "Commit: $SHA"
echo "No migrations or policy/licensing/entitlement changes"
