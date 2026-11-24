#!/usr/bin/env bash
# Bambodia role-scoped private support tickets. Guarded on-site compatibility installer.
# Preflight is read-only. Zero-fuzz patching preserves unrelated live modifications.
set -Eeuo pipefail
umask 077
[[ $# == 1 ]] || { echo "Usage: sudo bash script --preflight|--apply"; exit 2; }
MODE="$1"
[[ "$MODE" == --preflight || "$MODE" == --apply ]] || exit 2
[[ "$(id -u)" == 0 ]] || { echo "STOP: run with sudo/root"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
OLD="78cd7d9c1bf16157fc3fcfacbf3a805cdf5411e5"
NEW="49a97f198f97b48fbe4085405c584682631f130f"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
for binary in curl git python3 patch tar runuser install; do
  command -v "$binary" >/dev/null || { echo "STOP: missing $binary"; exit 2; }
done
[[ -f "$SITE/.env" && -f "$SITE/artisan" && -d "$SITE/vendor" && -x "$PHP" ]] ||
  { echo "STOP: unexpected live site"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: APP_ENV not production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: nginx root mismatch"; exit 2; }

http() {
  curl -4 -sS --connect-timeout 8 --max-time 18 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' \
    "https://bambodia.com$1"
}
[[ "$(http /)" == 200 && "$(http /support-center)" == 200 ]] ||
  { echo "STOP: origin or public Support Center unhealthy"; exit 2; }

STAGE="$(mktemp -d /root/bambodia-role-tickets-stage.XXXXXXXX)"
BACKUP=""
INSTALLED=0
cleanup() {
  code=$?
  trap - EXIT
  if [[ "$INSTALLED" == 1 ]]; then
    echo "ROLLBACK: restoring previous production files" >&2
    tar -C "$SITE" -xpf "$BACKUP/original.tar" ||
      echo "ERROR: manual restore needed from $BACKUP/original.tar" >&2
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
      runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
  fi
  rm -rf -- "$STAGE"
  exit "$code"
}
trap cleanup EXIT
mkdir -p "$STAGE/base" "$STAGE/target" "$STAGE/candidates"
cat > "$STAGE/manifest" <<'FILES'
app/Models/User.php|f861493fba5afbaf5c98ecfd5d5d164899b3ec01|0c35ef576c279ccc1287d2e66adbfdcf226b5e6b
routes/web.php|64b296d91647e1278733e8f7f89470d511d0494a|a5856ca0a14b4a0dc96331df20d22102f36f8a93
app/Http/Controllers/SupportController.php|3a2c64b9cb3863a35719d85b4eedebbc5e303f93|c5f97172d88deefda386fe8b2f32989d5fb35715
app/Http/Controllers/Dashboard/SupportCenterController.php|86fabd66432c42494a049c3a4ba23c1a225606dc|5091167bd44c9688c920a849a62a879115925ef4
resources/views/layouts/workspace.blade.php|0d44042ecbe8dea80a2d9640c7a7fc64441e7068|87127746b222d0c4494a4c1bba6cbb2a14a6a1b5
resources/views/layouts/main.blade.php|84fae2c92a8d041fe04af94ee9328a521d1362a1|5324169cc4777b325a78a06f775ed5d4bce17ef3
resources/views/support/index.blade.php|3c7b807e8c94ae3c49bd59f6194b20c5164d91c3|e4d9f11f482d02b9c3bb07250858e730f431ef40
resources/views/support/create.blade.php|e32f656b961c42454175eb843b347a81c538ab9a|7dd0eff51e03325de1409350e6defb592dba4202
resources/views/support/show.blade.php|c9dc25bc00495452d9004dd119399595b2883d8f|cb8463eab3339d648ec347cdbf5a568307713f0b
resources/views/support/knowledge-index.blade.php|7510cdeaf3cb879b529b13b1f16e6e61342f50c0|8952fa8b84553e85faef6a7029b903cc6d079dd7
resources/views/support/knowledge-show.blade.php|de156a4f29d980931174b50f5c77068e6e9fb0b9|60b28acf8ce0ac837b7ac4cdec6209a6bbbbd412
resources/views/dashboard/support.blade.php|adc1e9c4a25ae05716001f2fa77e6196feedea4d|f284edc94f0604c058bf36ac31a9c7c606c738d2
FILES
: > "$STAGE/live-hashes"
while IFS='|' read -r file old_sha new_sha; do
  [[ -n "$file" && ! -L "$SITE/$file" && -f "$SITE/$file" ]] ||
    { echo "STOP: missing/symlink live file $file"; exit 1; }
  for snapshot in base target candidates; do
    mkdir -p "$STAGE/$snapshot/$(dirname "$file")"
  done
  curl -fLsS --retry 2 --connect-timeout 8 --max-time 45 \
    "https://raw.githubusercontent.com/$REPO/$OLD/$file" -o "$STAGE/base/$file"
  curl -fLsS --retry 2 --connect-timeout 8 --max-time 45 \
    "https://raw.githubusercontent.com/$REPO/$NEW/$file" -o "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$old_sha" ]] ||
    { echo "STOP: base integrity $file"; exit 1; }
  [[ "$(git hash-object "$STAGE/target/$file")" == "$new_sha" ]] ||
    { echo "STOP: target integrity $file"; exit 1; }

  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$STAGE/live-hashes"
  cp -p "$SITE/$file" "$STAGE/candidates/$file"
  python3 - "$STAGE" "$file" <<'PY_PATCH'
from pathlib import Path
from difflib import unified_diff
import subprocess
import sys

stage, relative = Path(sys.argv[1]), sys.argv[2]
base = (stage / 'base' / relative).read_bytes()
target = (stage / 'target' / relative).read_bytes()
candidate = stage / 'candidates' / relative
live = candidate.read_bytes()
if live == target:
    print('ALREADY: ' + relative)
    raise SystemExit(0)

patch = ''.join(unified_diff(
    base.decode('utf-8').splitlines(keepends=True),
    target.decode('utf-8').splitlines(keepends=True),
    fromfile='a/' + relative, tofile='b/' + relative, n=3
))
if not patch:
    raise SystemExit('STOP: Empty patch ' + relative)

result = subprocess.run(
    ['patch', '-p1', '--batch', '--forward', '--fuzz=0',
     '--no-backup-if-mismatch', '-d', str(stage / 'candidates')],
    input=patch, text=True, capture_output=True
)
if result.returncode != 0:
    raise SystemExit(
        'STOP: incompatible live changes ' + relative + '\n'
        + result.stdout + '\n' + result.stderr
    )
if candidate.read_bytes() == live:
    raise SystemExit('STOP: patch made no changes ' + relative)
print('PASS: exact-context staged patch ' + relative)
PY_PATCH
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    "$PHP" -l "$STAGE/candidates/$file" >/dev/null ||
      { echo "STOP: invalid PHP syntax in $file"; exit 1; }
  fi
done < "$STAGE/manifest"

[[ "$(http /)" == 200 && "$(http /support-center)" == 200 ]] ||
  { echo "STOP: origin changed during preflight"; exit 1; }
echo "PASS: files staged; hashes and PHP syntax verified"
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT ONLY: no production modifications."
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-role-tickets-backup.XXXXXXXX)"
cut -d'|' -f1 "$STAGE/manifest" > "$STAGE/paths"
tar -C "$SITE" -cpf "$BACKUP/original.tar" -T "$STAGE/paths"
echo "Backup: $BACKUP/original.tar"

while IFS='|' read -r file original_sha; do
  [[ "$(git hash-object "$SITE/$file")" == "$original_sha" ]] ||
    { echo "STOP: live file changed since review: $file"; exit 1; }
done < "$STAGE/live-hashes"

INSTALLED=1
while IFS='|' read -r file old_sha new_sha; do
  destination="$SITE/$file"
  tmp="$(mktemp "$(dirname "$destination")/.role-ticket.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/candidates/$file" "$tmp"
  mv -f "$tmp" "$destination"
done < "$STAGE/manifest"

(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json --no-ansi) |
  python3 -c '
import json,sys
byname={r.get("name"):r for r in json.load(sys.stdin)}
for namespace in ("account.support","studio.support-tickets","dashboard.support-tickets"):
    for operation in ("index","create","store","show","reply"):
        key=namespace+"."+operation
        assert key in byname, "Missing route: "+key
assert "dashboard.support.reply" in byname
'
[[ "$(http /)" == 200 && "$(http /support-center)" == 200 ]] ||
  { echo "STOP: origin failed after installation; restoring backup"; exit 1; }

INSTALLED=0
echo "SUCCESS: Role-based private ticket workspaces installed."
echo "Support Center remains public. Staff support queue unchanged."
echo "Retain backup: $BACKUP/original.tar"
