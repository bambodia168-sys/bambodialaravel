#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=e47b7fbc604f9ff9d1c98c2eba313dd3e3cd780f
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
[[ -f "$SITE/artisan" && -x "$PHP" && ! -L "$SITE" ]]
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
exec 8>/run/lock/bambodia-creator-performance.lock
flock -n 8
WORK="$(mktemp -d /root/bambodia-header-cart-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original cart assets" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || { echo "ROLLBACK FAILED: $BACKUP" >&2; code=1; }
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
echo "=== Header cart icon release 206: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/206" "$WORK/pr.json"
for run in 45983378677 45983378868 45983378815 45983378761 45983378714; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import pathlib,json,re,sys
work,head,mode=pathlib.Path(sys.argv[1]),sys.argv[2],sys.argv[3]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main':
    sys.exit('STOP: reviewed PR changed')
ready=True
for run in (45983378677,45983378868,45983378815,45983378761,45983378714):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}",file=sys.stderr)
    ready=ready and data['head_sha']==head and data['status']=='completed' and data['conclusion']=='success'
merged=pr.get('merged') is True
print(f"All CI successful: {ready}; PR merged: {merged}",file=sys.stderr)
if mode=='--apply' and not (ready and merged):
    sys.exit('STOP: CI or merge gate not satisfied; no files installed')
release=pr.get('merge_commit_sha') if ready and merged else head
if not isinstance(release,str) or not re.fullmatch(r'[0-9a-f]{40}',release):
    sys.exit('STOP: invalid release SHA')
print(release)
PY
)"
[[ "$RELEASE" == "934778ae5340aed8dc46204b6b08976be72ec143" ]]
cat > "$WORK/manifest" <<'MANIFEST'
public/css/storefront-mini-cart.css|c1dcf0f33108c6125ae05252ec16344aefc9ac66|3fcd083f220626142efc6a2b4dfdfd10167ce4aa
resources/views/layouts/partials/mini-cart-assets.blade.php|6575dd6c2e4507a8a68b7c7e10eed7335090a1fb|c3445d4c8589152adda22fdb46509cb52347d1b3
MANIFEST
touch "$WORK/changed" "$WORK/observed"
while IFS='|' read -r file old new; do
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" && ! -L "$(dirname "$SITE/$file")" ]]
  mkdir -p "$WORK/target/$(dirname "$file")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" "$WORK/target/$file"
  [[ "$(git hash-object "$WORK/target/$file")" == "$new" ]]
  actual="$(git hash-object "$SITE/$file")"
  [[ "$actual" == "$old" || "$actual" == "$new" ]] || { echo "STOP: unexpected live template $file ($actual)" >&2; exit 1; }
  if [[ "$actual" != "$new" ]]; then
    echo "$file" >> "$WORK/changed"
    printf '%s|%s\n' "$file" "$actual" >> "$WORK/observed"
  fi
  echo "PASS: compatible $file"
done < "$WORK/manifest"
grep -Fq "storefront-mini-cart.css') }}?v=4" "$WORK/target/resources/views/layouts/partials/mini-cart-assets.blade.php"
grep -Fq "storefront-mini-cart.js') }}?v=3" "$WORK/target/resources/views/layouts/partials/mini-cart-assets.blade.php"
grep -Fq '.site-header .mini-cart > .mini-cart-trigger' "$WORK/target/public/css/storefront-mini-cart.css"
grep -Fq '.workspace-topbar .mini-cart > .mini-cart-trigger:focus-visible' "$WORK/target/public/css/storefront-mini-cart.css"
for file in resources/views/layouts/main.blade.php resources/views/layouts/workspace.blade.php resources/views/layouts/auth.blade.php resources/views/components/storefront-mini-cart.blade.php resources/views/auth/email-verified.blade.php resources/views/auth/verify-email.blade.php resources/views/commerce/cart.blade.php resources/views/account/studio.blade.php public/css/page-backgrounds.css public/js/storefront-mini-cart.js routes/web.php app/Support/SubmissionNotifications.php; do
  [[ -f "$SITE/$file" ]]
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$WORK/protected"
done
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file changed: $file" >&2; return 1; }
  done < "$WORK/protected"
}
verify_asset() {
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/storefront-mini-cart.css?v=4" -o "$WORK/live.css"
  [[ "$(git hash-object "$WORK/live.css")" == "3fcd083f220626142efc6a2b4dfdfd10167ce4aa" ]]
  echo "PASS: live cart stylesheet matches approved CSS"
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/" -o "$WORK/live.html"
  grep -Fq 'css/storefront-mini-cart.css?v=4' "$WORK/live.html"
  grep -Fq 'js/storefront-mini-cart.js?v=3' "$WORK/live.html"
  grep -Fq 'mini-cart-trigger' "$WORK/live.html"
  echo "PASS: public header loads version 4 CSS and retains cart trigger and JavaScript"
}
verify_protected
echo "PASS: existing global layouts, background system and cart behavior protected"
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION_FILES_MODIFIED=0"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  verify_asset
  echo "BAMBODIA_HEADER_CART_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-header-cart-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/changed"
echo "BACKUP=$BACKUP"
while IFS='|' read -r file actual; do
  [[ "$(git hash-object "$SITE/$file")" == "$actual" ]]
done < "$WORK/observed"
APPLYING=1
while IFS= read -r file; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.header-cart.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
  echo "INSTALLED: $file"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r file old new; do
  [[ "$(git hash-object "$SITE/$file")" == "$new" ]]
done < "$WORK/manifest"
verify_protected
verify_asset
echo "PASS: global layouts, background system, cart JavaScript, checkout and authentication unchanged"
APPLYING=0
echo "BAMBODIA_HEADER_CART_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only cart stylesheet and shared asset reference installed. Final authenticated visual acceptance still required."
