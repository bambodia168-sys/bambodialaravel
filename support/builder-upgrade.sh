#!/usr/bin/env bash
# Unified Builder aaPanel deployment. Use --check before --apply.
# bash support/builder-upgrade.sh --check INSTALLER_RUN_ID FULL_GIT_SHA
set -Eeuo pipefail
umask 077

SOURCE='/root/bambodia-release-audit'
APP='/www/wwwroot/bambodia-staging-qa'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
PHP='/www/server/php/85/bin/php'
REPO='bambodia168-sys/bambodialaravel'
ARTIFACT='Bambodia-Installer-v0.0.1'
PREVIOUS='256cd367f0ed54f107d4d37ef39ebbe56ae070aa'
BACKUP_ROOT='/root/Bambodia-Builder-Backups'
MODE="${1:-}"
RUN="${2:-}"
SHA="${3:-}"

fail(){ printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail 'Expected --check or --apply'
[[ "$RUN" =~ ^[0-9]{8,15}$ ]] || fail 'Expected numerical installer run ID'
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || fail 'Expected full Git SHA'
[[ "$(id -u)" == 0 ]] || fail 'Run as root'
for tool in gh git tar unzip rsync cmp install sha256sum runuser mktemp grep cut; do
  command -v "$tool" >/dev/null || fail "Missing command: $tool"
done
[[ -x "$PHP" && -d "$SOURCE/.git" ]] || fail 'Missing PHP or Git source'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || fail 'Installed app missing'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" ]] || fail 'Installed runtime incomplete'
[[ -f "$APP/resources/views/layouts/workspace.blade.php" ]] || fail 'Workspace layout missing'
[[ -f "$CONF" && ! -L "$APP" && ! -L "$APP/public/build" ]] || fail 'Unsafe site paths'
[[ ! -e "$APP/storage/framework/down" ]] || fail 'App already in maintenance mode'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" \
  || fail 'Unexpected Nginx document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail 'Unknown source origin'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Source Git working copy modified'
gh auth status >/dev/null 2>&1 || fail 'GitHub not authenticated'

# Do not replace public main layout, search view, frontend JS source, .env,
# database, private vault, customer assets or Nginx configurations.
EXISTING=(
 routes/web.php
 app/Http/Controllers/Creator/ListingController.php
 app/Http/Controllers/Creator/ProductController.php
 app/Http/Controllers/JobBoardController.php
 app/Models/AuditEvent.php
 resources/views/account/listings.blade.php
 resources/views/account/products.blade.php
 resources/views/account/studio.blade.php
 resources/views/jobs/mine.blade.php
 resources/css/app.css
)
NEW=(app/Http/Controllers/BuilderController.php resources/views/builder/index.blade.php)
ALL=("${EXISTING[@]}" "${NEW[@]}")

echo "====== UNIFIED BUILDER UPGRADE ($MODE) ======"
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] || fail 'Git source does not match expected release'
git -C "$SOURCE" cat-file -e "$PREVIOUS^{commit}" || fail 'Previous source snapshot unavailable'

echo '====== CHECK INSTALLED BASELINE ======'
mismatches=0
for file in "${EXISTING[@]}"; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || fail "Missing or symlinked installed file: $file"
  if cmp -s "$APP/$file" <(git -C "$SOURCE" show "$PREVIOUS:$file"); then
    echo "MATCH: $file"
  else
    echo "DIFFERENT: $file"
    mismatches=1
  fi
done
for file in "${NEW[@]}"; do
  [[ -f "$SOURCE/$file" ]] || fail "New builder source missing: $file"
  if [[ -e "$APP/$file" || -L "$APP/$file" ]]; then
    echo "EXISTS ALREADY: $file"
    mismatches=1
  fi
done
if ! cmp -s "$APP/resources/js/app.js" "$SOURCE/resources/js/app.js"; then
  echo 'DIFFERENT: resources/js/app.js (compiled JS must not overwrite custom changes)'
  mismatches=1
fi
if (( mismatches != 0 )); then
  echo 'Do not bypass this guard. Inspect installed changes before proceeding.'
  fail 'Installed files diverge from the verified release.'
fi

echo '====== VERIFY GITHUB CI ======'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  successes="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$SHA&per_page=20" \
      --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$successes" =~ ^[0-9]+$ ]] && (( successes > 0 )) || fail "Workflow failed/not completed: $wf"
  echo "PASS: $wf"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq .headSha)" == "$SHA" ]] || fail 'Installer SHA mismatch'
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq .conclusion)" == success ]] || fail 'Installer run unsuccessful'

TMP="$(mktemp -d /root/bambodia-builder.XXXXXXXX)"
ARCHIVE=''
DOWN=0
TOUCHED=0
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo 'RESTORING: Original source and compiled assets' >&2
    if [[ -f "$ARCHIVE" ]]; then
      tar -xzf "$ARCHIVE" -C "$APP" || echo 'WARNING: Backup restoration failed' >&2
    fi
    for file in "${NEW[@]}"; do rm -f -- "$APP/$file"; done
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && \
      runuser -u www -- "$PHP" artisan view:clear && \
      runuser -u www -- "$PHP" artisan view:cache) || true
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'WARNING: Check maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
PACKAGE="$TMP/Bambodia-Installer.zip"
[[ -s "$PACKAGE" ]] || fail 'Installer ZIP absent'
unzip -tq "$PACKAGE" >/dev/null || fail 'Installer ZIP corrupt'
mkdir -p "$TMP/release"
unzip -q "$PACKAGE" -d "$TMP/release"
RELEASE="$TMP/release/Bambodia"
for file in artisan composer.json VERSION; do
  [[ -f "$RELEASE/$file" ]] && cmp -s "$APP/$file" "$RELEASE/$file" \
    || fail "Installed runtime differs: $file"
done
[[ -f "$RELEASE/public/build/manifest.json" ]] || fail 'Missing compiled Vite assets'
for file in "${ALL[@]}" resources/js/app.js; do
  [[ -f "$SOURCE/$file" && -f "$RELEASE/$file" ]] && cmp -s "$SOURCE/$file" "$RELEASE/$file" \
    || fail "Installer/source mismatch: $file"
done

if [[ "$MODE" == '--check' ]]; then
  echo 'CHECK PASSED: Source, installed baseline, compiled installer and all six CI gates.'
  echo 'Installed application unchanged. Apply only after this check succeeds.'
  echo "Command: bash $SOURCE/support/builder-upgrade.sh --apply $RUN $SHA"
  exit 0
fi

install -d -m 0700 "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/builder-XXXXXXXX")"
ARCHIVE="$BACKUP/rollback.tar.gz"
tar -czf "$ARCHIVE" -C "$APP" "${EXISTING[@]}" public/build
tar -tzf "$ARCHIVE" >/dev/null || fail 'Backup validation failed'
ENV_SHA="$(sha256sum "$APP/.env" | cut -d ' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)"

(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1
for file in "${ALL[@]}"; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$RELEASE/$file" "$APP/$file"
  cmp -s "$APP/$file" "$RELEASE/$file" || fail "File copy verification failed: $file"
done
# Keep older hashed assets instead of deleting unrelated generated files.
rsync -a "$RELEASE/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build/"
cmp -s "$APP/public/build/manifest.json" "$RELEASE/public/build/manifest.json" || fail 'Vite manifest differs'

(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && \
  runuser -u www -- "$PHP" artisan view:clear && \
  runuser -u www -- "$PHP" artisan view:cache && \
  runuser -u www -- "$PHP" artisan route:list --path=builder)
[[ "$(sha256sum "$APP/.env" | cut -d ' ' -f1)" == "$ENV_SHA" ]] || fail '.env unexpectedly changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] || fail 'Installer lock unexpectedly changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0

echo '====== BUILDER DEPLOYMENT SUCCESS ======'
echo "Commit: $SHA"
echo "Rollback archive: $ARCHIVE"
echo 'Only builder-related sources and Vite assets updated. Database, secrets, search view, public navigation and private vault unchanged.'
