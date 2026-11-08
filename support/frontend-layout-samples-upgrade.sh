#!/usr/bin/env bash
# Bambodia: guarded 25-item frontend layout sample release, backup-first.
# Usage: bash support/frontend-layout-samples-upgrade.sh --check SHA
#        bash support/frontend-layout-samples-upgrade.sh --apply SHA
set -Eeuo pipefail
umask 077
MODE="$1"
SHA="$2"
REPO="bambodia168-sys/bambodialaravel"
BASE="4eea7a21df29cffe2ee0271e8e890127c18f3e17"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
PHP="/www/server/php/85/bin/php"
SEED="$SOURCE/support/frontend-layout-samples.php"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS="/root/Bambodia-FrontendLayoutSamples-Backups"
FILES="app/Http/Controllers/HomeController.php app/Http/Controllers/JobBoardController.php app/Http/Controllers/ServiceInquiryController.php resources/views/jobs/show.blade.php resources/views/marketplace/detail.blade.php"
stop(){ echo "FRONTEND_LAYOUT_DEPLOY=BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || stop "Use --check|--apply SHA"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop "Expected exact 40-character Git SHA"
[[ "$(id -u)" -eq 0 ]] || stop "Root required"
for cmd in git gh tar gzip cmp install runuser curl sha256sum mktemp; do
  command -v "$cmd" >/dev/null || stop "Missing $cmd"
done
[[ -x "$PHP" && -d "$SOURCE/.git" && -f "$APP/.env" && -f "$APP/storage/app/installed.lock" && -f "$APP/vendor/autoload.php" ]] || stop "Unknown installed application"
[[ -f "$CONF" && ! -L "$CONF" && ! -L "$APP" ]] || stop "Unexpected symlink"
[[ ! -e "$APP/storage/framework/down" ]] || stop "Site already in maintenance mode"
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || stop "Nginx webroot changed"
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || stop "Git remote mismatch"
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || stop "Git checkout contains changes"
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] || stop "Source commit mismatch"
[[ "$(gh api "repos/$REPO/commits/main" --jq .sha)" == "$SHA" ]] || stop "Remote Main moved"
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$SHA" || stop "Unknown deployed ancestry"
echo "========== VERIFY DEPLOYED PHP/VIEW BASELINE =========="
for file in $FILES; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" && -f "$SOURCE/$file" ]] || stop "Missing file $file"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") || stop "Installed file drift: $file"
  if [[ "$file" == *.php ]]; then "$PHP" -l "$SOURCE/$file" >/dev/null; fi
  echo "MATCH: $file"
done
"$PHP" -l "$SEED" >/dev/null
echo "========== VERIFY SIX CI WORKFLOWS =========="
for workflow in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  result="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$SHA&per_page=30" --jq '[.workflow_runs[] | select(.head_sha=="'"$SHA"'")] | sort_by(.created_at) | last | if . == null then "missing" else .status+":"+(.conclusion // "pending") end')" || stop "CI lookup failed: $workflow"
  [[ "$result" == "completed:success" ]] || stop "CI not green: $workflow ($result)"
  echo "CI PASS: $workflow"
done
echo "========== READ-ONLY DATABASE PREFLIGHT =========="
"$PHP" "$SEED" --check || stop "Database preflight failed"
if [[ "$MODE" == "--check" ]]; then
  echo "FRONTEND_LAYOUT_PREFLIGHT=PASS"
  echo "DATABASE_CHANGES=NONE"
  echo "WEBSITE_CHANGES=NONE"
  exit 0
fi
[[ -f "$DB_HELPER" ]] || stop "Trusted database-backup helper unavailable"
install -d -m 0700 "$BACKUPS"
SAVE="$(mktemp -d "$BACKUPS/release-XXXXXXXX")"
ARCHIVE="$SAVE/original-application-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" $FILES || stop "File archive failed"
tar -tzf "$ARCHIVE" >/dev/null || stop "File archive validation failed"
chmod 0600 "$ARCHIVE"
DB_BACKUP="$(bash "$DB_HELPER")" || stop "MySQL backup failed"
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] || stop "Database snapshot invalid"
gzip -t "$DB_BACKUP" || stop "Database snapshot integrity failed"
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
DOWN=0
TOUCHED=0
SEED_STARTED=0
recover(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    if (( SEED_STARTED == 0 )); then
      echo "Restoring protected baseline PHP and Blade files." >&2
      tar -xzf "$ARCHIVE" -C "$APP" || echo "CRITICAL: file restore failed." >&2
      ( cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear && runuser -u www -- "$PHP" artisan view:cache ) || echo "CRITICAL: cache rebuild failed." >&2
    else
      echo "ALERT: preserving preview-aware controllers because data insertion began." >&2
      echo "Review database snapshot: $DB_BACKUP" >&2
    fi
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo "CRITICAL: site may remain in maintenance mode." >&2
  fi
  exit "$rc"
}
trap recover EXIT
echo "========== SHORT MAINTENANCE =========="
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1
echo "========== APPLY GUARDS AND FRONTEND DETAIL VIEWS =========="
for file in $FILES; do
  install -o www -g www -m 0644 "$SOURCE/$file" "$APP/$file"
  cmp -s "$SOURCE/$file" "$APP/$file" || stop "Copied file mismatch: $file"
done
(cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear && runuser -u www -- "$PHP" artisan view:cache) || stop "Laravel validation failed"
echo "========== CREATE EXACTLY 25 LABELED PREVIEWS =========="
SEED_STARTED=1
BAMBODIA_LAYOUT_DEPLOY=1 "$PHP" "$SEED" --apply "$DB_BACKUP" || stop "Database fixture transaction failed"
BAMBODIA_LAYOUT_DEPLOY=1 "$PHP" "$SEED" --check || stop "Post-insert fixture validation failed"
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || stop ".env was modified"
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] || stop "Installer lock was modified"
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
echo "========== FRONTEND SMOKE ACCEPTANCE =========="
for url in "https://bambodia.com/" "https://bambodia.com/search?type=product" "https://bambodia.com/search?type=project" "https://bambodia.com/search?type=service" "https://bambodia.com/search?type=freelance" "https://bambodia.com/jobs"; do
  page="$(curl -fsSLsS --max-time 25 "$url")" || stop "HTTP test failed: $url"
  grep -Fq '[LAYOUT TEST]' <<<"$page" || stop "No preview cards at $url"
done
TOUCHED=0
echo "FRONTEND_LAYOUT_SAMPLES=DEPLOYED"
echo "CREATED=25 (5 each: product,project,service,freelance,job)"
echo "RELEASE_SHA=$SHA"
echo "FILES_BACKUP=$ARCHIVE"
echo "MYSQL_BACKUP=$DB_BACKUP"
echo "NO_SCHEMA_MIGRATIONS=YES"
