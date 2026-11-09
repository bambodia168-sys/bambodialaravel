#!/usr/bin/env bash
# Bambodia User Manager v2 — verified aaPanel scoped upgrade.
# Usage: bash support/user-manager-v2-upgrade.sh --check
#        bash support/user-manager-v2-upgrade.sh --apply
set -Eeuo pipefail
umask 077
APP='/www/wwwroot/bambodia-staging-qa'
SOURCE='/root/bambodia-release-audit'
PHP='/www/server/php/85/bin/php'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
REPO='bambodia168-sys/bambodialaravel'
FEATURE='6ec0c74082f96c4191201e8ddd7214396debc396'
BASE='34db30b64a54221ee00d86829ccafd117696eaa5'
RUN='42573728450'
ARTIFACT='Bambodia-Installer-v0.0.1'
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS='/root/Bambodia-UserManagerV2-Backups'
FILES='app/Models/User.php app/Models/AuditEvent.php bootstrap/app.php app/Http/Middleware/AuthenticateApiToken.php app/Http/Controllers/Dashboard/UserManagerController.php routes/web.php resources/views/layouts/workspace.blade.php resources/views/dashboard/users.blade.php resources/css/app.css'
NEW_FILES='database/migrations/2026_11_09_002000_expand_user_manager.php app/Models/UserProfile.php app/Support/UserManagementAccess.php app/Http/Middleware/EnsureActiveAccount.php app/Http/Middleware/EnsureCapability.php app/Http/Controllers/Dashboard/UserOwnershipTransferController.php resources/views/dashboard/user-form.blade.php resources/views/dashboard/user-show.blade.php resources/views/dashboard/user-transfer.blade.php'
MODE="$1"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail 'Use --check or --apply'
[[ "$(id -u)" == 0 ]] || fail 'Run as root on aaPanel'
for bin in gh git tar curl unzip rsync cmp install sha256sum cut runuser mktemp gzip; do
  command -v "$bin" >/dev/null || fail "Missing executable: $bin"
done
[[ -x "$PHP" && -d "$SOURCE/.git" ]] || fail 'Missing PHP 8.5 or Git source'
[[ -f "$APP/.env" && -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/storage/app/installed.lock" ]] || fail 'Installed Laravel runtime missing'
[[ -f "$APP/public/build/manifest.json" && ! -L "$APP/public/build" ]] || fail 'Compiled frontend missing'
[[ -f "$CONF" && ! -L "$APP" && ! -e "$APP/storage/framework/down" ]] || fail 'Unexpected site path or site in maintenance mode'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || fail 'Unexpected site document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail 'Unexpected Git remote'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail 'Uncommitted source files'

echo "========== BAMBODIA USER MANAGER V2 ($MODE) =========="
echo '1. Verify immutable feature commit and six successful CI workflows'
git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
git -C "$SOURCE" merge-base --is-ancestor "$FEATURE" HEAD || fail 'Feature release not in Main'
cmp -s "$0" "$SOURCE/support/user-manager-v2-upgrade.sh" || fail 'Downloaded deployment script differs from GitHub Main'
for wf in quality.yml build-installer.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  n="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$FEATURE&per_page=20" --jq '[.workflow_runs[] | select(.conclusion == "success")] | length')"
  [[ "$n" =~ ^[0-9]+$ && "$n" -ge 1 ]] || fail "CI not successful: $wf"
  echo "CI passed: $wf"
done
[[ "$(gh run view "$RUN" -R "$REPO" --json conclusion --jq '.conclusion')" == 'success' ]] || fail 'Installer CI not successful'
[[ "$(gh run view "$RUN" -R "$REPO" --json headSha --jq '.headSha')" == "$FEATURE" ]] || fail 'Installer release does not match feature commit'

echo '2. Verify installed operational baseline, refuse customizations'
for f in $FILES; do
  [[ -f "$APP/$f" && ! -L "$APP/$f" ]] || fail "Unsafe installed path: $f"
  cmp -s "$APP/$f" <(git -C "$SOURCE" show "$BASE:$f") || fail "Installed file differs from last verified production baseline: $f"
  cmp -s "$SOURCE/$f" <(git -C "$SOURCE" show "$FEATURE:$f") || fail "Current source differs from verified release: $f"
done
for f in $NEW_FILES; do
  [[ ! -e "$APP/$f" && ! -L "$APP/$f" ]] || fail "New feature path already installed/customized: $f"
  cmp -s "$SOURCE/$f" <(git -C "$SOURCE" show "$FEATURE:$f") || fail "Current new feature file differs: $f"
done
[[ -f "$DB_HELPER" ]] || fail 'Trusted full MySQL backup helper missing'

TMP="$(mktemp -d /root/bambodia-user-manager-v2.XXXXXXXX)"
BACKUP=''
DB_BACKUP=''
CHANGED=0
MAINTENANCE=0
cleanup(){
  result=$?
  trap - EXIT
  if (( result != 0 && CHANGED == 1 )); then
    echo 'Upgrade failed. Restoring previous application files and Vite bundle.' >&2
    if (( MAINTENANCE == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINTENANCE=1
    fi
    if [[ -f "$BACKUP" ]]; then
      tar -xzf "$BACKUP" -C "$APP" || echo 'ALERT: Manual file restoration required' >&2
    fi
    for f in $NEW_FILES; do
      if [[ -f "$APP/$f" && ! -L "$APP/$f" ]]; then rm -f -- "$APP/$f"; fi
    done
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache) || true
    echo "Rollback archive: $BACKUP" >&2
    echo "MySQL snapshot: $DB_BACKUP" >&2
    echo 'NOTICE: additive user manager database tables may remain; do not automatically restore a full DB snapshot' >&2
  fi
  if (( MAINTENANCE == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || echo 'ALERT: Application remains in maintenance mode' >&2
  fi
  rm -rf -- "$TMP"
  exit "$result"
}
trap cleanup EXIT

echo '3. Download and validate signed workflow artifact against exact source'
gh run download "$RUN" -R "$REPO" -n "$ARTIFACT" -D "$TMP"
ZIP="$TMP/Bambodia-Installer.zip"
[[ -s "$ZIP" ]] || fail 'Expected installer artifact missing'
unzip -tq "$ZIP" >/dev/null || fail 'Installer artifact corrupted'
mkdir -m 0700 "$TMP/release"
unzip -q "$ZIP" -d "$TMP/release"
REL="$TMP/release/Bambodia"
for core in artisan composer.json VERSION; do
  [[ -f "$REL/$core" && -f "$APP/$core" ]] && cmp -s "$REL/$core" "$APP/$core" || fail "Core app changed: $core"
done
[[ -s "$REL/public/build/manifest.json" ]] || fail 'Compiled Vite assets missing'
for f in $FILES $NEW_FILES; do
  [[ -f "$REL/$f" ]] && cmp -s "$REL/$f" "$SOURCE/$f" || fail "Artifact and GitHub differ: $f"
  if [[ "$f" == *.php ]]; then "$PHP" -l "$REL/$f" >/dev/null || fail "PHP lint error: $f"; fi
done
if [[ "$MODE" == '--check' ]]; then
  echo 'CHECK PASSED: production baseline, 6 GitHub CI workflows, installer artifact and PHP syntax.'
  echo 'No production file, database or server setting changed.'
  echo 'To apply: bash /root/bambodia-user-manager-v2-upgrade.sh --apply'
  exit 0
fi

echo '4. Private archive and full MySQL backup before any changes'
install -d -m 0700 "$BACKUPS"
DIR="$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -m 0700 "$DIR"
BACKUP="$DIR/original.tar.gz"
tar -czf "$BACKUP" -C "$APP" $FILES public/build
chmod 0600 "$BACKUP"
tar -tzf "$BACKUP" >/dev/null || fail 'Backup file invalid'
DB_BACKUP="$(bash "$DB_HELPER")" || fail 'Full MySQL backup failed'
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] || fail 'Invalid DB snapshot path'
gzip -t "$DB_BACKUP" || fail 'DB snapshot corrupted'
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
echo "File backup: $BACKUP"
echo "MySQL snapshot: $DB_BACKUP"

echo '5. Scoped install in maintenance mode'
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINTENANCE=1
CHANGED=1
for f in $FILES $NEW_FILES; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$f")"
  install -o www -g www -m 0644 "$REL/$f" "$APP/$f"
  cmp -s "$REL/$f" "$APP/$f" || fail "Installed file mismatch: $f"
done
rsync -a --delete "$REL/public/build/" "$APP/public/build/"
chown -R www:www "$APP/public/build"
cmp -s "$REL/public/build/manifest.json" "$APP/public/build/manifest.json" || fail 'Compiled assets mismatch'

echo '6. Migrate additively; verify all management routes, data and privacy gates'
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate --force --no-interaction && runuser -u www -- "$PHP" artisan route:clear && runuser -u www -- "$PHP" artisan view:clear && runuser -u www -- "$PHP" artisan view:cache)
(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach ([
 'dashboard.users','dashboard.users.create','dashboard.users.store',
 'dashboard.users.show','dashboard.users.edit','dashboard.users.update',
 'dashboard.users.archive','dashboard.users.restore',
 'dashboard.users.transfer','dashboard.users.transfer.store'
] as $name) {
 if (!\Illuminate\Support\Facades\Route::has($name)) {
   fwrite(STDERR,"Missing User Manager route: $name\n"); exit(2);
 }
}
foreach(['user_profiles','user_permission_overrides','user_ownership_transfers','user_management_events'] as $name){
 if(!\Illuminate\Support\Facades\Schema::hasTable($name)){fwrite(STDERR,"Missing table: $name\n");exit(3);}
}
if(!\Illuminate\Support\Facades\Schema::hasColumn('users','account_status'))exit(4);
$owner=\App\Models\User::query()->where('email',\App\Support\CreatorBadgeAwards::OWNER_EMAIL)->first();
if (!$owner || $owner->role!=='super_admin' || $owner->account_status!=='active') exit(5);
echo "User Manager routes, schema and designated owner verified.\n";
PHP
) || fail 'User Manager post-migration checks failed'
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || fail '.env unexpectedly modified'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] || fail 'Installed lock unexpectedly changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINTENANCE=0

echo '7. Public smoke checks, auth remains protected'
for url in 'https://bambodia.com/' 'https://bambodia.com/creators' 'https://bambodia.com/images/badges/top-rated.svg'; do
  code="$(curl -sSL --max-time 25 -o /dev/null -w '%{http_code}' "$url")"
  [[ "$code" == '200' ]] || fail "Public URL failed ($code): $url"
  echo "HTTP 200: $url"
done
auth_code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' 'https://bambodia.com/dashboard/user-manager')"
[[ "$auth_code" == 302 || "$auth_code" == 303 ]] || fail "User manager guest route not protected: $auth_code"
CHANGED=0
echo '========== BAMBODIA USER MANAGER V2 DEPLOYMENT SUCCESS =========='
echo "FEATURE_COMMIT=$FEATURE"
echo 'PERMISSIONS=role-based with scoped per-user restrictions'
echo 'ACCOUNT_REMOVAL=reversible archive, preserves contractual records'
echo "ROLLBACK_FILES=$BACKUP"
echo "FULL_MYSQL_SNAPSHOT=$DB_BACKUP"
echo 'UNCHANGED=private vault, entitlements, licenses, contracts, Nginx, PHP-FPM, .env and installer lock'
