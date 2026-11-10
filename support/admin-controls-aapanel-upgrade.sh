#!/usr/bin/env bash
# Verified aaPanel release: Creator Profile -> Admin Control Center + TOTP.
# --check must be run and inspected before --apply.
set -Eeuo pipefail
umask 077
MODE="--check"
if [[ $# -gt 0 ]]; then MODE="$1"; fi
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo 'Usage: --check or --apply'; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="f8006c3c7ad029ddc9e1d664c89a2917c7b142f4"
RELEASE="e92e0f98d74ac516489aa371fc763c16d60457b2"
TESTED="418c2a67b55c2afac29c86a17922ba742a6b3032"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
DB_HELPER="$SOURCE/support/builder-studio-db-backup.sh"
HELPER="support/admin-controls-aapanel-upgrade.sh"
BACKUPS="/root/Bambodia-AdminControlCenter-Backups"
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == "0" ]] || fail "Run as root."
for bin in git gh python3 cmp curl tar gzip runuser mktemp install sha256sum cut; do
  command -v "$bin" >/dev/null || fail "Missing $bin"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && -d "$APP" && ! -L "$APP" ]] ||
  fail "Unexpected source or application paths."
[[ -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -f "$APP/.env" && ! -L "$APP/.env" ]] ||
  fail "Laravel runtime or private environment missing."
[[ -x "$PHP" && -f "$DB_HELPER" && ! -e "$APP/storage/framework/down" ]] ||
  fail "PHP, backup helper or maintenance state invalid."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail "Git source remote not trusted."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Git source has local changes."
id www >/dev/null || fail "aaPanel web user missing."

TMP="$(mktemp -d /root/bambodia-admin-release.XXXXXXXX)"
FILE_BACKUP=""
DB_BACKUP=""
APPLIED=0
MAINT=0
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
cleanup(){
  rc=$?
  trap - EXIT
  if (( rc != 0 && APPLIED == 1 )); then
    echo "INSTALL FAILED: restoring protected file archive." >&2
    if (( MAINT == 0 )); then
      (cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) || true
      MAINT=1
    fi
    if [[ -s "$TMP/created" ]]; then
      while IFS= read -r name; do rm -f -- "$APP/$name"; done < "$TMP/created"
    fi
    [[ -n "$FILE_BACKUP" && -f "$FILE_BACKUP" ]] && tar -xzf "$FILE_BACKUP" -C "$APP" || true
    (cd "$APP" && runuser -u www -- "$PHP" artisan route:clear &&
      runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$FILE_BACKUP" >&2
    echo "MYSQL_SNAPSHOT=$DB_BACKUP" >&2
    echo "IMPORTANT: Additive schema migrations are retained after file rollback." >&2
  fi
  if (( MAINT == 1 )); then (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true; fi
  rm -rf -- "$TMP"
  exit "$rc"
}
trap cleanup EXIT

echo "========== ADMIN CONTROL CENTER $MODE =========="
echo "1. Validate pinned GitHub code, installer integrity and five workflows."
git -C "$SOURCE" fetch origin "refs/heads/main:refs/remotes/origin/main"
CURRENT="$(git -C "$SOURCE" rev-parse refs/remotes/origin/main)"
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Known live baseline missing."
git -C "$SOURCE" cat-file -e "$RELEASE^{commit}" || fail "Frozen code release missing."
git -C "$SOURCE" merge-base --is-ancestor "$BASE" "$RELEASE" || fail "Wrong release lineage."
git -C "$SOURCE" merge-base --is-ancestor "$RELEASE" "$CURRENT" ||
  fail "Frozen release not present on main."
git -C "$SOURCE" diff --quiet "$RELEASE" "$TESTED" ||
  fail "Merged code differs from full-CI-tested source."
git -C "$SOURCE" diff --name-only "$RELEASE" "$CURRENT" | LC_ALL=C sort > "$TMP/after"
printf '%s\n' "$HELPER" > "$TMP/allowed"
cmp -s "$TMP/after" "$TMP/allowed" || fail "Main contains later unreviewed changes."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$CURRENT:$HELPER")" ]] ||
  fail "Installer does not match GitHub main."
for workflow in quality.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  pass="$(gh api "repos/$REPO/actions/workflows/$workflow/runs?head_sha=$TESTED&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha=="'"$TESTED"'" and .conclusion=="success")] | length')" ||
    fail "GitHub Actions lookup unavailable for $workflow"
  [[ "$pass" -ge 1 ]] || fail "Workflow did not pass: $workflow"
  echo "PASS $workflow"
done

echo "2. Verify limited scope, PHP syntax, original assets and live file hashes."
git -C "$SOURCE" diff --quiet "$BASE" "$RELEASE" -- \
  resources/views/creators/show.blade.php resources/views/components/creator-badges.blade.php \
  public/images/bambodia-logo.webp public/images/bambodia-logo-dark.svg ||
  fail "Creator badges, Published Work or original logo were modified."

python3 - "$SOURCE" "$BASE" "$RELEASE" "$TMP" <<'PY'
import subprocess,sys,pathlib,re
source,base,target,tmp=sys.argv[1:]
out=subprocess.check_output(["git","-C",source,"diff","--name-status","-z",base,target])
parts=[s.decode() for s in out.split(b"\x00") if s]
if len(parts)%2: raise SystemExit("BLOCKED: unsupported Git diff format")
included=[]
for status,path in zip(parts[::2],parts[1::2]):
    if status not in ("A","M"): raise SystemExit("BLOCKED: removed or renamed file")
    if not re.fullmatch(r"[A-Za-z0-9_.\/-]+",path) or ".." in path.split("/"):
        raise SystemExit("BLOCKED: invalid relative file path")
    approved=(
        (path.startswith("app/") and path.endswith(".php"))
        or path=="bootstrap/app.php"
        or (path.startswith("database/migrations/") and path.endswith(".php"))
        or (path.startswith("resources/views/") and path.endswith(".blade.php"))
        or path in ("routes/web.php","routes/console.php","public/js/site-identity-theme-icon.js")
    )
    if approved: included.append((status,path))
    elif path.startswith(("tests/","support/")): continue
    else: raise SystemExit(f"BLOCKED: outside release scope: {path}")
if not (40<=len(included)<=110): raise SystemExit("BLOCKED: unexpected deployment scope")
pathlib.Path(tmp,"scope").write_text("".join(f"{s}\t{p}\n" for s,p in included))
print(f"VERIFIED_DEPLOYMENT_SCOPE={len(included)}")
PY

: > "$TMP/existing"
: > "$TMP/created"
mkdir -p "$TMP/payload"
while IFS="$(printf '\t')" read -r status rel; do
  [[ -n "$rel" ]] || continue
  install -d -m 0700 "$TMP/payload/$(dirname "$rel")"
  git -C "$SOURCE" show "$RELEASE:$rel" > "$TMP/payload/$rel" ||
    fail "Git object unavailable: $rel"
  [[ "$rel" != *.php ]] || "$PHP" -l "$TMP/payload/$rel" >/dev/null ||
    fail "Invalid PHP source: $rel"
  [[ ! -L "$APP/$rel" ]] || fail "Unexpected live symlink: $rel"
  if [[ -f "$APP/$rel" ]]; then
    if cmp -s "$APP/$rel" "$TMP/payload/$rel"; then
      echo "ALREADY_AT_RELEASE $rel"
    elif [[ "$status" == "M" ]] && cmp -s "$APP/$rel" <(git -C "$SOURCE" show "$BASE:$rel"); then
      echo "KNOWN_LIVE_BASELINE $rel"
    else
      fail "Live file modified outside verified release: $rel"
    fi
    printf '%s\n' "$rel" >> "$TMP/existing"
  else
    [[ "$status" == "A" ]] || fail "Required old file missing: $rel"
    printf '%s\n' "$rel" >> "$TMP/created"
    echo "NEW_FILE $rel"
  fi
done < "$TMP/scope"
(cd "$APP" && runuser -u www -- "$PHP" artisan migrate:status >/dev/null) ||
  fail "Unable to verify database migration ledger."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] ||
  fail "Environment hash changed unexpectedly."

if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION=UNCHANGED"
  echo "DEPLOY_ONLY_AFTER_REVIEW=--apply"
  exit 0
fi

echo "3. Back up original files and create full MySQL snapshot with private permissions."
install -d -m 0700 "$BACKUPS"
BACKUP_DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
FILE_BACKUP="$BACKUP_DIR/original-files.tar.gz"
cp "$TMP/scope" "$BACKUP_DIR/scope.txt"
cp "$TMP/created" "$BACKUP_DIR/new-files.txt"
if [[ -s "$TMP/existing" ]]; then
  tar -czf "$FILE_BACKUP" -C "$APP" -T "$TMP/existing" || fail "File backup failed."
else
  tar -czf "$FILE_BACKUP" --files-from /dev/null
fi
tar -tzf "$FILE_BACKUP" >/dev/null || fail "Backup archive corrupt."
DB_BACKUP="$(bash "$DB_HELPER")" || fail "MySQL backup failed."
[[ -f "$DB_BACKUP" && ! -L "$DB_BACKUP" ]] || fail "MySQL backup not verified."
gzip -t "$DB_BACKUP" || fail "MySQL backup gzip integrity failed."

echo "4. Enter maintenance and apply the exact pinned code."
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30) ||
  fail "Maintenance mode failed."
MAINT=1
APPLIED=1
while IFS="$(printf '\t')" read -r status rel; do
  [[ -n "$rel" ]] || continue
  install -d -o www -g www -m 0755 "$APP/$(dirname "$rel")"
  install -o www -g www -m 0644 "$TMP/payload/$rel" "$APP/$rel" ||
    fail "Copy failed: $rel"
done < "$TMP/scope"
(
  cd "$APP"
  runuser -u www -- "$PHP" artisan migrate --force --no-interaction
  runuser -u www -- "$PHP" artisan route:clear
  runuser -u www -- "$PHP" artisan view:clear
  runuser -u www -- "$PHP" artisan view:cache
) || fail "Laravel migration or view compilation failed."

echo "5. Verify new routes, data tables and unchanged private environment."
(cd "$APP" && runuser -u www -- "$PHP" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach ([
    'dashboard.site-identity','dashboard.seo-settings','dashboard.social.index',
    'dashboard.languages.index','dashboard.typography.index','dashboard.cloud-storage.index',
    'dashboard.turnstile.index','account.security','login.two-factor'
] as $name) {
    if (!\Illuminate\Support\Facades\Route::getRoutes()->getByName($name)) {
        fwrite(STDERR,"MISSING_ROUTE=$name\n");exit(1);
    }
    echo "REGISTERED_ROUTE=$name\n";
}
foreach ([
    'site_brand_assets','site_seo_pages','site_social_links','site_languages',
    'site_translation_entries','site_typography_rules','cloud_storage_connections',
    'turnstile_bot_protections','user_two_factors'
] as $name) {
    if (!\Illuminate\Support\Facades\Schema::hasTable($name)) {
        fwrite(STDERR,"MISSING_TABLE=$name\n");exit(1);
    }
}
echo "ROUTES_AND_DATABASE=PASS\n";
PHP
) || fail "Route or table verification failed."
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] ||
  fail "Private .env was unexpectedly modified."
(cd "$APP" && runuser -u www -- "$PHP" artisan up) ||
  fail "Unable to exit maintenance."
MAINT=0
for url in \
  https://bambodia.com/ \
  https://bambodia.com/login \
  https://bambodia.com/creators/34302348 \
  https://bambodia.com/site.webmanifest \
  'https://bambodia.com/js/site-identity-theme-icon.js?v=1'
do
  curl -fsSL --retry 2 --max-time 35 -o /dev/null "$url" ||
    fail "Public smoke check failed: $url"
  echo "HTTP_OK $url"
done
echo "========== ADMIN CONTROL CENTER INSTALLED =========="
echo "RELEASE=$RELEASE"
echo "FILE_BACKUP=$FILE_BACKUP"
echo "MYSQL_BACKUP=$DB_BACKUP"
echo "PRESERVED=.env, installer lock, published work, badges, prior media and layout."
