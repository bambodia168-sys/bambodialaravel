#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="4c85965fbb4c4600f0b870afa673ef7ab049ae01"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== Creator-reference dashboard release 201: $MODE ==="
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
WORK="$(mktemp -d /root/bambodia-reference-dashboards-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original files" >&2
    while IFS= read -r path; do rm -f -- "$SITE/$path" || true; done < "$WORK/new"
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    tac "$WORK/created-dirs" | while IFS= read -r dir; do rmdir -- "$SITE/$dir" || true; done
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP=$BACKUP"
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/201" "$WORK/pr.json"
for run in 45944739860 45944739900 45944740082 45944740005 45944739846; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45944739860,45944739900,45944740082,45944740005,45944739846):
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
[[ "$RELEASE" == "5737a8cab2b8c59c4abf530fdf2ab5f2c5401185" ]]
mkdir -p "$WORK/target"
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed" "$WORK/created-dirs"
cat > "$WORK/manifest" <<'MANIFEST'
app/View/Components/RoleDashboardTrends.php|NEW|6ade4e638a729c0051635760b83c004ec13fc106
resources/views/components/role-dashboard-trends.blade.php|NEW|58284b9a8f4a214fc2d1768577de07903f5d778d
resources/views/components/role-dashboard-period-filter.blade.php|NEW|e471b028a64c30d5e5201093d1a2512d560e9ef1
resources/views/components/role-dashboard-kpi.blade.php|NEW|9a1b642eabdfba80c6779c2c5e0036b01a9ae854
resources/views/components/role-dashboard-report.blade.php|6d81f8af465880d3cd63afdcd13d556577fffaeb|15ae37ed9a9ef81fb71853c11d82764497740d02
public/css/role-dashboard-overview.css|79a24af4f9832caae73b7c630b1887ff9ae43324|868bc90d041f9cd0bb231cf2b9f7d09be8542c29
public/js/role-dashboard-overview.js|397727aa2f4367d6ff29358ef21dbad23a75eb34|08c9a2c5b9326cdaf0984cfffc0bd6718782b90d
resources/views/account/index.blade.php|03b9fcc6889e991b5e28aa37b79b0f763c6766c8|785d1978f18c4a21151cce9371f0fa3b5d6e4b7a
resources/views/dashboard/index.blade.php|5abb07fb2e2b698b93fb9ec1c61eafd4eeb70c2d|c1a5d97fad9633825b5c9da2ab8cb5973212dba0
resources/views/developer/index.blade.php|97ad2e3acaf3909de219bd5f84e317a7aab49249|87bdcde1df9e5109a4892369d10a355ee5addf63
MANIFEST
while IFS='|' read -r path old new; do
  target="$SITE/$path"
  if [[ "$path" == app/View/Components/RoleDashboardTrends.php ]]; then
    for dir in app app/View app/View/Components; do
      [[ ! -L "$SITE/$dir" ]]
      [[ ! -e "$SITE/$dir" || -d "$SITE/$dir" ]]
    done
  else
    [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" ]]
  fi
  [[ ! -L "$target" ]]
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
"$PHP" -l "$WORK/target/app/View/Components/RoleDashboardTrends.php"
for view in resources/views/account/index.blade.php resources/views/dashboard/index.blade.php resources/views/developer/index.blade.php; do
  grep -Fq "role-dashboard-overview.css') }}?v=2" "$WORK/target/$view"
  grep -Fq "role-dashboard-overview.js') }}?v=2" "$WORK/target/$view"
done
echo "PASS: isolated dashboard assets and PHP syntax"
for route in developer.mcp.index developer.oauth.index; do
  grep -Fq "route('$route')" "$WORK/target/resources/views/developer/index.blade.php"
done
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --name=developer --json) > "$WORK/developer-routes.json"
python3 - "$WORK/developer-routes.json" <<'PYROUTES'
import json,sys
names={r.get("name") for r in json.load(open(sys.argv[1]))}
if not {"developer.mcp.index","developer.oauth.index"}.issubset(names):
    sys.exit("STOP: existing MCP or OAuth route is missing")
print("PASS: MCP and OAuth routes registered and links retained")
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
for file in app/Http/Controllers/AccountController.php app/Http/Controllers/DeveloperController.php app/Http/Controllers/Dashboard/DashboardController.php app/Support/UserManagementAccess.php app/Support/MarketplaceManagement.php routes/web.php public/css/customer-account-overview.css; do
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
  echo "BAMBODIA_REFERENCE_DASHBOARDS_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-reference-dashboards-backup.XXXXXXXX)"
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
  if [[ "$path" == app/View/Components/RoleDashboardTrends.php ]]; then
    for dir in app/View app/View/Components; do
      [[ ! -L "$SITE/$dir" ]]
      if [[ ! -d "$SITE/$dir" ]]; then
        install -d -o www -g www -m 0755 "$SITE/$dir"
        echo "$dir" >> "$WORK/created-dirs"
      fi
    done
  fi
  tmp="$(mktemp "$(dirname "$SITE/$path")/.reference-dashboard.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$tmp"
  mv -f -- "$tmp" "$SITE/$path"
  echo "INSTALLED: $path"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" -r 'require "vendor/autoload.php"; if (!class_exists("App\\View\\Components\\RoleDashboardTrends")) {fwrite(STDERR,"STOP: trend component autoload failed\n"); exit(1);} echo "PASS: trend component autoload\n";')
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r path old new; do
  [[ "$(git hash-object "$SITE/$path")" == "$new" ]]
done < "$WORK/manifest"
verify_protected
echo "PASS: Creator Studio, shared V4 workspace, existing permission helper and routes unchanged"
for asset in css/role-dashboard-overview.css js/role-dashboard-overview.js; do
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/$asset?v=2" -o "$WORK/live.asset"
  [[ "$(git hash-object "$WORK/live.asset")" == "$(git hash-object "$SITE/public/$asset")" ]]
  echo "PASS: live $asset matches approved release"
done
APPLYING=0
echo "BAMBODIA_REFERENCE_DASHBOARDS_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only approved reference dashboard files installed; Creator Studio, shared workspace, permissions, existing controllers and routes untouched; MCP/OAuth retained; no migrations."
