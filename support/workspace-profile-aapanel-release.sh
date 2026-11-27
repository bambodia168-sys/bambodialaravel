#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=cafa8178b936835744062eda2c527d43ecedfaa9
BASE=357cfae98aa894c52ce0cb0b401ae6e2e2aba5fb
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
WORK="$(mktemp -d /root/bambodia-workspace-profile-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original profile files" >&2
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
echo "=== Workspace profile release 208 compatibility v2: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/208" "$WORK/pr.json"
for run in 46031927278 46031927297 46031927564 46031927292 46031927285; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$BASE" "$MODE" <<'PY'
import pathlib,json,re,sys
work,head,base,mode=pathlib.Path(sys.argv[1]),sys.argv[2],sys.argv[3],sys.argv[4]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main' or (not pr.get('merged') and pr['base']['sha']!=base):
    sys.exit('STOP: reviewed PR changed')
ready=True
for run in (46031927278,46031927297,46031927564,46031927292,46031927285):
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
app/Support/ProfileWorkspace.php|NEW|fff643f44f0c93fa8e30552c771122aca9651a99
app/Http/Controllers/ProfileEditorController.php|24d82c82a189a509462c8edfa98fcdb1543a8137|ca9c29456c37717df0e8f23fe597e56a8ef77c53
app/Http/Controllers/CreatorProfileItemController.php|ae5bfcc3e3c2f29dd5a32a5a6ead59c1159d13ef|e43395c419f1d86bd7271ffc0e1a50462456d942
app/Http/Controllers/CreatorProfileSectionController.php|f19021f15cf0f163b352982cc11771d416f69749|6dff54e5db37c4aca3f56f4656f109fdcea479cd
resources/views/account/profile-editor.blade.php|f0465fe119d4fa87003c10ff4ca7400cbe76df11|8a4e1c482697a4393cbc832c75ed994ceb6a13c8
routes/web.php|6e52a7a139ad4ce84f66c3a451c461d5242e98cb|3c375455912dba025a676c672af452ea5e4ae505
resources/views/layouts/workspace.blade.php|c9d59936697341d5c5508ba207c6ee72d694257e|46d54b11a3c4353703d622b966deac7eb71d9006
MANIFEST
while IFS='|' read -r file old new; do
  target="$SITE/$file"
  [[ ! -L "$target" && ! -L "$(dirname "$target")" ]]
  [[ -d "$(dirname "$target")" ]]
  mkdir -p "$WORK/target/$(dirname "$file")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" "$WORK/target/$file"
  [[ "$(git hash-object "$WORK/target/$file")" == "$new" ]]
  if [[ "$file" == routes/web.php || "$file" == resources/views/layouts/workspace.blade.php ]]; then
    [[ -f "$target" ]]
    fetch "https://raw.githubusercontent.com/$REPO/$BASE/$file" "$WORK/base"
    [[ "$(git hash-object "$WORK/base")" == "$old" ]]
    python3 - "$file" "$WORK/base" "$WORK/target/$file" "$target" <<'PY'
import pathlib,sys
file=sys.argv[1]
base,approved,live=map(pathlib.Path,sys.argv[2:])
b,a,l=base.read_bytes(),approved.read_bytes(),live.read_bytes()
if file=='routes/web.php':
    anchors=[
        b"            ->name('studio.profile.update')->middleware('throttle:10,1');\n",
        b"    Route::prefix('dashboard')->middleware('role:super_admin,admin,staff')->group(function (): void {\n",
    ]
    changes=[]
    for anchor,prefix in zip(anchors,(b'studio.profile.',b'dashboard.profile.')):
        lines=[line for line in a.splitlines(keepends=True)
               if b"->name('"+prefix in line and b"Route::" in line and (prefix==b'dashboard.profile.' or b"/profile/" in line)]
        if len(lines)!=(8 if prefix==b'studio.profile.' else 10):
            sys.exit('STOP: unexpected reviewed route count')
        changes.append((anchor,anchor+b''.join(lines)))
else:
    changes=[
        (b"'studio.profile.edit','dashboard.users.profile.edit'",b"'studio.profile.edit','dashboard.profile.edit','dashboard.users.profile.edit'"),
        (b"'localized.*.studio.profile.edit','localized.*.dashboard.users.profile.edit'",b"'localized.*.studio.profile.edit','localized.*.dashboard.profile.edit','localized.*.dashboard.users.profile.edit'"),
        (b"['route'=>'account.profile.edit','label'=>'My Profile','icon'=>'user-round']",b"['route'=>'dashboard.profile.edit','label'=>'My Profile','icon'=>'user-round']"),
    ]
def patch(data):
    for before,after in changes:
        if data.count(after)==1:
            continue
        if data.count(before)!=1:
            sys.exit('STOP: missing or ambiguous profile compatibility anchor: '+file)
        data=data.replace(before,after,1)
    return data
if patch(b)!=a:
    sys.exit('STOP: approved source delta differs: '+file)
if b'dashboard.profile.edit' in l and not all(l.count(after)==1 for _,after in changes):
    sys.exit('STOP: partial or custom profile integration: '+file)
approved.write_bytes(patch(l))
print('PASS: exact reviewed profile delta, unrelated live bytes preserved: '+file)
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
for file in app/Support/ProfileWorkspace.php app/Http/Controllers/ProfileEditorController.php app/Http/Controllers/CreatorProfileItemController.php app/Http/Controllers/CreatorProfileSectionController.php routes/web.php; do
  "$PHP" -l "$WORK/target/$file"
done
# Do not silently replace a production route cache.
(cd "$SITE" && runuser -u www -- "$PHP" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if($app->routesAreCached()){fwrite(STDERR,"STOP: production routes are cached; reviewed cache handling required\n");exit(1);}')
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json --no-ansi) > "$WORK/routes-before.json"
for file in resources/views/layouts/main.blade.php resources/views/layouts/auth.blade.php resources/views/layouts/dashboard.blade.php resources/views/account/index.blade.php resources/views/account/studio.blade.php resources/views/account/partials/studio-performance.blade.php resources/views/developer/index.blade.php resources/views/dashboard/index.blade.php resources/views/dashboard/partials/performance-filters.blade.php resources/views/dashboard/partials/performance-trends.blade.php app/Http/Controllers/Dashboard/DashboardController.php app/Support/AdminDashboardFilters.php app/Http/Controllers/AccountController.php app/Http/Controllers/DeveloperController.php app/Http/Controllers/Creator/CreatorProfileController.php app/Models/User.php public/css/creator-profile-v3.css public/js/creator-profile-v3.js public/css/admin-dashboard-filters.css public/css/workspace-glass-sidebar.css public/js/workspace-glass-sidebar.js public/css/page-backgrounds.css public/css/storefront-mini-cart.css app/Support/UserManagementAccess.php; do
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
  echo "BAMBODIA_WORKSPACE_PROFILE_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-workspace-profile-backup.XXXXXXXX)"
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
  tmp="$(mktemp "$(dirname "$SITE/$file")/.workspace-profile.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
  echo "INSTALLED: $file"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r file hash; do
  [[ "$(git hash-object "$SITE/$file")" == "$hash" ]]
done < "$WORK/effective"
verify_protected
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json --no-ansi) > "$WORK/routes-after.json"
python3 - "$WORK" "$BACKUP" <<'PY'
import collections,difflib,json,pathlib,re,sys,tarfile
work,backup=map(pathlib.Path,sys.argv[1:])
before=json.loads((work/'routes-before.json').read_text())
after=json.loads((work/'routes-after.json').read_text())
# Laravel 13 includes closure source location in route:list JSON. The reviewed
# route insertion can move line numbers but must not change any route semantics.
# Accept only exact line mapping in the original and approved source bytes.
with tarfile.open(backup/'original-code.tar') as archive:
    try:
        original=archive.extractfile('routes/web.php').read().splitlines()
    except KeyError:
        original=(work/'target/routes/web.php').read_bytes().splitlines()
candidate=(work/'target/routes/web.php').read_bytes().splitlines()
mapping={}
for i,j,n in difflib.SequenceMatcher(None,original,candidate,autojunk=False).get_matching_blocks():
    mapping.update({i+k+1:j+k+1 for k in range(n)})
def identity(row):
    return json.dumps(row,sort_keys=True)
actual=collections.Counter(map(identity,after))
shifted=0
missing=[]
for row in before:
    key=identity(row)
    if actual[key]:
        actual[key]-=1
        continue
    adjusted=dict(row)
    source=row.get('path')
    match=re.fullmatch(r'((?:.*/)?routes/web\.php):([0-9]+)',source) if isinstance(source,str) else None
    if row.get('action')=='Closure' and match:
        line=int(match.group(2))
        if line in mapping and mapping[line]!=line:
            adjusted['path']=match.group(1)+':'+str(mapping[line])
            adjusted_key=identity(adjusted)
            if actual[adjusted_key]:
                actual[adjusted_key]-=1
                shifted+=1
                continue
    missing.append(row)
if missing:
    print('STOP: existing route identity mismatch; first 12 differences:',file=sys.stderr)
    for row in missing[:12]:
        print('BEFORE '+identity(row),file=sys.stderr)
        matches=[r for r in after if all(r.get(k)==row.get(k) for k in ('domain','method','uri','name'))]
        for r in matches[:2]:
            print('AFTER  '+identity(r),file=sys.stderr)
    sys.exit(1)
print(f'PASS: {shifted} closure source-line shifts verified against unchanged source lines')

names={r['name']:r for r in after if r.get('name')}
suffixes=('edit','update','items.store','items.order','items.update','items.destroy','sections.store','sections.order','sections.update','sections.destroy')
for prefix,role in [('dashboard','role:super_admin,admin,staff'),('studio','role:freelancer,super_admin')]:
    for suffix in suffixes:
        name=prefix+'.profile.'+suffix
        row=names.get(name)
        if row is None or not any(middleware==role or middleware.endswith('EnsureRole:'+role.split(':',1)[1]) for middleware in row.get('middleware',[])):
            sys.exit('STOP: missing route or role gate '+name)
print('PASS: all existing route identities retained; 20 role-gated profile endpoints available')
PY
(cd "$SITE" && runuser -u www -- "$PHP" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(!class_exists(App\Support\ProfileWorkspace::class)||!view()->exists("account.profile-editor"))exit(1);echo "PASS: profile helper and editor available".PHP_EOL;')
verify_protected
echo "PASS: existing dashboard filters, Creator Studio analytics, global assets and permissions preserved"
APPLYING=0
echo "BAMBODIA_WORKSPACE_PROFILE_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only seven approved profile files installed; authenticated visual and save acceptance still required."
