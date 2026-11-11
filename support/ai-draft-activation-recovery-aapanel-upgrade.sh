#!/usr/bin/env bash
# Safe scoped aaPanel installer for AI Draft Studio activation error feedback.
# Does NOT configure, test, enable or send an API key to a provider.
set -Eeuo pipefail
umask 077

MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Usage: --check|--apply" >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="1e5603581a7ba4f7fcb22895dee0d5a74fde5154"
CODE="d46891ccd1a34f92152f59426dd5965801c26110"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
HELPER="support/ai-draft-activation-recovery-aapanel-upgrade.sh"
BACKUPS="/root/Bambodia-AiDraftActivation-Backups"

fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail "Must run as root."
for cmd in gh git cmp curl tar gzip runuser mktemp install sha256sum sort python3 stat; do
  command -v "$cmd" >/dev/null || fail "Missing command: $cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] || fail "Unexpected source or application."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] || fail "Laravel runtime missing."
[[ -x "$PHP" && -f "$DB_HELPER" && ! -e "$APP/storage/framework/down" ]] || fail "PHP, backup helper or maintenance status invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail "Git origin mismatch."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Source checkout is modified."
id www >/dev/null || fail "aaPanel web user missing."

TMP="$(mktemp -d /root/bambodia-ai-activation-check.XXXXXXXX)"
FILES_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && APPLIED == 1 )); then
    echo "INSTALL ERROR: attempting to restore original application files." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -f "$FILES_BACKUP" ]]; then tar -xzf "$FILES_BACKUP" -C "$APP" ||
      echo "CRITICAL: manual file restore required from $FILES_BACKUP" >&2; fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILES_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$MYSQL_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo "========== BAMBODIA AI ACTIVATION RECOVERY $MODE =========="
echo "1. Verify trusted source and five completed CI workflows."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Original release unavailable."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "Activation fix commit unavailable."
git -C "$SOURCE" merge-base --is-ancestor "$CODE" refs/remotes/origin/main ||
  fail "Activation fix not merged to main."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "refs/remotes/origin/main:$HELPER")" ]] ||
  fail "Installer bytes are not identical to GitHub main."

cat > "$TMP/expected-after" <<'EOF'
.github/workflows/quality.yml
support/ai-draft-activation-recovery-aapanel-upgrade.sh
EOF
git -C "$SOURCE" diff --name-only "$CODE" refs/remotes/origin/main | LC_ALL=C sort > "$TMP/actual-after"
cmp -s "$TMP/expected-after" "$TMP/actual-after" || fail "Unreviewed additional main changes."

for wf in quality.yml installer-acceptance.yml operational-acceptance.yml browser-emulation.yml isolated-authenticated-journeys.yml; do
  passed="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$CODE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$CODE"'" and .conclusion=="success")] | length')" ||
    fail "Unable to verify workflow $wf"
  [[ "$passed" -ge 1 ]] || fail "Required CI not passed: $wf"
  echo "PASS $wf"
done

echo "2. Verify exact three-file production baseline and protected components."
cat > "$TMP/expected-feature" <<'EOF'
app/Http/Controllers/Dashboard/AiDraftSettingsController.php
bootstrap/app.php
resources/views/dashboard/ai-draft-settings.blade.php
tests/Feature/AiDraftStudioTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/actual-feature"
cmp -s "$TMP/expected-feature" "$TMP/actual-feature" || fail "Unexpected feature change set."

cat > "$TMP/scope" <<'EOF'
app/Http/Controllers/Dashboard/AiDraftSettingsController.php
bootstrap/app.php
resources/views/dashboard/ai-draft-settings.blade.php
EOF
cat > "$TMP/protected" <<'EOF'
routes/web.php
app/Models/AuditEvent.php
app/Http/Controllers/Api/BambodiaMcpController.php
EOF
: > "$TMP/protected-before"
while IFS= read -r rel; do
  [[ -f "$APP/$rel" && ! -L "$APP/$rel" ]] || fail "Protected component missing: $rel"
  git hash-object "$APP/$rel" >> "$TMP/protected-before"
done < "$TMP/protected"

mkdir -p "$TMP/payload"
while IFS= read -r rel; do
  [[ -f "$APP/$rel" && ! -L "$APP/$rel" ]] || fail "Expected existing file missing: $rel"
  cmp -s "$APP/$rel" <(git -C "$SOURCE" show "$BASE:$rel") ||
    fail "Production file has unexpected changes: $rel"
  mkdir -p "$TMP/payload/$(dirname "$rel")"
  git -C "$SOURCE" show "$CODE:$rel" > "$TMP/payload/$rel" ||
    fail "Missing immutable payload: $rel"
  cmp -s "$TMP/payload/$rel" <(git -C "$SOURCE" show "refs/remotes/origin/main:$rel") ||
    fail "Main and reviewed fix differ: $rel"
  if [[ "$rel" == *.php ]]; then "$PHP" -l "$TMP/payload/$rel" >/dev/null ||
    fail "Invalid PHP syntax in $rel"; fi
  echo "EXACT_BASELINE $rel"
done < "$TMP/scope"

(cd "$APP" && runuser -u www -- "$PHP" artisan route:list --name=dashboard.ai-draft >/dev/null) ||
  fail "AI routes are missing."
(cd "$APP" && runuser -u www -- "$PHP" artisan route:list --name=mcp >/dev/null) ||
  fail "MCP route probe failed."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env was modified."
echo "MCP_AND_SECURITY=UNCHANGED_BY_RELEASE"
echo "DATABASE_MIGRATIONS=NONE"
echo "PROVIDER_SETTINGS=UNCHANGED"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION=UNCHANGED"
  exit 0
fi

echo "3. Back up exact files and take verified MySQL snapshot."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILES_BACKUP="$DIR/original-files.tar.gz"
cp "$TMP/scope" "$DIR/file-scope.txt"
tar -czf "$FILES_BACKUP" -C "$APP" -T "$TMP/scope" || fail "File backup failed."
tar -tzf "$FILES_BACKUP" >/dev/null || fail "File archive corrupt."
MYSQL_BACKUP="$(bash "$DB_HELPER")" || fail "MySQL snapshot failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] || fail "MySQL backup not found."
gzip -t "$MYSQL_BACKUP" || fail "MySQL backup corrupt."

echo "4. Scoped installation; restore original files on failure."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) ||
  fail "Unable to enter maintenance mode."
MAINT=1
APPLIED=1
while IFS= read -r rel; do
  owner="$(stat -c %u "$APP/$rel")"
  group="$(stat -c %g "$APP/$rel")"
  install -o "$owner" -g "$group" -m 0644 "$TMP/payload/$rel" "$APP/$rel" ||
    fail "Unable to install $rel"
  cmp -s "$APP/$rel" "$TMP/payload/$rel" || fail "Installed file mismatch: $rel"
done < "$TMP/scope"

(
  cd "$APP"
  runuser -u www -- "$PHP" artisan route:clear
  runuser -u www -- "$PHP" artisan view:clear
  runuser -u www -- "$PHP" artisan view:cache
) || fail "Application compilation failed."

: > "$TMP/protected-after"
while IFS= read -r rel; do git hash-object "$APP/$rel" >> "$TMP/protected-after"; done < "$TMP/protected"
cmp -s "$TMP/protected-before" "$TMP/protected-after" ||
  fail "A protected MCP/Security file changed."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env modified."

(cd "$APP" && runuser -u www -- "$PHP" artisan up) || fail "Failed to resume site."
MAINT=0
echo "5. Non-authenticated smoke checks."
curl -fsSL --retry 2 --max-time 35 -o /dev/null "https://bambodia.com/" ||
  fail "Homepage smoke failed."
curl -fsSL --retry 2 --max-time 35 -o /dev/null "https://bambodia.com/login" ||
  fail "Login smoke failed."
echo "========== BAMBODIA AI ACTIVATION RECOVERY INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILES_BACKUP=$FILES_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "MCP_SECURITY_PROVIDER_SETTINGS=UNCHANGED"
echo "NEXT=Run a single real provider test after 60 seconds; enable only after successful test."
