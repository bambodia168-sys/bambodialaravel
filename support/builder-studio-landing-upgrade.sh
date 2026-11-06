#!/usr/bin/env bash
# Guarded landing-only update, no migrations.
# bash support/builder-studio-landing-upgrade.sh --check RUN_ID FULL_SHA
# bash support/builder-studio-landing-upgrade.sh --apply RUN_ID FULL_SHA
set -Eeuo pipefail
umask 077
SRC='/root/bambodia-release-audit'
APP='/www/wwwroot/bambodia-staging-qa'
VHOST='/www/server/panel/vhost/nginx/bambodia.com.conf'
PHP='/www/server/php/85/bin/php'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
PREVIOUS='284e4059d2d63fd188bb56703141e48c79241fb7'
BACKUPS='/root/Bambodia-BuilderStudio-Backups'
MODE="${1:-}"
RUN="${2:-}"
SHA="${3:-}"
stop(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || stop 'Use --check or --apply'
[[ "$RUN" =~ ^[0-9]{8,15}$ && "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop 'Supply the installer run ID and full SHA'
[[ "$(id -u)" == 0 ]] || stop 'Run as root'
for tool in git gh tar unzip rsync cmp install runuser sha256sum mktemp grep; do
  command -v "$tool" >/dev/null || stop "Missing utility: $tool"
done
[[ -d "$SRC/.git" && -x "$PHP" ]] || stop 'Git or PHP missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || stop 'Wrong application'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || stop 'Incomplete Laravel runtime'
[[ -f "$VHOST" && ! -L "$APP" && ! -L "$APP/public/build" ]] || stop 'Unsafe application path'
[[ ! -e "$APP/storage/framework/down" ]] || stop 'Already in maintenance'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$VHOST" || stop 'Unexpected Nginx root'
[[ "$(git -C "$SRC" remote get-url origin)" == "https://github.com/$REPO.git" ]] || stop 'Unexpected repository origin'
[[ -z "$(git -C "$SRC" status --porcelain)" ]] || stop 'Local changes in Git source'
gh auth status >/dev/null 2>&1 || stop 'GitHub CLI not authenticated'

echo "====== BUILDER STUDIO LANDING ($MODE) ======"
git -C "$SRC" fetch origin main
git -C "$SRC" merge --ff-only origin/main
[[ "$(git -C "$SRC" rev-parse HEAD)" == "$SHA" ]] || stop 'Unexpected GitHub main SHA'
for file in resources/views/builder/index.blade.php resources/css/app.css; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Installed file unavailable: $file"
  cmp -s "$APP/$file" <(git -C "$SRC" show "$PREVIOUS:$file") \
    || stop "Installed $file differs from the verified prior release"
  echo "BASE MATCH: $file"
done
[[ -f "$APP/resources/js/app.js" ]] || stop 'Installed JS missing'
cmp -s "$APP/resources/js/app.js" <(git -C "$SRC" show "$PREVIOUS:resources/js/app.js") \
  || stop 'Unexpected installed JS changes'
cmp -s "$SRC/resources/js/app.js" "$APP/resources/js/app.js" \
  || stop 'New release JS differs from installed source'

echo '====== VERIFY CI GATES ======'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$SHA&per_page=20" \
    --jq '[.workflow_runs[]|select(.conclusion=="success")]|length')"
  [[ "$count" =~ ^[0-9]+$ ]] && (( count > 0 )) || stop "Workflow not green: $wf"
  echo "PASS: $wf"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq .headSha)" == "$SHA" ]] || stop 'Installer run SHA mismatch'
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq .conclusion)" == success ]] || stop 'Installer run failed'

TMP="$(mktemp -d /root/bambodia-landing.XXXXXXXX)"
ARCHIVE=''
DOWN=0
CHANGED=0
finish(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && CHANGED == 1 )); then
    echo 'ROLLBACK: Restoring original source and frontend assets' >&2
    [[ -f "$ARCHIVE" ]] && tar -xzf "$ARCHIVE" -C "$APP" || true
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Check maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap finish EXIT

gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
PKG="$TMP/Bambodia-Installer.zip"
[[ -s "$PKG" ]] || stop 'Installer ZIP missing'
unzip -tq "$PKG" >/dev/null || stop 'Installer ZIP invalid'
mkdir -p "$TMP/release"
unzip -q "$PKG" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for file in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$APP/$file" "$RELEASE/$file" \
    || stop "Runtime identity mismatch: $file"
done
for file in resources/views/builder/index.blade.php resources/css/app.css resources/js/app.js; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$RELEASE/$file" "$SRC/$file" \
    || stop "Installer/source mismatch: $file"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || stop 'Vite build missing'
if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: Installed baseline, source, CI, and package verified.'
  echo 'Live website unchanged. Apply only after check passes.'
  echo "Command: bash $SRC/support/builder-studio-landing-upgrade.sh --apply $RUN $SHA"
  exit 0
fi

install -d -m 0700 '/root/Bambodia-BuilderStudio-Backups'
BACKUP="$(mktemp -d '/root/Bambodia-BuilderStudio-Backups/landing-XXXXXXXX')"
ARCHIVE="$BACKUP/original-files.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" \
  resources/views/builder/index.blade.php resources/css/app.css public/build
tar -tzf "$ARCHIVE" >/dev/null || stop 'Backup integrity failed'
ENV_HASH="$(sha256sum "$APP/.env" | cut -d ' ' -f1)"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)"
echo "Backup: $ARCHIVE"
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
CHANGED=1
for file in resources/views/builder/index.blade.php resources/css/app.css; do
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || stop "Copy failed: $file"
done
rsync -a --chown=www:www "$RELEASE/public/build/" "$APP/public/build/"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" \
  || stop 'Compiled manifest mismatch'
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d ' ' -f1)" == "$ENV_HASH" ]] || stop '.env changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)" == "$LOCK_HASH" ]] || stop 'Installer lock changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
CHANGED=0
echo '====== BUILDER STUDIO LANDING DEPLOYED SUCCESSFULLY ======'
echo "Release: $SHA"
echo "Rollback archive: $ARCHIVE"
echo 'Changed: builder Blade view, app.css and compiled Vite assets only.'
