#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=9b7a41125bb512f4167bc6518f92bcc8d4ffebec
BASE=9f43c6e7b1ae5777a04fe0780220d5d3b2b4e5a8
RELEASE=f41e07a63655c7da32773a53db3bdb96c57eed17
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == --preflight || "$MODE" == --apply ) ]]
[[ "$(id -u)" == 0 ]]
[[ -f "$SITE/artisan" && -x "$PHP" && ! -L "$SITE" ]]
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
exec 8>/run/lock/bambodia-creator-performance.lock
flock -n 8
WORK="$(mktemp -d /root/bambodia-page-background-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original layouts" >&2
    while IFS= read -r file; do rm -f -- "$SITE/$file" || code=1; done < "$WORK/new"
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || { echo "ROLLBACK FAILED: $BACKUP" >&2; code=1; }
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || code=1
    echo "RETAIN BACKUP=$BACKUP"
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
echo "=== Global page background release 205: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/205" "$WORK/pr.json"
for run in 45975329956 45975329610 45975329582 45975329922 45975329583; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
python3 - "$WORK" "$HEAD" "$RELEASE" <<'PY'
import pathlib,json,sys
work,head,release=pathlib.Path(sys.argv[1]),sys.argv[2],sys.argv[3]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main' or pr.get('merged') is not True or pr.get('merge_commit_sha')!=release:
    sys.exit('STOP: reviewed merge does not match release')
for run in (45975329956,45975329610,45975329582,45975329922,45975329583):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}")
    if data['head_sha']!=head or data['status']!='completed' or data['conclusion']!='success':
        sys.exit('STOP: CI gate not satisfied')
print("All CI successful: True; PR merged: True")
PY
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed" "$WORK/effective"
cat > "$WORK/manifest" <<'MANIFEST'
public/css/page-backgrounds.css|NEW|8e719f7bc86ed25c373af5930a874d425782fe11
resources/views/layouts/main.blade.php|5324169cc4777b325a78a06f775ed5d4bce17ef3|c5cf38b3a8bb06bf6a58b449ba0f9ecd931e10c9
resources/views/layouts/auth.blade.php|90ddf588d383c5e74df399c1097510cb45ec3296|eddc497135aa70b1ebde70e83096d82c898e21b5
MANIFEST
while IFS='|' read -r file old new; do
  target="$SITE/$file"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" && ! -L "$target" ]]
  mkdir -p "$WORK/target/$(dirname "$file")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" "$WORK/target/$file"
  [[ "$(git hash-object "$WORK/target/$file")" == "$new" ]]
  if [[ "$old" != NEW ]]; then
    [[ -f "$target" ]]
    fetch "https://raw.githubusercontent.com/$REPO/$BASE/$file" "$WORK/base"
    [[ "$(git hash-object "$WORK/base")" == "$old" ]]
    # Derive the exact two reviewed layout edits and prove the transformation
    # reproduces the approved GitHub blob before applying to live bytes.
    python3 - "$file" "$WORK/base" "$WORK/target/$file" "$target" <<'PY'
import pathlib,sys
file=sys.argv[1]
base,approved,live=map(pathlib.Path,sys.argv[2:])
b,a,l=base.read_bytes(),approved.read_bytes(),live.read_bytes()
asset=b'  <link rel="stylesheet" href="{{ asset(\'css/page-backgrounds.css\') }}?v=1">\n'
if file.endswith('/main.blade.php'):
    stack=b"  @stack('page-styles')"
    before=b'<main class="site-main"'
    start=a.index(b'@php\n  $pageBackground = match (true) {')
    end=a.index(b' data-page-background="{{ $pageBackground }}"',start)+len(b' data-page-background="{{ $pageBackground }}"')
    changes=[(stack,asset+stack),(before,a[start:end])]
else:
    stack=b"  @stack('head')"
    changes=[(stack,asset+stack),(b'<body class="auth-body">',b'<body class="auth-body" data-page-background="ambient">')]
def patch(data):
    for before,after in changes:
        if data.count(after)==1:
            continue
        if data.count(before)!=1:
            sys.exit("STOP: layout compatibility anchor missing or ambiguous: "+file)
        data=data.replace(before,after,1)
    return data
if patch(b)!=a:
    sys.exit("STOP: reviewed layout delta differs from expected: "+file)
# Reject unknown prior page-background implementations rather than duplicate them.
if any(x in l for x in (b'page-backgrounds.css',b'data-page-background=')) and not all(l.count(after)==1 for _,after in changes):
    sys.exit("STOP: partial or customized background integration: "+file)
p=patch(l)
approved.write_bytes(p)
print("PASS: exact reviewed layout delta; unrelated live bytes preserved: "+file)
PY
    old="$(git hash-object "$target")"
    new="$(git hash-object "$WORK/target/$file")"
  fi
  printf '%s|%s\n' "$file" "$new" >> "$WORK/effective"
  if [[ -f "$target" ]]; then
    actual="$(git hash-object "$target")"
    if [[ "$actual" == "$new" ]]; then
      echo "ALREADY INSTALLED: $file"
      continue
    fi
    [[ "$actual" == "$old" ]]
    echo "$file" >> "$WORK/previous"
  else
    [[ "$old" == NEW && ! -e "$target" ]]
    actual=NEW
    echo "$file" >> "$WORK/new"
  fi
  echo "$file" >> "$WORK/changed"
  printf '%s|%s\n' "$file" "$actual" >> "$WORK/observed"
  echo "PASS: compatible $file"
done < "$WORK/manifest"
for file in resources/views/layouts/workspace.blade.php resources/views/account/studio.blade.php resources/views/auth/email-verified.blade.php resources/views/auth/verify-email.blade.php resources/views/commerce/cart.blade.php resources/views/home.blade.php public/css/home-hero-aurora.css public/css/footer-two-tone-themes.css public/css/account-email-verification.css public/css/workspace-glass-sidebar.css routes/web.php app/Http/Controllers/Auth/AuthController.php app/Support/SubmissionNotifications.php; do
  [[ -f "$SITE/$file" ]]
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$WORK/protected"
done
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file changed: $file" >&2; return 1; }
  done < "$WORK/protected"
}
verify_protected
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION_FILES_MODIFIED=0"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_PAGE_BACKGROUNDS_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-page-background-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/previous"
cp "$WORK/new" "$BACKUP/new-files"
echo "BACKUP=$BACKUP"
while IFS='|' read -r file actual; do
  if [[ "$actual" == NEW ]]; then
    [[ ! -e "$SITE/$file" && ! -L "$SITE/$file" ]]
  else
    [[ ! -L "$SITE/$file" && "$(git hash-object "$SITE/$file")" == "$actual" ]]
  fi
done < "$WORK/observed"
APPLYING=1
while IFS= read -r file; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.page-background.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
  echo "INSTALLED: $file"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r file hash; do
  [[ "$(git hash-object "$SITE/$file")" == "$hash" ]]
done < "$WORK/effective"
verify_protected
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/page-backgrounds.css?v=1" -o "$WORK/live.css"
[[ "$(git hash-object "$WORK/live.css")" == "8e719f7bc86ed25c373af5930a874d425782fe11" ]]
echo "PASS: live background stylesheet matches approved CSS"
for route in / /login; do
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com$route" -o "$WORK/live.html"
  grep -Fq 'css/page-backgrounds.css?v=1' "$WORK/live.html"
  if [[ "$route" == / ]]; then mode=standard; else mode=ambient; fi
  grep -Fq "data-page-background=\"$mode\"" "$WORK/live.html"
  echo "PASS: live $route renders $mode background"
done
echo "PASS: protected homepage hero, footer, checkout, authentication, notification and workspace files unchanged"
APPLYING=0
echo "BAMBODIA_PAGE_BACKGROUNDS_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only three approved background-system files installed; authenticated visual acceptance still required."
