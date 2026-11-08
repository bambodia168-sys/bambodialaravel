#!/usr/bin/env bash
# Bambodia: featured-first public post layout, share, comments, and default 4-column Blog.
# Usage:
#   bash support/post-cover-comments-upgrade.sh --check EXACT_40_CHAR_SHA
#   bash support/post-cover-comments-upgrade.sh --apply EXACT_40_CHAR_SHA
# Adds one isolated post_comments table and updates the public article UI, comment routes,
# and compiled frontend. Existing post records, media, secrets and runtime are retained.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SHA="${2:-}"
REPO="bambodia168-sys/bambodialaravel"
BASE="f9f065e0dd3daf3522fcaa06a6450f91743d2c2a"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
PHP="/www/server/php/85/bin/php"
ARTIFACT="Bambodia-Installer-v0.0.1"
BACKUPS="/root/Bambodia-PostCoverComments-Backups"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"

stop() { echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || stop "Use --check|--apply FULL_SHA"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop "Expected exact 40-character Git SHA"
[[ "$(id -u)" == "0" ]] || stop "Root access required"
for bin in git gh unzip cmp tar gzip rsync install sha256sum runuser mktemp curl; do
    command -v "$bin" >/dev/null || stop "Missing required command: $bin"
done
[[ -x "$PHP" && -d "$SOURCE/.git" ]] || stop "PHP 8.5 or source checkout missing"
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] ||
    stop "Installed application identity missing"
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] ||
    stop "Installed Laravel runtime or frontend missing"
[[ -f "$CONF" && ! -L "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] ||
    stop "Unexpected application or webroot symlink"
[[ ! -e "$APP/storage/framework/down" ]] || stop "Site is already in maintenance"
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" ||
    stop "Nginx docroot is not the known Bambodia app"
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
    stop "Unexpected GitHub remote"
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || stop "Source has local modifications"
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] || stop "Source is not exact release SHA"
[[ "$(gh api "repos/$REPO/commits/main" --jq '.sha')" == "$SHA" ]] ||
    stop "GitHub Main moved beyond the verified release"
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$SHA" ||
    stop "Previous deployed version not in release ancestry"

FILES=(
    app/Http/Controllers/ContentController.php
    app/Models/ContentEntry.php
    routes/web.php
    resources/views/content/show.blade.php
    resources/views/components/icon.blade.php
    resources/css/app.css
    resources/js/app.js
)
NEW_FILES=(
    app/Http/Controllers/PostCommentController.php
    app/Models/PostComment.php
    database/migrations/2026_11_08_000113_create_post_comments_table.php
)
MIGRATION="database/migrations/2026_11_08_000113_create_post_comments_table.php"

echo "========== VERIFY INSTALLED BASELINE =========="
for file in "${FILES[@]}"; do
    [[ -f "$APP/$file" && ! -L "$APP/$file" && -f "$SOURCE/$file" ]] ||
        stop "Missing or symlinked installed/source file: $file"
    cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") ||
        stop "Installed file differs from verified baseline: $file"
    [[ -f "$SOURCE/$file" ]] || stop "Missing new source file: $file"
    echo "MATCH: $file"
done

for file in "${NEW_FILES[@]}"; do
    [[ ! -e "$APP/$file" && ! -L "$APP/$file" ]] ||
        stop "New file already exists in live application: $file"
    [[ -f "$SOURCE/$file" ]] || stop "Missing new release source: $file"
    echo "NEW: $file"
done

echo "========== VERIFY SIX CI WORKFLOWS =========="
for workflow in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
    result="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$SHA&per_page=30" \
        --jq '[.workflow_runs[] | select(.head_sha=="'"$SHA"'")] | sort_by(.created_at) | last | if . == null then "missing" else .status+":"+(.conclusion // "pending") end')" ||
        stop "Unable to verify CI workflow: $workflow"
    [[ "$result" == "completed:success" ]] || stop "CI not passed: $workflow = $result"
    echo "CI PASS: $workflow"
done

RUN="$(gh api "repos/$REPO/actions/workflows/build-installer.yml/runs?head_sha=$SHA&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$SHA"'" and .conclusion=="success")] | sort_by(.created_at) | last | .id')" ||
    stop "Cannot identify installer artifact"
[[ "$RUN" =~ ^[0-9]+$ ]] || stop "No verified installer run for this SHA"

TMP="$(mktemp -d /root/bambodia-post-cover-comments.XXXXXXXX)"
DOWN=0
TOUCHED=0
ARCHIVE=""
SAVE=""
DB_BACKUP=""
DB_MIGRATED=0
cleanup() {
    local rc=$?
    trap - EXIT
    if (( rc != 0 && TOUCHED == 1 )); then
        echo "ALERT: restoring original application files and frontend from $ARCHIVE" >&2
        tar -xzf "$ARCHIVE" -C "$APP" ||
            echo "ALERT: file restoration failed, manual restore required!" >&2
        for file in "${NEW_FILES[@]}"; do
            rm -f -- "$APP/$file" ||
                echo "ALERT: could not remove new file $file" >&2
        done
        if (( DB_MIGRATED == 1 )); then
            echo "ALERT: add-only post_comments table remains. Review database recovery separately." >&2
            echo "ALERT: recovery backup: $DB_BACKUP" >&2
        fi
        (cd "$APP" && runuser -u www -- "$PHP" artisan optimize:clear &&
           runuser -u www -- "$PHP" artisan view:cache) ||
            echo "ALERT: cache rebuild failed after restoration." >&2
    fi
    if (( DOWN == 1 )); then
        (cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
            echo "ALERT: site may still be in maintenance mode!" >&2
    fi
    [[ -z "$TMP" || ! -d "$TMP" ]] || rm -rf -- "$TMP"
    exit "$rc"
}
trap cleanup EXIT

echo "========== VERIFY IMMUTABLE INSTALLER =========="
gh run download "$RUN" --repo "$REPO" --name "$ARTIFACT" --dir "$TMP" ||
    stop "Installer artifact could not be downloaded"
ZIP="$TMP/Bambodia-Installer.zip"
[[ -s "$ZIP" ]] && unzip -tq "$ZIP" >/dev/null || stop "Installer ZIP is missing or corrupt"
mkdir -p "$TMP/release"
unzip -q "$ZIP" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
[[ -f "$RELEASE/artisan" && -f "$RELEASE/public/build/manifest.json" ]] ||
    stop "Installer missing Laravel runtime or compiled frontend"
for identity in artisan VERSION composer.json; do
    cmp -s "$APP/$identity" "$RELEASE/$identity" ||
        stop "Application identity changed unexpectedly: $identity"
done
for file in "${FILES[@]}" "${NEW_FILES[@]}"; do
    cmp -s "$SOURCE/$file" "$RELEASE/$file" ||
        stop "Release artifact source mismatch: $file"
    [[ "$file" != *.php ]] || "$PHP" -l "$SOURCE/$file" >/dev/null ||
        stop "PHP syntax check failed: $file"
done

if [[ "$MODE" == "--check" ]]; then
    echo "POST COVER AND COMMENTS CHECK PASSED"
    echo "No application files, database, or media were changed."
    exit 0
fi

echo "========== CREATE FRESH RECOVERY BACKUPS =========="
[[ -f "$DB_HELPER" ]] || stop "Protected MySQL backup helper missing"
install -d -m 0700 "$BACKUPS"
SAVE="$(mktemp -d "$BACKUPS/release-XXXXXXXX")"
ARCHIVE="$SAVE/original-app-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" "${FILES[@]}" public/build ||
    stop "Application archive failed"
tar -tzf "$ARCHIVE" >/dev/null || stop "Application archive invalid"
chmod 0600 "$ARCHIVE"
DB_BACKUP="$(bash "$DB_HELPER")" || stop "MySQL backup failed"
[[ -f "$DB_BACKUP" && -s "$DB_BACKUP" ]] && gzip -t "$DB_BACKUP" ||
    stop "MySQL backup invalid"
ENV_HASH="$(sha256sum "$APP/.env" | awk '{print $1}')"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | awk '{print $1}')"

echo "========== ENTER MAINTENANCE MODE =========="
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1

echo "========== APPLY VERIFIED FILES AND FRONTEND =========="
for file in "${FILES[@]}" "${NEW_FILES[@]}"; do
    install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
    cmp -s "$RELEASE/$file" "$APP/$file" ||
        stop "Copy verification failed: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$RELEASE/public/build/manifest.json" "$APP/public/build/manifest.json" ||
    stop "Frontend manifest differs"

echo "========== APPLY ADDITIVE COMMENT MIGRATION =========="
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction     --path="$MIGRATION" --no-ansi) ||
    stop "Post comments database migration failed"
DB_MIGRATED=1

echo "========== VALIDATE INSTALLED APPLICATION =========="
(cd "$APP" &&
    runuser -u www -- "$PHP" artisan optimize:clear &&
    runuser -u www -- "$PHP" artisan view:cache &&
    runuser -u www -- "$PHP" artisan route:list --path=blog --no-ansi >/dev/null &&
    runuser -u www -- "$PHP" artisan migrate:status --no-ansi | grep -q "2026_11_08_000113_create_post_comments_table.*Ran") ||
    stop "Installed Laravel runtime verification failed"

[[ "$(sha256sum "$APP/.env" | awk '{print $1}')" == "$ENV_HASH" ]] ||
    stop "Environment file changed"
[[ "$(sha256sum "$APP/storage/app/installed.lock" | awk '{print $1}')" == "$LOCK_HASH" ]] ||
    stop "Installer lock changed"

(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
HTTP_CODE="$(curl -ILsS --max-time 20 -o /dev/null -w '%{http_code}' https://bambodia.com/)" ||
    stop "Public HTTP smoke check failed"
[[ "$HTTP_CODE" == "200" ]] || stop "Public home is not HTTP 200: $HTTP_CODE"
BLOG_PAGE="$(curl -fsSLsS --max-time 20 https://bambodia.com/blog)" ||
    stop "Public blog smoke check failed"
grep -q 'blog-view-toolbar' <<<"$BLOG_PAGE" ||
    stop "New public Blog display controls are missing"
grep -q 'blog-post-grid columns-4' <<<"$BLOG_PAGE" ||
    stop "Default four-column Blog grid is missing"
POST_HTML="$(curl -fsSLsS --max-time 20     'https://bambodia.com/996502246_a-practical-checklist-for-readable-and-consistent-interfaces')" ||
    stop "Featured Design article smoke check failed"
grep -q 'blog-article-cover' <<<"$POST_HTML" ||
    stop "Edge-to-edge article cover missing"
grep -q 'data-article-share' <<<"$POST_HTML" ||
    stop "Article share control missing"
grep -q 'blog-comments' <<<"$POST_HTML" ||
    stop "Public article comments section missing"

TOUCHED=0
echo "POST COVER AND COMMENTS DEPLOYED SUCCESSFULLY"
echo "Release SHA: $SHA"
echo "HTTP status: $HTTP_CODE"
echo "Original application backup: $ARCHIVE"
echo "Fresh MySQL snapshot: $DB_BACKUP"
echo "Existing posts, media, and Laravel runtime preserved. Only post_comments table added."
echo "Verify four-column /blog default, edge-to-edge media, byline, share, and article comments."
