#!/usr/bin/env bash
# Bambodia private R2 Media Library video player: --check or --apply.
set -Eeuo pipefail
umask 077
MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo 'Usage: --check|--apply' >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="e04153700c56993b8000b573a1ddb5b5c922b026"
CODE="e13420ccfafbeb1b21389c0da2053649514a8a86"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
HELPER="support/media-video-r2-aapanel-upgrade.sh"
BACKUPS="/root/Bambodia-MediaVideo-R2-Backups"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"

fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == "0" ]] || fail "Requires root."
for bin in git gh cmp curl tar gzip runuser mktemp install sha256sum sort; do
  command -v "$bin" >/dev/null || fail "Missing command: $bin"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] ||
  fail "Trusted aaPanel source or runtime missing."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail "Laravel runtime or private environment missing."
[[ -f "$DB_HELPER" && -x "$PHP" && ! -e "$APP/storage/framework/down" ]] ||
  fail "Backup helper, PHP, or maintenance state invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "Unexpected repository source."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Source checkout contains local changes."
id www >/dev/null || fail "aaPanel www user is missing."

TMP="$(mktemp -d /root/bambodia-video-r2-preflight.XXXXXXXX)"
FILE_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && APPLIED == 1 )); then
    echo "FAILED: restoring original files from private archive." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    while IFS= read -r name; do
      [[ -z "$name" ]] || rm -f -- "$APP/$name"
    done < "$TMP/created"
    if [[ -f "$FILE_BACKUP" ]]; then
      tar -xzf "$FILE_BACKUP" -C "$APP" ||
        echo "CRITICAL: Manual file restoration may be required." >&2
    fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILE_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$MYSQL_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo "========== PRIVATE R2 MEDIA VIDEO $MODE =========="
echo "1. Verify immutable GitHub release and five green workflows."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
MAIN="$(git -C "$SOURCE" rev-parse refs/remotes/origin/main)"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Expected pre-upgrade baseline absent."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "Pinned video release absent."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$CODE" || fail "Wrong release history."
git -C "$SOURCE" merge-base --is-ancestor "$CODE" "$MAIN" ||
  fail "Pinned video release is not merged into GitHub main."
git -C "$SOURCE" diff --name-only "$CODE" "$MAIN" | LC_ALL=C sort > "$TMP/after"
printf '%s\n' ".github/workflows/quality.yml" "$HELPER" | LC_ALL=C sort > "$TMP/allowed-after"
cmp -s "$TMP/after" "$TMP/allowed-after" ||
  fail "Unapproved changes exist after pinned release."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$MAIN:$HELPER")" ]] ||
  fail "Installer integrity differs from GitHub main."

for workflow in quality.yml installer-acceptance.yml operational-acceptance.yml browser-emulation.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$CODE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$CODE"'" and .conclusion=="success")] | length')" ||
    fail "Could not verify workflow: $workflow"
  [[ "$count" -ge 1 ]] || fail "Required workflow has not passed: $workflow"
  echo "PASS $workflow"
done

echo "2. Confirm exact Media Library deployment scope and live file hashes."
cat > "$TMP/expected" <<'EOF'
app/Http/Controllers/MediaLibraryController.php
app/Support/Media/PrivateVideoStreamer.php
public/css/media-library-video.css
public/js/media-library-video.js
resources/views/components/icon.blade.php
resources/views/media/index.blade.php
tests/Feature/MediaLibraryPrivateVideoTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/delta"
cmp -s "$TMP/delta" "$TMP/expected" ||
  fail "Feature changes outside permitted Media Library scope."
git -C "$SOURCE" diff --quiet "$BASE" "$CODE" -- \
  resources/views/layouts resources/views/creators/show.blade.php public/images ||
  fail "Unrelated public layouts, badges or original assets were changed."

cat > "$TMP/modified" <<'EOF'
app/Http/Controllers/MediaLibraryController.php
resources/views/components/icon.blade.php
resources/views/media/index.blade.php
EOF
cat > "$TMP/new" <<'EOF'
app/Support/Media/PrivateVideoStreamer.php
public/css/media-library-video.css
public/js/media-library-video.js
EOF
cat "$TMP/modified" "$TMP/new" > "$TMP/paths"
: > "$TMP/created"
mkdir -p "$TMP/payload"
while IFS= read -r name; do
  install -d -m 0700 "$TMP/payload/$(dirname "$name")"
  git -C "$SOURCE" show "$CODE:$name" > "$TMP/payload/$name" ||
    fail "Cannot read pinned code: $name"
  if [[ "$name" == *.php ]]; then
    "$PHP" -l "$TMP/payload/$name" >/dev/null || fail "PHP syntax invalid: $name"
  fi
  [[ ! -L "$APP/$name" ]] || fail "Unsafe symlink: $name"
  if [[ -f "$APP/$name" ]]; then
    if cmp -s "$APP/$name" "$TMP/payload/$name"; then
      echo "ALREADY_INSTALLED $name"
    elif grep -Fxq "$name" "$TMP/modified" &&
      cmp -s "$APP/$name" <(git -C "$SOURCE" show "$BASE:$name"); then
      echo "KNOWN_BASELINE $name"
    else
      fail "Unrecognized live modification: $name"
    fi
  else
    grep -Fxq "$name" "$TMP/new" || fail "Required existing file missing: $name"
    printf '%s\n' "$name" >> "$TMP/created"
    echo "NEW_FILE $name"
  fi
done < "$TMP/paths"

(cd "$APP" && runuser -u www -- "$PHP" artisan migrate:status >/dev/null) ||
  fail "Database migration ledger unreadable."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] ||
  fail "Private environment modified during preflight."
echo "VERIFIED_FILES=6"
echo "DATABASE_MIGRATIONS=NONE"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION=UNCHANGED"
  exit 0
fi

echo "3. Back up original files and snapshot the full MySQL database."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILE_BACKUP="$DIR/previous-files.tar.gz"
cp "$TMP/paths" "$DIR/file-scope.txt"
cp "$TMP/created" "$DIR/created.txt"
tar -czf "$FILE_BACKUP" -C "$APP" -T "$TMP/modified" || fail "File backup failed."
tar -tzf "$FILE_BACKUP" >/dev/null || fail "File archive integrity invalid."
MYSQL_BACKUP="$(bash "$DB_HELPER")" || fail "Full MySQL snapshot failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] || fail "MySQL snapshot not found."
gzip -t "$MYSQL_BACKUP" || fail "MySQL backup gzip verification failed."

echo "4. Apply exact pinned files, preserving R2 objects and existing database."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) ||
  fail "Cannot enter maintenance mode."
MAINT=1
APPLIED=1
while IFS= read -r name; do
  install -d -o www -g www -m 0755 "$APP/$(dirname "$name")"
  install -o www -g www -m 0644 "$TMP/payload/$name" "$APP/$name" ||
    fail "Could not install file: $name"
done < "$TMP/paths"
(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
  runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache &&
  runuser -u www -- "$PHP" artisan route:list --name=media.preview >/dev/null) ||
  fail "Laravel route/view verification failed."
while IFS= read -r name; do
  cmp -s "$APP/$name" "$TMP/payload/$name" ||
    fail "Live file did not match pinned release: $name"
done < "$TMP/paths"
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] ||
  fail "Private environment modified unexpectedly."

(cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
  fail "Unable to return website to live service."
MAINT=0

echo "5. Public HTTP checks and content integrity for video player assets."
for url in \
  https://bambodia.com/ \
  https://bambodia.com/login \
  https://bambodia.com/creators/34302348 \
  'https://bambodia.com/css/media-library-video.css?v=1' \
  'https://bambodia.com/js/media-library-video.js?v=1'
do
  curl -fsSL --retry 2 --max-time 35 -o /dev/null "$url" ||
    fail "Public HTTP check failed: $url"
  echo "HTTP_OK $url"
done
cmp -s "$APP/public/css/media-library-video.css" \
  <(curl -fsSL --max-time 35 'https://bambodia.com/css/media-library-video.css?v=1') ||
  fail "Public CSS bytes differ from pinned asset."
cmp -s "$APP/public/js/media-library-video.js" \
  <(curl -fsSL --max-time 35 'https://bambodia.com/js/media-library-video.js?v=1') ||
  fail "Public JS bytes differ from pinned asset."

echo "========== PRIVATE R2 VIDEO PLAYER INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILES_BACKUP=$FILE_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PRESERVED=Cloudflare R2 keys and files, Media Library data, Published work, badges, layouts, .env"
