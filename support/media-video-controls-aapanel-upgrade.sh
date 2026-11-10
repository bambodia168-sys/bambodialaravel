#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo 'Usage: --check or --apply' >&2; exit 2; }
REPO="bambodia168-sys/bambodialaravel"
BASE="47faa5806f293db7fd4f494985c0bcec8f9f3994"
CODE="1f45805260ea87e0ab5d484dc291ea3e4cbf0d62"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
HELPER="support/media-video-controls-aapanel-upgrade.sh"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS="/root/Bambodia-MediaVideo-ControlFix-Backups"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail "Root required."
for cmd in git gh cmp curl tar gzip runuser mktemp install sha256sum sort; do
  command -v "$cmd" >/dev/null || fail "Missing command: $cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] || fail "Unexpected source/runtime."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail "Laravel runtime or protected environment missing."
[[ -x "$PHP" && -f "$DB_HELPER" && ! -e "$APP/storage/framework/down" ]] ||
  fail "PHP / snapshot helper / site maintenance state invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "GitHub origin mismatch."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Git checkout has local changes."
id www >/dev/null || fail "aaPanel www user missing."

TMP="$(mktemp -d /root/bambodia-video-controls.XXXXXXXX)"
FILE_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && APPLIED == 1 )); then
    echo "INSTALLATION FAILED: restoring original two files." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -f "$FILE_BACKUP" ]]; then
      tar -xzf "$FILE_BACKUP" -C "$APP" ||
        echo "CRITICAL: Restore archive manually: $FILE_BACKUP" >&2
    fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILE_BACKUP" >&2
    echo "MYSQL_BACKUP=$MYSQL_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true; fi
  rm -rf -- "$TMP"
  exit "$result"
}
trap cleanup EXIT

echo "========== VIDEO CONTROL SIZING $MODE =========="
echo "1. Verify immutable GitHub source and all five acceptance runs."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
MAIN="$(git -C "$SOURCE" rev-parse refs/remotes/origin/main)"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Installed baseline missing."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "New release missing."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$CODE" || fail "Wrong release lineage."
git -C "$SOURCE" merge-base --is-ancestor "$CODE" "$MAIN" || fail "Not yet merged to main."
git -C "$SOURCE" diff --name-only "$CODE" "$MAIN" | LC_ALL=C sort > "$TMP/after"
printf '%s\n' ".github/workflows/quality.yml" "$HELPER" | LC_ALL=C sort > "$TMP/expected-after"
cmp -s "$TMP/after" "$TMP/expected-after" || fail "Unreviewed changes in release ancestry."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$MAIN:$HELPER")" ]] ||
  fail "Deployment helper hash differs from merged GitHub main."
for wf in quality.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  n="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$CODE&per_page=30" \
      --jq '[.workflow_runs[] | select(.head_sha=="'"$CODE"'" and .conclusion=="success")] | length')" ||
      fail "Cannot verify workflow $wf"
  [[ "$n" -ge 1 ]] || fail "Workflow has not passed: $wf"
  echo "PASS $wf"
done

echo "2. Verify only two runtime files changed and production matches its exact baseline."
cat > "$TMP/approved-feature" <<'EOF'
public/css/media-library-video.css
resources/views/media/index.blade.php
tests/Feature/MediaLibraryPrivateVideoTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/feature"
cmp -s "$TMP/approved-feature" "$TMP/feature" || fail "Unapproved feature files."
cat > "$TMP/paths" <<'EOF'
public/css/media-library-video.css
resources/views/media/index.blade.php
EOF
mkdir -p "$TMP/new"
while IFS= read -r file; do
  install -d -m 0700 "$TMP/new/$(dirname "$file")"
  git -C "$SOURCE" show "$CODE:$file" > "$TMP/new/$file" || fail "Missing source: $file"
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || fail "Unsafe or missing live file: $file"
  if cmp -s "$APP/$file" "$TMP/new/$file"; then
    echo "ALREADY_INSTALLED $file"
  elif cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file"); then
    echo "KNOWN_BASELINE $file"
  else
    fail "Unexpected live edit: $file"
  fi
done < "$TMP/paths"
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate:status >/dev/null) ||
  fail "Cannot verify database migration status."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env modified."
echo "VERIFIED_FILES=2"
echo "DATABASE_MIGRATIONS=NONE"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION=UNCHANGED"
  exit 0
fi

echo "3. Back up both original files and MySQL."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILE_BACKUP="$DIR/original-files.tar.gz"
cp "$TMP/paths" "$DIR/file-scope.txt"
tar -czf "$FILE_BACKUP" -C "$APP" -T "$TMP/paths" || fail "File archive failed."
tar -tzf "$FILE_BACKUP" >/dev/null || fail "Archive unreadable."
MYSQL_BACKUP="$(bash "$DB_HELPER")" || fail "MySQL snapshot failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] || fail "DB snapshot missing."
gzip -t "$MYSQL_BACKUP" || fail "Database snapshot corrupt."

echo "4. Install CSS and Blade, then validate cached views."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || fail "Maintenance mode failed."
MAINT=1
APPLIED=1
while IFS= read -r file; do
  install -m 0644 -o www -g www "$TMP/new/$file" "$APP/$file" || fail "Failed to install $file"
done < "$TMP/paths"
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache) || fail "View compilation failed."
while IFS= read -r file; do
  cmp -s "$APP/$file" "$TMP/new/$file" || fail "Installed source differs: $file"
done < "$TMP/paths"
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env modified."
(cd "$APP" && runuser -u www -- "$PHP" artisan up) || fail "Unable to resume website."
MAINT=0

echo "5. Verify public site and live cache-busted stylesheet."
for url in 'https://bambodia.com/' 'https://bambodia.com/creators/34302348' \
  'https://bambodia.com/css/media-library-video.css?v=2'; do
  curl -fsSL --retry 2 --max-time 30 -o /dev/null "$url" || fail "HTTP smoke failed: $url"
  echo "HTTP_OK $url"
done
cmp -s "$APP/public/css/media-library-video.css" \
  <(curl -fsSL --max-time 30 'https://bambodia.com/css/media-library-video.css?v=2') ||
  fail "Live CSS mismatch."
echo "========== MEDIA PLAYER CONTROL FIX INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILE_BACKUP=$FILE_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PRESERVED=Cloudflare R2, all uploaded videos, database, player JS, original frontend layouts."
