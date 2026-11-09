#!/usr/bin/env bash
# Scoped Bambodia creator-badge visibility upgrade for aaPanel.
set -Eeuo pipefail
umask 077
APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
REPO='bambodia168-sys/bambodialaravel'
RELEASE='63c0ed47dcb41323e086c50e441ef8a7c7b34c52'
PREVIOUS='5bb8f14eab6f4b67de4a352adb346ed20345e10a'
AWARDS_PREVIOUS='56e7bbfa5366feac230d6264b9ea617044c5ef2a'
RUN='42565640852'
ARCHIVE_NAME='Bambodia-Installer-v0.0.1'
BACKUPS='/root/Bambodia-PublicCreatorBadge-Backups'
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
FILES='app/Support/CreatorBadgeAwards.php app/Http/Controllers/Creator/CreatorProfileController.php app/Http/Controllers/Marketplace/ListingController.php resources/views/marketplace/detail.blade.php resources/views/creators/show.blade.php resources/views/creators/index.blade.php resources/views/account/creator-profile.blade.php'
MODE="$1"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || fail 'Expected --check or --apply'
[[ "$(id -u)" == 0 && -x "$PHP" && -d "$SOURCE/.git" ]] || fail 'Run as root on expected aaPanel host'
[[ -f "$APP/.env" && -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/storage/app/installed.lock" ]] || fail 'Unexpected installed Laravel runtime'
[[ ! -L "$APP" && ! -e "$APP/storage/framework/down" ]] || fail 'Application path is a symlink or site in maintenance'
[[ -f "$DB_HELPER" ]] || fail 'Private full MySQL backup helper missing'
for bin in gh git curl cmp tar unzip install mktemp sha256sum gzip runuser; do command -v "$bin" >/dev/null || fail "Missing $bin"; done
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail 'Unexpected Git remote'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Source checkout contains local changes'

echo "========== PUBLIC CREATOR BADGES ($MODE) =========="
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
git -C "$SOURCE" merge-base --is-ancestor "$RELEASE" HEAD || fail 'Verified feature release is not in Main'
cmp -s "$0" "$SOURCE/support/creator-badge-public-display-upgrade.sh" || fail 'Downloaded script does not match GitHub Main'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  n="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$RELEASE&per_page=20" --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$n" =~ ^[0-9]+$ && "$n" -ge 1 ]] || fail "CI not yet successful: $wf"
  echo "CI passed: $wf"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq '.conclusion')" == success ]] || fail 'Release installer not successful'
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq '.headSha')" == "$RELEASE" ]] || fail 'Installer SHA mismatch'
for f in $FILES; do
  [[ -f "$APP/$f" && ! -L "$APP/$f" ]] || fail "Unexpected installed file $f"
  old="$PREVIOUS"
  [[ "$f" != 'app/Support/CreatorBadgeAwards.php' ]] || old="$AWARDS_PREVIOUS"
  cmp -s "$APP/$f" <(git -C "$SOURCE" show "$old:$f") || fail "Installed file is customized: $f"
  cmp -s "$SOURCE/$f" <(git -C "$SOURCE" show "$RELEASE:$f") || fail "Unexpected source change: $f"
done

echo '========== VERIFY OWNER ACCOUNT AND BADGE ENTITLEMENT =========='
OWNER_ID="$(
  cd "$APP"
  runuser -u www -- "$PHP" <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner=\App\Models\User::query()->where('email',\App\Support\CreatorBadgeAwards::OWNER_EMAIL)->where('role','super_admin')->first();
if (!$owner || !preg_match('/^[0-9]{8}$/D',(string)$owner->public_id)) exit(2);
if (\App\Support\CreatorBadgeAwards::earned($owner)->count() !== 16) exit(3);
echo $owner->public_id;
PHP
)" || fail 'Designated owner with 16 active awards not verified'
[[ "$OWNER_ID" =~ ^[0-9]{8}$ ]] || fail 'Owner public ID missing'

TMP="$(mktemp -d /root/bambodia-public-badge.XXXXXXXX)"
BACKUP=''
DB_BACKUP=''
APPLIED=0
MAINT=0
cleanup(){
  code=$?
  trap - EXIT
  if (( code != 0 && APPLIED == 1 )); then
    echo 'UPGRADE FAILED: Restoring original PHP and Blade files' >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -s "$BACKUP" ]]; then tar -xzf "$BACKUP" -C "$APP" || echo 'ALERT: Manual restoration required' >&2; fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache) || true
    echo "Rollback: $BACKUP" >&2
    echo "MySQL snapshot: $DB_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Laravel still in maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$code"
}
trap cleanup EXIT

echo '========== VERIFY IMMUTABLE RELEASE PACKAGE =========='
gh run download "$RUN" -R "$REPO" -n "$ARCHIVE_NAME" -D "$TMP"
ZIP="$TMP/Bambodia-Installer.zip"
[[ -s "$ZIP" ]] && unzip -tq "$ZIP" >/dev/null || fail 'Installer unavailable or corrupted'
mkdir "$TMP/release"
unzip -q "$ZIP" -d "$TMP/release"
REL="$TMP/release/Bambodia"
for f in artisan composer.json VERSION; do cmp -s "$APP/$f" "$REL/$f" || fail "Core runtime changed: $f"; done
for f in $FILES; do
  cmp -s "$SOURCE/$f" "$REL/$f" || fail "Installer does not match source: $f"
  if [[ "$f" == *.php ]]; then "$PHP" -l "$REL/$f" >/dev/null || fail "PHP lint: $f"; fi
done

if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: expected production baseline, six passing CI workflows and owner badges verified.'
  echo 'No installed site files, settings or database modified.'
  exit 0
fi

echo '========== BACKUP AND APPLY =========='
install -d -m 0700 "$BACKUPS"
DEST="$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -m 0700 "$DEST"
BACKUP="$DEST/previous-files.tar.gz"
tar -czf "$BACKUP" -C "$APP" $FILES
chmod 0600 "$BACKUP"
tar -tzf "$BACKUP" >/dev/null || fail 'Invalid file backup'
DB_BACKUP="$(bash "$DB_HELPER")" || fail 'MySQL backup failed'
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] || fail 'Invalid backup path'
gzip -t "$DB_BACKUP" || fail 'Corrupted MySQL backup'
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"

(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINT=1
APPLIED=1
for f in $FILES; do
  install -o www -g www -m 0644 "$REL/$f" "$APP/$f"
  cmp -s "$REL/$f" "$APP/$f" || fail "Copy verification failed: $f"
done
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || fail '.env unexpectedly changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] || fail 'Installer lock unexpectedly changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINT=0

echo '========== PUBLIC PROFILE HTTP ACCEPTANCE =========='
URL="https://bambodia.com/creators/$OWNER_ID"
HTML="$(curl -fsSL --max-time 30 "$URL")" || fail 'Owner public badge profile failed'
grep -Fq 'images/badges/top-rated.svg' <<< "$HTML" || fail 'Top Rated medal not visible publicly'
grep -Fq 'images/badges/vip-client-favorite.svg' <<< "$HTML" || fail 'VIP medal not visible publicly'
curl -fsSL --max-time 25 -o /dev/null 'https://bambodia.com/creators' || fail 'Creator directory unavailable'
curl -fsSL --max-time 25 -o /dev/null 'https://bambodia.com/' || fail 'Homepage unavailable'
APPLIED=0
echo '========== PUBLIC CREATOR BADGES SUCCESS =========='
echo "PUBLIC_OWNER_BADGES=$URL"
echo "FEATURE_COMMIT=$RELEASE"
echo "BACKUP=$BACKUP"
echo "MYSQL_BACKUP=$DB_BACKUP"
echo 'SCOPE=seven PHP/Blade files; no database migrations, CSS, JS or server configuration changes'
