#!/usr/bin/env bash
# Guarded, code-only Baray customer UX release compatible with exact 2026-11-24 production route drift.
# Maintains all existing live routes and adds only the verified Baray status endpoint.
# No payment-setting or DB changes.
set -Eeuo pipefail
umask 077
[[ $# == 2 ]] || { echo "Usage: bash script --preflight|--apply 40-char-Git-commit"; exit 2; }
MODE="$1"; RELEASE="$2"
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
REPO=bambodia168-sys/bambodialaravel
[[ "$MODE" == "--preflight" || "$MODE" == "--apply" ]] || exit 2
command -v python3 >/dev/null || { echo "STOP: python3 is required"; exit 2; }
[[ "$RELEASE" =~ ^[0-9a-f]{40}$ ]] || exit 2
[[ "$(id -u)" == 0 ]] || { echo "Run via sudo"; exit 2; }
[[ -f "$SITE/.env" && -f "$SITE/artisan" && -d "$SITE/vendor" && -x "$PHP" ]] || { echo "Unexpected Bambodia path"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" || { echo "Not production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf || { echo "Unexpected nginx root"; exit 2; }
for cache in packages.php services.php; do
  runuser -u www -- test -r "$SITE/bootstrap/cache/$cache" || { echo "STOP: unreadable $cache"; exit 2; }
done
http() {
  curl -4 -sS --connect-timeout 8 --max-time 18 --resolve bambodia.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://bambodia.com/
}
[[ "$(http)" == 200 ]] || { echo "STOP: Bambodia origin not HTTP 200"; exit 2; }
STAGE="$(mktemp -d /root/bambodia-baray-on-site-stage.XXXXXXXX)"
BACKUP=""
INSTALLED=0
cleanup() {
  code="$?"
  trap - EXIT
  if [[ "$INSTALLED" == 1 ]]; then
    echo "ROLLBACK: restoring Bambodia payment UI files" >&2
    [[ -f "$BACKUP/original.tar" ]] && tar -C "$SITE" -xpf "$BACKUP/original.tar" || true
    while IFS= read -r file; do
      [[ -n "$file" ]] && rm -f -- "$SITE/$file" || true
    done < "$STAGE/new.list"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
  fi
  rm -rf -- "$STAGE"
  exit "$code"
}
trap cleanup EXIT
cat > "$STAGE/manifest" <<'FILES'
app/Http/Controllers/CommercePaymentController.php|0447095f09c3313127045a083b1ddecbaa408603|6594467bbd7ca92999121a874756b12acb2f0e14
routes/web.php|a835c364f69d7d4c7c5341ce1eedbf2d42f85203|7eb2f83618109e5a17e5feef50401fc6e7e7c30c
resources/views/commerce/payments.blade.php|34145c52ab3abca8ce3285a6879555f757aacfef|21b2803b896e667c8678b851d8ef5abd5a02623d
resources/views/commerce/show.blade.php|d97585a59567123359b101d2d008ffd2e42b2384|44daab1352e99fb066ad3d4f623b7ededba242fe
public/css/order-review.css|269b72397918b77bc886a4fec9a483e427da8b86|19e6833cddf848e96739b3ecb9d82b46378d48ac
public/js/commerce-baray-status.js|NEW|5536e026f9faf075fb41646bd791f7e24c5df4f2
FILES
: > "$STAGE/old.list"; : > "$STAGE/new.list"
while IFS='|' read -r file old new; do
  [[ ! -L "$SITE/$file" ]] || { echo "STOP: symlink $file"; exit 1; }
  if [[ "$old" == NEW ]]; then
    [[ ! -e "$SITE/$file" ]] || { echo "STOP: already installed $file"; exit 1; }
    echo "$file" >> "$STAGE/new.list"
  else
    [[ -f "$SITE/$file" ]] || { echo "STOP: missing $file"; exit 1; }
    current="$(git hash-object "$SITE/$file")"
    [[ "$current" == "$old" ]] || { echo "STOP: live file drift at $file ($current != $old); no changes made"; exit 1; }
    echo "$file" >> "$STAGE/old.list"
  fi
  mkdir -p "$STAGE/$(dirname "$file")"
  curl -fsSL --retry 2 --connect-timeout 8 --max-time 45 \
    "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" -o "$STAGE/$file"
  [[ "$(git hash-object "$STAGE/$file")" == "$new" ]] || { echo "STOP: downloaded blob mismatch: $file"; exit 1; }
  # routes/web.php has additional live-only features. Preserve that exact reviewed
  # production file and insert ONLY the Baray status endpoint from the pinned release.
  if [[ "$file" == "routes/web.php" ]]; then
    python3 - "$SITE/$file" "$STAGE/$file" <<'PY'
from pathlib import Path
import sys

live_path, canonical_path = (Path(a) for a in sys.argv[1:])
live = live_path.read_bytes()
canonical = canonical_path.read_bytes()
anchor = (b"        Route::get('/{number}/payments/{payment}/return', [CommercePaymentController::class, 'returned'])\n"
          b"            ->whereUuid(['number', 'payment'])->name('payment.return');\n")
addition = (b"        Route::get('/{number}/payments/{payment}/baray-status', [CommercePaymentController::class, 'barayStatus'])\n"
            b"            ->whereUuid(['number', 'payment'])->middleware('throttle:30,1')->name('payment.baray-status');\n")
if canonical.count(anchor + addition) != 1:
    raise SystemExit("STOP: pinned release route signature changed")
if live.count(anchor) != 1 or b"baray-status" in live or b"barayStatus" in live:
    raise SystemExit("STOP: unexpected live Baray route state; no changes made")
patched = live.replace(anchor, anchor + addition, 1)
if patched.replace(anchor + addition, anchor, 1) != live:
    raise SystemExit("STOP: route insertion integrity mismatch")
canonical_path.write_bytes(patched)
print("PASS: preserved live routes; only Baray payment-status endpoint inserted")
PY
  fi
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    "$PHP" -l "$STAGE/$file" >/dev/null || { echo "STOP: PHP syntax $file"; exit 1; }
  fi
done < "$STAGE/manifest"
if command -v node >/dev/null; then node --check "$STAGE/public/js/commerce-baray-status.js"; fi
echo "PASS: exact reviewed source, PHP/JS syntax, production files, cache permissions and HTTP 200."
if [[ "$MODE" == "--preflight" ]]; then echo "No live changes made."; exit 0; fi
BACKUP="$(mktemp -d /root/bambodia-baray-on-site-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original.tar" -T "$STAGE/old.list"
echo "Backup: $BACKUP/original.tar"
while IFS='|' read -r file old new; do
  if [[ "$old" == NEW ]]; then
    [[ ! -e "$SITE/$file" ]] || exit 1
  else
    [[ "$(git hash-object "$SITE/$file")" == "$old" ]] || { echo "STOP: file changed after preflight: $file"; exit 1; }
  fi
done < "$STAGE/manifest"
INSTALLED=1
while IFS='|' read -r file old new; do
  dest="$SITE/$file"
  mkdir -p "$(dirname "$dest")"
  tmp="$(mktemp "$(dirname "$dest")/.baray-ui.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/$file" "$tmp"
  mv -f "$tmp" "$dest"
  runuser -u www -- test -r "$dest" || exit 1
done < "$STAGE/manifest"
(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --name=account.orders.payment.baray-status --no-ansi) |
  grep -q 'baray-status' || { echo "STOP: status route missing"; exit 1; }
[[ "$(http)" == 200 ]] || { echo "STOP: Bambodia origin not HTTP 200; rolling back"; exit 1; }
for cache in packages.php services.php; do
  runuser -u www -- test -r "$SITE/bootstrap/cache/$cache" || exit 1
done
INSTALLED=0
echo "SUCCESS: Bambodia on-site order status with separate secure Baray checkout tab."
echo "Production-only developer, jobs, licenses and media routes preserved."
echo "Production payment settings, API credentials, orders and database were not changed."
echo "Keep the backup: $BACKUP/original.tar"
