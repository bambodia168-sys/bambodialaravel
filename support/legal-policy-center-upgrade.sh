#!/usr/bin/env bash
# Guarded, file-only Policy Center legal drafts upgrade for Bambodia.
# Run --check SHA first; --apply SHA requires a fresh verified SQL backup.
# Does not run migrations, seed policies, publish terms, or change licenses.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SHA="${2:-}"
REPO='bambodia168-sys/bambodialaravel'
BASE='34e20f87315da169d05c59c724e06078a636e6d9'
SOURCE='/root/bambodia-release-audit'
APP='/www/wwwroot/bambodia-staging-qa'
VHOST='/www/server/panel/vhost/nginx/bambodia.com.conf'
PHP='/www/server/php/85/bin/php'
BACKUPS='/root/Bambodia-LegalPolicies-Backups'

stop() { echo "BLOCKED: $*" >&2; exit 1; }
[[ "$MODE" == --check || "$MODE" == --apply ]] || stop 'Usage: --check|--apply FULL_SHA'
[[ "$SHA" =~ ^[a-f0-9]{40}$ ]] || stop 'Expected a 40-character release SHA'
[[ "$(id -u)" == 0 ]] || stop 'Run as root on the Bambodia aaPanel server'
for tool in git gh tar gzip cmp install stat sha256sum runuser mktemp; do
  command -v "$tool" >/dev/null || stop "Missing command $tool"
done
[[ -d "$SOURCE/.git" && -x "$PHP" ]] || stop 'Repository checkout or PHP missing'
[[ -f "$APP/.env" && -f "$APP/storage/app/installed.lock" ]] || stop 'Installed site identity missing'
[[ -f "$APP/vendor/autoload.php" && -f "$VHOST" ]] || stop 'Installed Laravel runtime or vhost missing'
[[ ! -L "$APP" && ! -L "$VHOST" ]] || stop 'Unexpected symlink'
[[ ! -e "$APP/storage/framework/down" ]] || stop 'Site is already in maintenance mode'
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$VHOST" ||
  stop 'Unexpected production Nginx document root'
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  stop 'Source remote mismatch'
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || stop 'Source working tree is dirty'
gh auth status >/dev/null 2>&1 || stop 'GitHub authentication unavailable'

git -C "$SOURCE" fetch origin main
git -C "$SOURCE" merge --ff-only origin/main
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] || stop 'GitHub Main is not the specified release SHA'
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$SHA" || stop 'Expected license baseline is not an ancestor'

EXISTING=(
  app/Models/AuditEvent.php
  app/Http/Controllers/BuilderController.php
  app/Http/Controllers/Dashboard/PolicyManagerController.php
  resources/views/builder/index.blade.php
  resources/views/dashboard/policies.blade.php
  routes/web.php
)
NEW=(app/Support/LegalPolicyDrafts.php)
ALL=("${EXISTING[@]}" "${NEW[@]}")

echo '========== CHECK INSTALLED BASELINE =========='
for file in "${EXISTING[@]}"; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] || stop "Missing or unsafe production file: $file"
  cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file") ||
    stop "Production file differs from reviewed baseline: $file"
  echo "MATCH: $file"
done
for file in "${NEW[@]}"; do
  [[ -f "$SOURCE/$file" && ! -e "$APP/$file" && ! -L "$APP/$file" ]] ||
    stop "New file missing from release or already present in production: $file"
done
for file in "${ALL[@]}"; do
  "$PHP" -l "$SOURCE/$file" >/dev/null || stop "PHP syntax error: $file"
done
[[ -f "$SOURCE/tests/Feature/LegalPolicyDraftsTest.php" ]] || stop 'Policy regression tests missing'

echo '========== VERIFY SIX EXACT-COMMIT CI WORKFLOWS =========='
WORKFLOWS=(
  quality.yml
  build-installer.yml
  installer-acceptance.yml
  browser-emulation.yml
  operational-acceptance.yml
  isolated-authenticated-journeys.yml
)
for workflow in "${WORKFLOWS[@]}"; do
  passed="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$SHA&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha == "'$SHA'" and .conclusion == "success")] | length')" ||
    stop "Unable to query CI: $workflow"
  [[ "$passed" =~ ^[0-9]+$ && "$passed" -ge 1 ]] || stop "CI has not passed: $workflow"
  echo "PASS: $workflow"
done

if [[ "$MODE" == --check ]]; then
  echo 'CHECK PASSED: baseline, source, PHP syntax and all six workflows.'
  echo 'No production files, database tables, policy states or issued licenses were modified.'
  exit 0
fi

echo '========== VERIFY RECENT PROTECTED MYSQL BACKUP =========='
DB_BACKUP="${BAMBODIA_DB_BACKUP_FILE:-}"
[[ -f "$DB_BACKUP" && -s "$DB_BACKUP" && ! -L "$DB_BACKUP" && "$DB_BACKUP" == /root/* ]] ||
  stop 'BAMBODIA_DB_BACKUP_FILE must name a private verified .sql.gz file under /root'
[[ "$DB_BACKUP" == *.sql.gz ]] || stop 'Expected a compressed SQL snapshot'
(( $(date +%s) - $(stat -c %Y "$DB_BACKUP") < 3600 )) ||
  stop 'Database snapshot must be less than one hour old'
MODE_BITS="$(stat -c %a "$DB_BACKUP")"
(( (8#$MODE_BITS & 077) == 0 )) || stop 'Database backup permissions are not private'
gzip -t "$DB_BACKUP" || stop 'Database backup failed integrity check'

install -d -m 0700 "$BACKUPS"
SAVE="$(mktemp -d "$BACKUPS/release-XXXXXXXX")"
FILE_BACKUP="$SAVE/original-files.tar.gz"
tar -czf "$FILE_BACKUP" -C "$APP" "${EXISTING[@]}"
tar -tzf "$FILE_BACKUP" >/dev/null || stop 'Source files backup failed'
ENV_HASH="$(sha256sum "$APP/.env" | cut -d ' ' -f1)"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)"
echo "File rollback archive: $FILE_BACKUP"
echo "Database snapshot: $DB_BACKUP"

DOWN=0
TOUCHED=0
cleanup() {
  rc=$?
  trap - EXIT
  if (( rc != 0 && TOUCHED == 1 )); then
    echo 'ROLLBACK: restoring application source files' >&2
    tar -xzf "$FILE_BACKUP" -C "$APP" || true
    for file in "${NEW[@]}"; do rm -f -- "$APP/$file"; done
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear) || true
  fi
  if (( DOWN == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
      echo 'ALERT: manually inspect maintenance mode' >&2
  fi
  exit "$rc"
}
trap cleanup EXIT

(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
DOWN=1
TOUCHED=1
for file in "${ALL[@]}"; do
  install -d -o www -g www -m 0755 "$(dirname "$APP/$file")"
  install -o www -g www -m 0644 "$SOURCE/$file" "$APP/$file"
  cmp -s "$SOURCE/$file" "$APP/$file" || stop "Copied file mismatch: $file"
done
(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
  runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache &&
  runuser -u www -- "$PHP" artisan route:list --path=policy-center)
[[ "$(sha256sum "$APP/.env" | cut -d ' ' -f1)" == "$ENV_HASH" ]] || stop '.env changed'
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d ' ' -f1)" == "$LOCK_HASH" ]] ||
  stop 'Installer lock changed'
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
DOWN=0
TOUCHED=0
echo 'LEGAL POLICY CENTER FILE-ONLY UPGRADE DEPLOYED SUCCESSFULLY'
echo "Commit: $SHA"
echo "File rollback archive: $FILE_BACKUP"
echo 'Policies were NOT seeded, reviewed, or published; existing licenses and entitlements were not changed.'
