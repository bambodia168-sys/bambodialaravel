#!/usr/bin/env bash
# Creator Studio analytics: additive tables, real-data charts, and private tracker.
# Exact-SHA sources, patch compatibility, guarded install, code backup and rollback.
set -Eeuo pipefail
umask 077

[[ "$#" -eq 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] ||
  { echo "Usage: sudo bash release.sh --preflight|--apply"; exit 2; }
MODE="$1"
[[ "$(id -u)" -eq 0 ]] || { echo "STOP: Root required"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="4fc61f102131c27a5c7d1fd85fc76ae6b2c03e7a"
TARGET="5ccea23b80a91b0865b5e523390dcf1838c5bd5d"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MIGRATION="database/migrations/2026_11_25_010000_create_creator_studio_analytics_tables.php"

for cmd in curl git patch python3 tar runuser mktemp install; do
  command -v "$cmd" >/dev/null || { echo "STOP: Missing $cmd"; exit 2; }
done
[[ -x "$PHP" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && ! -L "$SITE" ]] ||
  { echo "STOP: Unexpected Laravel site directory"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: APP_ENV not production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: Unexpected production Nginx root"; exit 2; }

health() {
  curl -4 -fLsS --connect-timeout 10 --max-time 25 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null \
    "https://bambodia.com$1"
}
health / && health /support-center || { echo "STOP: Public site health failed"; exit 2; }

STAGE="$(mktemp -d /root/bambodia-creator-analytics.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
finish() {
  local status="$?"
  trap - EXIT
  if [[ "$DEPLOYING" -eq 1 && -n "$BACKUP" ]]; then
    echo "ROLLBACK: Restore original Creator Studio code" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    if [[ -f "$STAGE/installed-new.list" ]]; then
      while IFS= read -r file; do rm -f -- "$SITE/$file"; done < "$STAGE/installed-new.list"
    fi
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi) || true
    echo "IMPORTANT: Additive migration tables, if created, are retained; no customer data deleted." >&2
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$STAGE"
  exit "$status"
}
trap finish EXIT

download() {
  local commit="$1" file="$2" output="$3" attempt
  for attempt in 1 2 3 4 5; do
    if curl -fLsS --connect-timeout 12 --max-time 120 \
      "https://raw.githubusercontent.com/$REPO/$commit/$file" -o "$output"; then
      return 0
    fi
    rm -f -- "$output"
    if [[ "$attempt" -lt 5 ]]; then sleep "$((attempt * 2))"; fi
  done
  echo "STOP: Download failed $file" >&2
  return 1
}

mkdir -p "$STAGE"/{base,target,candidate}
cat > "$STAGE/manifest" <<'MANIFEST'
app/Http/Controllers/AccountController.php|665d9dc589ba329f3bcfc43c52c348f782bf43d2|cf7d32d71b33584f8c2be04911fba8072d8c2f94
app/Http/Controllers/Creator/StudioAnalyticsController.php|NEW|147a68421a6295a829384541b8944fd2c3d83cfa
app/Support/CreatorStudioAnalytics.php|NEW|961d7220daa0e58aca802fdcd142ae8a26017755
database/migrations/2026_11_25_010000_create_creator_studio_analytics_tables.php|NEW|727494cc0e4e01cae9e92ae15c9fab164f3c8b88
public/css/creator-studio-analytics.css|NEW|eb398dae460bcf4b071e46e887c4803fde076774
public/js/creator-studio-analytics.js|NEW|ac2c82e555319ee89fd0e12845944fdf9558a6c9
resources/views/account/studio.blade.php|ff7abb27853a22a1dd4ef374a36b5cefd8b4df1b|47cf0056e7eea43b0cdafecdbcbd76995ccddc81
resources/views/account/partials/studio-performance.blade.php|NEW|32fcf4094cfe03174a679ca5030deac659797d01
resources/views/account/partials/studio-projects.blade.php|NEW|9af961039d7205d62414d89f30fe446ed80d19fa
resources/views/account/partials/studio-tracking.blade.php|NEW|43a4bc730a50fbed0ba480043abfad3f733b42fb
routes/web.php|198146f5b3e6bb38b64aadaa10a511421abdb911|a69726f90753eb50a118eca66873d9ce1bd2e7ab
MANIFEST

: > "$STAGE/changed.list"
: > "$STAGE/existing.list"
: > "$STAGE/installed-new.list"
: > "$STAGE/live.list"
echo "=== Verify pinned sources and live compatibility ==="
while IFS='|' read -r file base_sha target_sha; do
  [[ "$file" != /* && "$file" != *..* ]] || { echo "STOP: Unsafe path"; exit 2; }
  mkdir -p "$STAGE/target/$(dirname "$file")" "$STAGE/candidate/$(dirname "$file")"
  download "$TARGET" "$file" "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/target/$file")" == "$target_sha" ]] ||
    { echo "STOP: Target file SHA mismatch: $file"; exit 2; }

  if [[ "$base_sha" == "NEW" ]]; then
    if [[ -e "$SITE/$file" || -L "$SITE/$file" ]]; then
      [[ -f "$SITE/$file" && ! -L "$SITE/$file" &&
         "$(git hash-object "$SITE/$file")" == "$target_sha" ]] ||
        { echo "STOP: Unexpected existing new file $file"; exit 2; }
      echo "PASS: Already installed $file"
    else
      echo "$file" >> "$STAGE/changed.list"
      echo "PASS: New file verified $file"
    fi
    continue
  fi

  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: Missing or symlinked original $file"; exit 2; }
  mkdir -p "$STAGE/base/$(dirname "$file")"
  download "$BASE" "$file" "$STAGE/base/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$base_sha" ]] ||
    { echo "STOP: Base file SHA mismatch $file"; exit 2; }
  printf '%s\n' "$file" >> "$STAGE/existing.list"
  livehash="$(git hash-object "$SITE/$file")"
  printf '%s|%s\n' "$file" "$livehash" >> "$STAGE/live.list"
  cp -p -- "$SITE/$file" "$STAGE/candidate/$file"
  if [[ "$livehash" == "$target_sha" ]]; then
    echo "PASS: Already installed $file"
    continue
  fi

  if [[ "$livehash" == "$base_sha" ]]; then
    cp -- "$STAGE/target/$file" "$STAGE/candidate/$file"
  else
    python3 - "$STAGE" "$file" <<'PY_PATCH'
from difflib import unified_diff
from pathlib import Path
import subprocess
import sys
import tempfile

stage=Path(sys.argv[1]); rel=sys.argv[2]
base=(stage/"base"/rel).read_text()
target=(stage/"target"/rel).read_text()
live=stage/"candidate"/rel
diff="".join(unified_diff(
    base.splitlines(keepends=True),target.splitlines(keepends=True),
    fromfile="a/"+rel,tofile="b/"+rel,n=3
))
if not diff:
    raise SystemExit("STOP: Missing changes for "+rel)
with tempfile.TemporaryDirectory(prefix="bambodia-creator-patch-") as tmp:
    from shutil import copyfile
    dest=Path(tmp)/rel
    dest.parent.mkdir(parents=True,exist_ok=True)
    copyfile(live,dest)
    result=subprocess.run(
        ["patch","--batch","--forward","--fuzz=0","--no-backup-if-mismatch",
         "-p1","-d",tmp],input=diff,text=True,capture_output=True
    )
    if result.returncode != 0:
        raise SystemExit("STOP: Incompatible live file "+rel+"\n"+result.stdout+result.stderr)
    copyfile(dest,live)
PY_PATCH
  fi
  echo "$file" >> "$STAGE/changed.list"
  echo "PASS: Compatible $file"
done < "$STAGE/manifest"

for file in \
  app/Http/Controllers/AccountController.php \
  app/Http/Controllers/Creator/StudioAnalyticsController.php \
  app/Support/CreatorStudioAnalytics.php \
  routes/web.php \
  "$MIGRATION"
do
  "$PHP" -l "$STAGE/candidate/$file" 2>/dev/null ||
  "$PHP" -l "$STAGE/target/$file" ||
    { echo "STOP: PHP syntax error: $file"; exit 2; }
done
if command -v node >/dev/null; then
  node --check "$STAGE/target/public/js/creator-studio-analytics.js"
fi
echo "PASS: SHA, exact-context compatibility and PHP/JS syntax"
echo "Files requiring installation: $(wc -l < "$STAGE/changed.list")"
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT ONLY: No site files or database changed"
  echo "REMINDER: Create a fresh aaPanel database backup before --apply"
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-creator-analytics-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/existing.list"
while IFS='|' read -r file livehash; do
  [[ "$(git hash-object "$SITE/$file")" == "$livehash" ]] ||
    { echo "STOP: Production file changed during preflight: $file"; exit 2; }
done < "$STAGE/live.list"
echo "Backup: $BACKUP/original-code.tar"
DEPLOYING=1
while IFS= read -r file; do
  destination="$SITE/$file"
  mkdir -p -- "$(dirname "$destination")"
  if [[ ! -e "$destination" ]]; then
    echo "$file" >> "$STAGE/installed-new.list"
  fi
  temp="$(mktemp "$(dirname "$destination")/.creator-studio.XXXXXXXX")"
  source="$STAGE/candidate/$file"
  if [[ ! -f "$source" ]]; then source="$STAGE/target/$file"; fi
  install -o www -g www -m 0644 "$source" "$temp"
  mv -f -- "$temp" "$destination"
done < "$STAGE/changed.list"

echo "=== Run additive Creator Studio migration ==="
(cd "$SITE" && runuser -u www -- "$PHP" artisan migrate \
  --path="$MIGRATION" --force --no-interaction --no-ansi)
echo "=== Clear compiled views and route cache ==="
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi)
health / && health /support-center ||
  { echo "STOP: Site health failed; restoring original code"; exit 1; }
DEPLOYING=0
echo "SUCCESS: Live Creator Studio analytics installed"
echo "RETAIN BACKUP: $BACKUP/original-code.tar"
echo "Additive creator-owned tables created; no existing orders or contracts altered"
