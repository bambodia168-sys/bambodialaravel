#!/usr/bin/env bash
# Bambodia private role workspace layout release: PR #169 + PR #170.
# This code-only installer refuses incompatible live drift and keeps a backup.
# Usage: sudo bash private-workspaces-aapanel-release.sh --preflight
#        sudo bash private-workspaces-aapanel-release.sh --apply
set -Eeuo pipefail
umask 077

[[ $# == 1 && ( "$1" == --preflight || "$1" == --apply ) ]] ||
  { echo "Usage: $0 --preflight | --apply"; exit 2; }
MODE="$1"
[[ "$(id -u)" == 0 ]] || { echo "STOP: must run as root"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="28aad1e6a79ec9de9f1f11b076934b788e87607e"
TARGET="195903f27925dc663ed876773775fbc24a2d2127"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
for cmd in curl git patch python3 tar runuser install mktemp; do
  command -v "$cmd" >/dev/null || { echo "STOP: missing $cmd"; exit 2; }
done
[[ -f "$SITE/artisan" && -f "$SITE/.env" && -d "$SITE/vendor" && -x "$PHP" ]] ||
  { echo "STOP: unexpected production site or PHP"; exit 2; }
[[ ! -L "$SITE" ]] || { echo "STOP: site directory is a symlink"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: expected production Laravel environment"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: production virtual-host document root changed"; exit 2; }

origin_code() {
  curl -4 -sS --connect-timeout 8 --max-time 15 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null \
    -w '%{http_code}' "https://bambodia.com$1"
}
[[ "$(origin_code /)" == "200" && "$(origin_code /support-center)" == "200" ]] ||
  { echo "STOP: Bambodia origin public health check failed"; exit 2; }

# Retry independent TLS/connection failures to raw.githubusercontent.com.
# curl --retry alone does not retry all SSL handshake errors (e.g. curl:35).
# Never disable certificate verification; all downloaded bytes still require
# the pinned Git object SHA before any file can be staged or installed.
download_pinned() {
  local commit="$1" relative="$2" destination="$3" attempt
  for attempt in 1 2 3 4 5; do
    if curl -fLsS --connect-timeout 12 --max-time 100 \
      "https://raw.githubusercontent.com/$REPO/$commit/$relative" -o "$destination"; then
      return 0
    fi
    rm -f -- "$destination"
    if [[ "$attempt" -lt 5 ]]; then
      echo "RETRY: GitHub TLS/source download $relative (attempt $attempt/5 failed)" >&2
      sleep "$((attempt * 2))"
    fi
  done
  echo "STOP: source fetch failed after 5 attempts: $relative" >&2
  return 1
}

STAGE="$(mktemp -d /root/bambodia-private-shells-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$DEPLOYING" == 1 ]]; then
    echo "ROLLBACK: restoring backed-up layout and controller files" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    (cd "$SITE" &&
      runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
      runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$STAGE"
  exit "$code"
}
trap finish EXIT

mkdir -p "$STAGE"/{base,target,candidate}
cat > "$STAGE/manifest" <<'MANIFEST'
app/Http/Controllers/SupportController.php|53a26374ae45c1529086dc9cebaf3d078e71e775|f40b60d4439fff479c6fb9c2b8c85342996e4e1f
app/Models/User.php|0c35ef576c279ccc1287d2e66adbfdcf226b5e6b|f19d81691b7756e86d9bbe0803931642bd1a9d00
resources/views/layouts/workspace.blade.php|87127746b222d0c4494a4c1bba6cbb2a14a6a1b5|d02a84c8868e16d88ec0a2f2df7393da5746b5b8
resources/views/support/create.blade.php|7dd0eff51e03325de1409350e6defb592dba4202|271407e6b01b843cc7942cb1067b2cd18de7f4c9
resources/views/support/edit.blade.php|ac412d32612fd947de4d4a88e88f4822def2e6b6|cf85401dc0da6383f14724b97e6d2fef8d867762
resources/views/support/index.blade.php|13093a84ef9d9a825cbc32e090711fa047500906|b7d637290f52fefa3fdf1f7717f24832df413a75
resources/views/support/row-actions.blade.php|1fc6baa449074c7bf8737f2560e2ad5156c0bb53|e84778de467f090befc499dc2caf2b1ad550b960
resources/views/support/show.blade.php|ab5e71aeec655136d90356114a9afb93c2fd35e9|34aff3fd27b6bd93e1f2674533bfa7afaef0948a
resources/views/contracts/index.blade.php|9313801212a51e1fd11b14a23482866c5b645b8a|97dcbc317d773e3c0affd9e3f1ee033d22278453
resources/views/contracts/show.blade.php|ec26e718eafebfdd90fdba8d1830c61301bec6b9|59092a7ec6894d38aae6825ed1db78ea31adac5c
resources/views/inquiries/index.blade.php|50f5094a8cf12b271d5e0854f9c3dbfe4212e5a2|488b1d3ce244a233eb7613e5fdf0a105374d21b3
resources/views/inquiries/show.blade.php|8b379505c57b511bab0b0e43d3b4aa024fa11903|99de261d98e7e29a99320a78ea2f9cf9582acf5d
resources/views/jobs/applied.blade.php|5f48868db9e2e4131518766837e51da7d3013f7a|8d8da8f8f40e1627a58d12b6b8eeafe712c8e08e
resources/views/jobs/mine.blade.php|3a222992be059c795ae2092fe58ae16e9fb6c208|03ca37189018ab28e06166be56f10b640caa3151
resources/views/jobs/proposals.blade.php|3b21faa20d1fe093eff00c44fa7e7d7e7fcab260|fa9f695bfa1631a59ce745ce34749de3611f0d60
resources/views/media/index.blade.php|8a1dab36d7b4fe79e7f0cc6a6faa3d2e81609d79|10c319e2bc857c83d1bcd70349ed2ce42df23b95
MANIFEST

: > "$STAGE/original.list"
: > "$STAGE/live-hashes"
while IFS='|' read -r file base_sha target_sha; do
  [[ "$file" != /* && "$file" != *..* ]] || { echo "STOP: path rejected"; exit 2; }
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: missing or symlinked live file $file"; exit 2; }
  for dir in base target candidate; do
    mkdir -p "$STAGE/$dir/$(dirname "$file")"
  done
  download_pinned "$BASE" "$file" "$STAGE/base/$file"
  download_pinned "$TARGET" "$file" "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$base_sha" &&
      "$(git hash-object "$STAGE/target/$file")" == "$target_sha" ]] ||
    { echo "STOP: pinned source mismatch for $file"; exit 2; }
  printf '%s\n' "$file" >> "$STAGE/original.list"
  live_sha="$(git hash-object "$SITE/$file")"
  printf '%s|%s\n' "$file" "$live_sha" >> "$STAGE/live-hashes"
  cp -p -- "$SITE/$file" "$STAGE/candidate/$file"

  if [[ "$live_sha" == "$target_sha" ]]; then
    echo "PASS: already current $file"
    continue
  fi
  python3 - "$STAGE" "$file" <<'PY_PATCH'
from pathlib import Path
from difflib import unified_diff
import subprocess
import sys
stage = Path(sys.argv[1])
relative = sys.argv[2]
base = (stage / 'base' / relative).read_text()
target = (stage / 'target' / relative).read_text()
candidate = stage / 'candidate' / relative
patch = ''.join(unified_diff(
    base.splitlines(keepends=True),
    target.splitlines(keepends=True),
    fromfile='a/' + relative, tofile='b/' + relative, n=3
))
if not patch:
    raise SystemExit('STOP: no pinned difference ' + relative)
p = subprocess.run(
    ['patch', '--batch', '--forward', '--fuzz=0',
     '--no-backup-if-mismatch', '-p1', '-d', str(stage / 'candidate')],
    input=patch, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE
)
if p.returncode:
    raise SystemExit('STOP: incompatible live customization in ' + relative +
                     '\n' + p.stdout + p.stderr)
if candidate.read_text() == (stage / 'base' / relative).read_text():
    raise SystemExit('STOP: no change to ' + relative)
print('PASS: exact-context compatible ' + relative)
PY_PATCH
done < "$STAGE/manifest"

while IFS='|' read -r file base_sha target_sha; do
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    "$PHP" -l "$STAGE/candidate/$file" >/dev/null ||
      { echo "STOP: PHP lint failed: $file"; exit 2; }
  fi
done < "$STAGE/manifest"
echo "PASS: 16 pinned release files staged; PHP syntax verified."

if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT ONLY: production files and database unchanged."
  exit 0
fi

[[ "$(origin_code /)" == "200" && "$(origin_code /support-center)" == "200" ]] ||
  { echo "STOP: origin health changed"; exit 2; }
BACKUP="$(mktemp -d /root/bambodia-private-shells-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/original.list"
printf '%s\n' "$BASE" "$TARGET" > "$BACKUP/release-commits.txt"
while IFS='|' read -r file initial_sha; do
  [[ "$(git hash-object "$SITE/$file")" == "$initial_sha" ]] ||
    { echo "STOP: live file changed during verification: $file"; exit 2; }
done < "$STAGE/live-hashes"
echo "Backup: $BACKUP/original-code.tar"
DEPLOYING=1
while IFS='|' read -r file base_sha target_sha; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.bambodia-layout.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/candidate/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
done < "$STAGE/manifest"
(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)
[[ "$(origin_code /)" == "200" && "$(origin_code /support-center)" == "200" ]] ||
  { echo "STOP: live public smoke test failed"; exit 1; }
DEPLOYING=0
echo "SUCCESS: role-based private pages and Support Ticket navigation installed."
echo "RETAIN code backup: $BACKUP/original-code.tar"
echo "No database changes were made."
