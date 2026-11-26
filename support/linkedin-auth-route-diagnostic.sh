#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="ecb37ba91c28995fba719b0ae571ffda00e289db"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
[[ $# == 0 ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== LinkedIn route inventory diagnostic (no production installation) ==="
test -f "$SITE/artisan"
test -f "$SITE/vendor/autoload.php"
test -x "$PHP"
test ! -L "$SITE"
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
WORK="$(mktemp -d /root/bambodia-linkedin-auth-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
PROBE=""
ROUTES_CACHED=0
if compgen -G "$SITE/bootstrap/cache/routes-*.php" >/dev/null; then ROUTES_CACHED=1; fi
refresh_routes() {
  (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi)
  if [[ "$ROUTES_CACHED" == 1 ]]; then
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:cache --no-ansi)
  fi
}
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original files" >&2
    while IFS= read -r path; do rm -f -- "$SITE/$path" || true; done < "$WORK/new"
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || echo 'ERROR: restore original-code.tar manually' >&2
    refresh_routes || echo 'ERROR: route cache restoration needs attention' >&2
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP=$BACKUP"
  fi
  if [[ -n "$PROBE" ]]; then rm -rf -- "$PROBE"; fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/191" "$WORK/pr.json"
for run in 45869586896 45869587028 45869587056 45869586875 45869586897; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45869586896,45869587028,45869587056,45869586875,45869586897):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}",file=sys.stderr)
    if data['head_sha'] != head or data['status'] != 'completed' or data['conclusion'] != 'success':
        ready=False
merged = pr.get('merged') is True
print(f'All CI successful: {ready}; PR merged: {merged}',file=sys.stderr)
if mode == '--apply' and not (ready and merged):
    sys.exit('STOP: CI or merge gate not satisfied. No production files changed.')
release=pr.get('merge_commit_sha') if merged and ready else head
if mode == '--apply' and release != 'ede5de574306ccafdd8dcd64592475a6f8035164':
    sys.exit('STOP: unexpected merge commit')
if not isinstance(release,str) or not re.fullmatch(r'[0-9a-f]{40}',release):
    sys.exit('STOP: Invalid release SHA')
print(release)
PY
)"
mkdir -p "$WORK/target"
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed"
cat > "$WORK/manifest" <<'MANIFEST'
app/Http/Controllers/Auth/SocialLinkedInController.php|NEW|bddbf14f9b4b0a4807eee5ba7fb42ec885111f23
resources/views/auth/social-linkedin-continue.blade.php|NEW|63b012616e0c1a02bff443f315c6ee610620ff71
public/css/system-configuration.css|6541f56806df1fd2ff8d8f6b35f41eab44ccf36a|f69db928f324f26f3ca3f2a11b226a6e95616601
resources/views/auth/alternative-sign-in.blade.php|2c1623c115ba3b0ab888630af6c136ee581e22ea|0312cd1c183a26e74539d3515ae4f33a8055cb7b
resources/views/auth/register.blade.php|036e8d338fcc7e7fff25dfe11ad8b43ae9f4bc2b|4698c5e221dcc19abc4cdb90aa640c5d43806396
routes/web.php|06500fdb62a395cd15795a52e3ab6335cbd92953|b2710d815ac31f8157d8deece57126ac898e69ac
app/Http/Controllers/Auth/SocialAuthController.php|73cdb94f773188087ae0be0ac646073a2b02f733|2820ac2ff4a4b0b06a845ebd0c5d15a956ffe099
MANIFEST

# Preserve the exact operator-reported route file. Never replace it with a
# historical full copy. The upstream PR must consist of precisely two insertions.
[[ -f "$SITE/routes/web.php" && ! -L "$SITE/routes" && ! -L "$SITE/routes/web.php" ]]
fetch "https://raw.githubusercontent.com/$REPO/fa661665e4dc1cc10327460daa233beb625c1900/routes/web.php" "$WORK/routes.base"
fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/routes/web.php" "$WORK/routes.approved"
[[ "$(git hash-object "$WORK/routes.base")" == 06500fdb62a395cd15795a52e3ab6335cbd92953 ]]
[[ "$(git hash-object "$WORK/routes.approved")" == b2710d815ac31f8157d8deece57126ac898e69ac ]]
mkdir -p "$WORK/target/routes"
python3 - "$WORK" "$SITE/routes/web.php" <<'PYCOMPAT'
import pathlib, hashlib, sys
work, live_path = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
base = (work/'routes.base').read_bytes()
approved = (work/'routes.approved').read_bytes()
live = live_path.read_bytes()
def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def require(ok, reason):
    if not ok:
        sys.exit('STOP: '+reason)
import_anchor = b'use App\\Http\\Controllers\\Auth\\SocialGoogleRegistrationController;\n'
import_added = b'use App\\Http\\Controllers\\Auth\\SocialLinkedInController;\n'
guest_anchor = b"    Route::get('/auth/{provider}/start', [SocialAuthController::class, 'start'])\n"
guest_added = b"""    Route::get('/auth/linkedin/continue', [SocialLinkedInController::class, 'show'])
        ->middleware('throttle:10,1')->block()->name('social.linkedin.continue');
    Route::post('/auth/linkedin/confirmation-code', [SocialLinkedInController::class, 'sendCode'])
        ->middleware('throttle:5,1,linkedin-code:')->block()->name('social.linkedin.code');
    Route::post('/auth/linkedin/continue', [SocialLinkedInController::class, 'store'])
        ->middleware('throttle:8,1,linkedin-confirm:')->block()->name('social.linkedin.store');
"""
def patch(data):
    require(data.count(import_anchor)==1 and data.count(guest_anchor)==1,'route anchors changed or are ambiguous')
    require(b'SocialLinkedInController' not in data and b'/auth/linkedin/continue' not in data
        and b'/auth/linkedin/confirmation-code' not in data and b'social.linkedin.' not in data,'LinkedIn continuation route already exists or conflicts')
    start = base.index(b"Route::middleware('guest')->group(function (): void {")
    end = base.index(b"\n});",start)+4
    require(data.count(base[start:end])==1,'existing guest route group differs from approved base')
    cb = base.index(b"Route::get('/auth/{provider}/callback'")
    cb_end = base.index(b"->name('social.callback');",cb)+len(b"->name('social.callback');")
    require(data.count(base[cb:cb_end])==1,'existing OAuth callback differs from approved base')
    return data.replace(import_anchor,import_anchor+import_added,1).replace(guest_anchor,guest_added+guest_anchor,1)
require(patch(base)==approved,'approved upstream route changes are not exactly the expected insertions')
known = {'06500fdb62a395cd15795a52e3ab6335cbd92953','fcfe1867d5e133a57365641776c8992fcc3d7499'}
live_hash=blob(live)
if live_hash in known:
    original=live
    target=patch(live)
else:
    # Allow an exact idempotent re-run of this same patch, not arbitrary drift.
    original=live.replace(import_added,b'',1).replace(guest_added,b'',1)
    require(blob(original) in known and patch(original)==live,'unrecognized live route version '+live_hash)
    target=live
require(target.replace(import_added,b'',1).replace(guest_added,b'',1)==original,'route patch changed existing bytes')
(work/'target/routes/web.php').write_bytes(target)
manifest=work/'manifest'
rows=manifest.read_text().splitlines()
old_row='routes/web.php|06500fdb62a395cd15795a52e3ab6335cbd92953|b2710d815ac31f8157d8deece57126ac898e69ac'
require(rows.count(old_row)==1,'unexpected release manifest')
rows[rows.index(old_row)]='routes/web.php|'+blob(original)+'|'+blob(target)
manifest.write_text('\n'.join(rows)+'\n')
print('PASS: exact live route base '+blob(original))
print('PASS: only approved import and three LinkedIn routes inserted; all existing source bytes preserved')
print('COMPATIBLE_ROUTE_HASH='+blob(target))
PYCOMPAT

while IFS='|' read -r path old new; do
  target="$SITE/$path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" && ! -L "$target" ]]
  mkdir -p "$WORK/target/$(dirname "$path")"
  if [[ "$path" != routes/web.php ]]; then
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
for path in app/Http/Controllers/Auth/SocialLinkedInController.php app/Http/Controllers/Auth/SocialAuthController.php routes/web.php; do
  "$PHP" -l "$WORK/target/$path"
done

# Run separate PHP CLI processes. The alternate bootstrap is an exact copy of
# the live bootstrap with only its directory token and web route path changed.
# No production file, route cache, database record or service is modified.
PROBE="$(mktemp -d /tmp/bambodia-linkedin-route-probe.XXXXXXXX)"
chown root:www "$PROBE"
chmod 0750 "$PROBE"
install -o root -g www -m 0640 "$WORK/target/routes/web.php" "$PROBE/web.php"
install -o root -g www -m 0640 "$WORK/target/app/Http/Controllers/Auth/SocialLinkedInController.php" "$PROBE/SocialLinkedInController.php"
python3 - "$SITE" "$PROBE" <<'PYBOOT'
import pathlib, sys
site, probe = map(pathlib.Path,sys.argv[1:])
source=(site/'bootstrap/app.php').read_text()
anchor="web: __DIR__.'/../routes/web.php',"
if source.count(anchor)!=1:
    sys.exit('STOP: live application bootstrap route path differs; no files changed')
# PHP single-quoted literal path; all paths here are fixed or mktemp paths.
def literal(path):
    return "'"+str(path).replace("\\","\\\\").replace("'","\\'")+"'"
for mode, route in [('original',site/'routes/web.php'),('candidate',probe/'web.php')]:
    result=source.replace(anchor,'web: '+literal(route)+',').replace('__DIR__',literal(site/'bootstrap'))
    (probe/(mode+'.php')).write_text(result)
PYBOOT
cat > "$PROBE/probe.php" <<'PHP'
<?php
$site = $argv[1];
$probe = $argv[2];
$mode = $argv[3];
require $site.'/vendor/autoload.php';
if ($mode === 'candidate') require $probe.'/SocialLinkedInController.php';
$app = require $probe.'/'.$mode.'.php';
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput(['artisan','route:list','--json'])));
PHP
chown root:www "$PROBE/"*.php
chmod 0640 "$PROBE/"*.php
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json) > "$WORK/live.json"
for mode in original candidate; do
  (cd "$SITE" && runuser -u www -- env APP_ROUTES_CACHE="$PROBE/no-route-cache.php" "$PHP" "$PROBE/probe.php" "$SITE" "$PROBE" "$mode") > "$WORK/$mode.json"
done
python3 - "$WORK" <<'PYREPORT'
import pathlib,sys,json,collections
work=pathlib.Path(sys.argv[1])
def load(name):
    rows=json.loads((work/(name+'.json')).read_text())
    assert isinstance(rows,list) and rows
    return rows
def key(r):
    return (r.get('domain'),r.get('method'),r.get('uri'),r.get('name'))
def canonical(r):
    return json.dumps(r,sort_keys=True,separators=(',',':'))
def report(label,a,b):
    old=collections.Counter(map(canonical,a))
    new=collections.Counter(map(canonical,b))
    removed=[json.loads(x) for x in (old-new).elements()]
    added=[json.loads(x) for x in (new-old).elements()]
    print(label+': before='+str(len(a))+' after='+str(len(b))+' removed_or_changed='+str(len(removed))+' added_or_changed='+str(len(added)))
    indexed=collections.defaultdict(list)
    for r in added:
        indexed[key(r)].append(r)
    for r in removed[:15]:
        candidates=indexed.get(key(r),[])
        if candidates:
            other=candidates.pop()
            delta={k:{'before':r.get(k),'after':other.get(k)} for k in r.keys()|other.keys() if r.get(k)!=other.get(k)}
            print('CHANGED '+str(key(r))+' '+json.dumps(delta,ensure_ascii=True)[:1500])
        else:
            print('MISSING '+json.dumps({k:r.get(k) for k in ('domain','method','uri','name','action','middleware')},ensure_ascii=True)[:1500])
    for r in added[:15]:
        print('ADDED_OR_CHANGED '+json.dumps({k:r.get(k) for k in ('domain','method','uri','name','action','middleware')},ensure_ascii=True)[:1500])
    if len(removed)>15 or len(added)>15:
        print('Further differences omitted from terminal output.')
    return removed,added
live,original,candidate=map(load,('live','original','candidate'))
report('LIVE_VS_ISOLATED_BASELINE',live,original)
report('BASELINE_VS_LINKEDIN_CANDIDATE',original,candidate)
for r in candidate:
    if r.get('name') in ('social.linkedin.continue','social.linkedin.code','social.linkedin.store'):
        print('LINKEDIN_ROUTE '+json.dumps(r,ensure_ascii=True))
print('ROUTE_DIAGNOSTIC_COMPLETE=YES')
PYREPORT
echo "Production files and caches not modified."
