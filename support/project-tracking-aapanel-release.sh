#!/usr/bin/env bash
# Verified creator/customer Project Tracking — additive code and database release.
# --preflight never modifies production application code or the database.
set -Eeuo pipefail
umask 077

[[ $# -eq 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] || {
  echo 'Usage: sudo bash project-tracking-aapanel-release.sh --preflight|--apply' >&2; exit 2;
}
MODE="$1"
[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: Root is required' >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="7099f919d92db0716a590081160847f812eb718c"
TARGET="55047c023c17996af39ca972e9dab187462f20a3"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MIGRATION="database/migrations/2026_11_25_170000_create_verified_order_tracking_tables.php"

for command in curl git patch python3 tar runuser mktemp install; do
  command -v "$command" >/dev/null || { echo "STOP: Missing $command" >&2; exit 2; }
done
[[ -x "$PHP" && ! -L "$SITE" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && -f "$SITE/.env" ]] ||
  { echo 'STOP: Unexpected Laravel runtime path' >&2; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo 'STOP: This installer requires the verified production environment' >&2; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo 'STOP: Nginx site root differs from verified installation' >&2; exit 2; }

health() {
  curl -4 -fLsS --connect-timeout 10 --max-time 25 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null "https://bambodia.com$1"
}
health / && health /support-center || { echo 'STOP: Public website health check failed' >&2; exit 2; }

STAGE="$(mktemp -d /root/bambodia-tracking-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
on_exit() {
  status=$?
  trap - EXIT
  if [[ "$DEPLOYING" -eq 1 && -n "$BACKUP" ]]; then
    echo 'ROLLBACK: Restore original application code; do not delete business data' >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    while IFS= read -r file; do
      [[ -z "$file" ]] || rm -f -- "$SITE/$file"
    done < "$STAGE/installed-new.list"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi) || true
    echo 'NOTE: New DB tables, if migrated, are intentionally retained after code rollback.' >&2
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$STAGE"
  exit "$status"
}
trap on_exit EXIT

download_verified_source() {
  local ref="$1" file="$2" destination="$3" attempt
  for attempt in 1 2 3 4 5; do
    if curl -fLsS --connect-timeout 12 --max-time 120 \
      "https://raw.githubusercontent.com/$REPO/$ref/$file" -o "$destination"; then
      return 0
    fi
    rm -f -- "$destination"
    if [[ $attempt -lt 5 ]]; then sleep "$((attempt * 2))"; fi
  done
  echo "STOP: Failed to download pinned source for $file" >&2
  return 1
}

mkdir -p "$STAGE"/{base,target,candidate}
cat > "$STAGE/manifest" <<'MANIFEST'
app/Http/Controllers/ProjectTrackingController.php|NEW|1db6f4d3e07aaa5c65ed647186bab16ee82cacd2
app/Http/Controllers/ServiceDeliveryController.php|256c567e995deb4d040876b3dd2fd48f0638fb16|12045fef4e58c50595b9378ec1ac4a528627e89c
app/Models/AccountNotification.php|4d9822debd7bcc26cb28fb2ab40e3001af119e74|5b9b8c7f01d1e3894445a1869f134496e918fc61
app/Models/AuditEvent.php|3a6cf98650a95cceb3a822dc3b4579c5dc2e4d69|3b0c02c7f331178fe4abdb1acd2bc895bb2f2b89
database/migrations/2026_11_25_170000_create_verified_order_tracking_tables.php|NEW|1afbf918bf3556e401f46dad20309cdeb75ef26a
public/css/project-tracking.css|NEW|63f0118d509c0a697b2a041bd2874c41e1721dfe
public/js/project-tracking.js|NEW|a3368f45ab66af6301f67f479eb745593ef41fa8
resources/views/tracking/index.blade.php|NEW|53f99f1d7f75461ce19a734bc86bca1d0510d6ee
resources/views/tracking/show.blade.php|NEW|b1d11148468dd8008c294c693ca8f1b38832a3b2
resources/views/inquiries/delivery.blade.php|104b9241890a63b97468179e7c96f4b2988bd5df|9453ccee49838eb6146955e0df8ed92f80d048e9
resources/views/commerce/show.blade.php|44daab1352e99fb066ad3d4f623b7ededba242fe|c40c43f368c442af0f2914cb4e9a8be3d79a3784
resources/views/inquiries/show.blade.php|99de261d98e7e29a99320a78ea2f9cf9582acf5d|d0e9812a4a5b4813227df74602b18c597d90f9e2
routes/web.php|a69726f90753eb50a118eca66873d9ce1bd2e7ab|58ee4b4b2c663b445b0a4dfa11e326ceda84c2a9
resources/views/commerce/orders.blade.php|1921d25ffa77eaaa300f7d1a98edebec2ad5c75c|131456d60804cfa4edf75191f748a1821128e187
resources/views/inquiries/index.blade.php|488b1d3ce244a233eb7613e5fdf0a105374d21b3|5fd32b71e5f1e9317b692068f623be208491bdfb
MANIFEST

: > "$STAGE/changed.list"
: > "$STAGE/existing.list"
: > "$STAGE/installed-new.list"
: > "$STAGE/live.list"

echo '=== Verify pinned release and production code compatibility ==='
while IFS='|' read -r file base_sha target_sha; do
  [[ "$file" != /* && "$file" != *..* ]] || { echo 'STOP: Unsafe path' >&2; exit 2; }
  mkdir -p "$STAGE/target/$(dirname "$file")" "$STAGE/candidate/$(dirname "$file")"
  download_verified_source "$TARGET" "$file" "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/target/$file")" == "$target_sha" ]] ||
    { echo "STOP: Target hash mismatch $file" >&2; exit 2; }
  if [[ "$base_sha" == 'NEW' ]]; then
    if [[ -e "$SITE/$file" || -L "$SITE/$file" ]]; then
      [[ -f "$SITE/$file" && ! -L "$SITE/$file" &&
        "$(git hash-object "$SITE/$file")" == "$target_sha" ]] ||
        { echo "STOP: Existing unexpected file $file" >&2; exit 2; }
      echo "PASS: Already installed $file"
    else
      printf '%s\n' "$file" >> "$STAGE/changed.list"
      echo "PASS: New source verified $file"
    fi
    continue
  fi
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: Missing or symlinked live file $file" >&2; exit 2; }
  mkdir -p "$STAGE/base/$(dirname "$file")"
  download_verified_source "$BASE" "$file" "$STAGE/base/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$base_sha" ]] ||
    { echo "STOP: Baseline hash mismatch $file" >&2; exit 2; }
  echo "$file" >> "$STAGE/existing.list"
  live_sha="$(git hash-object "$SITE/$file")"
  printf '%s|%s\n' "$file" "$live_sha" >> "$STAGE/live.list"
  cp -p -- "$SITE/$file" "$STAGE/candidate/$file"
  if [[ "$live_sha" == "$target_sha" ]]; then
    echo "PASS: Already current $file"
    continue
  fi
  if [[ "$live_sha" == "$base_sha" ]]; then
    cp -- "$STAGE/target/$file" "$STAGE/candidate/$file"
  else
    python3 - "$STAGE" "$file" <<'PY_PATCH'
from difflib import unified_diff
from pathlib import Path
import subprocess
import sys
import tempfile

stage = Path(sys.argv[1])
path = sys.argv[2]
base = (stage / 'base' / path).read_text()
target = (stage / 'target' / path).read_text()
candidate = stage / 'candidate' / path
diff = ''.join(unified_diff(base.splitlines(keepends=True),
                            target.splitlines(keepends=True),
                            fromfile='a/' + path, tofile='b/' + path, n=3))
if not diff:
    raise SystemExit('STOP: Missing patch: ' + path)
with tempfile.TemporaryDirectory(prefix='bambodia-tracking-patch-') as temp:
    from shutil import copyfile
    out = Path(temp) / path
    out.parent.mkdir(parents=True, exist_ok=True)
    copyfile(candidate, out)
    result = subprocess.run(
        ['patch','--batch','--forward','--fuzz=0','--no-backup-if-mismatch',
         '-p1','-d',temp],input=diff,text=True,capture_output=True
    )
    if result.returncode:
        raise SystemExit('STOP: Incompatible live code in ' + path + '\n' +
                         result.stdout + result.stderr)
    copyfile(out, candidate)
PY_PATCH
  fi
  echo "$file" >> "$STAGE/changed.list"
  echo "PASS: Exact-context compatible $file"
done < "$STAGE/manifest"

for file in \
  app/Http/Controllers/ProjectTrackingController.php \
  app/Http/Controllers/ServiceDeliveryController.php \
  "$MIGRATION" \
  routes/web.php
do
  source="$STAGE/candidate/$file"
  [[ -f "$source" ]] || source="$STAGE/target/$file"
  "$PHP" -l "$source" || { echo "STOP: PHP syntax invalid: $file" >&2; exit 2; }
done
if command -v node >/dev/null; then
  node --check "$STAGE/target/public/js/project-tracking.js"
fi

echo "PASS: Verified all $(wc -l < "$STAGE/manifest") pinned files and PHP/JS syntax"
echo "Files requiring installation: $(wc -l < "$STAGE/changed.list")"
if [[ "$MODE" == '--preflight' ]]; then
  echo 'PREFLIGHT ONLY: Production code, data, payments and databases unchanged'
  echo 'IMPORTANT: Create and verify a fresh database backup before --apply'
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-tracking-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/existing.list"
echo "Backup: $BACKUP/original-code.tar"
while IFS='|' read -r file prior_sha; do
  [[ "$(git hash-object "$SITE/$file")" == "$prior_sha" ]] ||
    { echo "STOP: Live code changed during preflight: $file" >&2; exit 2; }
done < "$STAGE/live.list"
DEPLOYING=1
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  destination="$SITE/$file"
  mkdir -p -- "$(dirname "$destination")"
  if [[ ! -e "$destination" ]]; then
    echo "$file" >> "$STAGE/installed-new.list"
  fi
  temp="$(mktemp "$(dirname "$destination")/.bambodia-track.XXXXXXXX")"
  source="$STAGE/candidate/$file"
  [[ -f "$source" ]] || source="$STAGE/target/$file"
  install -o www -g www -m 0644 "$source" "$temp"
  mv -f -- "$temp" "$destination"
done < "$STAGE/changed.list"

echo '=== Apply additive tracking migration ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan migrate \
  --path="$MIGRATION" --force --no-interaction --no-ansi)
echo '=== Clear compiled views and route cache ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi)
echo '=== Confirm production routes ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --name=tracking --no-ansi) |
  grep -q 'tracking.orders.show' ||
  { echo 'STOP: Tracking routes are missing after installation' >&2; exit 1; }
health / && health /support-center ||
  { echo 'STOP: Public site health failed; restoring original code' >&2; exit 1; }
DEPLOYING=0
echo 'SUCCESS: Private Project Tracking UI and additive tables installed'
echo "RETAIN BACKUP: $BACKUP/original-code.tar"
echo 'No changes to global header, sidebar, payment, contract terms or existing order records'
