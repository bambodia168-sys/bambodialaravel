#!/usr/bin/env bash
# Bambodia Tracking Chat UI refresh: four verified code/static files, no DB migration.
set -Eeuo pipefail
set +x
umask 077

[[ $# == 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] || {
  echo 'Usage: sudo bash project-tracking-chat-ui-aapanel-release.sh --preflight|--apply' >&2
  exit 2
}
[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: Root privileges required' >&2; exit 2; }
MODE="$1"
REPO="bambodia168-sys/bambodialaravel"
TARGET="9910a936e788c24e9d19a2189edb1d87b402cd5a"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"

for cmd in git curl tar mktemp install runuser; do
  command -v "$cmd" >/dev/null || { echo "STOP: Missing $cmd" >&2; exit 2; }
done
[[ -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && -x "$PHP" && ! -L "$SITE" ]] ||
  { echo 'STOP: Unexpected Laravel runtime' >&2; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo 'STOP: Unexpected production environment' >&2; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo 'STOP: Unexpected Nginx site root' >&2; exit 2; }

WORK="$(mktemp -d /root/bambodia-chat-ui-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == "1" ]]; then
    echo 'ROLLBACK: Restore prior chat UI files' >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
mkdir -p "$WORK/target"
: > "$WORK/changed"
: > "$WORK/previous"
: > "$WORK/observed"
cat > "$WORK/manifest" <<'MANIFEST'
resources/views/tracking/show.blade.php|ce1f2b9edd865755bb1beef129a090d5643141f4|ec526dd1bdd2939f0d0591ae6510227e5656d585
resources/views/tracking/partials/message.blade.php|bfd0815d02d57f3d19e0d9563b6443d90c73390a|62e23ed6811e38ce9e9b21ec3eccaafef793e666
public/css/project-tracking.css|694aca53315bb0e9093fcc8a8f68e706d3eed3b5|c0eac514e73b3cb9a69ef1658b9307e5bc3ec65a
public/js/tracking-conversation.js|6c77fcfb65824c25ae2c94c9db41a7662fb1b4b6|f6eb8852f08a8ec48c5b61a8f010d20eabbfe098
MANIFEST

echo '=== Verify four approved source blobs and exact production compatibility ==='
while IFS='|' read -r path old_sha new_sha; do
  [[ "$path" != /* && "$path" != *..* ]] ||
    { echo 'STOP: Unsafe manifest entry' >&2; exit 2; }
  target="$SITE/$path"
  dir="$(dirname "$target")"
  [[ -f "$target" && ! -L "$target" && -d "$dir" && ! -L "$dir" ]] ||
    { echo "STOP: Unexpected live path $path" >&2; exit 2; }
  runuser -u www -- test -r "$target" ||
    { echo "STOP: PHP-FPM cannot read $path" >&2; exit 2; }
  mkdir -p "$WORK/target/$(dirname "$path")"
  curl -fLsS --retry 3 --retry-delay 2 --connect-timeout 12 --max-time 120 \
    "https://raw.githubusercontent.com/$REPO/$TARGET/$path" \
    -o "$WORK/target/$path"
  [[ "$(git hash-object "$WORK/target/$path")" == "$new_sha" ]] ||
    { echo "STOP: Mismatched approved source $path" >&2; exit 2; }
  live_sha="$(git hash-object "$target")"
  [[ "$live_sha" == "$old_sha" || "$live_sha" == "$new_sha" ]] ||
    { echo "STOP: Unrelated production changes found: $path ($live_sha)" >&2; exit 2; }
  printf '%s|%s\n' "$path" "$live_sha" >> "$WORK/observed"
  if [[ "$live_sha" != "$new_sha" ]]; then
    echo "$path" >> "$WORK/changed"
    echo "$path" >> "$WORK/previous"
    echo "PASS: Compatible $path"
  else
    echo "PASS: Already installed $path"
  fi
done < "$WORK/manifest"

if command -v node >/dev/null; then
  node --check "$WORK/target/public/js/tracking-conversation.js"
fi
"$PHP" -l "$WORK/target/resources/views/tracking/partials/message.blade.php" >/dev/null
echo 'PASS: Source integrity and syntax checks'
echo "Files needing update: $(wc -l < "$WORK/changed")"
if [[ "$MODE" == "--preflight" ]]; then
  echo 'PREFLIGHT ONLY: No production files, routes or database modified'
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-chat-ui-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/previous"
echo "CODE_BACKUP: $BACKUP/original-code.tar"
while IFS='|' read -r path observed_sha; do
  [[ "$(git hash-object "$SITE/$path")" == "$observed_sha" ]] ||
    { echo "STOP: File changed while preparing deployment: $path" >&2; exit 2; }
done < "$WORK/observed"
APPLYING=1
while IFS= read -r path; do
  [[ -z "$path" ]] && continue
  file="$SITE/$path"
  temp="$(mktemp "$(dirname "$file")/.tracking-chat-ui.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$temp"
  mv -f -- "$temp" "$file"
done < "$WORK/changed"

echo '=== Clear Laravel compiled views ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
echo '=== Verify views and unchanged private chat routes ==='
(cd "$SITE" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (["tracking.show","tracking.partials.message"] as $view) {
  if (!view()->exists($view)) { fwrite(STDERR,"Missing view ".$view.PHP_EOL);exit(1); }
}
if (!\Illuminate\Support\Facades\Route::has("tracking.conversation.messages")
    || !\Illuminate\Support\Facades\Route::has("tracking.conversation.send")) {
  fwrite(STDERR,"Chat routes not registered".PHP_EOL);exit(1);
}
echo "PASS: Chat templates and existing private routes resolve".PHP_EOL;
')
for path in / /support-center; do
  curl -4 -fLsS --connect-timeout 10 --max-time 25 \
    --resolve bambodia.com:443:127.0.0.1 \
    -o /dev/null "https://bambodia.com$path"
done
APPLYING=0
echo 'SUCCESS: Premium Tracking Chat UI polish installed'
echo "RETAIN CODE BACKUP: $BACKUP/original-code.tar"
echo 'No migrations, payments, messages, orders, routes or global navigation altered'
