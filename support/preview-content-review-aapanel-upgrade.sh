#!/usr/bin/env bash
# Scoped, backup-first installation of the READ-ONLY public preview dependency review.
# No listing/job mutation, content publication, customer export or database migration.
set -Eeuo pipefail
umask 077

MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--check" || "$MODE" == "--apply" ) ]] || {
  echo "Usage: $0 --check|--apply" >&2; exit 2;
}

REPO="bambodia168-sys/bambodialaravel"
BASE="0c674980c619e8582fb4a0b26d528daedbd0a3c4"
CODE="e8c8abb1f42f7d72c266bb5a26c42452949e0550"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
HELPER="support/preview-content-review-aapanel-upgrade.sh"
BACKUP_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS="/root/Bambodia-PreviewReview-Backups"
EXISTING="routes/console.php"
NEW="app/Support/PreviewContentReview.php"

fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail "Run as root."
for cmd in git gh cmp tar gzip curl runuser mktemp install sha256sum stat; do
  command -v "$cmd" >/dev/null || fail "Missing command: $cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] ||
  fail "Source checkout or aaPanel application is unexpected."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail "Laravel runtime is not in expected safe state."
[[ -x "$PHP" && -f "$BACKUP_HELPER" && ! -e "$APP/storage/framework/down" ]] ||
  fail "PHP, MySQL backup helper or maintenance state invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "Repository origin is not trusted."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Source checkout has local edits."
id www >/dev/null || fail "Web service user missing."

TMP="$(mktemp -d /root/bambodia-preview-review.XXXXXXXX)"
FILES_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && APPLIED == 1 )); then
    echo "INSTALL FAILED: attempt automatic source-file restoration." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    rm -f -- "$APP/$NEW"
    if [[ -f "$FILES_BACKUP" ]]; then
      tar -xzf "$FILES_BACKUP" -C "$APP" ||
        echo "CRITICAL: manual file restoration needed: $FILES_BACKUP" >&2
    fi
    echo "FILE_BACKUP=$FILES_BACKUP" >&2
    echo "MYSQL_BACKUP=$MYSQL_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo "========== BAMBODIA PUBLIC PREVIEW DEPENDENCY REVIEW $MODE =========="
echo "1. Verify GitHub source lineage, installer and five feature workflows."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Original baseline missing."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "Reviewed feature commit missing."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$CODE" || fail "Feature ancestry mismatch."
git -C "$SOURCE" merge-base --is-ancestor "$CODE" refs/remotes/origin/main ||
  fail "Feature not merged into GitHub main."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "refs/remotes/origin/main:$HELPER")" ]] ||
  fail "Installer does not match verified GitHub main."

cat > "$TMP/expected-feature" <<'EOF'
app/Support/PreviewContentReview.php
docs/PUBLIC_PREVIEW_CONTENT_CLEANUP.md
routes/console.php
tests/Feature/PreviewContentReviewTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/feature"
cmp -s "$TMP/expected-feature" "$TMP/feature" || fail "Feature file scope differs."

cat > "$TMP/expected-after" <<'EOF'
.github/workflows/quality.yml
support/preview-content-review-aapanel-upgrade.sh
EOF
git -C "$SOURCE" diff --name-only "$CODE" refs/remotes/origin/main | LC_ALL=C sort > "$TMP/after"
cmp -s "$TMP/expected-after" "$TMP/after" || fail "Unexpected changes after reviewed feature."

for wf in quality.yml installer-acceptance.yml operational-acceptance.yml browser-emulation.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$CODE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$CODE"'" and .conclusion=="success")] | length')" ||
    fail "Cannot read GitHub workflow result: $wf"
  [[ "$count" -ge 1 ]] || fail "Feature workflow not green: $wf"
  echo "PASS $wf"
done

echo "2. Compare exact live CLI baseline and preserve security/AI systems."
[[ -f "$APP/$EXISTING" && ! -L "$APP/$EXISTING" ]] || fail "Expected CLI source missing."
cmp -s "$APP/$EXISTING" <(git -C "$SOURCE" show "$BASE:$EXISTING") ||
  fail "Live console route file contains unrecognized changes."
[[ ! -e "$APP/$NEW" && ! -L "$APP/$NEW" ]] ||
  fail "The new review class already exists. Do not overwrite."
install -d -m 0700 "$TMP/payload/routes" "$TMP/payload/app/Support"
for rel in "$EXISTING" "$NEW"; do
  git -C "$SOURCE" show "$CODE:$rel" > "$TMP/payload/$rel"
  [[ -s "$TMP/payload/$rel" ]] || fail "Payload missing: $rel"
  cmp -s "$TMP/payload/$rel" <(git -C "$SOURCE" show "refs/remotes/origin/main:$rel") ||
    fail "Live main and feature payload diverge: $rel"
  "$PHP" -l "$TMP/payload/$rel" >/dev/null || fail "PHP syntax invalid: $rel"
done

cat > "$TMP/protected" <<'EOF'
routes/web.php
bootstrap/app.php
app/Models/AuditEvent.php
app/Models/AiDraftProvider.php
app/Support/AiDraftStudio.php
app/Http/Controllers/Api/BambodiaMcpController.php
resources/views/builder/index.blade.php
EOF
: > "$TMP/protected-before"
while IFS= read -r rel; do
  [[ -f "$APP/$rel" && ! -L "$APP/$rel" ]] || fail "Protected file missing: $rel"
  git hash-object "$APP/$rel" >> "$TMP/protected-before"
done < "$TMP/protected"

COMMANDS="$(cd "$APP" && runuser -u www -- "$PHP" artisan list --raw)" ||
  fail "Laravel CLI bootstrap failed."
if grep -Fq "marketplace:preview-review" <<< "$COMMANDS"; then
  fail "Preview review is already installed; refusing duplicate installation."
fi
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] ||
  fail "Private configuration changed."
echo "EXACT_BASELINE=$EXISTING"
echo "NEW_FILE=$NEW"
echo "MCP_SECURITY_PROVIDER=UNCHANGED"
echo "NO_DATABASE_MIGRATIONS=YES"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; LIVE_DATABASE_AND_APPLICATION=UNCHANGED"
  exit 0
fi

echo "3. Create original-file backup and verify full private MySQL snapshot."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILES_BACKUP="$DIR/original-console-routes.tar.gz"
tar -czf "$FILES_BACKUP" -C "$APP" "$EXISTING" || fail "Could not back up original CLI route."
tar -tzf "$FILES_BACKUP" >/dev/null || fail "Original file backup unreadable."
printf '%s\n' "$NEW" > "$DIR/new-file.txt"
MYSQL_BACKUP="$(bash "$BACKUP_HELPER")" || fail "Full MySQL backup failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] || fail "MySQL snapshot missing."
gzip -t "$MYSQL_BACKUP" || fail "MySQL snapshot integrity check failed."

echo "4. Install only the audited read-only CLI functionality."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) ||
  fail "Cannot enter maintenance mode."
MAINT=1
APPLIED=1
owner="$(stat -c %u "$APP/$EXISTING")"
group="$(stat -c %g "$APP/$EXISTING")"
install -o www -g www -m 0644 "$TMP/payload/$NEW" "$APP/$NEW" ||
  fail "Cannot add review class."
install -o "$owner" -g "$group" -m 0644 "$TMP/payload/$EXISTING" "$APP/$EXISTING" ||
  fail "Cannot update CLI registration."
for rel in "$EXISTING" "$NEW"; do
  cmp -s "$APP/$rel" "$TMP/payload/$rel" || fail "Installed bytes mismatch: $rel"
done
COMMANDS="$(cd "$APP" && runuser -u www -- "$PHP" artisan list --raw)" ||
  fail "New CLI bootstrap failed."
grep -Fq "marketplace:preview-review" <<< "$COMMANDS" ||
  fail "New preview-review command is not registered."

: > "$TMP/protected-after"
while IFS= read -r rel; do git hash-object "$APP/$rel" >> "$TMP/protected-after"; done < "$TMP/protected"
cmp -s "$TMP/protected-before" "$TMP/protected-after" ||
  fail "Protected MCP, Security or AI file changed."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] ||
  fail ".env was modified."

(cd "$APP" && runuser -u www -- "$PHP" artisan up) || fail "Cannot restore website."
MAINT=0
echo "5. Check public homepage and login."
for url in "https://bambodia.com/" "https://bambodia.com/login"; do
  curl -fsSL --max-time 35 --retry 2 -o /dev/null "$url" ||
    fail "Public HTTP smoke failed: $url"
  echo "HTTP_OK $url"
done
echo "========== READ-ONLY PREVIEW DEPENDENCY REVIEW INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILES_BACKUP=$FILES_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PROTECTED_MCP_SECURITY_PROVIDER=UNCHANGED"
echo "NEXT=Run marketplace:preview-review --json --strict under PHP 8.5 as www"
