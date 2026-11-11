#!/usr/bin/env bash
# Scope: render immutable published Builder Terms as readable sanitized documents.
set -Eeuo pipefail
umask 077
MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo 'Usage: --check|--apply' >&2; exit 2; }
[[ $# -le 1 ]] || exit 2

REPO="bambodia168-sys/bambodialaravel"
BASE="3085cbf08c72ca4cb012079025b7d725749cf420"
CODE="48bca27352c2e61cef4ed40ecb8ddc92441b3602"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
HELPER="support/builder-terms-readable-aapanel-upgrade.sh"
BACKUPS="/root/Bambodia-BuilderTerms-Backups"
NEW="public/css/builder-terms-readable.css"

fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail "Run as root."
for cmd in git gh cmp tar gzip curl runuser mktemp install sha256sum stat; do
  command -v "$cmd" >/dev/null || fail "Missing: $cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] ||
  fail "Unexpected source or aaPanel app directory."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail "Invalid Laravel runtime."
[[ -x "$PHP" && -f "$DB_HELPER" && ! -e "$APP/storage/framework/down" ]] ||
  fail "PHP, database backup helper or maintenance mode invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "Git source remote mismatch."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Source checkout has local edits."
id www >/dev/null || fail "aaPanel web user missing."

TMP="$(mktemp -d /root/bambodia-terms-readable.XXXXXXXX)"
FILES_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && APPLIED == 1 )); then
    echo "Install error. Attempting original-file recovery." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    rm -f -- "$APP/$NEW"
    if [[ -f "$FILES_BACKUP" ]]; then
      tar -xzf "$FILES_BACKUP" -C "$APP" ||
        echo "CRITICAL: manual original-file restore required: $FILES_BACKUP" >&2
    fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILES_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$MYSQL_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true; fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo "========== BAMBODIA BUILDER SUBMISSION TERMS $MODE =========="
echo "1. Verify immutable code, scoped changes, installer and CI."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Baseline commit unavailable."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "Tested feature commit unavailable."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$CODE" || fail "Wrong feature ancestry."
git -C "$SOURCE" merge-base --is-ancestor "$CODE" refs/remotes/origin/main ||
  fail "Feature not merged in GitHub main."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "refs/remotes/origin/main:$HELPER")" ]] ||
  fail "Installer does not match GitHub main."

cat > "$TMP/expected-after" <<'EOF'
.github/workflows/quality.yml
support/builder-terms-readable-aapanel-upgrade.sh
EOF
git -C "$SOURCE" diff --name-only "$CODE" refs/remotes/origin/main | LC_ALL=C sort > "$TMP/after"
cmp -s "$TMP/expected-after" "$TMP/after" || fail "Unreviewed post-feature changes."

cat > "$TMP/expected-feature" <<'EOF'
.github/workflows/browser-emulation.yml
.github/workflows/quality.yml
app/Support/BuilderSubmissionTerms.php
public/css/builder-terms-readable.css
resources/views/builder/index.blade.php
tests/Feature/BuilderTermsReadableTest.php
tests/browser/builder-terms-readable.mjs
tests/browser/seed.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/feature"
cmp -s "$TMP/expected-feature" "$TMP/feature" || fail "Feature diff has unexpected changes."

for wf in quality.yml installer-acceptance.yml operational-acceptance.yml browser-emulation.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$CODE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$CODE"'" and .conclusion=="success")] | length')" ||
    fail "Cannot check $wf"
  [[ "$count" -ge 1 ]] || fail "Required workflow has not passed: $wf"
  echo "PASS $wf"
done

echo "2. Compare exact published policy-rendering runtime files; protect MCP/security/provider."
cat > "$TMP/modified" <<'EOF'
app/Support/BuilderSubmissionTerms.php
resources/views/builder/index.blade.php
EOF
cat > "$TMP/protected" <<'EOF'
routes/web.php
app/Models/AuditEvent.php
app/Http/Controllers/Api/BambodiaMcpController.php
app/Http/Controllers/Dashboard/AiDraftSettingsController.php
app/Models/AiDraftProvider.php
bootstrap/app.php
public/js/ai-draft-studio.js
resources/views/dashboard/ai-draft-settings.blade.php
EOF
: > "$TMP/protected-before"
while IFS= read -r rel; do
  [[ -f "$APP/$rel" && ! -L "$APP/$rel" ]] || fail "Protected file missing: $rel"
  git hash-object "$APP/$rel" >> "$TMP/protected-before"
done < "$TMP/protected"

mkdir -p "$TMP/payload"
while IFS= read -r rel; do
  [[ -f "$APP/$rel" && ! -L "$APP/$rel" ]] || fail "Existing runtime file missing: $rel"
  cmp -s "$APP/$rel" <(git -C "$SOURCE" show "$BASE:$rel") ||
    fail "Unrecognized production modification: $rel"
  install -d -m 0700 "$TMP/payload/$(dirname "$rel")"
  git -C "$SOURCE" show "$CODE:$rel" > "$TMP/payload/$rel"
  cmp -s "$TMP/payload/$rel" <(git -C "$SOURCE" show "refs/remotes/origin/main:$rel") ||
    fail "Reviewed code differs from main: $rel"
  if [[ "$rel" == *.php ]]; then "$PHP" -l "$TMP/payload/$rel" >/dev/null ||
    fail "Invalid PHP: $rel"; fi
  echo "EXACT_BASELINE $rel"
done < "$TMP/modified"

[[ ! -e "$APP/$NEW" && ! -L "$APP/$NEW" ]] || fail "New stylesheet destination is occupied."
install -d -m 0700 "$TMP/payload/public/css"
git -C "$SOURCE" show "$CODE:$NEW" > "$TMP/payload/$NEW"
[[ -s "$TMP/payload/$NEW" ]] || fail "CSS payload empty."
cmp -s "$TMP/payload/$NEW" <(git -C "$SOURCE" show "refs/remotes/origin/main:$NEW") ||
  fail "CSS differs from reviewed code."
echo "NEW_FILE $NEW"

(cd "$APP" && runuser -u www -- "$PHP" artisan route:list --name=builder.ai >/dev/null) ||
  fail "Builder AI routes not available."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] ||
  fail "Private environment changed."
echo "PROVIDER_CREDENTIALS=UNCHANGED"
echo "MCP_SECURITY=UNCHANGED_BY_RELEASE"
echo "DATABASE_MIGRATIONS=NONE"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION=UNCHANGED"
  exit 0
fi

echo "3. Back up code and full MySQL database."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILES_BACKUP="$DIR/original-files.tar.gz"
cp "$TMP/modified" "$DIR/original-file-scope.txt"
printf '%s\n' "$NEW" > "$DIR/new-files.txt"
tar -czf "$FILES_BACKUP" -C "$APP" -T "$TMP/modified" || fail "File backup failed."
tar -tzf "$FILES_BACKUP" >/dev/null || fail "File backup unreadable."
MYSQL_BACKUP="$(bash "$DB_HELPER")" || fail "MySQL snapshot failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] || fail "MySQL snapshot missing."
gzip -t "$MYSQL_BACKUP" || fail "MySQL snapshot corrupt."

echo "4. Apply only reviewed Builder Terms rendering files."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) ||
  fail "Could not enter maintenance mode."
MAINT=1
APPLIED=1
while IFS= read -r rel; do
  owner="$(stat -c %u "$APP/$rel")"
  group="$(stat -c %g "$APP/$rel")"
  install -o "$owner" -g "$group" -m 0644 "$TMP/payload/$rel" "$APP/$rel" ||
    fail "Could not install $rel"
  cmp -s "$APP/$rel" "$TMP/payload/$rel" || fail "Installed file mismatch: $rel"
done < "$TMP/modified"
install -o www -g www -m 0644 "$TMP/payload/$NEW" "$APP/$NEW" ||
  fail "Could not install CSS."
cmp -s "$APP/$NEW" "$TMP/payload/$NEW" || fail "CSS checksum mismatch."

(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache) ||
  fail "Blade compilation failed."

: > "$TMP/protected-after"
while IFS= read -r rel; do git hash-object "$APP/$rel" >> "$TMP/protected-after"; done < "$TMP/protected"
cmp -s "$TMP/protected-before" "$TMP/protected-after" ||
  fail "MCP, Security or AI provider file changed."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] ||
  fail ".env was modified."

(cd "$APP" && runuser -u www -- "$PHP" artisan up) || fail "Failed to restore availability."
MAINT=0
echo "5. HTTP smoke checks."
for url in "https://bambodia.com/" "https://bambodia.com/login" \
  "https://bambodia.com/css/builder-terms-readable.css?v=1"; do
  curl -fsSL --max-time 35 --retry 2 -o /dev/null "$url" || fail "HTTP smoke failed: $url"
  echo "HTTP_OK $url"
done
echo "========== BAMBODIA BUILDER TERMS FORMAT INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILES_BACKUP=$FILES_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PROVIDER_MCP_SECURITY=UNCHANGED"
echo "NEXT=Open Builder Accept Terms popup and verify the published headings, paragraphs and lists."
