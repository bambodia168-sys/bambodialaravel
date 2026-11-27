#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="4eea64b216e3b9f23a3cd79287c16cd51082f114"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== Creator Studio pipeline card stack release 199: $MODE ==="
test -f "$SITE/artisan"
test -f "$SITE/vendor/autoload.php"
test -x "$PHP"
test ! -L "$SITE"
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
exec 8>/run/lock/bambodia-creator-performance.lock
flock -n 8
WORK="$(mktemp -d /root/bambodia-creator-stack-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original files" >&2
    while IFS= read -r path; do rm -f -- "$SITE/$path" || true; done < "$WORK/new"
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP=$BACKUP"
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/199" "$WORK/pr.json"
for run in 45935868495 45935868486 45935868497 45935868476 45935868468; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45935868495,45935868486,45935868497,45935868476,45935868468):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}",file=sys.stderr)
    if data['head_sha'] != head or data['status'] != 'completed' or data['conclusion'] != 'success':
        ready=False
merged = pr.get('merged') is True
print(f'All CI successful: {ready}; PR merged: {merged}',file=sys.stderr)
if mode == '--apply' and not (ready and merged):
    sys.exit('STOP: CI or merge gate not satisfied. No production files changed.')
release=pr.get('merge_commit_sha') if merged and ready else head
if not isinstance(release,str) or not re.fullmatch(r'[0-9a-f]{40}',release):
    sys.exit('STOP: Invalid release SHA')
print(release)
PY
)"
mkdir -p "$WORK/target"
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed"
cat > "$WORK/manifest" <<'MANIFEST'
public/css/creator-studio-analytics.css|3736a8ea7b445e6412628b4a509e599c041a1d72|c6b6df3dab0ba9843cf4a9fb0737a7d04dc943ec
resources/views/account/partials/studio-performance.blade.php|47ee1e29119b3a8a200e1d8b6ac1588e4b7e7ad7|7c5f314b955365a76215e11007ddac38e9c89fd8
resources/views/account/studio.blade.php|63c9b583e27ab2c8d202f72ba7c17ee699565655|eab0ca6accc64a81b88df708ba312c62814a4344
MANIFEST
while IFS='|' read -r path old new; do
  target="$SITE/$path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" && ! -L "$target" ]]
  mkdir -p "$WORK/target/$(dirname "$path")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" "$WORK/target/$path"
  [[ "$(git hash-object "$WORK/target/$path")" == "$new" ]]
  if [[ -f "$target" ]]; then
    actual="$(git hash-object "$target")"
    if [[ "$actual" == "$new" ]]; then
      echo "ALREADY INSTALLED: $path"
      continue
    fi
    [[ "$actual" == "$old" ]] || { echo "STOP: incompatible live file $path ($actual)" >&2; exit 1; }
    echo "$path" >> "$WORK/previous"
  else
    [[ "$old" == NEW && ! -e "$target" ]]
    actual=NEW
    echo "$path" >> "$WORK/new"
  fi
  echo "$path" >> "$WORK/changed"
  printf '%s|%s\n' "$path" "$actual" >> "$WORK/observed"
  echo "PASS: compatible $path"
done < "$WORK/manifest"
grep -Fq "creator-studio-analytics.css') }}?v=3" "$WORK/target/resources/views/account/studio.blade.php"
echo "PASS: Creator Studio CSS version 3"
BASE=d65fa781c8d49877ed4b85cb732a4c8995e0c184
fetch "https://raw.githubusercontent.com/$REPO/$BASE/resources/views/account/studio.blade.php" "$WORK/studio.base"
fetch "https://raw.githubusercontent.com/$REPO/$BASE/resources/views/account/partials/studio-performance.blade.php" "$WORK/performance.base"
fetch "https://raw.githubusercontent.com/$REPO/$BASE/public/css/creator-studio-analytics.css" "$WORK/css.base"
[[ "$(git hash-object "$WORK/studio.base")" == 63c9b583e27ab2c8d202f72ba7c17ee699565655 ]]
[[ "$(git hash-object "$WORK/performance.base")" == 47ee1e29119b3a8a200e1d8b6ac1588e4b7e7ad7 ]]
[[ "$(git hash-object "$WORK/css.base")" == 3736a8ea7b445e6412628b4a509e599c041a1d72 ]]
python3 - "$WORK" <<'PYVERIFY'
import pathlib,sys,re
w=pathlib.Path(sys.argv[1])
base=(w/'studio.base').read_bytes()
target=(w/'target/resources/views/account/studio.blade.php').read_bytes()
cards=[line for line in base.splitlines() if re.match(rb'\s*<x-workspace-metric label="(?:Product drafts and rejections|Products awaiting review|Open client inquiries)"',line)]
old=b"creator-studio-analytics.css') }}?v=2"
new=b"creator-studio-analytics.css') }}?v=3"
if len(cards)!=3 or base.count(old)!=1 or base.count(b'\n'.join(cards)+b'\n')!=1:
    sys.exit('STOP: unexpected original creator metrics')
if base.replace(b'\n'.join(cards)+b'\n',b'',1).replace(old,new,1)!=target:
    sys.exit('STOP: unexpected creator template changes')
partial=(w/'performance.base').read_bytes()
anchor=b'  </div>\n  <script type="application/json" id="studio-analytics-payload">'
stack=b'    <div class="studio-chart-metric-stack" role="group" aria-label="Additional creator activity">\n'+b'\n'.join(b'  '+line for line in cards)+b'\n    </div>\n'
if partial.count(anchor)!=1 or partial.replace(anchor,stack+anchor,1)!=(w/'target/resources/views/account/partials/studio-performance.blade.php').read_bytes():
    sys.exit('STOP: analytics partial changes exceed card relocation')
oldcss=(w/'css.base').read_bytes()
newcss=(w/'target/public/css/creator-studio-analytics.css').read_bytes()
if not newcss.startswith(oldcss) or len(newcss)<=len(oldcss):
    sys.exit('STOP: previous analytics CSS changed')
print('PASS: exact original three metric components relocated; previous chart markup and CSS preserved')
PYVERIFY
cat > "$WORK/protected" <<'PROTECTED'
resources/views/layouts/workspace.blade.php|8e19e8eec7b6ae97c3c4889c3f4235d2d628bd93
public/css/workspace-glass-sidebar.css|968442d116189eaa0127a2b1a9a91799d1083c91
public/js/creator-studio-analytics.js|ac2c82e555319ee89fd0e12845944fdf9558a6c9
PROTECTED
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file differs: $file" >&2; return 1; }
  done < "$WORK/protected"
}
verify_protected
echo "PASS: shared V4 workspace and analytics JavaScript intact"
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT=PASS (no live files changed; CI and merge still required for apply)"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_CREATOR_STACK_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-creator-stack-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/previous"
cp "$WORK/new" "$BACKUP/new-files"
echo "BACKUP=$BACKUP"
while IFS='|' read -r path actual; do
  if [[ "$actual" == NEW ]]; then
    [[ ! -e "$SITE/$path" && ! -L "$SITE/$path" ]]
  else
    [[ ! -L "$SITE/$path" && "$(git hash-object "$SITE/$path")" == "$actual" ]]
  fi
done < "$WORK/observed"
APPLYING=1
while IFS= read -r path; do
  tmp="$(mktemp "$(dirname "$SITE/$path")/.creator-performance.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$tmp"
  mv -f -- "$tmp" "$SITE/$path"
  echo "INSTALLED: $path"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r path old new; do
  [[ "$(git hash-object "$SITE/$path")" == "$new" ]]
done < "$WORK/manifest"
verify_protected
echo "PASS: shared V4 workspace and analytics JavaScript unchanged"
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/creator-studio-analytics.css?v=3" -o "$WORK/live.css"
[[ "$(git hash-object "$WORK/live.css")" == c6b6df3dab0ba9843cf4a9fb0737a7d04dc943ec ]]
echo "PASS: live Creator Studio CSS matches approved release"
APPLYING=0
echo "BAMBODIA_CREATOR_STACK_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only three original metric cards relocated and responsive grid CSS appended; shared workspace, JavaScript, analytics logic, other workspaces, routes and database untouched; no migrations."
