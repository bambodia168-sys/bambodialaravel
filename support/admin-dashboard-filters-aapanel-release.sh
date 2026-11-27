#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=a4bec522da35ac3f48f487f1964f12539e308af5
BASE=934778ae5340aed8dc46204b6b08976be72ec143
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
WORK="$(mktemp -d /root/bambodia-admin-filter-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original admin dashboard files" >&2
    while IFS= read -r file; do rm -f -- "$SITE/$file" || code=1; done < "$WORK/new"
    if [[ -f "$WORK/created-partials" ]]; then rmdir "$SITE/resources/views/dashboard/partials" || code=1; fi
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || { echo "ROLLBACK FAILED: $BACKUP" >&2; code=1; }
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || code=1
    echo "RETAIN BACKUP=$BACKUP"
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
echo "=== Admin dashboard filters release 207: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/207" "$WORK/pr.json"
for run in 46001611013 46001611061 46001610963 46001611119 46001611112; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$BASE" "$MODE" <<'PY'
import pathlib,json,re,sys
work,head,base,mode=pathlib.Path(sys.argv[1]),sys.argv[2],sys.argv[3],sys.argv[4]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main' or (not pr.get('merged') and pr['base']['sha']!=base):
    sys.exit('STOP: reviewed PR changed')
ready=True
for run in (46001611013,46001611061,46001610963,46001611119,46001611112):
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
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed" "$WORK/effective"
cat > "$WORK/manifest" <<'MANIFEST'
app/Support/AdminDashboardFilters.php|NEW|b73808472f11c67b235242ceae46f1716c7e6d9d
app/Http/Controllers/Dashboard/DashboardController.php|bfc78cce81947c43b096117d52461eb2563448c9|130c1d8a324968dc869a85df9b817de6027f7046
resources/views/dashboard/index.blade.php|c1a5d97fad9633825b5c9da2ab8cb5973212dba0|d7f7c1f3ffef8d1a2f675587e5a3f1ebb0ed226e
resources/views/dashboard/partials/performance-filters.blade.php|NEW|68b17d6f6ae7848cd112a0b02c375de8eaf4a1ed
resources/views/dashboard/partials/performance-trends.blade.php|NEW|9ecc4105fa6bdfbfcd61c9801e0a99cf444227af
public/css/admin-dashboard-filters.css|NEW|18140b589b40fcb0b6705bd28bc5df05ef3d9d0b
MANIFEST
while IFS='|' read -r file old new; do
  target="$SITE/$file"
  [[ ! -L "$target" && ! -L "$(dirname "$target")" ]]
  if [[ "$file" == resources/views/dashboard/partials/* ]]; then
    [[ -d "$SITE/resources/views/dashboard" && ! -L "$SITE/resources/views/dashboard" ]]
    [[ ! -e "$(dirname "$target")" || -d "$(dirname "$target")" ]]
  else
    [[ -d "$(dirname "$target")" ]]
  fi
  mkdir -p "$WORK/target/$(dirname "$file")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" "$WORK/target/$file"
  [[ "$(git hash-object "$WORK/target/$file")" == "$new" ]]
  printf '%s|%s\n' "$file" "$new" >> "$WORK/effective"
  if [[ -f "$target" ]]; then
    actual="$(git hash-object "$target")"
    if [[ "$actual" == "$new" ]]; then
      echo "ALREADY INSTALLED: $file"
      continue
    fi
    [[ "$actual" == "$old" ]] || { echo "STOP: unexpected live file $file ($actual)" >&2; exit 1; }
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
"$PHP" -l "$WORK/target/app/Support/AdminDashboardFilters.php"
"$PHP" -l "$WORK/target/app/Http/Controllers/Dashboard/DashboardController.php"
for file in resources/views/layouts/workspace.blade.php resources/views/layouts/main.blade.php resources/views/layouts/auth.blade.php resources/views/layouts/dashboard.blade.php resources/views/account/index.blade.php resources/views/account/studio.blade.php resources/views/account/partials/studio-performance.blade.php resources/views/developer/index.blade.php app/Http/Controllers/AccountController.php app/Http/Controllers/DeveloperController.php app/View/Components/RoleDashboardTrends.php resources/views/components/role-dashboard-trends.blade.php resources/views/components/role-dashboard-period-filter.blade.php public/css/role-dashboard-overview.css public/js/role-dashboard-overview.js public/css/creator-studio-analytics.css public/js/creator-studio-analytics.js public/css/workspace-glass-sidebar.css public/js/workspace-glass-sidebar.js public/css/page-backgrounds.css public/css/storefront-mini-cart.css routes/web.php app/Support/UserManagementAccess.php; do
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
  echo "BAMBODIA_ADMIN_FILTERS_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-admin-filter-backup.XXXXXXXX)"
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
if [[ ! -d "$SITE/resources/views/dashboard/partials" ]]; then
  install -d -o www -g www -m 0755 "$SITE/resources/views/dashboard/partials"
  touch "$WORK/created-partials"
fi
while IFS= read -r file; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.admin-filter.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
  echo "INSTALLED: $file"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r file hash; do
  [[ "$(git hash-object "$SITE/$file")" == "$hash" ]]
done < "$WORK/effective"
verify_protected
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/admin-dashboard-filters.css?v=1" -o "$WORK/live.css"
[[ "$(git hash-object "$WORK/live.css")" == "18140b589b40fcb0b6705bd28bc5df05ef3d9d0b" ]]
echo "PASS: live admin filter stylesheet matches approved CSS"
(cd "$SITE" && runuser -u www -- "$PHP" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!class_exists(App\Support\AdminDashboardFilters::class)) exit(1); $filters=App\Support\AdminDashboardFilters::fromRequest(Illuminate\Http\Request::create("/dashboard?type=product&period=quarter")); if ($filters->type!=="product" || $filters->period!=="quarter") exit(1); foreach (["dashboard.index","dashboard.partials.performance-filters","dashboard.partials.performance-trends"] as $view) { if (!view()->exists($view)) exit(1); } echo "PASS: admin filter class and view availability".PHP_EOL;')
verify_protected
echo "PASS: other account layouts, shared components, role permissions and global UI unchanged"
APPLYING=0
echo "BAMBODIA_ADMIN_FILTERS_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only six approved Admin Dashboard files installed; authenticated visual acceptance still required."
