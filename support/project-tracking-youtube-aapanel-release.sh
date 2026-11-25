#!/usr/bin/env bash
# Bambodia Tracking Chat YouTube: SHA-pinned code-only release; no DB migration.
# Preserves all messages, attachments, orders, and shared navigation.
set -Eeuo pipefail
set +x
umask 077

[[ $# -eq 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] || {
  echo 'Usage: sudo bash project-tracking-youtube-aapanel-release.sh --preflight|--apply' >&2; exit 2;
}
[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: Root privileges required' >&2; exit 2; }
MODE="$1"
REPO="bambodia168-sys/bambodialaravel"
TARGET="7ae0d1c80fa3f8f2d2f0a64928f7c25cf2f88000"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
for cmd in git curl tar install mktemp runuser; do
  command -v "$cmd" >/dev/null || { echo "STOP: Missing $cmd" >&2; exit 2; }
done
[[ -x "$PHP" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && ! -L "$SITE" ]] ||
  { echo 'STOP: Unexpected Laravel site runtime' >&2; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo 'STOP: Not verified as production' >&2; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo 'STOP: Nginx document root does not match' >&2; exit 2; }

WORK="$(mktemp -d /root/bambodia-tracking-youtube-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
rollback() {
  status=$?
  trap - EXIT
  if [[ "$DEPLOYING" == "1" ]]; then
    echo 'ROLLBACK: Restore previous tracking chat code' >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    while IFS= read -r path; do
      [[ -z "$path" ]] || rm -f -- "$SITE/$path"
    done < "$WORK/new-installed"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN CODE BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$WORK"
  exit "$status"
}
trap rollback EXIT

mkdir -p "$WORK/target"
: > "$WORK/changed"
: > "$WORK/replaced"
: > "$WORK/new-installed"
: > "$WORK/observed"

cat > "$WORK/manifest" <<'MANIFEST'
app/Support/TrackingYouTubeContent.php|NEW|bb95c0848f1a0acf34f0613e24dd5cfa21df515e
app/Support/SupportMessageContent.php|d5ac4cbfb06d4f8b8155ce2ab26f9fe196078fcf|f16254fb2b651ac2c874c877f0462fde822c0ad4
app/Http/Controllers/TrackingConversationController.php|fc98ef4d0fde892ff37250d4a5618fbbaa4b5d33|e602a6c4a3a00b7e34d9214cad79cd177d7d095d
resources/views/tracking/partials/message.blade.php|62e23ed6811e38ce9e9b21ec3eccaafef793e666|d70fc3c46e1f07c7f5ac777048b13e5af3aadefc
public/js/support-ticket-editor.js|86736a07456d7f4c37d2ae53ca1b877216a06d73|f68c7a152472e62d27c628977c4b10511b5710c0
public/js/tracking-conversation.js|f6eb8852f08a8ec48c5b61a8f010d20eabbfe098|1775fced617b56be690794e6428caa4df25c9c38
public/css/project-tracking.css|c0eac514e73b3cb9a69ef1658b9307e5bc3ec65a|43abd264a384c1f6dac3d3f433b3835dffce5e4c
resources/views/tracking/show.blade.php|ec526dd1bdd2939f0d0591ae6510227e5656d585|fb7e0a3fb23ca64a77b41c01c13a4b0feaa6a208
MANIFEST

echo '=== Verify source blobs and compatibility ==='
while IFS='|' read -r path old_sha new_sha; do
  [[ -n "$path" && "$path" != /* && "$path" != *..* ]] ||
    { echo "STOP: Unsafe release path $path" >&2; exit 2; }
  destination="$SITE/$path"
  directory="$(dirname "$destination")"
  [[ -d "$directory" && ! -L "$directory" && ! -L "$destination" ]] ||
    { echo "STOP: Unsafe live file directory for $path" >&2; exit 2; }
  mkdir -p "$WORK/target/$(dirname "$path")"
  curl -fLsS --retry 3 --retry-delay 2 --connect-timeout 12 --max-time 120 \
    "https://raw.githubusercontent.com/$REPO/$TARGET/$path" \
    -o "$WORK/target/$path"
  [[ "$(git hash-object "$WORK/target/$path")" == "$new_sha" ]] ||
    { echo "STOP: Source SHA mismatch $path" >&2; exit 2; }
  if [[ -f "$destination" ]]; then
    live_sha="$(git hash-object "$destination")"
    [[ "$live_sha" == "$old_sha" || "$live_sha" == "$new_sha" ]] ||
      { echo "STOP: Unrelated live changes in $path ($live_sha)" >&2; exit 2; }
    runuser -u www -- test -r "$destination" ||
      { echo "STOP: PHP-FPM cannot read $path" >&2; exit 2; }
    printf '%s|%s\n' "$path" "$live_sha" >> "$WORK/observed"
    if [[ "$live_sha" != "$new_sha" ]]; then
      echo "$path" >> "$WORK/replaced"
      echo "$path" >> "$WORK/changed"
    fi
  else
    [[ "$old_sha" == "NEW" ]] ||
      { echo "STOP: Missing expected production file $path" >&2; exit 2; }
    echo "$path" >> "$WORK/changed"
  fi
  echo "PASS: Compatible $path"
done < "$WORK/manifest"

for file in \
  app/Support/TrackingYouTubeContent.php \
  app/Support/SupportMessageContent.php \
  app/Http/Controllers/TrackingConversationController.php; do
  "$PHP" -l "$WORK/target/$file"
done
if command -v node >/dev/null; then
  node --check "$WORK/target/public/js/support-ticket-editor.js"
  node --check "$WORK/target/public/js/tracking-conversation.js"
fi
echo 'PASS: PHP, JS and all 8 pinned file hashes'
echo "Files to update: $(wc -l < "$WORK/changed")"
if [[ "$MODE" == "--preflight" ]]; then
  echo 'PREFLIGHT ONLY: No production files, routes, or database changed'
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-tracking-youtube-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/replaced"
echo "CODE_BACKUP: $BACKUP/original-code.tar"
while IFS='|' read -r path observed; do
  [[ "$(git hash-object "$SITE/$path")" == "$observed" ]] ||
    { echo "STOP: Concurrent production change $path" >&2; exit 2; }
done < "$WORK/observed"

DEPLOYING=1
while IFS= read -r path; do
  [[ -z "$path" ]] && continue
  destination="$SITE/$path"
  [[ -f "$destination" ]] || echo "$path" >> "$WORK/new-installed"
  temp="$(mktemp "$(dirname "$destination")/.tracking-youtube.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$temp"
  mv -f -- "$temp" "$destination"
done < "$WORK/changed"

echo '=== Clear compiled views ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
echo '=== Check YouTube parser, Laravel views, chat routes ==='
(cd "$SITE" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$id=\App\Support\TrackingYouTubeContent::videoId("https://www.youtube.com/watch?v=p2UE5ZVUcQc");
if ($id!=="p2UE5ZVUcQc") exit(1);
$html=\App\Support\TrackingYouTubeContent::display("<p>https://youtu.be/p2UE5ZVUcQc</p>",true);
if (!str_contains($html,"/embed/p2UE5ZVUcQc")
    || !str_contains($html,"strict-origin-when-cross-origin")) exit(1);
foreach (["tracking.show","tracking.partials.message"] as $view) {
  if (!view()->exists($view)) exit(1);
}
if (!\Illuminate\Support\Facades\Route::has("tracking.conversation.send")
    || !\Illuminate\Support\Facades\Route::has("tracking.conversation.messages")) exit(1);
echo "PASS: YouTube parsing, preview, views, and private chat routes".PHP_EOL;
')
for path in / /support-center; do
  curl -4 -fLsS --connect-timeout 10 --max-time 25 \
    --resolve bambodia.com:443:127.0.0.1 \
    -o /dev/null "https://bambodia.com$path"
done
DEPLOYING=0
echo 'SUCCESS: Bambodia YouTube chat previews and media-only sending installed'
echo "RETAIN CODE BACKUP: $BACKUP/original-code.tar"
echo 'No migrations, payments, order records, messages, header or sidebar changed'
