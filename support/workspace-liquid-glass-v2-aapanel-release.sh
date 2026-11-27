#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="a343d41deab1dfedc5b219f3e3da8be906cb2a54"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== Workspace liquid glass V2 release 194 (live template compatibility): $MODE ==="
test -f "$SITE/artisan"
test -f "$SITE/vendor/autoload.php"
test -x "$PHP"
test ! -L "$SITE"
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
WORK="$(mktemp -d /root/bambodia-liquid-glass-v2-stage.XXXXXXXX)"
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
fetch "$API/pulls/194" "$WORK/pr.json"
for run in 45923401437 45923401414 45923401444 45923401402 45923401406; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45923401437,45923401414,45923401444,45923401402,45923401406):
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
public/css/workspace-glass-sidebar.css|b91c6da5a6d966c442c4d6de71026e26b191f6a0|3275bc46397f5afd56dc4b613b74bf79a7420674
public/js/workspace-glass-sidebar.js|9c99eb77faacbbd83f9861859321e13a46d7d5c1|3f778ab237840e763dea7a78750e5edefeb56aae
resources/views/layouts/workspace.blade.php|7fed39b93ece35851e48cf8755271ff71229f408|44b2982408d60e85cb366dc15951e3da63309c47
MANIFEST

# Preserve all prior production customizations; apply only five exact reversible UI edits.
LAYOUT=resources/views/layouts/workspace.blade.php
[[ -f "$SITE/$LAYOUT" && ! -L "$SITE/$LAYOUT" && ! -L "$SITE/resources/views/layouts" ]]
fetch "https://raw.githubusercontent.com/$REPO/4023b4c643b99aaf871eb4f7a5c632caea2c5740/$LAYOUT" "$WORK/layout.base"
fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$LAYOUT" "$WORK/layout.approved"
[[ "$(git hash-object "$WORK/layout.base")" == 7fed39b93ece35851e48cf8755271ff71229f408 ]]
[[ "$(git hash-object "$WORK/layout.approved")" == 44b2982408d60e85cb366dc15951e3da63309c47 ]]
mkdir -p "$WORK/target/resources/views/layouts"
python3 - "$WORK" "$SITE/$LAYOUT" <<'PYCOMPAT'
import pathlib,sys,hashlib
work,live_path=map(pathlib.Path,sys.argv[1:])
base=(work/'layout.base').read_bytes()
approved=(work/'layout.approved').read_bytes()
live=live_path.read_bytes()
def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def require(ok,msg):
    if not ok: sys.exit('STOP: '+msg)
known={'7fed39b93ece35851e48cf8755271ff71229f408','500677fb93abbc3f45c6a46eda2a6579a76d12ee'}
patches=[(x.encode(),y.encode()) for x,y in [["  <link rel=\"stylesheet\" href=\"{{ asset('css/workspace-glass-sidebar.css') }}?v=1\">\n  <script defer src=\"{{ asset('js/workspace-glass-sidebar.js') }}?v=1\"></script>\n","  <link rel=\"stylesheet\" href=\"{{ asset('css/workspace-glass-sidebar.css') }}?v=2\">\n  <script defer src=\"{{ asset('js/workspace-glass-sidebar.js') }}?v=2\"></script>\n"],["        <span class=\"brand-mark workspace-brand-mini\" aria-hidden=\"true\">B</span>\n","        <span class=\"workspace-brand-mini brand-logo-pair workspace-favicon-pair\" aria-hidden=\"true\">\n          <img class=\"brand-logo-light\" src=\"{{ \\App\\Models\\SiteBrandAsset::urlFor('favicon_light') }}\" alt=\"\" width=\"36\" height=\"36\" decoding=\"async\">\n          <img class=\"brand-logo-dark\" src=\"{{ \\App\\Models\\SiteBrandAsset::urlFor('favicon_dark') }}\" alt=\"\" width=\"36\" height=\"36\" decoding=\"async\">\n        </span>\n"],["      <div class=\"workspace-sidebar-identity\" title=\"{{ $user->name }}\">\n        <x-profile-avatar :user=\"$user\" :size=\"18\"/>\n        <div class=\"workspace-sidebar-identity-copy\"><strong>{{ $user->name }}</strong><small>{{ ucwords(str_replace('_',' ',$user->role)) }}</small></div>\n      </div>\n    </div>\n    <nav class=\"workspace-navigation\"","    </div>\n    <nav class=\"workspace-navigation\""],["  <div class=\"workspace-backdrop\" data-workspace-backdrop aria-hidden=\"true\"></div>\n","  <button type=\"button\" class=\"workspace-mobile-trigger\" data-workspace-toggle aria-label=\"Open navigation\" aria-controls=\"workspace-sidebar\" aria-expanded=\"false\" title=\"Open navigation\">\n    <x-icon name=\"panel-left-open\" size=\"20\"/>\n  </button>\n  <div class=\"workspace-backdrop\" data-workspace-backdrop aria-hidden=\"true\"></div>\n"],["    <header class=\"workspace-topbar\">\n      <button type=\"button\" class=\"icon-button workspace-sidebar-toggle\"\n              data-workspace-toggle aria-label=\"Collapse navigation\"\n              aria-controls=\"workspace-sidebar\" aria-expanded=\"true\"\n              title=\"Show or hide sidebar\">\n        <span class=\"workspace-collapse-icon\"><x-icon name=\"panel-left-close\" size=\"20\"/></span>\n        <span class=\"workspace-expand-icon\"><x-icon name=\"panel-left-open\" size=\"20\"/></span>\n      </button>\n      <div class=\"workspace-top-title\">","    <header class=\"workspace-topbar\">\n      <div class=\"workspace-top-title\">"]]]
def transform(data,changes):
    for x,y in changes:
        require(data.count(x)==1,'approved change context missing or ambiguous: '+x[:90].decode())
        data=data.replace(x,y,1)
    return data
def forward(data): return transform(data,patches)
def reverse(data): return transform(data,[(y,x) for x,y in reversed(patches)])
require(forward(base)==approved and reverse(approved)==base,'upstream patch is not exact and reversible')
if blob(live) in known:
    original=live
    target=forward(live)
else:
    original=reverse(live)
    require(blob(original) in known and forward(original)==live,'unknown live workspace version: '+blob(live))
    target=live
require(reverse(target)==original,'unapproved live layout bytes changed')
require(target.count(b'data-workspace-toggle')==1 and target.count(b'data-workspace-glass-toggle')==1,'duplicate or missing controls')
require(b'workspace-sidebar-identity' not in target and b'workspace-sidebar-toggle' not in target,'old profile or header control remains')
(work/'target/resources/views/layouts/workspace.blade.php').write_bytes(target)
manifest=work/'manifest'
row='resources/views/layouts/workspace.blade.php|7fed39b93ece35851e48cf8755271ff71229f408|44b2982408d60e85cb366dc15951e3da63309c47'
rows=manifest.read_text().splitlines()
require(rows.count(row)==1,'unexpected manifest')
rows[rows.index(row)]='resources/views/layouts/workspace.blade.php|'+blob(original)+'|'+blob(target)
manifest.write_text('\n'.join(rows)+'\n')
print('PASS: exact previous production layout '+blob(original))
print('PASS: five approved UI edits; all unrelated source bytes preserved')
print('COMPATIBLE_WORKSPACE_HASH='+blob(target))
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
grep -Fq "workspace-glass-sidebar.css') }}?v=2" "$WORK/target/resources/views/layouts/workspace.blade.php"
grep -Fq "workspace-glass-sidebar.js') }}?v=2" "$WORK/target/resources/views/layouts/workspace.blade.php"
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
  echo "BAMBODIA_LIQUID_GLASS_V2_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-liquid-glass-v2-backup.XXXXXXXX)"
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
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/workspace-glass-sidebar.css?v=2" -o "$WORK/live.css"
[[ "$(git hash-object "$WORK/live.css")" == 3275bc46397f5afd56dc4b613b74bf79a7420674 ]]
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/js/workspace-glass-sidebar.js?v=2" -o "$WORK/live.js"
[[ "$(git hash-object "$WORK/live.js")" == 3f778ab237840e763dea7a78750e5edefeb56aae ]]
echo "PASS: both live glass sidebar assets match approved hashes"
APPLYING=0
echo "BAMBODIA_LIQUID_GLASS_V2_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Shared sidebar layout updated across all four workspaces; existing role menus, routes, authentication, Vite bundles and public frontend untouched; no migrations."
