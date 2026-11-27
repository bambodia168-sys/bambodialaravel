#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="50864080714aef324b881fd412d36d0361560190"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== Workspace glass sidebar release 193 (live template compatibility): $MODE ==="
test -f "$SITE/artisan"
test -f "$SITE/vendor/autoload.php"
test -x "$PHP"
test ! -L "$SITE"
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
WORK="$(mktemp -d /root/bambodia-glass-sidebar-stage.XXXXXXXX)"
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
fetch "$API/pulls/193" "$WORK/pr.json"
for run in 45916252491 45916252707 45916252606 45916252443 45916252450; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45916252491,45916252707,45916252606,45916252443,45916252450):
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
public/css/workspace-glass-sidebar.css|NEW|b91c6da5a6d966c442c4d6de71026e26b191f6a0
public/js/workspace-glass-sidebar.js|NEW|9c99eb77faacbbd83f9861859321e13a46d7d5c1
resources/views/layouts/workspace.blade.php|d02a84c8868e16d88ec0a2f2df7393da5746b5b8|7fed39b93ece35851e48cf8755271ff71229f408
MANIFEST

# The production template may contain earlier operator changes. Preserve every
# original byte and insert only the three approved, exact UI additions.
LAYOUT=resources/views/layouts/workspace.blade.php
[[ -f "$SITE/$LAYOUT" && ! -L "$SITE/$LAYOUT" && ! -L "$SITE/resources/views/layouts" ]]
fetch "https://raw.githubusercontent.com/$REPO/8c5f9044df21fc59f5c046fbb9723814bb8d53d5/$LAYOUT" "$WORK/layout.base"
fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$LAYOUT" "$WORK/layout.approved"
[[ "$(git hash-object "$WORK/layout.base")" == d02a84c8868e16d88ec0a2f2df7393da5746b5b8 ]]
[[ "$(git hash-object "$WORK/layout.approved")" == 7fed39b93ece35851e48cf8755271ff71229f408 ]]
mkdir -p "$WORK/target/resources/views/layouts"
python3 - "$WORK" "$SITE/$LAYOUT" <<'PYCOMPAT'
import pathlib,sys,hashlib,difflib
work,live_path=map(pathlib.Path,sys.argv[1:])
base=(work/'layout.base').read_bytes()
approved=(work/'layout.approved').read_bytes()
live=live_path.read_bytes()
def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def require(ok,msg):
    if not ok: sys.exit('STOP: '+msg)
known={'d02a84c8868e16d88ec0a2f2df7393da5746b5b8','9872f790fc2746653bced6e09bf7adb10b2cc2ba'}
patches=[
(b"  @stack('head')\n",b"""  <link rel="stylesheet" href="{{ asset('css/workspace-glass-sidebar.css') }}?v=1">
  <script defer src="{{ asset('js/workspace-glass-sidebar.js') }}?v=1"></script>
"""),
(b'    </div>\n    <nav class="workspace-navigation" aria-label="{{ $workspaceTitles[$workspace] }} menu" id="workspace-navigation">\n',b"""      <div class="workspace-sidebar-identity" title="{{ $user->name }}">
        <x-profile-avatar :user="$user" :size="18"/>
        <div class="workspace-sidebar-identity-copy"><strong>{{ $user->name }}</strong><small>{{ ucwords(str_replace('_',' ',$user->role)) }}</small></div>
      </div>
"""),
(b'  <div class="workspace-backdrop" data-workspace-backdrop aria-hidden="true"></div>\n',b"""  <button type="button" class="workspace-glass-toggle" data-workspace-glass-toggle aria-controls="workspace-sidebar" aria-expanded="true" aria-label="Collapse navigation" title="Show icons only" hidden>
    <span class="workspace-glass-chevron-close"><x-icon name="chevron-left" size="17"/></span>
    <span class="workspace-glass-chevron-open"><x-icon name="chevron-right" size="17"/></span>
  </button>
""")]
def patch(data):
    for marker in (b'workspace-glass-sidebar.css',b'workspace-glass-sidebar.js',b'workspace-sidebar-identity',b'data-workspace-glass-toggle'):
        require(marker not in data,'existing glass sidebar marker conflicts')
    for marker in (b'id="workspace-shell"',b'id="workspace-sidebar"',b'id="workspace-navigation"',b'id="workspace-main"',b'data-workspace-toggle',b'data-workspace-search',b'data-workspace-backdrop',b'<div class="workspace-sidebar-head">',b'@foreach($groups as $group)',b'@foreach($group[\'items\'] as $item)'):
        require(data.count(marker)==1,'required workspace structure missing or ambiguous: '+marker.decode())
    # Check exact surrounding head and sidebar boundaries, not just token matches.
    head_start=b'    <div class="workspace-sidebar-head">\n'
    head_end=patches[1][0]
    start=base.index(head_start)
    end=base.index(head_end,start)+len(head_end)
    require(data.count(base[start:end])==1,'sidebar brand/head context differs from approved base')
    aside_end=b'  </aside>\n'+patches[2][0]
    require(data.count(aside_end)==1,'sidebar/backdrop boundary differs from approved base')
    for anchor,addition in patches:
        require(data.count(anchor)==1,'approved insertion anchor missing or ambiguous')
        data=data.replace(anchor,addition+anchor,1)
    return data
def reverse(data):
    for anchor,addition in patches:
        require(data.count(addition+anchor)==1,'cannot reverse exact approved insertion')
        data=data.replace(addition+anchor,anchor,1)
    return data
require(patch(base)==approved,'upstream change is not exactly three approved insertions')
if blob(live) in known:
    original=live
    target=patch(live)
else:
    original=reverse(live)
    require(blob(original) in known and patch(original)==live,'unknown live layout version '+blob(live))
    target=live
require(reverse(target)==original,'existing layout bytes changed')
(work/'target/resources/views/layouts/workspace.blade.php').write_bytes(target)
manifest=work/'manifest'
row='resources/views/layouts/workspace.blade.php|d02a84c8868e16d88ec0a2f2df7393da5746b5b8|7fed39b93ece35851e48cf8755271ff71229f408'
rows=manifest.read_text().splitlines()
require(rows.count(row)==1,'unexpected manifest')
rows[rows.index(row)]='resources/views/layouts/workspace.blade.php|'+blob(original)+'|'+blob(target)
manifest.write_text('\n'.join(rows)+'\n')
print('PASS: exact live workspace template '+blob(original))
print('PASS: only three approved insertions; all existing layout bytes preserved')
print('COMPATIBLE_WORKSPACE_HASH='+blob(target))
# Show differences from upstream without exposing the contents of the live file.
ops=difflib.SequenceMatcher(None,base.splitlines(),original.splitlines(),autojunk=False).get_opcodes()
print('Existing live/upstream differing source regions: '+str(sum(op[0]!='equal' for op in ops)))
PYCOMPAT

while IFS='|' read -r path old new; do
  target="$SITE/$path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" && ! -L "$target" ]]
  mkdir -p "$WORK/target/$(dirname "$path")"
  if [[ "$path" != "$LAYOUT" ]]; then
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" "$WORK/target/$path"
  fi
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
grep -Fq "workspace-glass-sidebar.css') }}?v=1" "$WORK/target/resources/views/layouts/workspace.blade.php"
grep -Fq "workspace-glass-sidebar.js') }}?v=1" "$WORK/target/resources/views/layouts/workspace.blade.php"
grep -Fq 'data-workspace-glass-toggle' "$WORK/target/resources/views/layouts/workspace.blade.php"
echo "PASS: shared workspace glass assets and toggle"
ROUTE_HASH="$(git hash-object "$SITE/routes/web.php")"
AUTH_HASH="$(git hash-object "$SITE/app/Http/Controllers/Auth/SocialAuthController.php")"
LINKEDIN_HASH="$(git hash-object "$SITE/app/Http/Controllers/Auth/SocialLinkedInController.php")"
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT=PASS (no live files changed; CI and merge still required for apply)"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_GLASS_SIDEBAR_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-glass-sidebar-backup.XXXXXXXX)"
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
  tmp="$(mktemp "$(dirname "$SITE/$path")/.glass-sidebar.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$tmp"
  mv -f -- "$tmp" "$SITE/$path"
  echo "INSTALLED: $path"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r path old new; do
  [[ "$(git hash-object "$SITE/$path")" == "$new" ]]
done < "$WORK/manifest"
[[ "$(git hash-object "$SITE/routes/web.php")" == "$ROUTE_HASH" ]]
[[ "$(git hash-object "$SITE/app/Http/Controllers/Auth/SocialAuthController.php")" == "$AUTH_HASH" ]]
[[ "$(git hash-object "$SITE/app/Http/Controllers/Auth/SocialLinkedInController.php")" == "$LINKEDIN_HASH" ]]
echo "PASS: existing production routes and social authentication unchanged"
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/workspace-glass-sidebar.css?v=1" -o "$WORK/live.css"
[[ "$(git hash-object "$WORK/live.css")" == b91c6da5a6d966c442c4d6de71026e26b191f6a0 ]]
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/js/workspace-glass-sidebar.js?v=1" -o "$WORK/live.js"
[[ "$(git hash-object "$WORK/live.js")" == 9c99eb77faacbbd83f9861859321e13a46d7d5c1 ]]
echo "PASS: both live glass sidebar assets match approved hashes"
APPLYING=0
echo "BAMBODIA_GLASS_SIDEBAR_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Shared sidebar layout updated across all four workspaces; existing role menus, routes, authentication, Vite bundles and public frontend untouched; no migrations."
