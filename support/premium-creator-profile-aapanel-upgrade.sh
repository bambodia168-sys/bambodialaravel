#!/usr/bin/env bash
# Bambodia profile: fail-closed, scoped aaPanel deployment. Run --check before --apply.
set -Eeuo pipefail
umask 077

MODE="$*"
[[ -n "$MODE" ]] || MODE="--check"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Usage: bash $0 --check|--apply" >&2; exit 2; }
REPO="bambodia168-sys/bambodialaravel"
BASE="9253ebfaf8a05608442cd4e0d5f922c6dbe5cc8e"
FEATURE="afa1fcee0b9ad564f1257b2db15014493b255279"
BRANCH="feature/premium-creator-profile-editor-20261110"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUP_ROOT="/root/Bambodia-PremiumProfile-Backups"
OLD_FILES="
app/Http/Controllers/Creator/CreatorProfileController.php
app/Models/AuditEvent.php
app/Models/UserProfile.php
resources/views/account/index.blade.php
resources/views/creators/show.blade.php
resources/views/dashboard/user-form.blade.php
resources/views/layouts/main.blade.php
resources/views/layouts/workspace.blade.php
routes/web.php
"
NEW_FILES="
app/Http/Controllers/CreatorProfileItemController.php
app/Http/Controllers/ProfileEditorController.php
app/Models/CreatorProfileItem.php
database/migrations/2026_11_10_000001_create_editable_creator_profile_sections.php
public/css/creator-profile-v3.css
public/js/creator-profile-v3.js
resources/views/account/profile-editor.blade.php
"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail "Root is required."
for bin in git gh python3 cmp curl tar gzip runuser mktemp install sha256sum cut; do
  command -v "$bin" >/dev/null || fail "Missing command: $bin."
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" ]] || fail "Source checkout unsafe or missing."
[[ -f "$APP/artisan" && ! -L "$APP" ]] || fail "Runtime unsafe or missing."
[[ -x "$PHP" && -f "$APP/.env" && -f "$APP/vendor/autoload.php" ]] || fail "PHP runtime incomplete."
[[ -f "$APP/storage/app/installed.lock" && -f "$APP/public/build/manifest.json" ]] || fail "Installed lock/build missing."
[[ -f "$CONF" ]] || fail "aaPanel virtual host not found."
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" ||
  fail "Unexpected production nginx root."
[[ ! -e "$APP/storage/framework/down" ]] || fail "App already in maintenance."
[[ -f "$DB_HELPER" ]] || fail "Secure MySQL backup helper missing."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail "Unexpected Git remote."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Source working tree is not clean."
id www >/dev/null || fail "Web user www unavailable."

TMP="$(mktemp -d /root/bambodia-profile-release.XXXXXXXX)"
FILE_BACKUP=""
DB_BACKUP=""
APPLY_STARTED=0
MAINT=0
NEWLY_CREATED=""
cleanup(){
  code=$?
  trap - EXIT
  if (( code != 0 && APPLY_STARTED == 1 )); then
    echo "INSTALL FAILED. Reverting scoped file changes." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -s "$FILE_BACKUP" ]]; then
      tar -xzf "$FILE_BACKUP" -C "$APP" || echo "ALERT: File restore failed." >&2
    fi
    for file in $NEWLY_CREATED; do
      [[ ! -L "$APP/$file" ]] && rm -f -- "$APP/$file"
    done
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILE_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$DB_BACKUP" >&2
    echo "NOTE: Additive migration schema is retained after file rollback." >&2
  fi
  if (( MAINT == 1 )); then (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true; fi
  rm -rf -- "$TMP"
  exit "$code"
}
trap cleanup EXIT

echo "========== PREMIUM CREATOR PROFILE $MODE =========="
echo "1. Verify immutable feature and passing CI."
git -C "$SOURCE" fetch origin "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
CURRENT="$(git -C "$SOURCE" rev-parse "refs/remotes/origin/$BRANCH")"
git -C "$SOURCE" merge-base --is-ancestor "$FEATURE" "$CURRENT" ||
  fail "Feature branch no longer descends from the verified release."
git -C "$SOURCE" diff --name-only "$FEATURE" "$CURRENT" | LC_ALL=C sort > "$TMP/post-release"
printf '%s\n' '.github/workflows/quality.yml' 'support/premium-creator-profile-aapanel-upgrade.sh' | LC_ALL=C sort > "$TMP/allowed-post-release"
cmp -s "$TMP/post-release" "$TMP/allowed-post-release" ||
  fail "Unexpected code changed after the verified release."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$CURRENT:support/premium-creator-profile-aapanel-upgrade.sh")" ]] ||
  fail "Local installer differs from the committed verified helper."
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Base commit unavailable."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$FEATURE" || fail "Unexpected commit ancestry."
for workflow in quality.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$FEATURE&per_page=30" \
     --jq '[.workflow_runs[] | select(.head_sha == "'"$FEATURE"'" and .conclusion == "success")] | length')" ||
     fail "Cannot verify CI: $workflow"
  [[ "$count" =~ ^[0-9]+$ && "$count" -ge 1 ]] || fail "Missing successful CI: $workflow"
  echo "PASS $workflow"
done

echo "2. Verify exact deployment scope, original files, and untouched Published work."
printf '%s\n' $OLD_FILES $NEW_FILES tests/Feature/PremiumCreatorProfileCrudTest.php | LC_ALL=C sort > "$TMP/expected"
git -C "$SOURCE" diff --name-only "$BASE" "$FEATURE" | LC_ALL=C sort > "$TMP/actual"
cmp -s "$TMP/expected" "$TMP/actual" || fail "Release contains unexpected files."
python3 - "$SOURCE" "$BASE" "$FEATURE" <<'PY'
import subprocess, sys
source, base, target = sys.argv[1:]
path = 'resources/views/creators/show.blade.php'
anchor = '  <section class="marketplace-section">'
def read(sha):
    return subprocess.check_output(['git','-C',source,'show',f'{sha}:{path}'],text=True)
old, new = read(base), read(target)
if old.count(anchor) != 1 or new.count(anchor) != 1 or old.split(anchor,1)[1] != new.split(anchor,1)[1]:
    raise SystemExit('BLOCKED: Published work was changed.')
print('PUBLISHED_WORK=VERBATIM_PRESERVED')
PY

for file in $OLD_FILES $NEW_FILES; do
  [[ ! -L "$APP/$file" ]] || fail "Symlink collision: $file"
  mkdir -p "$TMP/payload/$(dirname "$file")"
  git -C "$SOURCE" show "$FEATURE:$file" > "$TMP/payload/$file" || fail "Missing Git blob: $file"
  if [[ "$file" == *.php ]]; then
    "$PHP" -l "$TMP/payload/$file" >/dev/null || fail "PHP lint failed: $file"
  fi
done
for file in $OLD_FILES; do
  [[ -f "$APP/$file" ]] || fail "Existing production file missing: $file"
  if cmp -s "$APP/$file" "$TMP/payload/$file"; then
    echo "ALREADY_AT_RELEASE $file"
  elif cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file"); then
    echo "KNOWN_BASELINE $file"
  else
    echo "PRODUCTION_BLOB=$(git hash-object "$APP/$file")" >&2
    fail "Unknown live customization; refusing overwrite: $file"
  fi
done
for file in $NEW_FILES; do
  if [[ -e "$APP/$file" ]]; then
    [[ -f "$APP/$file" ]] && cmp -s "$APP/$file" "$TMP/payload/$file" ||
      fail "Conflicting new file: $file"
  else
    NEWLY_CREATED="$NEWLY_CREATED $file"
  fi
done

if [[ "$MODE" == "--check" ]]; then
  echo "CHECK PASSED: CI, PHP lint, safe baselines and Published work."
  echo "No production application, database or system configuration was altered."
  exit 0
fi

echo "3. Make private file backup and full MySQL snapshot."
install -d -m 0700 "$BACKUP_ROOT"
BACKUP_DIR="$(mktemp -d "$BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILE_BACKUP="$BACKUP_DIR/original-files.tar.gz"
tar -czf "$FILE_BACKUP" -C "$APP" $OLD_FILES
chmod 0600 "$FILE_BACKUP"
tar -tzf "$FILE_BACKUP" >/dev/null || fail "File archive corrupt."
DB_BACKUP="$(bash "$DB_HELPER")" || fail "Database backup failed."
[[ "$DB_BACKUP" == /root/* && "$DB_BACKUP" == *.sql.gz && -s "$DB_BACKUP" ]] ||
  fail "Database backup path invalid."
gzip -t "$DB_BACKUP" || fail "MySQL snapshot gzip integrity failed."
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"

echo "4. Apply verified files and additive database migration."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINT=1
APPLY_STARTED=1
for file in $OLD_FILES $NEW_FILES; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$TMP/payload/$file" "$APP/$file"
  cmp -s "$TMP/payload/$file" "$APP/$file" || fail "Installation mismatch: $file"
done
(cd "$APP" &&
  runuser -u www -- "$PHP" artisan migrate --force --no-interaction &&
  runuser -u www -- "$PHP" artisan route:clear &&
  runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache)
(cd "$APP" && runuser -u www -- "$PHP" artisan route:list --name=profile --except-vendor) > "$TMP/routes.txt"
grep -q 'account.profile.edit' "$TMP/routes.txt" || fail "Account profile route missing."
grep -q 'dashboard.users.profile.edit' "$TMP/routes.txt" || fail "Admin profile route missing."
grep -q 'studio.profile.edit' "$TMP/routes.txt" || fail "Creator profile route missing."
if [[ ! -e "$APP/public/storage" ]]; then
  (cd "$APP" && runuser -u www -- "$PHP" artisan storage:link) ||
    fail "Public avatar storage link cannot be created."
fi
[[ -L "$APP/public/storage" ]] || fail "Avatar storage link missing or unsafe."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] || fail ".env changed."
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] ||
  fail "Installation lock changed."
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINT=0

echo "5. Check public profile, new assets and guest authorization."
for url in \
  "https://bambodia.com/" \
  "https://bambodia.com/creators/34302348" \
  "https://bambodia.com/css/creator-profile-v3.css?v=1" \
  "https://bambodia.com/js/creator-profile-v3.js?v=1"; do
  curl -fsSL --max-time 35 -o /dev/null "$url" || fail "HTTP check failed: $url"
  echo "HTTP_OK $url"
done
curl -fsSL --max-time 35 "https://bambodia.com/creators/34302348" -o "$TMP/profile.html"
python3 - "$TMP/profile.html" <<'PY'
from pathlib import Path
import sys
s=Path(sys.argv[1]).read_text()
markers=['class="profile-v3-sidebar"', 'class="profile-v3-awards"', 'Published work']
spots=[s.find(x) for x in markers]
if any(x<0 for x in spots) or spots != sorted(spots) or 'data-profile-copy="34302348"' not in s:
    raise SystemExit('BLOCKED: Live premium creator profile verification failed.')
print('PUBLIC_PROFILE=PASS')
PY
code="$(curl -sS --max-time 25 -o /dev/null -w '%{http_code}' 'https://bambodia.com/account/profile')"
[[ "$code" == 302 || "$code" == 303 ]] ||
  fail "Guest account-profile authorization failed (HTTP $code)."
APPLY_STARTED=0
echo "========== PREMIUM CREATOR PROFILE INSTALLED =========="
echo "RELEASE=$FEATURE"
echo "ROLLBACK_FILES=$FILE_BACKUP"
echo "MYSQL_BACKUP=$DB_BACKUP"
echo "PRESERVED=Published work, card grid, Badge Manager, unrelated UI, .env, and installer lock."
