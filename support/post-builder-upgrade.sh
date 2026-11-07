#!/usr/bin/env bash
# Guarded Bambodia Post Manager rich editor and CRUD release.
# Usage: bash support/post-builder-upgrade.sh --check SHA
#        BAMBODIA_DB_BACKUP_FILE=/root/path/database.sql.gz bash support/post-builder-upgrade.sh --apply SHA
# Adds post metadata columns, never seeds or publishes existing content.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SHA="${2:-}"
REPO="bambodia168-sys/bambodialaravel"
BASE="de9f38c5c2729cb906996bed0a471786a563bf6d"
SRC="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
ARTIFACT="Bambodia-Installer-v0.0.1"
BACKUP_ROOT="/root/Bambodia-PostBuilder-Backups"
MIGRATION="database/migrations/2026_11_08_000110_extend_content_entries_for_post_builder.php"

stop(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || stop "Use --check|--apply FULL_SHA"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop "Expected exact 40-digit commit SHA"
[[ "$(id -u)" == 0 ]] || stop "Run as root"
for tool in git gh tar gzip cmp install stat runuser mktemp unzip rsync sha256sum; do
  command -v "$tool" >/dev/null || stop "Missing $tool"
done
[[ -d "$SRC/.git" && -x "$PHP" ]] || stop "Git checkout or PHP missing"
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || stop "Installed Bambodia identity missing"
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || stop "Runtime or frontend missing"
[[ -f "$CONF" && ! -L "$APP" && ! -L "$CONF" ]] || stop "Unsafe or missing installed site"
[[ ! -e "$APP/storage/framework/down" ]] || stop "Site already in maintenance mode"
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" ||
  stop "Unexpected Nginx document root"
[[ "$(git -C "$SRC" remote get-url origin)" == "https://github.com/$REPO.git" ]] || stop "Wrong GitHub remote"
[[ -z "$(git -C "$SRC" status --porcelain)" ]] || stop "Uncommitted changes in source checkout"
gh auth status >/dev/null 2>&1 || stop "GitHub CLI unavailable"

git -C "$SRC" fetch origin main
git -C "$SRC" merge --ff-only origin/main
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] || stop "GitHub Main differs from requested release"
git -C "$SRC" merge-base --is-ancestor "$BASE" "$SHA" || stop "Deployed Bambodia baseline not in release history"

EXISTING=(
  app/Models/ContentEntry.php
  app/Models/AuditEvent.php
  app/Http/Controllers/ContentController.php
  app/Http/Controllers/MediaLibraryController.php
  routes/web.php
  resources/views/content/show.blade.php
  resources/views/content/blog.blade.php
  resources/views/layouts/main.blade.php
  resources/css/app.css
  resources/js/app.js
)
NEW=(
  "$MIGRATION"
  app/Http/Controllers/Dashboard/PostManagerController.php
  resources/views/dashboard/posts.blade.php
  resources/views/dashboard/post-builder.blade.php
)
ALL=("${EXISTING[@]}" "${NEW[@]}")
echo "========== VERIFY INSTALLED POST BUILDER BASELINE =========="
for file in "${EXISTING[@]}"; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Missing or symlinked installed file: $file"
  cmp -s "$APP/$file" <(git -C "$SRC" show "$BASE:$file") ||
    stop "Installed file customized since last verified deployment: $file"
  echo "MATCH: $file"
done
for file in "${NEW[@]}"; do
  [[ -f "$SRC/$file" && ! -e "$APP/$file" && ! -L "$APP/$file" ]] ||
    stop "New file missing from source or already installed: $file"
done
for file in "${ALL[@]}"; do
  if [[ "$file" == *.php ]]; then
    "$PHP" -l "$SRC/$file" >/dev/null || stop "PHP syntax error in $file"
  fi
done

[[ -f "$SRC/tests/Feature/PostBuilderExperienceTest.php" &&
   -f "$SRC/tests/browser/post-builder.mjs" ]] || stop "Missing post builder acceptance tests"

echo "========== VERIFY SIX CI WORKFLOWS AGAINST EXACT SHA =========="
for workflow in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  pass="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$SHA&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'$SHA'" and .conclusion=="success")] | length')" ||
    stop "Unable to check CI: $workflow"
  [[ "$pass" =~ ^[0-9]+$ && "$pass" -ge 1 ]] || stop "CI must pass before deployment: $workflow"
  echo "PASS: $workflow"
done

echo "========== VERIFY IMMUTABLE FRONTEND RELEASE ARTIFACT =========="
RUN="$(gh api "repos/$REPO/actions/workflows/build-installer.yml/runs?head_sha=$SHA&per_page=30" \
   --jq '[.workflow_runs[] | select(.head_sha=="'$SHA'" and .conclusion=="success")][0].id')" || stop "Cannot identify builder artifact"
[[ "$RUN" =~ ^[0-9]+$ ]] || stop "No successful artifact build for exact commit"
TMP="$(mktemp -d /root/bambodia-post-builder.XXXXXXXX)"
ARCHIVE=''
DOWN=0
TOUCHED=0
MIGRATED=0
cleanup() {
  rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo "ALERT: restoring original source and compiled frontend assets" >&2
    tar -xzf "$ARCHIVE" -C "$APP" || true
    for file in "${NEW[@]}"; do
      if [[ "$file" != "$MIGRATION" || "$MIGRATED" == 0 ]]; then rm -f -- "$APP/$file"; fi
    done
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear) || true
  fi
  if (( rc != 0 && MIGRATED == 1 )); then
    echo "ALERT: additive post metadata schema may remain; do not drop it automatically." >&2
    echo "Snapshot supplied: ${BAMBODIA_DB_BACKUP_FILE:-none}" >&2
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
      echo "ALERT: check maintenance mode immediately." >&2
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP" ||
  stop "Cannot download exact commit installer artifact"
PKG="$TMP/Bambodia-Installer.zip"
[[ -s "$PKG" ]] || stop "Installer archive missing"
unzip -tq "$PKG" >/dev/null || stop "Installer archive corrupted"
mkdir -p "$TMP/release"
unzip -q "$PKG" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for stable in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$stable" ]] && cmp -s "$APP/$stable" "$RELEASE/$stable" ||
    stop "Installer identity mismatch: $stable"
done
[[ -s "$RELEASE/public/build/manifest.json" ]] || stop "Compiled frontend missing"
for file in "${ALL[@]}"; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$SRC/$file" "$RELEASE/$file" ||
    stop "Artifact differs from exact GitHub source: $file"
done

if [[ "$MODE" == --check ]]; then
  echo "BAMBODIA POST BUILDER PREFLIGHT PASSED: no installed files or database changed."
  echo "Application requires a protected fresh MySQL backup before --apply."
  exit 0
fi

DB_BACKUP="${BAMBODIA_DB_BACKUP_FILE:-}"
[[ -n "$DB_BACKUP" && -f "$DB_BACKUP" && -s "$DB_BACKUP" && ! -L "$DB_BACKUP" && "$DB_BACKUP" == /root/* ]] ||
  stop "Set BAMBODIA_DB_BACKUP_FILE to fresh private /root/*.sql.gz snapshot"
[[ "$DB_BACKUP" == *.sql.gz ]] || stop "Expected compressed SQL archive"
(( $(date +%s) - $(stat -c %Y "$DB_BACKUP") < 3600 )) || stop "Snapshot older than one hour"
MODE_BITS="$(stat -c %a "$DB_BACKUP")"
(( (8#$MODE_BITS & 077) == 0 )) || stop "Snapshot must be inaccessible to group and others"
gzip -t "$DB_BACKUP" || stop "Invalid MySQL backup"

install -d -m 0700 "$BACKUP_ROOT"
SAVE="$(mktemp -d "$BACKUP_ROOT/release-XXXXXXXX")"
ARCHIVE="$SAVE/original-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" "${EXISTING[@]}" public/build
tar -tzf "$ARCHIVE" >/dev/null || stop "Original file backup failed"
ENV_SHA="$(sha256sum "$APP/.env" | cut -d ' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)"
echo "Source rollback: $ARCHIVE"
echo "Database recovery: $DB_BACKUP"

(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1

install -d -o www -g www -m 0755 "$(dirname "$APP/$MIGRATION")"
install -o www -g www -m 0644 "$RELEASE/$MIGRATION" "$APP/$MIGRATION"
MIGRATED=1
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --path="$MIGRATION" --force --no-interaction)
for file in "${ALL[@]}"; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || stop "Copy mismatch: $file"
done
rsync -a "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" ||
  stop "Compiled frontend copy mismatch"
(cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear &&
  runuser -u www -- "$PHP" artisan view:cache &&
  runuser -u www -- "$PHP" artisan route:list --path=post-manager)
[[ "$(sha256sum "$APP/.env" | cut -d ' ' -f1)" == "$ENV_SHA" ]] || stop ".env modified"
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] || stop "Installer lock modified"

(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0
MIGRATED=0
echo "BAMBODIA POST BUILDER DEPLOYED SUCCESSFULLY"
echo "Release SHA: $SHA"
echo "File recovery: $ARCHIVE"
echo "MySQL recovery: $DB_BACKUP"
echo "Existing posts, pages, tickets, licenses and policies preserved; no content auto-published."
echo "Open /dashboard/post-manager to edit and publish posts individually."
