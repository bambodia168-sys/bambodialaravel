#!/usr/bin/env bash
# Code-only, hash-pinned task-presets release for Bambodia Project Tracking.
# No migration, order write, route edit, or global layout/menu modification.
set -Eeuo pipefail
set +x
umask 077

[[ $# -eq 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] || {
  echo 'Usage: sudo bash project-tracking-presets-aapanel-release.sh --preflight|--apply' >&2
  exit 2
}
MODE="$1"
[[ "$(id -u)" == "0" ]] || { echo 'STOP: Root is required' >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="34a99448cba619c69801726939faccf1cccc0976"
TARGET="21a5299439924a5c3c1cd1fa12e0dd6e1390cd74"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"

for cmd in git curl python3 runuser tar install mktemp; do
  command -v "$cmd" >/dev/null || { echo "STOP: Missing $cmd" >&2; exit 2; }
done
[[ -x "$PHP" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && ! -L "$SITE" ]] ||
  { echo 'STOP: Unexpected Bambodia Laravel runtime' >&2; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo 'STOP: Production environment not verified' >&2; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo 'STOP: Unexpected Nginx site root' >&2; exit 2; }
for file in \
  resources/views/tracking/index.blade.php \
  resources/views/tracking/show.blade.php \
  public/css/project-tracking.css \
  public/js/project-tracking.js
do
  runuser -u www -- test -r "$SITE/$file" ||
    { echo "STOP: Existing tracking file inaccessible to PHP-FPM: $file" >&2; exit 2; }
done

WORK="$(mktemp -d /root/bambodia-tracking-presets.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
rollback_on_failure() {
  status=$?
  trap - EXIT
  if [[ "$DEPLOYING" -eq 1 ]]; then
    echo 'ROLLBACK: restoring original Project Tracking code' >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    while IFS= read -r file; do
      [[ -z "$file" ]] || rm -f -- "$SITE/$file"
    done < "$WORK/new-files.list"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$WORK"
  exit "$status"
}
trap rollback_on_failure EXIT
mkdir -p "$WORK"/{target,base,original}
: > "$WORK/new-files.list"
: > "$WORK/replaced-files.list"
: > "$WORK/changed-files.list"
: > "$WORK/live-hashes.list"

cat > "$WORK/manifest" <<'MANIFEST'
app/Support/TrackingTaskPresets.php|NEW|c0c456b3401c1d1b3690ae970e3230b227de648f
resources/views/tracking/show.blade.php|b1d11148468dd8008c294c693ca8f1b38832a3b2|9b976f9bf97d1dd912cf70a1df8ebef82dab4ca2
public/js/project-tracking.js|a3368f45ab66af6301f67f479eb745593ef41fa8|1e8091b954460b60065e8dd2891bdc1c7a657ef2
public/css/project-tracking.css|63f0118d509c0a697b2a041bd2874c41e1721dfe|b531c1aa45c02c491df20cd1276355b54bece18e
MANIFEST

download() {
  local ref="$1" file="$2" destination="$3"
  curl -fLsS --retry 3 --retry-delay 2 \
    --connect-timeout 12 --max-time 120 \
    "https://raw.githubusercontent.com/$REPO/$ref/$file" \
    -o "$destination"
}

echo '=== Verify exact source hashes and production compatibility ==='
while IFS='|' read -r file old_sha new_sha; do
  [[ "$file" != /* && "$file" != *..* ]] ||
    { echo "STOP: Unsafe path: $file" >&2; exit 2; }
  mkdir -p "$WORK/target/$(dirname "$file")"
  download "$TARGET" "$file" "$WORK/target/$file"
  [[ "$(git hash-object "$WORK/target/$file")" == "$new_sha" ]] ||
    { echo "STOP: Source mismatch: $file" >&2; exit 2; }

  destination="$SITE/$file"
  [[ ! -L "$destination" ]] ||
    { echo "STOP: Unexpected symbolic link: $file" >&2; exit 2; }

  if [[ -f "$destination" ]]; then
    live_sha="$(git hash-object "$destination")"
    [[ "$live_sha" == "$old_sha" || "$live_sha" == "$new_sha" ]] ||
      { echo "STOP: Production file has unrelated edits: $file" >&2; exit 2; }
    printf '%s|%s\n' "$file" "$live_sha" >> "$WORK/live-hashes.list"
    if [[ "$live_sha" == "$new_sha" ]]; then
      echo "PASS: Already installed $file"
      continue
    fi
    echo "$file" >> "$WORK/replaced-files.list"
  else
    [[ "$old_sha" == "NEW" ]] ||
      { echo "STOP: Expected original file missing: $file" >&2; exit 2; }
    [[ -d "$(dirname "$destination")" && ! -L "$(dirname "$destination")" ]] ||
      { echo "STOP: Unexpected destination directory: $file" >&2; exit 2; }
    echo "$file" >> "$WORK/new-files.list"
  fi
  echo "$file" >> "$WORK/changed-files.list"
  echo "PASS: Compatible $file"
done < "$WORK/manifest"

"$PHP" -l "$WORK/target/app/Support/TrackingTaskPresets.php"
if command -v node >/dev/null; then
  node --check "$WORK/target/public/js/project-tracking.js"
fi

echo 'PASS: Code syntax and all 4 release source hashes'
echo "Files requiring installation: $(wc -l < "$WORK/changed-files.list")"

if [[ "$MODE" == "--preflight" ]]; then
  echo 'PREFLIGHT ONLY: Production files and database unchanged'
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-tracking-presets-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/replaced-files.list"
echo "Backup: $BACKUP/original-code.tar"
while IFS='|' read -r file hash; do
  [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] ||
    { echo "STOP: File changed during preflight: $file" >&2; exit 2; }
done < "$WORK/live-hashes.list"

DEPLOYING=1
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  dest="$SITE/$file"
  temp="$(mktemp "$(dirname "$dest")/.tracking-presets.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$file" "$temp"
  mv -f -- "$temp" "$dest"
done < "$WORK/changed-files.list"

echo '=== Validate Laravel view discovery as the web worker ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (["tracking.index", "tracking.show"] as $name) {
    if (!view()->exists($name)) {
        fwrite(STDERR, "STOP: View not found: ".$name.PHP_EOL);
        exit(1);
    }
}
if (!class_exists(\App\Support\TrackingTaskPresets::class)) {
    fwrite(STDERR, "STOP: Preset class unavailable".PHP_EOL);
    exit(1);
}
$groups = \App\Support\TrackingTaskPresets::groupsFor("freelance");
if (!isset($groups["Freelance planning & drafts"])) {
    fwrite(STDERR, "STOP: Preset library incomplete".PHP_EOL);
    exit(1);
}
echo "PASS: Tracking views and task presets load under PHP-FPM account".PHP_EOL;
')
for path in / /support-center; do
  curl -4 -fLsS --connect-timeout 10 --max-time 25 \
    --resolve bambodia.com:443:127.0.0.1 \
    -o /dev/null "https://bambodia.com$path"
done

DEPLOYING=0
echo 'SUCCESS: Premium Project Tracking milestone presets installed'
echo "RETAIN BACKUP: $BACKUP/original-code.tar"
echo 'CODE ONLY: No migration, payment, customer order or sidebar/header change'
