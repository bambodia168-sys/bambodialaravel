#!/usr/bin/env bash
# SHA-pinned, fail-closed private chat deployment with exact-context live drift merge.
# Only the approved audit/route insertions are staged onto two exact live blob SHAs.
# No customer/order/payment/header/sidebar data is rewritten by this reconciliation.
set -Eeuo pipefail
set +x
umask 077
[[ $# -eq 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] || { echo 'Usage: --preflight|--apply' >&2; exit 2; }
[[ "$(id -u)" == "0" ]] || { echo 'STOP: Root required' >&2; exit 2; }
MODE="$1"
REPO="bambodia168-sys/bambodialaravel"
TARGET="2c7a7759a3f83be97ff98896ded31881cfddd330"
BASE="3b617dd52579faaefc8fabf5875b1a95ffda25da"
RECONCILE_REF="46a5cb07766132dab5ae33812a081aabe8dec0c8"
RECONCILE_SHA="31d294e095ec1ff11d89b181126e4ab41741dfe2"
AUDIT_DRIFT="93517dabee01c959abc1029e4a7eae974ffedf49"
ROUTES_DRIFT="87a1b68023d2d796be095a732f74effe4360b2e0"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MIGRATION="database/migrations/2026_11_25_220000_add_tracking_chat_attachments_and_one_time_notes.php"
for cmd in curl git tar install mktemp runuser python3; do command -v "$cmd" >/dev/null || exit 2; done
[[ -x "$PHP" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && ! -L "$SITE" ]] || { echo 'STOP: Runtime mismatch' >&2; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" || { echo 'STOP: Unexpected APP_ENV' >&2; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo 'STOP: Wrong Nginx document root' >&2; exit 2; }
STAGE="$(mktemp -d /root/bambodia-chat-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$DEPLOYING" == 1 ]]; then
    echo 'ROLLBACK: Restore original code; retain additive schema' >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    while IFS= read -r file; do [[ -z "$file" ]] || rm -f -- "$SITE/$file"; done < "$STAGE/new-installed"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi) || true
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$STAGE"
  exit "$code"
}
trap finish EXIT
mkdir -p "$STAGE/target" "$STAGE/base" "$STAGE/candidates"
: > "$STAGE/replaced"
: > "$STAGE/new-installed"
: > "$STAGE/install-list"
: > "$STAGE/live-hashes"
cat > "$STAGE/manifest" <<'MANIFEST'
app/Http/Controllers/ProjectTrackingController.php|1db6f4d3e07aaa5c65ed647186bab16ee82cacd2|c16576e5ee99cd78e99a42ce0a8b9c3710638211
app/Http/Controllers/TrackingConversationController.php|NEW|fc98ef4d0fde892ff37250d4a5618fbbaa4b5d33
app/Models/ServiceInquiryMessage.php|e8e80b909b5446199d351268ce703a894c776be7|db039d9fd0f09be74459bcb16babe832700c4d85
app/Models/TrackingMessageAttachment.php|NEW|592d9123c751606c6472ad46a52733428333a29c
app/Models/TrackingOneTimeNote.php|NEW|c6f5e68ad163fc2aa016d53951d9772659bcab1f
app/Models/AuditEvent.php|3b0c02c7f331178fe4abdb1acd2bc895bb2f2b89|478ab1fd7d6ed75028aca3e64ced4a572f286a27
app/Support/VerifiedTrackingOrder.php|NEW|9cefcbeda4a79a114c7613183ebfa2e00baac7ee
database/migrations/2026_11_25_220000_add_tracking_chat_attachments_and_one_time_notes.php|NEW|214a1027aec88bcf1405a202f0d5750df81e6b76
public/css/project-tracking.css|b531c1aa45c02c491df20cd1276355b54bece18e|694aca53315bb0e9093fcc8a8f68e706d3eed3b5
public/js/tracking-conversation.js|NEW|6c77fcfb65824c25ae2c94c9db41a7662fb1b4b6
resources/views/inquiries/show.blade.php|d0e9812a4a5b4813227df74602b18c597d90f9e2|8cc471b1a23f341e47e3eb65511a7abf91edacad
resources/views/tracking/show.blade.php|c9ecf0ffe05909665ccfae570b5103039e4c0839|ce1f2b9edd865755bb1beef129a090d5643141f4
resources/views/tracking/partials/message.blade.php|NEW|bfd0815d02d57f3d19e0d9563b6443d90c73390a
resources/views/tracking/partials/one-time-note.blade.php|NEW|6cd886889e0bd1c52822e9ccfd84039017ce3b96
routes/web.php|58ee4b4b2c663b445b0a4dfa11e326ceda84c2a9|06500fdb62a395cd15795a52e3ab6335cbd92953
MANIFEST

# Pin the reconciliation helper to an immutable, verified GitHub blob.
curl -fLsS --retry 3 --retry-delay 2 --connect-timeout 12 --max-time 120 \
  "https://raw.githubusercontent.com/$REPO/$RECONCILE_REF/support/project-tracking-chat-reconcile.py" \
  -o "$STAGE/reconcile.py"
[[ "$(git hash-object "$STAGE/reconcile.py")" == "$RECONCILE_SHA" ]] ||
  { echo "STOP: Reconciliation helper integrity mismatch" >&2; exit 2; }
python3 "$STAGE/reconcile.py" --self-test

echo '=== Verify source hashes and exact live compatibility ==='
while IFS='|' read -r file oldsha newsha; do
  [[ "$file" != /* && "$file" != *..* ]] || { echo 'STOP: Unsafe path' >&2; exit 2; }
  mkdir -p "$STAGE/target/$(dirname "$file")"
  curl -fLsS --retry 3 --retry-delay 2 --connect-timeout 12 --max-time 120 \
    "https://raw.githubusercontent.com/$REPO/$TARGET/$file" -o "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/target/$file")" == "$newsha" ]] ||
    { echo "STOP: Source hash mismatch $file" >&2; exit 2; }
  [[ ! -L "$SITE/$file" ]] || { echo "STOP: Symlinked live file $file" >&2; exit 2; }
  if [[ -f "$SITE/$file" ]]; then
    actual="$(git hash-object "$SITE/$file")"
    special=""
    if [[ "$file" == "app/Models/AuditEvent.php" && "$actual" == "$AUDIT_DRIFT" ]]; then
      special="audit"
    elif [[ "$file" == "routes/web.php" && "$actual" == "$ROUTES_DRIFT" ]]; then
      special="routes"
    fi
    [[ "$actual" == "$oldsha" || "$actual" == "$newsha" || -n "$special" ]] ||
      { echo "STOP: Unapproved live file SHA at $file ($actual)" >&2; exit 2; }
    printf '%s|%s\n' "$file" "$actual" >> "$STAGE/live-hashes"
    [[ "$actual" == "$newsha" ]] && { echo "PASS: Already current $file"; continue; }
    if [[ -n "$special" ]]; then
      mkdir -p "$STAGE/base/$(dirname "$file")" "$STAGE/candidates/$(dirname "$file")"
      curl -fLsS --retry 3 --retry-delay 2 --connect-timeout 12 --max-time 120 \
        "https://raw.githubusercontent.com/$REPO/$BASE/$file" -o "$STAGE/base/$file"
      [[ "$(git hash-object "$STAGE/base/$file")" == "$oldsha" ]] ||
        { echo "STOP: Original release base changed for $file" >&2; exit 2; }
      python3 "$STAGE/reconcile.py" \
        --kind "$special" --live "$SITE/$file" --base "$STAGE/base/$file" \
        --approved "$STAGE/target/$file" --output "$STAGE/candidates/$file"
      # The downloaded SHA-pinned approved file has already been verified.
      # Replace only the ephemeral staging candidate; never edit live in preflight.
      mv -f -- "$STAGE/candidates/$file" "$STAGE/target/$file"
      echo "PASS: Preserved live $file; staged only the approved additions"
    fi
    echo "$file" >> "$STAGE/replaced"
  else
    [[ "$oldsha" == "NEW" ]] || { echo "STOP: Missing original $file" >&2; exit 2; }
  fi
  echo "$file" >> "$STAGE/install-list"
  echo "PASS: Compatible $file"
done < "$STAGE/manifest"
while IFS='|' read -r file oldsha newsha; do
  case "$file" in *.php) "$PHP" -l "$STAGE/target/$file" >/dev/null || exit 2;; esac
done < "$STAGE/manifest"
if command -v node >/dev/null; then node --check "$STAGE/target/public/js/tracking-conversation.js"; fi
echo 'PASS: PHP/JS syntax and exact-context preservation of live modifications'
echo "Files to install: $(wc -l < "$STAGE/install-list")"
if [[ "$MODE" == "--preflight" ]]; then
  echo 'PREFLIGHT ONLY: No production files or database changed'
  echo 'Before --apply, verify a fresh aaPanel DB backup and set BAMBODIA_TRACKING_DB_BACKUP to its absolute path'
  exit 0
fi
# Apply requires explicit external backup, created and verified by operator.
DB_BACKUP="$(printenv BAMBODIA_TRACKING_DB_BACKUP || true)"
[[ -n "$DB_BACKUP" && -f "$DB_BACKUP" && -s "$DB_BACKUP" && ! -L "$DB_BACKUP" ]] ||
  { echo 'STOP: Valid BAMBODIA_TRACKING_DB_BACKUP required' >&2; exit 2; }
echo 'PASS: Nonempty database backup file present (restore validity requires separate verification)'
BACKUP="$(mktemp -d /root/bambodia-tracking-chat-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/replaced"
while IFS='|' read -r file sha; do
  [[ "$(git hash-object "$SITE/$file")" == "$sha" ]] ||
    { echo "STOP: Concurrent live changes $file" >&2; exit 2; }
done < "$STAGE/live-hashes"
echo "CODE_BACKUP: $BACKUP/original-code.tar"
DEPLOYING=1
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  dst="$SITE/$file"
  dir="$(dirname "$dst")"
  if [[ ! -e "$dir" ]]; then install -d -o www -g www -m 0755 -- "$dir"; fi
  [[ -d "$dir" && ! -L "$dir" ]] || { echo "STOP: Unsafe directory $dir" >&2; exit 2; }
  [[ -f "$dst" ]] || echo "$file" >> "$STAGE/new-installed"
  tmp="$(mktemp "$dir/.tracking-chat.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/target/$file" "$tmp"
  mv -f -- "$tmp" "$dst"
done < "$STAGE/install-list"
echo '=== Apply additive schema ==='
(cd "$SITE" && runuser -u www -- "$PHP" artisan migrate --path="$MIGRATION" --force --no-interaction --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi)
echo '=== Verify Laravel views and database under www ==='
(cd "$SITE" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(["tracking.show","tracking.partials.message","tracking.partials.one-time-note"] as $v){
  if(!view()->exists($v)){fwrite(STDERR,"Missing view ".$v.PHP_EOL);exit(1);}
}
foreach(["tracking_message_attachments","tracking_one_time_notes"] as $t){
  if(!\Illuminate\Support\Facades\Schema::hasTable($t)){fwrite(STDERR,"Missing table ".$t.PHP_EOL);exit(1);}
}
if(!\Illuminate\Support\Facades\Schema::hasColumn("service_inquiry_messages","content_format"))exit(1);
if(!\Illuminate\Support\Facades\Route::has("tracking.conversation.send"))exit(1);
if(!\Illuminate\Support\Facades\Route::has("tracking.one-time.open"))exit(1);
echo "PASS: Views, tables, and routes available".PHP_EOL;
')
for path in / /support-center; do
  curl -4 -fLsS --connect-timeout 10 --max-time 25 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null "https://bambodia.com$path"
done
DEPLOYING=0
echo 'SUCCESS: Private realtime tracking chat and one-time notes installed'
echo 'PRESERVED: unrelated live AuditEvent.php and routes/web.php changes'
echo "RETAIN CODE BACKUP: $BACKUP/original-code.tar"
echo 'No changes to header, sidebar, payments, contracts or existing order records'
