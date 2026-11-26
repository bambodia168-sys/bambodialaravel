#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="ecb37ba91c28995fba719b0ae571ffda00e289db"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== LinkedIn authentication release 191: $MODE ==="
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
for path in app/Http/Controllers/Auth/SocialLinkedInController.php app/Http/Controllers/Auth/SocialAuthController.php routes/web.php; do
  "$PHP" -l "$WORK/target/$path"
done
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT=PASS (no live files changed; CI and merge still required for apply)"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_LINKEDIN_AUTH_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-linkedin-auth-backup.XXXXXXXX)"
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
  tmp="$(mktemp "$(dirname "$SITE/$path")/.linkedin-auth.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$tmp"
  mv -f -- "$tmp" "$SITE/$path"
  echo "INSTALLED: $path"
done < "$WORK/changed"
refresh_routes
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r path old new; do
  [[ "$(git hash-object "$SITE/$path")" == "$new" ]]
done < "$WORK/manifest"
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --name=social.linkedin --json) > "$WORK/routes.json"
python3 - "$WORK/routes.json" <<'PYROUTES'
import json,sys
routes=json.load(open(sys.argv[1]))
found={r['name']:r['method'] for r in routes}
for name,method in [('social.linkedin.continue','GET'),('social.linkedin.code','POST'),('social.linkedin.store','POST')]:
    assert method in found.get(name,'').split('|'), f'Missing route: {name}'
print('PASS: all three LinkedIn continuation routes')
PYROUTES
curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/system-configuration.css?v=6" -o "$WORK/live.css"
[[ "$(git hash-object "$WORK/live.css")" == f69db928f324f26f3ca3f2a11b226a6e95616601 ]]
curl -4 -fsSL --max-redirs 3 --connect-timeout 10 --max-time 30 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/login" -o "$WORK/login.html"
grep -Fq 'Sign in with LinkedIn' "$WORK/login.html"
echo "PASS: production login renders Sign in with LinkedIn"
APPLYING=0
echo "BAMBODIA_LINKEDIN_AUTH_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "OAuth credentials, database, provider settings and shared dashboard layouts untouched; no migrations."
