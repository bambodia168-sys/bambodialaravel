#!/usr/bin/env bash
# Bambodia MCP scoped aaPanel upgrade. --check is read-only for production.
set -Eeuo pipefail
umask 077
MODE="--check"
if (( $# )); then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Use --check or --apply" >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BRANCH="feature/bambodia-mcp-foundation-20261110"
BASE="e04153700c56993b8000b573a1ddb5b5c922b026"
RELEASE="021cc1da048f6d4c4787b717e09b46378d754238"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
CONF="/www/server/panel/vhost/nginx/bambodia.com.conf"
HELPER="support/bambodia-mcp-aapanel-upgrade.sh"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS="/root/Bambodia-MCP-Backups"

fail() { echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail "Root privileges required."
for bin in git gh cmp tar gzip install curl runuser grep sha256sum mktemp sort; do
  command -v "$bin" >/dev/null || fail "Missing $bin"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] ||
  fail "Unexpected Git source or Laravel root."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" &&
   -f "$APP/storage/app/installed.lock" ]] || fail "Installed Laravel runtime is incomplete."
[[ ! -L "$APP/.env" && ! -L "$APP/storage/app/installed.lock" ]] ||
  fail "Private application files are symbolic links."
[[ -x "$PHP" && -f "$DB_HELPER" && -f "$CONF" ]] ||
  fail "PHP, backup helper or Nginx configuration missing."
[[ ! -e "$APP/storage/framework/down" ]] || fail "Site is already in maintenance mode."
[[ "$(readlink -f "$APP")" == "$APP" ]] || fail "Unexpected Laravel root resolution."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "Untrusted Git source."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Git source has local changes."
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' "$CONF" ||
  fail "Nginx is not serving the confirmed runtime."
id www >/dev/null || fail "Missing aaPanel www user."

TMP="$(mktemp -d /root/bambodia-mcp-preflight.XXXXXXXX)"
FILE_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_SHA="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_SHA="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
cleanup() {
  code=$?
  trap - EXIT
  if (( code != 0 && APPLIED == 1 )); then
    echo "DEPLOYMENT_FAILED: reverting MCP files." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    while IFS= read -r file; do
      [[ -z "$file" ]] || rm -f -- "$APP/$file"
    done < "$TMP/new"
    if [[ -n "$FILE_BACKUP" && -f "$FILE_BACKUP" ]]; then
      tar -xzf "$FILE_BACKUP" -C "$APP" ||
        echo "CRITICAL: manual rollback may be required." >&2
    fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear) || true
    echo "ROLLBACK_FILES=$FILE_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$MYSQL_BACKUP" >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
      echo "CRITICAL: unable to exit maintenance mode." >&2
  fi
  rm -rf -- "$TMP"
  exit "$code"
}
trap cleanup EXIT

echo "========== BAMBODIA MCP $MODE =========="
echo "1. Verify GitHub release, helper integrity, exact source delta and CI."
git -C "$SOURCE" fetch origin \
  "refs/heads/main:refs/remotes/origin/main" \
  "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
MAIN="$(git -C "$SOURCE" rev-parse refs/remotes/origin/main)"
HEAD="$(git -C "$SOURCE" rev-parse "refs/remotes/origin/$BRANCH")"
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$MAIN" ||
  fail "Verified branch point is not an ancestor of GitHub main."
git -C "$SOURCE" cat-file -e "$RELEASE^{commit}" ||
  fail "Pinned MCP code commit is missing."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$RELEASE" ||
  fail "Pinned MCP release is not based on the verified main."
git -C "$SOURCE" merge-base --is-ancestor "$RELEASE" "$HEAD" ||
  fail "Feature branch no longer contains the code release."
[[ "$(git -C "$SOURCE" hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$HEAD:$HELPER")" ]] ||
  fail "This upgrade script differs from its GitHub object."

git -C "$SOURCE" diff --name-only "$RELEASE" "$HEAD" | LC_ALL=C sort > "$TMP/post-release"
printf '%s\n' "$HELPER" '.github/workflows/quality.yml' | LC_ALL=C sort > "$TMP/allowed-after"
cmp -s "$TMP/post-release" "$TMP/allowed-after" ||
  fail "Unexpected source changes after the tested MCP code release."

for workflow in quality.yml installer-acceptance.yml operational-acceptance.yml \
  browser-emulation.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$RELEASE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$RELEASE"'" and .conclusion=="success")] | length')" ||
    fail "Cannot verify $workflow"
  [[ "$count" -ge 1 ]] || fail "Required CI not green: $workflow"
  echo "CI_PASS=$workflow"
done

cat > "$TMP/modified" <<'EOF'
app/Http/Controllers/DeveloperController.php
app/Models/ApiToken.php
app/Models/AuditEvent.php
routes/web.php
EOF
cat > "$TMP/added" <<'EOF'
app/Http/Controllers/Api/BambodiaMcpController.php
app/Support/Mcp/AdministrationTools.php
app/Support/Mcp/BambodiaTools.php
app/Support/Mcp/DraftMediaTools.php
EOF
cat > "$TMP/expected-delta" <<'EOF'
app/Http/Controllers/Api/BambodiaMcpController.php
app/Http/Controllers/DeveloperController.php
app/Models/ApiToken.php
app/Models/AuditEvent.php
app/Support/Mcp/AdministrationTools.php
app/Support/Mcp/BambodiaTools.php
app/Support/Mcp/DraftMediaTools.php
docs/integrations/bambodia-mcp.md
docs/integrations/chatgpt-custom-plugin.md
routes/web.php
tests/Feature/BambodiaMcpTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$RELEASE" | LC_ALL=C sort > "$TMP/delta"
cmp -s "$TMP/delta" "$TMP/expected-delta" ||
  fail "Unapproved files appear in the MCP release."

echo "2. Check every live file against its known GitHub baseline."
cat "$TMP/modified" "$TMP/added" > "$TMP/scope"
# Main can advance independently, but only if none of the runtime files in
# this scoped release were modified by another feature. This is deliberately
# stronger than checking only the branch point or the total main SHA.
git -C "$SOURCE" diff --quiet "$BASE" "$MAIN" --   $(cat "$TMP/scope") ||
  fail "GitHub main changed MCP runtime files since the branch point."
: > "$TMP/existing"
: > "$TMP/new"
mkdir -p "$TMP/payload"
while IFS= read -r file; do
  [[ -n "$file" ]] || continue
  [[ ! -L "$APP/$file" ]] || fail "Unsafe live symlink: $file"
  install -d -m 0700 "$TMP/payload/$(dirname "$file")"
  git -C "$SOURCE" show "$RELEASE:$file" > "$TMP/payload/$file" ||
    fail "Unable to retrieve pinned file: $file"
  "$PHP" -l "$TMP/payload/$file" >/dev/null || fail "Invalid PHP: $file"
  if [[ -f "$APP/$file" ]]; then
    if cmp -s "$APP/$file" "$TMP/payload/$file"; then
      echo "ALREADY_INSTALLED=$file"
    elif grep -Fxq "$file" "$TMP/modified" &&
      cmp -s "$APP/$file" <(git -C "$SOURCE" show "$BASE:$file"); then
      echo "KNOWN_BASELINE=$file"
    else
      echo "LIVE_BLOB=$(git hash-object "$APP/$file")" >&2
      fail "Unknown live file revision: $file (no overwrite)."
    fi
    printf '%s\n' "$file" >> "$TMP/existing"
  else
    grep -Fxq "$file" "$TMP/added" ||
      fail "Required baseline file absent: $file"
    printf '%s\n' "$file" >> "$TMP/new"
    echo "NEW_MCP_FILE=$file"
  fi
done < "$TMP/scope"

(cd "$APP" && runuser -u www -- "$PHP" artisan migrate:status >/dev/null) ||
  fail "Cannot read live DB migrations."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] ||
  fail "Environment file was unexpectedly modified."
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] ||
  fail "Installer lock was unexpectedly modified."

echo "VERIFIED_MCP_RUNTIME_FILES=8"
echo "DATABASE_MIGRATIONS=NONE"
echo "FRONTEND_LAYOUTS=UNCHANGED"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS"
  echo "PRODUCTION_FILES=UNCHANGED"
  echo "DATABASE=UNCHANGED"
  exit 0
fi

echo "3. Create a private file backup and full MySQL snapshot."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILE_BACKUP="$DIR/previous-files.tar.gz"
cp "$TMP/scope" "$DIR/scope.txt"
cp "$TMP/new" "$DIR/new-files.txt"
tar -czf "$FILE_BACKUP" -C "$APP" -T "$TMP/existing" ||
  fail "File backup failed."
tar -tzf "$FILE_BACKUP" >/dev/null || fail "File archive invalid."
MYSQL_BACKUP="$(bash "$DB_HELPER")" || fail "Full MySQL snapshot failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] ||
  fail "Full MySQL snapshot missing."
gzip -t "$MYSQL_BACKUP" || fail "MySQL archive invalid."

echo "4. Apply only the eight MCP runtime files."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) ||
  fail "Could not enter maintenance mode."
MAINT=1
APPLIED=1
while IFS= read -r file; do
  install -d -o www -g www -m 0755 "$APP/$(dirname "$file")"
  install -o www -g www -m 0644 "$TMP/payload/$file" "$APP/$file" ||
    fail "Unable to install: $file"
  cmp -s "$APP/$file" "$TMP/payload/$file" || fail "Installed hash mismatch: $file"
done < "$TMP/scope"

(cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
  runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache) ||
  fail "Application route/view cache refresh failed."

echo "5. Verify MCP route, unchanged secrets and pinned files."
(cd "$APP" && runuser -u www -- "$PHP" artisan route:list --path=mcp --json) |
  "$PHP" -r '$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);foreach($r as $row){if(($row["uri"]??"")=="mcp"&&str_contains((string)($row["method"]??""),"POST")&&str_contains((string)($row["action"]??""),"BambodiaMcpController")){echo "MCP_ROUTE=PASS\n";exit(0);}}exit(1);' ||
  fail "MCP route not registered."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_SHA" ]] ||
  fail "Private configuration changed."
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_SHA" ]] ||
  fail "Installer lock changed."
while IFS= read -r file; do
  cmp -s "$APP/$file" "$TMP/payload/$file" ||
    fail "Unexpected installed file modification: $file"
done < "$TMP/scope"

(cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
  fail "Unable to resume website."
MAINT=0

echo "6. Check public website and MCP authentication denial."
HTTP="$(curl -sS --max-time 35 -o "$TMP/mcp-response" -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  https://bambodia.com/mcp)" || fail "Public MCP HTTP request failed."
[[ "$HTTP" == "401" ]] || fail "MCP must reject missing credentials; got HTTP $HTTP."
curl -fsSL --retry 2 --max-time 35 -o /dev/null https://bambodia.com/ ||
  fail "Website home page is unavailable."

APPLIED=0
echo "========== BAMBODIA MCP INSTALLED =========="
echo "RELEASE=$RELEASE"
echo "MCP_URL=https://bambodia.com/mcp"
echo "UNAUTHENTICATED_REQUEST=DENIED"
echo "FILE_BACKUP=$FILE_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PRESERVED=Front-end, existing data, media, database, .env, Nginx and PHP"
echo "NEXT=Test an expiring read-only token; browser OAuth not yet implemented."
