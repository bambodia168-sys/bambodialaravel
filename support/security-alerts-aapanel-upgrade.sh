#!/usr/bin/env bash
# Safe Bambodia Security Alerts installer: --check (read-only) or --apply.
set -Eeuo pipefail
umask 077
MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo 'Usage: --check|--apply' >&2; exit 2; }
REPO="bambodia168-sys/bambodialaravel"
BASE="acc2bf931154eceaa10a07efbbf7695501955dec"
CODE="9a91c35c3dcdfbcc26c111d0d875447ddbf8c275"
# MCP foundation is already installed on this particular aaPanel runtime.
# These objects pin the exact known live overlays, avoiding a destructive reset.
MCP="2026baa2daa215d8031497921bc44bc351f9df32"
MCP_AUDIT_SHA="ee89c0714d1a2259999b858d84def93130feaeb1"
MCP_ROUTES_SHA="502c73e246eba4ee38c35d19529f6206d4f73678"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
HELPER="support/security-alerts-aapanel-upgrade.sh"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS="/root/Bambodia-SecurityAlerts-Backups"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }

[[ "$(id -u)" == "0" ]] || fail "Run as root."
for cmd in gh git cmp curl tar gzip runuser mktemp install sha256sum sort python3; do
  command -v "$cmd" >/dev/null || fail "Missing $cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] ||
  fail "Unexpected source or live runtime."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail "Laravel runtime or private environment missing."
[[ -x "$PHP" && -f "$DB_HELPER" && ! -e "$APP/storage/framework/down" ]] ||
  fail "PHP, snapshot helper or maintenance state invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "Unexpected Git origin."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Source checkout contains edits."
id www >/dev/null || fail "aaPanel web user missing."

TMP="$(mktemp -d /root/bambodia-security-alerts-verify.XXXXXXXX)"
FILE_BACKUP=""
MYSQL_BACKUP=""
MAINT=0
APPLIED=0
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && APPLIED == 1 )); then
    echo "DEPLOYMENT ERROR: restoring original files." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -f "$TMP/new-files" ]]; then
      while IFS= read -r name; do [[ -z "$name" ]] || rm -f -- "$APP/$name"; done < "$TMP/new-files"
    fi
    if [[ -f "$FILE_BACKUP" ]]; then tar -xzf "$FILE_BACKUP" -C "$APP" ||
      echo "CRITICAL: Manual restoration needed: $FILE_BACKUP" >&2; fi
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILE_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$MYSQL_BACKUP" >&2
    echo "NOTE: Additive DB migrations remain until separately reviewed." >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true
  fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo "========== BAMBODIA SECURITY ALERTS $MODE =========="
echo "1. Verify tested immutable code, trusted installer and five workflows."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
# Obtain the already-deployed MCP source as immutable objects; do not check out
# or overwrite the production MCP foundation.
git -C "$SOURCE" fetch origin "refs/heads/feature/bambodia-mcp-foundation-20261110:refs/remotes/origin/feature/bambodia-mcp-foundation-20261110"
git -C "$SOURCE" cat-file -e "$MCP^{commit}" || fail "Pinned deployed MCP foundation is unavailable."
[[ "$(git -C "$SOURCE" rev-parse "$MCP:app/Models/AuditEvent.php")" == "$MCP_AUDIT_SHA" ]] ||
  fail "Unexpected source MCP audit events."
[[ "$(git -C "$SOURCE" rev-parse "$MCP:routes/web.php")" == "$MCP_ROUTES_SHA" ]] ||
  fail "Unexpected source MCP routes."
MAIN="$(git -C "$SOURCE" rev-parse refs/remotes/origin/main)"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Installed baseline not found."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "Tested security release not found."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$CODE" || fail "Wrong release ancestry."
git -C "$SOURCE" merge-base --is-ancestor "$CODE" "$MAIN" || fail "Release not yet merged to main."
git -C "$SOURCE" diff --name-only "$CODE" "$MAIN" | LC_ALL=C sort > "$TMP/after"
printf '%s\n' '.github/workflows/quality.yml' "$HELPER" | LC_ALL=C sort > "$TMP/allowed"
cmp -s "$TMP/after" "$TMP/allowed" || fail "Unreviewed changes after frozen feature commit."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$MAIN:$HELPER")" ]] ||
  fail "Installer bytes differ from GitHub main."
for wf in quality.yml installer-acceptance.yml operational-acceptance.yml browser-emulation.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$CODE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$CODE"'" and .conclusion=="success")] | length')" ||
    fail "Unable to check $wf"
  [[ "$count" -ge 1 ]] || fail "Required acceptance did not pass: $wf"
  echo "PASS $wf"
done

echo "2. Check exactly 14 deployable files, PHP syntax and live file hashes."
cat > "$TMP/expected" <<'EOF'
app/Http/Controllers/SecurityAlertController.php
app/Http/Controllers/SecurityAlertRuleController.php
app/Http/Controllers/SecurityDashboardController.php
app/Models/AccountNotification.php
app/Models/AuditEvent.php
app/Models/SecurityAlert.php
app/Models/SecurityAlertRule.php
app/Support/Security/SecurityAlertMonitor.php
database/migrations/2026_11_11_000001_create_security_alert_rules_and_alerts.php
resources/views/security/alert-center.blade.php
resources/views/security/index.blade.php
resources/views/security/partials/rule-fields.blade.php
routes/console.php
routes/web.php
tests/Feature/SecurityAlertMonitoringTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/delta"
cmp -s "$TMP/expected" "$TMP/delta" || fail "Unexpected files in Security Alerts release."

cat > "$TMP/modified" <<'EOF'
app/Http/Controllers/SecurityDashboardController.php
app/Models/AccountNotification.php
app/Models/AuditEvent.php
resources/views/security/index.blade.php
routes/console.php
routes/web.php
EOF
cat > "$TMP/additions" <<'EOF'
app/Http/Controllers/SecurityAlertController.php
app/Http/Controllers/SecurityAlertRuleController.php
app/Models/SecurityAlert.php
app/Models/SecurityAlertRule.php
app/Support/Security/SecurityAlertMonitor.php
database/migrations/2026_11_11_000001_create_security_alert_rules_and_alerts.php
resources/views/security/alert-center.blade.php
resources/views/security/partials/rule-fields.blade.php
EOF
cat "$TMP/modified" "$TMP/additions" > "$TMP/runtime"
: > "$TMP/backed-up"
: > "$TMP/new-files"
mkdir -p "$TMP/payload"
while IFS= read -r rel; do
  install -d -m 0700 "$TMP/payload/$(dirname "$rel")"
  git -C "$SOURCE" show "$CODE:$rel" > "$TMP/payload/$rel" || fail "Missing source file: $rel"
done < "$TMP/runtime"

# Reconcile both known branches without modifying the server: security events
# plus the five existing MCP events, and security admin routes plus the exact
# existing stateless bearer-token /mcp route. Reject any unexpected MCP diff.
python3 - "$SOURCE" "$BASE" "$MCP" "$TMP/payload" <<'PY'
import subprocess
import sys
from pathlib import Path

source, baseline, mcp, directory = sys.argv[1:]
root = Path(directory)

def get(ref, path):
    return subprocess.check_output(['git','-C',source,'show',f'{ref}:{path}']).decode('utf-8')

def require(ok, reason):
    if not ok:
        raise SystemExit('BLOCKED: MCP/security reconciliation: '+reason)

audit = 'app/Models/AuditEvent.php'
anchor = "        'developer.api.listings.read',\n"
events = ''.join(f"        '{name}',\n" for name in (
    'mcp.draft.created','mcp.draft.updated','mcp.draft.deleted',
    'mcp.media.attached','mcp.media.detached'))
base_audit = get(baseline, audit)
mcp_audit = get(mcp, audit)
target_audit = (root / audit).read_text()
require(base_audit.count(anchor)==1 and target_audit.count(anchor)==1,
        'Unexpected original audit event structure')
require(mcp_audit == base_audit.replace(anchor, anchor+events),
        'MCP audit changes extend beyond the five approved events')
require("'security.alert.rule.created'," in target_audit and
        "'security.alert.triggered'," in target_audit and
        "'security.alert.acknowledged'," in target_audit,
        'Tested Security Alerts events missing')
merged_audit = target_audit.replace(anchor, anchor+events)
require(merged_audit.count("'mcp.draft.created',")==1 and
        merged_audit.count("'security.alert.triggered',")==1,
        'Duplicated audit events')
(root / audit).write_text(merged_audit)

routes = 'routes/web.php'
base_routes = get(baseline, routes)
mcp_routes = get(mcp, routes)
target_routes = (root / routes).read_text()
require(mcp_routes.startswith(base_routes), 'MCP route diff is not append-only')
addition = mcp_routes[len(base_routes):]
require(addition.count("Route::post('/mcp'")==1 and
        "api.token:mcp.read" in addition and
        'PreventRequestForgery::class' in addition and
        addition.strip().endswith("->name('mcp');"),
        'MCP route or its token/CSRF safety requirements changed')
require("Route::post('/mcp'" not in target_routes and
        "->name('security.alerts.scan')" in target_routes and
        "->name('security.rules.store')" in target_routes,
        'Unexpected Security Alerts route structure')
(root / routes).write_text(target_routes+addition)
print('MCP_AUDIT_AND_ROUTES=RECONCILED_IN_PRIVATE_TEMPORARY_PAYLOAD')
PY

# Ensure the exact merged PHP is valid, not only the original Security branch.
"$PHP" -l "$TMP/payload/app/Models/AuditEvent.php" >/dev/null ||
  fail "Combined MCP + Security AuditEvent PHP syntax failed."
"$PHP" -l "$TMP/payload/routes/web.php" >/dev/null ||
  fail "Combined MCP + Security routes PHP syntax failed."

# This release MUST NOT erase the deployed MCP endpoint or its supporting
# controller. The exact MCP hashes were observed in production on 11 Nov 2026.
[[ -f "$APP/app/Http/Controllers/Api/BambodiaMcpController.php" ]] ||
  fail "Deployed MCP controller absent; refuse to add orphaned MCP route."
already=0
while IFS= read -r rel; do
  [[ "$rel" != *.php ]] || "$PHP" -l "$TMP/payload/$rel" >/dev/null || fail "PHP lint failed: $rel"
  [[ ! -L "$APP/$rel" ]] || fail "Live symlink blocked: $rel"
  if [[ -f "$APP/$rel" ]]; then
    if cmp -s "$APP/$rel" "$TMP/payload/$rel"; then
      echo "ALREADY_INSTALLED $rel"
      already=$((already+1))
    elif [[ "$rel" == "app/Models/AuditEvent.php" &&
            "$(git hash-object "$APP/$rel")" == "$MCP_AUDIT_SHA" ]] ||
         [[ "$rel" == "routes/web.php" &&
            "$(git hash-object "$APP/$rel")" == "$MCP_ROUTES_SHA" ]]; then
      echo "KNOWN_MCP_BASELINE $rel"
    elif grep -Fxq "$rel" "$TMP/modified" &&
      cmp -s "$APP/$rel" <(git -C "$SOURCE" show "$BASE:$rel"); then
      echo "KNOWN_BASELINE $rel"
    else
      fail "Customized or unrecognized live file: $rel"
    fi
    printf '%s\n' "$rel" >> "$TMP/backed-up"
  else
    grep -Fxq "$rel" "$TMP/additions" || fail "Expected existing live file missing: $rel"
    printf '%s\n' "$rel" >> "$TMP/new-files"
    echo "NEW_FILE $rel"
  fi
done < "$TMP/runtime"
[[ "$already" -lt 14 ]] || fail "Release already present; review migration status before rerunning."
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate:status >/dev/null) ||
  fail "Cannot verify migration ledger."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail "Private .env changed."
echo "VERIFIED_RUNTIME_FILES=14"
echo "PRESERVED_MCP_AUDIT_AND_ROUTE=YES"
echo "DATABASE_MIGRATIONS=ONE_ADDITIVE"
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION=UNCHANGED"
  exit 0
fi

echo "3. Back up existing code and full MySQL snapshot."
install -d -m 0700 "$BACKUPS"
DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILE_BACKUP="$DIR/original-files.tar.gz"
cp "$TMP/runtime" "$DIR/release-file-scope.txt"
cp "$TMP/new-files" "$DIR/new-files.txt"
tar -czf "$FILE_BACKUP" -C "$APP" -T "$TMP/backed-up" || fail "Code backup failed."
tar -tzf "$FILE_BACKUP" >/dev/null || fail "Code archive invalid."
MYSQL_BACKUP="$(bash "$DB_HELPER")" || fail "MySQL snapshot failed."
[[ -f "$MYSQL_BACKUP" && ! -L "$MYSQL_BACKUP" ]] || fail "MySQL backup missing."
gzip -t "$MYSQL_BACKUP" || fail "MySQL snapshot failed integrity check."

echo "4. Apply pinned release and verify additive migrations and scheduler."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || fail "Maintenance mode failed."
MAINT=1
APPLIED=1
while IFS= read -r rel; do
  install -d -o www -g www -m 0755 "$APP/$(dirname "$rel")"
  install -o www -g www -m 0644 "$TMP/payload/$rel" "$APP/$rel" || fail "Cannot install $rel"
done < "$TMP/runtime"
(
  cd "$APP"
  runuser -u www -- "$PHP" artisan migrate --force --no-interaction
  runuser -u www -- "$PHP" artisan route:clear
  runuser -u www -- "$PHP" artisan view:clear
  runuser -u www -- "$PHP" artisan view:cache
  runuser -u www -- "$PHP" artisan schedule:list | grep -F 'security:alerts-scan' >/dev/null
) || fail "Laravel migration / views / scheduler registration failed."

(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['security.index','security.alerts.scan','security.rules.store',
    'security.rules.update','security.rules.destroy','security.alerts.acknowledge'] as $name) {
    $route=\Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
    if (!$route || !in_array('role:super_admin',$route->gatherMiddleware(),true)) {
        fwrite(STDERR,"MISSING_PROTECTED_ROUTE=$name\n");exit(1);
    }
}
foreach (['security_alert_rules','security_alerts','audit_events','account_notifications'] as $name) {
    if (!\Illuminate\Support\Facades\Schema::hasTable($name)) {fwrite(STDERR,"MISSING_TABLE=$name\n");exit(1);}
}
if (!in_array('security.alert.detected',\App\Models\AccountNotification::EVENTS,true)) exit(1);
$mcp=\Illuminate\Support\Facades\Route::getRoutes()->getByName('mcp');
if (!$mcp || !in_array('api.token:mcp.read',$mcp->gatherMiddleware(),true)) {
    fwrite(STDERR,"MCP_BEARER_ROUTE_MISSING\n");exit(1);
}
foreach (['mcp.draft.created','mcp.draft.updated','mcp.draft.deleted',
    'mcp.media.attached','mcp.media.detached',
    'security.alert.rule.created','security.alert.triggered','security.alert.acknowledged'] as $event) {
    if (!in_array($event,\App\Models\AuditEvent::EVENTS,true)) {
        fwrite(STDERR,"MISSING_AUDIT_EVENT=$event\n");exit(1);
    }
}
echo "SECURITY_MONITOR_ROUTES_AND_TABLES=PASS\n";
echo "MCP_AUTH_ROUTE_AND_AUDIT_EVENTS=PRESERVED\n";
PHP
) || fail "Security routing, RBAC or migrated tables failed."
while IFS= read -r rel; do
  cmp -s "$APP/$rel" "$TMP/payload/$rel" || fail "Installed source mismatch: $rel"
done < "$TMP/runtime"
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env changed."

(cd "$APP" && runuser -u www -- "$PHP" artisan up) || fail "Unable to resume site."
MAINT=0
echo "5. Check guest pages only; admin UI requires authenticated browser acceptance."
for url in https://bambodia.com/ https://bambodia.com/login \
  https://bambodia.com/creators/34302348; do
  curl -fsSL --retry 2 --max-time 35 -o /dev/null "$url" || fail "Guest HTTP smoke failed: $url"
  echo "HTTP_OK $url"
done
echo "========== BAMBODIA SECURITY ALERTS INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILES_BACKUP=$FILE_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PRESERVED=Existing MCP bearer route and audit events, Cloudflare R2, uploaded media, private keys, Creator Badges, Published Work, .env"
echo "NEXT=Ensure aaPanel cron calls php artisan schedule:run once every minute."
