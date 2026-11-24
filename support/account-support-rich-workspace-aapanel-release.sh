#!/usr/bin/env bash
# Guarded Bambodia private-account ticket release. Preserves all unrelated live routes.
set -Eeuo pipefail
umask 077
[[ $# == 2 ]] || { echo "Usage: bash script --preflight|--apply COMMIT"; exit 2; }
MODE="$1"; RELEASE="$2"
REPO="bambodia168-sys/bambodialaravel"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
[[ "$MODE" == --preflight || "$MODE" == --apply ]] || exit 2
[[ "$RELEASE" =~ ^[0-9a-f]{40}$ && "$(id -u)" == 0 ]] || { echo "STOP: invalid commit or not root"; exit 2; }
for binary in curl git python3 runuser tar; do command -v "$binary" >/dev/null || { echo "STOP: missing $binary"; exit 2; }; done
[[ -f "$SITE/.env" && -f "$SITE/artisan" && -d "$SITE/vendor" && -x "$PHP" ]] || { echo "STOP: unexpected production path"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" || { echo "STOP: not production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf || { echo "STOP: nginx root mismatch"; exit 2; }
for cache in packages.php services.php; do runuser -u www -- test -r "$SITE/bootstrap/cache/$cache" || exit 2; done
http() { curl -4 -sS --connect-timeout 8 --max-time 18 --resolve bambodia.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://bambodia.com/; }
[[ "$(http)" == 200 ]] || { echo "STOP: origin HTTP not 200"; exit 2; }
STAGE="$(mktemp -d /root/bambodia-support-stage.XXXXXXXX)"
BACKUP=""
INSTALLED=0
rollback() {
  result=$?
  trap - EXIT
  if [[ "$INSTALLED" == 1 ]]; then
    echo "ROLLBACK: restoring existing support files and production routes" >&2
    [[ -f "$BACKUP/original.tar" ]] && tar -C "$SITE" -xpf "$BACKUP/original.tar" || true
    while IFS= read -r file; do [[ -z "$file" ]] || rm -f -- "$SITE/$file"; done < "$STAGE/new.list"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
  fi
  rm -rf -- "$STAGE"
  exit "$result"
}
trap rollback EXIT
cat > "$STAGE/manifest" <<'FILES'
app/Http/Controllers/SupportController.php|adf8ca02ad2a3179e54b2d05f6f2d240e6b175b9|3a2c64b9cb3863a35719d85b4eedebbc5e303f93
app/Http/Controllers/Dashboard/SupportCenterController.php|ca525e5b05c2e170226f22321d7b740b08a2ec7c|86fabd66432c42494a049c3a4ba23c1a225606dc
resources/views/support/index.blade.php|db78f3f4d75f2b694b432c4888c4b55b697f8555|3c7b807e8c94ae3c49bd59f6194b20c5164d91c3
resources/views/support/show.blade.php|211efde572209ed000ba0e3c95f9a145c8a3e5d7|c9dc25bc00495452d9004dd119399595b2883d8f
resources/views/support/knowledge-index.blade.php|3b3ef2f4bb4ebf280805bc3960198c12f72a89aa|7510cdeaf3cb879b529b13b1f16e6e61342f50c0
resources/views/support/knowledge-show.blade.php|806bd0e54126cdb189412bd96b37a23f0ba7cd36|de156a4f29d980931174b50f5c77068e6e9fb0b9
resources/views/layouts/main.blade.php|c382c191c837b390f3006ff58dcc2bc372556a9d|84fae2c92a8d041fe04af94ee9328a521d1362a1
resources/views/layouts/workspace.blade.php|4623bff1409dc6a7af4a6aced15ebf8a095ac4fc|0d44042ecbe8dea80a2d9640c7a7fc64441e7068
app/Support/SupportMessageContent.php|NEW|d5ac4cbfb06d4f8b8155ce2ab26f9fe196078fcf
resources/views/support/editor.blade.php|NEW|eb28abc6fcb8c1acb0f5ae17e6a81c686a43b85a
resources/views/support/create.blade.php|NEW|e32f656b961c42454175eb843b347a81c538ab9a
public/js/support-ticket-editor.js|NEW|01c913578138a5be3f703e97b3fa91ae9f477fd6
public/css/support-tickets.css|NEW|8e4f1e9fe691303f0e432547e462325543bbe2a9
routes/web.php|PATCH|64b296d91647e1278733e8f7f89470d511d0494a
FILES

: > "$STAGE/old.list"; : > "$STAGE/new.list"
while IFS='|' read -r file old new; do
  [[ ! -L "$SITE/$file" ]] || { echo "STOP: symlink $file"; exit 1; }
  if [[ "$old" == NEW ]]; then
    [[ ! -e "$SITE/$file" ]] || { echo "STOP: already installed $file"; exit 1; }
    echo "$file" >> "$STAGE/new.list"
  else
    [[ -f "$SITE/$file" ]] || { echo "STOP: missing $file"; exit 1; }
    if [[ "$old" != PATCH ]]; then
      actual="$(git hash-object "$SITE/$file")"
      [[ "$actual" == "$old" ]] || { echo "STOP: live drift $file ($actual != $old); no changes made"; exit 1; }
    fi
    echo "$file" >> "$STAGE/old.list"
  fi
  mkdir -p "$STAGE/$(dirname "$file")"
  curl -fsSL --retry 2 --connect-timeout 8 --max-time 45 \
    "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" -o "$STAGE/$file"
  [[ "$(git hash-object "$STAGE/$file")" == "$new" ]] || { echo "STOP: source blob mismatch $file"; exit 1; }
  if [[ "$old" == PATCH ]]; then
    python3 - "$SITE/$file" "$STAGE/$file" <<'PY'
from pathlib import Path
import sys
live_path, canonical_path = map(Path, sys.argv[1:])
live = live_path.read_bytes()
canonical = canonical_path.read_bytes()
anchor = b"    Route::get('/support-center/tickets', [SupportController::class, 'index'])->name('support.tickets');"
addition = b"""    // Private support tickets live inside every signed-in role's account workspace.
    // Keep the historical routes below for existing notification links/bookmarks.
    Route::prefix('account/support-tickets')->name('account.support.')->group(function (): void {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('/create', [SupportController::class, 'create'])->name('create');
        Route::post('/', [SupportController::class, 'store'])->middleware('throttle:5,1,support-ticket-create:')->name('store');
        Route::get('/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('show');
        Route::post('/{ticket}/reply', [SupportController::class, 'reply'])
            ->whereNumber('ticket')->middleware('throttle:10,1')->name('reply');
    });
"""
if canonical.count(addition) != 1:
    raise SystemExit("STOP: canonical release route block changed")
if live.count(anchor) != 1 or b"account/support-tickets" in live or b"account.support." in live:
    raise SystemExit("STOP: unexpected live account route; no changes made")
patched = live.replace(anchor, addition + anchor, 1)
if patched.replace(addition + anchor, anchor, 1) != live:
    raise SystemExit("STOP: route patch failed to preserve production")
canonical_path.write_bytes(patched)
print("PASS: preserved production routes, inserted only private account-ticket routes")
PY
  fi
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    "$PHP" -l "$STAGE/$file" >/dev/null || { echo "STOP: PHP syntax $file"; exit 1; }
  fi
done < "$STAGE/manifest"
if command -v node >/dev/null; then node --check "$STAGE/public/js/support-ticket-editor.js"; fi
[[ "$(http)" == 200 ]] || { echo "STOP: Bambodia origin changed during review"; exit 1; }
echo "PASS: reviewed code integrity, PHP syntax, exact live-file guards, origin HTTP 200."
if [[ "$MODE" == --preflight ]]; then echo "PREFLIGHT ONLY: no live changes made."; exit 0; fi

BACKUP="$(mktemp -d /root/bambodia-support-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original.tar" -T "$STAGE/old.list"
echo "Backup: $BACKUP/original.tar"
while IFS='|' read -r file old new; do
  if [[ "$old" == NEW ]]; then
    [[ ! -e "$SITE/$file" ]] || { echo "STOP: new file appeared $file"; exit 1; }
  elif [[ "$old" == PATCH ]]; then
    cmp -s "$SITE/$file" <(tar -xOf "$BACKUP/original.tar" "$file") ||
      { echo "STOP: production route changed during review"; exit 1; }
  else
    [[ "$(git hash-object "$SITE/$file")" == "$old" ]] ||
      { echo "STOP: file changed during review: $file"; exit 1; }
  fi
done < "$STAGE/manifest"
INSTALLED=1
while IFS='|' read -r file old new; do
  dest="$SITE/$file"
  mkdir -p "$(dirname "$dest")"
  tmp="$(mktemp "$(dirname "$dest")/.support-release.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/$file" "$tmp"
  mv -f "$tmp" "$dest"
  runuser -u www -- test -r "$dest" || exit 1
done < "$STAGE/manifest"

(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json --name=account.support --no-ansi) |
  python3 -c "import json,sys; names={r.get('name') for r in json.load(sys.stdin)}; needed={'account.support.index','account.support.create','account.support.store','account.support.show','account.support.reply'}; assert needed.issubset(names), names"
[[ "$(http)" == 200 ]] || { echo "STOP: origin failed; rolling back"; exit 1; }
for cache in packages.php services.php; do runuser -u www -- test -r "$SITE/bootstrap/cache/$cache" || exit 1; done
INSTALLED=0
echo "SUCCESS: Public Support Center retained; private account tickets and rich editor installed."
echo "No database, credentials, payments or order records changed."
echo "Retain backup: $BACKUP/original.tar"
