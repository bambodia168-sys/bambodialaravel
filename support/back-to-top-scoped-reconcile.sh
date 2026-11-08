#!/usr/bin/env bash
# Bambodia: safe scoped footer correction while preserving unrelated live Blade changes.
# Usage:
#   bash support/back-to-top-scoped-reconcile.sh --check EXACT_40_CHAR_SHA
#   bash support/back-to-top-scoped-reconcile.sh --apply EXACT_40_CHAR_SHA
# Updates only the frontend layout, CSS and compiled Vite assets.
# Preserves existing database records, posts, media files, and Laravel runtime.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SHA="${2:-}"
REPO="bambodia168-sys/bambodialaravel"
BASE="f0eab5d962459fa8cc1949b6c0c14d67515900c2"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
PHP="/www/server/php/85/bin/php"
ARTIFACT="Bambodia-Installer-v0.0.1"
BACKUPS="/root/Bambodia-VerticalTextBackToTop-Backups"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"

stop() { echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || stop "Use --check|--apply FULL_SHA"
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop "Expected exact 40-character Git SHA"
[[ "$(id -u)" == "0" ]] || stop "Root access required"
for bin in git gh unzip cmp diff tar gzip rsync install sha256sum runuser mktemp curl; do
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
    resources/views/layouts/main.blade.php
    resources/css/app.css
)

echo "========== VERIFY INSTALLED BASELINE =========="
for file in "${FILES[@]}"; do
    [[ -f "$APP/$file" && ! -L "$APP/$file" && -f "$SOURCE/$file" ]] ||
        stop "Missing or symlinked installed/source file: $file"
    if [[ "$file" == "resources/views/layouts/main.blade.php" ]]; then
        if cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file"); then
            echo "MATCH: Blade template baseline"
        else
            echo "NOTICE: Live Blade template has edits outside the verified baseline."
            echo "NOTICE: Applying only the Back to Top fragment if it is unchanged."
            grep -Fq '<span class="back-to-top-label">Back<span>to top</span></span>' "$APP/$file" ||
                stop "Expected old Back to Top markup is missing from live template"
            if grep -Fq '<span class="back-to-top-label">Back to Top</span>' "$APP/$file"; then
                stop "Partial or conflicting Back to Top modification is present"
            fi
        fi
    else
        cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") ||
            stop "Installed CSS differs from verified baseline; do not replace unrelated styles"
    fi
    [[ -f "$SOURCE/$file" ]] || stop "Missing new source file: $file"
    echo "MATCH: $file"
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

TMP="$(mktemp -d /root/bambodia-vertical-backtop.XXXXXXXX)"
DOWN=0
TOUCHED=0
ARCHIVE=""
SAVE=""
DB_BACKUP=""
cleanup() {
    local rc=$?
    trap - EXIT
    if (( rc != 0 && TOUCHED == 1 )); then
        echo "ALERT: restoring original application files and frontend from $ARCHIVE" >&2
        tar -xzf "$ARCHIVE" -C "$APP" ||
            echo "ALERT: file restoration failed, manual restore required!" >&2
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
for file in "${FILES[@]}"; do
    cmp -s "$SOURCE/$file" "$RELEASE/$file" ||
        stop "Release artifact source mismatch: $file"
    [[ "$file" != *.php ]] || "$PHP" -l "$SOURCE/$file" >/dev/null ||
        stop "PHP syntax check failed: $file"
done

echo "========== VERIFY CURRENT COMPILED FRONTEND IDENTITY =========="
BASE_RUN="$(gh api "repos/$REPO/actions/workflows/build-installer.yml/runs?head_sha=$BASE&per_page=20" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$BASE"'" and .conclusion=="success")] | sort_by(.created_at) | last | .id')" ||
    stop "Unable to locate previous installed release artifact"
[[ "$BASE_RUN" =~ ^[0-9]+$ ]] || stop "Previous installer artifact run not found"
mkdir -p "$TMP/baseline-download" "$TMP/baseline-unpacked"
gh run download "$BASE_RUN" --repo "$REPO" --name "$ARTIFACT" --dir "$TMP/baseline-download" ||
    stop "Unable to download previous release artifact"
OLD_ZIP="$TMP/baseline-download/Bambodia-Installer.zip"
[[ -s "$OLD_ZIP" ]] && unzip -tq "$OLD_ZIP" >/dev/null ||
    stop "Previous installer artifact invalid"
unzip -q "$OLD_ZIP" -d "$TMP/baseline-unpacked" ||
    stop "Previous installer could not be extracted"
OLD_BUILD="$TMP/baseline-unpacked/Bambodia/public/build"
[[ -f "$OLD_BUILD/manifest.json" ]] || stop "Previous frontend manifest missing"
if ! diff -qr "$OLD_BUILD" "$APP/public/build" >"$TMP/build-diff.txt"; then
    stop "Installed compiled frontend differs from verified baseline; refusing to replace it"
fi
echo "COMPILED FRONTEND BASELINE: MATCH"

echo "========== VERIFY AND MERGE ONLY BACK TO TOP MARKUP =========="
python3 - "$SOURCE" "$APP" "$BASE" "$TMP/merged-main.blade.php" <<'PY'
from pathlib import Path
import subprocess, sys
source, app, base, output = sys.argv[1:]
file = "resources/views/layouts/main.blade.php"
old = '<span class="back-to-top-label">Back<span>to top</span></span>'
new = '<span class="back-to-top-label">Back to Top</span>'
baseline = subprocess.check_output(['git','-C',source,'show',f'{base}:{file}']).decode()
target = (Path(source) / file).read_text()
current = (Path(app) / file).read_text()
if baseline.count(old) != 1 or baseline.replace(old,new,1) != target:
    raise SystemExit('BLOCKED: GitHub release contains unexpected template changes')
if current.count(old) != 1 or current.count(new):
    raise SystemExit('BLOCKED: Live Back to Top markup diverged; cannot merge safely')
Path(output).write_text(current.replace(old,new,1))
print('SCOPED MERGE: original live template preserved apart from exact button text')
print('LIVE TEMPLATE: ' + ('matches previous release' if current == baseline else 'has unrelated edits; retained'))
PY

if [[ "$MODE" == "--check" ]]; then
    echo "SAFE SCOPED BACK TO TOP CHECK PASSED"
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
for file in "${FILES[@]}"; do
    source_file="$RELEASE/$file"
    if [[ "$file" == "resources/views/layouts/main.blade.php" ]]; then
        source_file="$TMP/merged-main.blade.php"
    fi
    install -o www -g www -m 0644 "$source_file" "$APP/$file"
    cmp -s "$source_file" "$APP/$file" ||
        stop "Scoped copy verification failed: $file"
done
rsync -a --delete "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$RELEASE/public/build/manifest.json" "$APP/public/build/manifest.json" ||
    stop "Frontend manifest differs"

echo "========== VALIDATE INSTALLED APPLICATION =========="
(cd "$APP" &&
    runuser -u www -- "$PHP" artisan optimize:clear &&
    runuser -u www -- "$PHP" artisan view:cache &&
    runuser -u www -- "$PHP" artisan route:list --path=blog --no-ansi >/dev/null &&
    runuser -u www -- "$PHP" artisan route:list --path=post-manager --no-ansi >/dev/null &&
    runuser -u www -- "$PHP" artisan migrate:status --no-ansi >/dev/null) ||
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
grep -q 'data-public-mobile-search' <<<"$BLOG_PAGE" ||
    stop "New mobile search icon and form are missing"
grep -q 'nav-language-menu' <<<"$BLOG_PAGE" ||
    stop "Language switcher was not moved to the header"
grep -q 'data-public-category-menu' <<<"$BLOG_PAGE" ||
    stop "Existing category dropdown missing"
grep -q 'data-back-to-top' <<<"$BLOG_PAGE" ||
    stop "Back to Top action missing from public pages"
grep -q 'class="back-to-top-label">Back to Top</span>' <<<"$BLOG_PAGE" ||
    stop "Correct, unbroken Back to Top label missing"
grep -q 'brand-logo-light' <<<"$BLOG_PAGE" ||
    stop "Footer light-mode brand missing"
grep -q 'brand-logo-dark' <<<"$BLOG_PAGE" ||
    stop "Footer dark-mode brand missing"
grep -q 'class="footer-copyright"' <<<"$BLOG_PAGE" ||
    stop "Footer copyright layout missing"
grep -q 'class="brand footer-brand"' <<<"$BLOG_PAGE" ||
    stop "Official footer brand link missing"
if grep -q 'class="brand-mark">B</span>' <<<"$BLOG_PAGE"; then
    stop "Legacy footer initial badge remains"
fi
if grep -q 'nav-marketplace-links' <<<"$BLOG_PAGE"; then
    stop "Old duplicate marketplace header links remain"
fi

TOUCHED=0
echo "SCOPED VERTICAL BACK TO TOP DEPLOYED SUCCESSFULLY"
echo "Release SHA: $SHA"
echo "HTTP status: $HTTP_CODE"
echo "Original application backup: $ARCHIVE"
echo "Fresh MySQL snapshot: $DB_BACKUP"
echo "Existing posts, media, and unrelated live Blade edits preserved. No schema migration performed."
echo "Verify a true rotated vertical Back to Top label (not stacked words), safe footer spacing and mobile behavior."
