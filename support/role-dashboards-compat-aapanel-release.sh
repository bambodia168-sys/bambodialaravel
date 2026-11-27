#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="b13db61b9a84ea73ada6d8988efb998f94787029"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== Role-aware dashboard release 200 compatibility: $MODE ==="
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
WORK="$(mktemp -d /root/bambodia-role-dashboards-stage.XXXXXXXX)"
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
fetch "$API/pulls/200" "$WORK/pr.json"
for run in 45939415076 45939415198 45939415114 45939415156 45939415086; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45939415076,45939415198,45939415114,45939415156,45939415086):
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
[[ "$RELEASE" == "908fe4fc2a835432a060fca7a69d07b8b9104d3f" ]]
mkdir -p "$WORK/target"
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed"
cat > "$WORK/manifest" <<'MANIFEST'
public/css/role-dashboard-overview.css|NEW|79a24af4f9832caae73b7c630b1887ff9ae43324
public/js/role-dashboard-overview.js|NEW|397727aa2f4367d6ff29358ef21dbad23a75eb34
resources/views/components/role-dashboard-report.blade.php|NEW|6d81f8af465880d3cd63afdcd13d556577fffaeb
resources/views/components/role-dashboard-table-filter.blade.php|NEW|dc71712e66cbc0f9d0956d3d0cbcbee7f944dc9d
app/Http/Controllers/Dashboard/DashboardController.php|dda60ac960392042fce94d787e21e3cf334ddb83|bfc78cce81947c43b096117d52461eb2563448c9
resources/views/account/index.blade.php|d0f6144459a3fe14121db277bbc94a67886b0854|03b9fcc6889e991b5e28aa37b79b0f763c6766c8
resources/views/dashboard/index.blade.php|9ef1e8f6ff4bb6051130d2688a19b40675691e3f|5abb07fb2e2b698b93fb9ec1c61eafd4eeb70c2d
resources/views/developer/index.blade.php|2d81e0e46cb86d93209365576b49ca6399d42211|97ad2e3acaf3909de219bd5f84e317a7aab49249
MANIFEST
while IFS='|' read -r path old new; do
  target="$SITE/$path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" && ! -L "$target" ]]
  mkdir -p "$WORK/target/$(dirname "$path")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" "$WORK/target/$path"
  if [[ "$path" == "resources/views/developer/index.blade.php" ]]; then
    [[ "$(git hash-object "$WORK/target/$path")" == e0abe51d961776d7d4b54aee163315cd39c01486 ]]
    fetch "https://raw.githubusercontent.com/$REPO/2890f08f6ced3f0d218468068ab6c3bfdd8ef3eb/$path" "$WORK/developer.base"
    [[ "$(git hash-object "$WORK/developer.base")" == 8fbed9f19647c11136eb27842c82e8154719af8c ]]
    python3 - "$WORK/developer.base" "$WORK/target/$path" "$WORK/developer.compat" <<'PYCOMPAT'
import pathlib, sys
base, approved, compat = map(pathlib.Path, sys.argv[1:])
anchor = b'''  <a class="btn subtle compact" href="{{ route('developer.docs') }}"><x-icon name="book" size="16"/> API endpoints and scope reference</a>\n'''
links = b'''  <a class="btn primary compact" href="{{ route('developer.mcp.index') }}"><x-icon name="plug" size="16"/> Bambodia Manager MCP</a>
  <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}"><x-icon name="shield" size="16"/> OAuth connections</a>
'''
for file in (base, approved):
    source = file.read_bytes()
    if source.count(anchor) != 1 or b"developer.mcp.index" in source or b"developer.oauth.index" in source:
        sys.exit('STOP: unexpected developer template content')
    if file == base:
        compat.write_bytes(source.replace(anchor,anchor+links,1))
    else:
        file.write_bytes(source.replace(anchor,anchor+links,1))
PYCOMPAT
    [[ "$(git hash-object "$WORK/developer.compat")" == "$old" ]]
    [[ "$(git hash-object "$WORK/target/$path")" == "$new" ]]
    echo "PASS: approved Developer dashboard plus exactly the two existing MCP/OAuth navigation links"
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
"$PHP" -l "$WORK/target/app/Http/Controllers/Dashboard/DashboardController.php"
for view in resources/views/account/index.blade.php resources/views/dashboard/index.blade.php resources/views/developer/index.blade.php; do
  grep -Fq "role-dashboard-overview.css') }}?v=1" "$WORK/target/$view"
  grep -Fq "role-dashboard-overview.js') }}?v=1" "$WORK/target/$view"
done
echo "PASS: isolated dashboard assets and PHP syntax"
# Verify existing integration destinations remain registered before changing files.
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --name=developer --json) > "$WORK/developer-routes.json"
python3 - "$WORK/developer-routes.json" <<'PYROUTES'
import json,sys
names={r.get("name") for r in json.load(open(sys.argv[1]))}
if not {"developer.mcp.index","developer.oauth.index"}.issubset(names):
    sys.exit("STOP: existing MCP or OAuth route is missing")
print("PASS: MCP and OAuth routes registered")
PYROUTES
cat > "$WORK/protected" <<'PROTECTED'
resources/views/layouts/workspace.blade.php|8e19e8eec7b6ae97c3c4889c3f4235d2d628bd93
public/css/workspace-glass-sidebar.css|968442d116189eaa0127a2b1a9a91799d1083c91
public/js/workspace-glass-sidebar.js|3f778ab237840e763dea7a78750e5edefeb56aae
resources/views/account/studio.blade.php|eab0ca6accc64a81b88df708ba312c62814a4344
resources/views/account/partials/studio-performance.blade.php|7c5f314b955365a76215e11007ddac38e9c89fd8
public/css/creator-studio-analytics.css|c6b6df3dab0ba9843cf4a9fb0737a7d04dc943ec
public/js/creator-studio-analytics.js|ac2c82e555319ee89fd0e12845944fdf9558a6c9
PROTECTED
# Preserve live deployment-specific controller and route variants as found.
for file in app/Http/Controllers/AccountController.php app/Http/Controllers/DeveloperController.php app/Support/UserManagementAccess.php app/Support/MarketplaceManagement.php routes/web.php; do
  test -f "$SITE/$file"
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$WORK/protected"
done
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file differs: $file" >&2; return 1; }
  done < "$WORK/protected"
}
verify_protected
echo "PASS: Creator Studio, shared V4 workspace, existing permission helper and routes intact"
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT=PASS (no live files changed; CI and merge still required for apply)"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_ROLE_DASHBOARDS_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-role-dashboards-backup.XXXXXXXX)"
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
echo "PASS: Creator Studio, shared V4 workspace, existing permission helper and routes unchanged"
for asset in css/role-dashboard-overview.css js/role-dashboard-overview.js; do
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/$asset?v=1" -o "$WORK/live.asset"
  [[ "$(git hash-object "$WORK/live.asset")" == "$(git hash-object "$SITE/public/$asset")" ]]
  echo "PASS: live $asset matches approved release"
done
APPLYING=0
echo "BAMBODIA_ROLE_DASHBOARDS_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only approved dashboard changes installed; original MCP/OAuth links retained; Creator Studio and shared workspace untouched; no migrations."
