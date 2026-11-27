#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=33b246f015f7737b814485cb2addb3ee152a889f
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
[[ -f "$SITE/artisan" && -x "$PHP" && ! -L "$SITE" ]]
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
exec 8>/run/lock/bambodia-creator-performance.lock
flock -n 8
WORK="$(mktemp -d /root/bambodia-verification-layout-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original verification templates" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || { echo "ROLLBACK FAILED: $BACKUP" >&2; code=1; }
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
echo "=== Email verification layout release 204: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/204" "$WORK/pr.json"
for run in 45972907471 45972907319 45972907436 45972907459 45972907467; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import pathlib,json,re,sys
work,head,mode=pathlib.Path(sys.argv[1]),sys.argv[2],sys.argv[3]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main':
    sys.exit('STOP: reviewed PR changed')
ready=True
for run in (45972907471,45972907319,45972907436,45972907459,45972907467):
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
cat > "$WORK/manifest" <<'MANIFEST'
resources/views/auth/email-verified.blade.php|70d78aed91e19d4b0ce631e2059a30e06d020d13|48acc302db7c8d51988e3d90a4e30d19c5fda83f
resources/views/auth/verify-email.blade.php|da14592867483a5bf161751fecf936168e3f90d9|8a7c55e266b26530c0eae180ffe046c63ee05269
MANIFEST
touch "$WORK/changed" "$WORK/observed"
while IFS='|' read -r file old new; do
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" && ! -L "$(dirname "$SITE/$file")" ]]
  mkdir -p "$WORK/target/$(dirname "$file")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$file" "$WORK/target/$file"
  [[ "$(git hash-object "$WORK/target/$file")" == "$new" ]]
  grep -Fq "@push('page-styles')" "$WORK/target/$file"
  grep -Fq "account-email-verification.css') }}?v=2" "$WORK/target/$file"
  actual="$(git hash-object "$SITE/$file")"
  [[ "$actual" == "$old" || "$actual" == "$new" ]] || { echo "STOP: unexpected live template $file ($actual)" >&2; exit 1; }
  if [[ "$actual" != "$new" ]]; then
    echo "$file" >> "$WORK/changed"
    printf '%s|%s\n' "$file" "$actual" >> "$WORK/observed"
  fi
  echo "PASS: compatible $file"
done < "$WORK/manifest"
grep -Fq "@stack('page-styles')" "$SITE/resources/views/layouts/main.blade.php"
[[ "$(git hash-object "$SITE/public/css/account-email-verification.css")" == "6b5f8d52777006f1ad8f1f5f0cd17f35d07050cb" ]]
for file in resources/views/layouts/main.blade.php resources/views/layouts/workspace.blade.php resources/views/account/studio.blade.php routes/web.php app/Http/Controllers/Auth/AuthController.php app/Http/Controllers/Auth/EmailVerificationController.php app/Support/SubmissionNotifications.php public/css/account-email-verification.css; do
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$WORK/protected"
done
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file changed: $file" >&2; return 1; }
  done < "$WORK/protected"
}
verify_asset() {
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/css/account-email-verification.css?v=2" -o "$WORK/live.css"
  [[ "$(git hash-object "$WORK/live.css")" == "6b5f8d52777006f1ad8f1f5f0cd17f35d07050cb" ]]
  echo "PASS: live verification stylesheet matches approved CSS"
}
verify_asset
echo "PASS: global page-styles stack and existing CSS"
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION_FILES_MODIFIED=0"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_VERIFICATION_LAYOUT_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-verification-layout-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/changed"
echo "BACKUP=$BACKUP"
while IFS='|' read -r file actual; do
  [[ "$(git hash-object "$SITE/$file")" == "$actual" ]]
done < "$WORK/observed"
APPLYING=1
while IFS= read -r file; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.verification-layout.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
  echo "INSTALLED: $file"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
while IFS='|' read -r file old new; do
  [[ "$(git hash-object "$SITE/$file")" == "$new" ]]
done < "$WORK/manifest"
verify_protected
verify_asset
echo "PASS: global layout, routes, authentication, notification helper and Creator Studio unchanged"
APPLYING=0
echo "BAMBODIA_VERIFICATION_LAYOUT_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only two approved Blade views installed. Authenticated visual acceptance still required."
