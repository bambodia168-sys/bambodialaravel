#!/usr/bin/env bash
# Safe Bambodia AI Draft Studio installer. Provider off by default. --check/--apply.
set -Eeuo pipefail
umask 077
MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo 'Usage: --check|--apply' >&2; exit 2; }
REPO="bambodia168-sys/bambodialaravel"
BASE="f6c863d0957238348b4231785f2286e2450c91ba"
MCP_OLD_BASE="acc2bf931154eceaa10a07efbbf7695501955dec"
CODE="82b22a26cb71756d305a855780534e18e685970d"
# MCP foundation is already installed on this particular aaPanel runtime.
# These objects pin the exact known live overlays, avoiding a destructive reset.
MCP="2026baa2daa215d8031497921bc44bc351f9df32"
MCP_AUDIT_SHA="ee89c0714d1a2259999b858d84def93130feaeb1"
MCP_ROUTES_SHA="502c73e246eba4ee38c35d19529f6206d4f73678"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
HELPER="support/ai-draft-studio-aapanel-upgrade.sh"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
BACKUPS="/root/Bambodia-AiDraftStudio-Backups"
MIGRATION="database/migrations/2026_11_11_000010_create_ai_draft_studio_tables.php"
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

TMP="$(mktemp -d /root/bambodia-ai-draft-verify.XXXXXXXX)"
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

echo "========== BAMBODIA AI DRAFT STUDIO $MODE =========="
echo "1. Verify tested immutable code, trusted installer and five workflows."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
# Obtain the already-deployed MCP source as immutable objects; do not check out
# or overwrite the production MCP foundation.
git -C "$SOURCE" fetch origin "refs/heads/feature/bambodia-mcp-foundation-20261110:refs/remotes/origin/feature/bambodia-mcp-foundation-20261110"
git -C "$SOURCE" cat-file -e "$MCP^{commit}" || fail "Pinned deployed MCP foundation is unavailable."
git -C "$SOURCE" cat-file -e "$MCP_OLD_BASE^{commit}" || fail "Original MCP comparison baseline missing."
MCP_CONTROLLER="app/Http/Controllers/Api/BambodiaMcpController.php"
[[ -f "$APP/$MCP_CONTROLLER" && ! -L "$APP/$MCP_CONTROLLER" ]] ||
  fail "Known production MCP controller not found."
[[ "$(git hash-object "$APP/$MCP_CONTROLLER")" == "$(git -C "$SOURCE" rev-parse "$MCP:$MCP_CONTROLLER")" ]] ||
  fail "Production MCP controller has unrecognized modifications."
[[ "$(git -C "$SOURCE" rev-parse "$MCP:app/Models/AuditEvent.php")" == "$MCP_AUDIT_SHA" ]] ||
  fail "Unexpected source MCP audit events."
[[ "$(git -C "$SOURCE" rev-parse "$MCP:routes/web.php")" == "$MCP_ROUTES_SHA" ]] ||
  fail "Unexpected source MCP routes."
MAIN="$(git -C "$SOURCE" rev-parse refs/remotes/origin/main)"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Installed baseline not found."
git -C "$SOURCE" cat-file -e "$CODE^{commit}" || fail "Tested AI feature release not found."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$CODE" || fail "Wrong AI release ancestry."
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

echo "2. Check exact AI changes, PHP syntax and installed live file hashes."
cat > "$TMP/expected" <<'EOF'
.github/workflows/quality.yml
app/Http/Controllers/AiDraftController.php
app/Http/Controllers/BuilderController.php
app/Http/Controllers/Dashboard/AiDraftSettingsController.php
app/Models/AiDraftProvider.php
app/Models/AiDraftRequest.php
app/Models/AuditEvent.php
app/Support/AiDraftStudio.php
app/Support/BuilderAccess.php
database/migrations/2026_11_11_000010_create_ai_draft_studio_tables.php
docs/integrations/bambodia-ai-draft-studio.md
public/js/ai-draft-studio.js
resources/views/builder/ai-draft-dialog.blade.php
resources/views/builder/index.blade.php
resources/views/dashboard/ai-draft-settings.blade.php
resources/views/layouts/workspace.blade.php
routes/web.php
tests/Feature/AiDraftStudioTest.php
EOF
git -C "$SOURCE" diff --name-only "$BASE" "$CODE" | LC_ALL=C sort > "$TMP/delta"
cmp -s "$TMP/expected" "$TMP/delta" || fail "Unexpected changes in the reviewed AI feature scope."

cat > "$TMP/modified" <<'EOF'
app/Http/Controllers/BuilderController.php
app/Models/AuditEvent.php
resources/views/builder/index.blade.php
resources/views/layouts/workspace.blade.php
routes/web.php
EOF
cat > "$TMP/additions" <<'EOF'
app/Http/Controllers/AiDraftController.php
app/Http/Controllers/Dashboard/AiDraftSettingsController.php
app/Models/AiDraftProvider.php
app/Models/AiDraftRequest.php
app/Support/AiDraftStudio.php
app/Support/BuilderAccess.php
database/migrations/2026_11_11_000010_create_ai_draft_studio_tables.php
public/js/ai-draft-studio.js
resources/views/builder/ai-draft-dialog.blade.php
resources/views/dashboard/ai-draft-settings.blade.php
EOF
cat "$TMP/modified" "$TMP/additions" > "$TMP/runtime"
: > "$TMP/backed-up"
: > "$TMP/new-files"
mkdir -p "$TMP/payload" "$TMP/expected-live"
while IFS= read -r rel; do
  install -d -m 0700 "$TMP/payload/$(dirname "$rel")"
  git -C "$SOURCE" show "$CODE:$rel" > "$TMP/payload/$rel" || fail "Missing source file: $rel"
done < "$TMP/runtime"

# Reconcile immutable MCP-only additions with Security Alerts + tested AI source.
# Generate both expected production baselines and the final payload in /root TMP.
python3 - "$SOURCE" "$BASE" "$CODE" "$MCP_OLD_BASE" "$MCP" "$TMP/payload" "$TMP/expected-live" <<'PY'
import subprocess
import sys
from pathlib import Path

source, baseline, code, old, mcp, payload, expected = sys.argv[1:]
payload, expected = Path(payload), Path(expected)

def get(ref, path):
    return subprocess.check_output(['git','-C',source,'show',f'{ref}:{path}']).decode('utf-8')

def require(ok, why):
    if not ok:
        raise SystemExit('BLOCKED: MCP/AI merge: '+why)

audit = 'app/Models/AuditEvent.php'
anchor = "        'developer.api.listings.read',\n"
events = ''.join(f"        '{event}',\n" for event in (
    'mcp.draft.created','mcp.draft.updated','mcp.draft.deleted',
    'mcp.media.attached','mcp.media.detached'))
old_audit = get(old,audit)
mcp_audit = get(mcp,audit)
base_audit = get(baseline,audit)
feature_audit = (payload/audit).read_text()
require(old_audit.count(anchor)==1 and base_audit.count(anchor)==1
        and feature_audit.count(anchor)==1, 'Unexpected audit anchor')
require(mcp_audit == old_audit.replace(anchor,anchor+events),
        'MCP audit diff is not exactly the known five events')
require("'security.alert.triggered'," in base_audit and
        "'ai.draft.generated'," in feature_audit and
        "'mcp.draft.created'," not in feature_audit,
        'Security Center, AI draft, or MCP audit events changed')
(expected/audit).parent.mkdir(parents=True, exist_ok=True)
(expected/audit).write_text(base_audit.replace(anchor,anchor+events))
(payload/audit).write_text(feature_audit.replace(anchor,anchor+events))

routes='routes/web.php'
old_routes=get(old,routes)
mcp_routes=get(mcp,routes)
base_routes=get(baseline,routes)
feature_routes=(payload/routes).read_text()
require(mcp_routes.startswith(old_routes), 'MCP routes are not append-only')
append=mcp_routes[len(old_routes):]
require(append.count("Route::post('/mcp'")==1 and
        'api.token:mcp.read' in append and
        'PreventRequestForgery::class' in append and
        append.strip().endswith("->name('mcp');"),
        'MCP bearer-token and CSRF conditions changed')
require("Route::post('/mcp'" not in feature_routes and
        "->name('dashboard.ai-draft.index')" in feature_routes and
        "->name('builder.ai.generate')" in feature_routes and
        "->name('security.index')" in feature_routes,
        'AI or Security routes missing from tested version')
(expected/routes).parent.mkdir(parents=True, exist_ok=True)
(expected/routes).write_text(base_routes+append)
(payload/routes).write_text(feature_routes+append)
print('MCP_AND_SECURITY=RECONCILED_IN_PRIVATE_PAYLOAD')
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
    elif [[ "$rel" == "app/Models/AuditEvent.php" || "$rel" == "routes/web.php" ]] &&
      cmp -s "$APP/$rel" "$TMP/expected-live/$rel"; then
      echo "KNOWN_MCP_SECURITY_BASELINE $rel"
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
[[ "$already" -lt 15 ]] || fail "AI feature already installed; review migration status before rerunning."
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate:status >/dev/null) ||
  fail "Cannot verify migration ledger."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail "Private .env changed."
(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['ai_draft_providers','ai_draft_requests'] as $table) {
    if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
        fwrite(STDERR,"BLOCKED: AI table already exists: $table\n");exit(1);
    }
}
echo "AI_TABLES=NOT_YET_PRESENT\n";
PHP
) || fail "Unexpected existing AI tables."
echo "VERIFIED_RUNTIME_FILES=15"
echo "PRESERVED_MCP_SECURITY_AUDIT_AND_ROUTES=YES"
echo "DATABASE_MIGRATIONS=ONE_ADDITIVE_SCOPED"
echo "PROVIDER_DEFAULT=DISABLED"
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

echo "4. Apply tested AI feature and verify only its additive migration."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || fail "Maintenance mode failed."
MAINT=1
APPLIED=1
while IFS= read -r rel; do
  install -d -o www -g www -m 0755 "$APP/$(dirname "$rel")"
  install -o www -g www -m 0644 "$TMP/payload/$rel" "$APP/$rel" || fail "Cannot install $rel"
done < "$TMP/runtime"
(
  cd "$APP"
  # Apply ONLY this release's additive migration; other pending unrelated
  # migrations remain untouched until independently approved.
  runuser -u www -- "$PHP" artisan migrate --path="$MIGRATION" --force --no-interaction
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
$routes=\Illuminate\Support\Facades\Route::getRoutes();
foreach (['dashboard.ai-draft.index','dashboard.ai-draft.save','dashboard.ai-draft.test',
    'dashboard.ai-draft.status','dashboard.ai-draft.disconnect','security.index'] as $name) {
    $r=$routes->getByName($name);
    if (!$r || !in_array('role:super_admin',$r->gatherMiddleware(),true)) {
        fwrite(STDERR,"BLOCKED: Missing super-admin protection: $name\n");exit(1);
    }
}
foreach (['builder.ai.generate','builder.ai.reviewed'] as $name) {
    $r=$routes->getByName($name);
    if (!$r || !in_array('auth',$r->gatherMiddleware(),true)) {
        fwrite(STDERR,"BLOCKED: Missing Builder authorization: $name\n");exit(1);
    }
}
$mcp=$routes->getByName('mcp');
if (!$mcp || !in_array('api.token:mcp.read',$mcp->gatherMiddleware(),true)) {
    fwrite(STDERR,"BLOCKED: Missing protected MCP route\n");exit(1);
}
foreach (['ai_draft_providers','ai_draft_requests',
   'security_alert_rules','security_alerts','account_notifications'] as $table) {
    if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
        fwrite(STDERR,"BLOCKED: Missing table: $table\n");exit(1);
    }
}
foreach (['ai.provider.configured','ai.draft.generated','ai.draft.reviewed',
   'mcp.draft.created','mcp.media.attached','security.alert.triggered'] as $event) {
    if (!in_array($event,\App\Models\AuditEvent::EVENTS,true)) {
        fwrite(STDERR,"BLOCKED: Missing audit event: $event\n");exit(1);
    }
}
if (\App\Models\AiDraftProvider::query()->exists()) {
    fwrite(STDERR,"BLOCKED: Provider must remain unconfigured\n");exit(1);
}
echo "AI_ADMIN_RBAC_AND_BUILDER_AUTH=PASS\n";
echo "MCP_AND_SECURITY_ALERTS=PRESERVED\n";
echo "AI_PROVIDER=DISABLED_NOT_CONFIGURED\n";
PHP
) || fail "AI security, database, or MCP acceptance failed."
while IFS= read -r rel; do
  cmp -s "$APP/$rel" "$TMP/payload/$rel" || fail "Installed source mismatch: $rel"
done < "$TMP/runtime"
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env changed."

(cd "$APP" && runuser -u www -- "$PHP" artisan up) || fail "Unable to resume site."
MAINT=0
echo "5. Smoke-check original public pages and AI Builder JavaScript."
for url in https://bambodia.com/ https://bambodia.com/login \
  https://bambodia.com/creators/34302348 \
  'https://bambodia.com/js/ai-draft-studio.js?v=1'; do
  curl -fsSL --retry 2 --max-time 35 -o /dev/null "$url" || fail "Guest HTTP smoke failed: $url"
  echo "HTTP_OK $url"
done
echo "========== BAMBODIA AI DRAFT STUDIO INSTALLED =========="
echo "RELEASE=$CODE"
echo "FILES_BACKUP=$FILE_BACKUP"
echo "MYSQL_BACKUP=$MYSQL_BACKUP"
echo "PRESERVED=Existing MCP bearer route and events, Security Alerts, Cloudflare R2, media, Creator Badges, Published Work, existing frontend layouts, .env"
echo "PROVIDER=DISABLED_UNTIL_SUPER_ADMIN_LIVE_CONNECTION_TEST"
echo "NEXT=Configure and test provider with authorized key, then manually review a Builder draft."
