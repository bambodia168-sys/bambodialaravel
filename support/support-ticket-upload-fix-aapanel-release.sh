#!/usr/bin/env bash
# Bambodia PR #174 Support Ticket icon + safe attachment scan release.
# Production preflight checks original temporary-upload scanner and private
# byte-for-byte file hashes; never bypasses ClamAV or migration checks.
set -Eeuo pipefail
umask 077

[[ "$#" -eq 1 && ( "$1" == --preflight || "$1" == --apply ) ]] ||
  { echo "Usage: sudo bash release.sh --preflight|--apply"; exit 2; }
MODE="$1"
[[ "$(id -u)" -eq 0 ]] || { echo "STOP: require root"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="ac41cb54e631b5140e8bb47a0c1ffb89893cacbd"
TARGET="79cc8f7b2b8f3c51fd86ab0c19cfea802aaca808"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
PROBE="support/operator/support-ticket-upload-scanner-probe.php"
PROBE_SHA="129709a6cf204e3b0734b5b7b36153dab70bfe12"

for command in curl git patch python3 tar runuser mktemp install; do
  command -v "$command" >/dev/null || { echo "STOP: missing $command"; exit 2; }
done
[[ -x "$PHP" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && ! -L "$SITE" ]] ||
  { echo "STOP: production Laravel path changed"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: APP_ENV must be production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: Bambodia production Nginx root changed"; exit 2; }
health() {
  curl -4 -fLsS --connect-timeout 10 --max-time 20 \
    --resolve bambodia.com:443:127.0.0.1 \
    -o /dev/null "https://bambodia.com$1"
}
health / && health /support-center ||
  { echo "STOP: public site health check failed"; exit 2; }

STAGE="$(mktemp -d /root/bambodia-ticket-upload-stage.XXXXXXXX)"
PROBE_TMP=""
BACKUP=""
DEPLOYING=0
finish() {
  result=$?
  trap - EXIT
  if [[ "$DEPLOYING" -eq 1 ]]; then
    echo "ROLLBACK: restoring original code (no database changes)." >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    (cd "$SITE" &&
      runuser -u www -- "$PHP" artisan view:clear --no-ansi &&
      runuser -u www -- "$PHP" artisan route:clear --no-ansi) || true
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  [[ -z "$PROBE_TMP" ]] || rm -f -- "$PROBE_TMP"
  rm -rf -- "$STAGE"
  exit "$result"
}
trap finish EXIT

download() {
  local commit="$1" file="$2" output="$3" attempt
  for attempt in 1 2 3 4 5; do
    if curl -fLsS --connect-timeout 12 --max-time 100 \
      "https://raw.githubusercontent.com/$REPO/$commit/$file" -o "$output"; then
      return 0
    fi
    rm -f -- "$output"
    if [[ "$attempt" -lt 5 ]]; then
      echo "RETRY: GitHub TLS/source for $file ($attempt/5)" >&2
      sleep "$((attempt * 2))"
    fi
  done
  echo "STOP: source download failed: $file" >&2
  return 1
}

echo "=== Verify ClamAV on the actual upload-storage path ==="
PROBE_TMP="$(mktemp /tmp/bambodia-ticket-upload-probe.XXXXXXXX.php)"
download "$TARGET" "$PROBE" "$PROBE_TMP"
[[ "$(git hash-object "$PROBE_TMP")" == "$PROBE_SHA" ]] ||
  { echo "STOP: scanner diagnostic SHA mismatch"; exit 2; }
chmod 0644 "$PROBE_TMP"
runuser -u www -- "$PHP" "$PROBE_TMP" ||
  { echo "STOP: PHP temporary upload security scan failed"; exit 2; }

mkdir -p "$STAGE"/{base,target,candidate}
cat > "$STAGE/manifest" <<'MANIFEST'
app/Support/SupportTicketFiles.php|c1b933fa8830eb945fad23a7a9dab3ea3402a0e5|562ac9a7cad70e14fd071d577c060a5bc5dc57fb
public/css/support-tickets.css|d75981a047c8fe9cee5e7953dc08608488b94d36|da3a9c66c8def7a9fb12bc23e4e801ab5a6449ac
resources/views/components/icon.blade.php|9bf6c221456cb497e50f7f7c61f96e58c331e678|ced6c0aae1b5a688fc994383cf384344e339d70e
resources/views/dashboard/support.blade.php|f076e1030cc249d0f7d4c04dd84979a5836ca34f|2d680eef48879f62279b0888eb2abcfbbd0d2bae
resources/views/support/create.blade.php|f4d1ef850d21cb0d4ea2ecae117912318b992633|73f5ce2a518a667b5377899caff78cc4dc671ec1
resources/views/support/edit.blade.php|34a3c8c042a225f18778fa12394f68ab0c32b40d|c94621e3050cdeeb6d90f20da0c4035517c65716
resources/views/support/index.blade.php|d63ed5d885abd03f3f08a8c17bacefca6f687f74|8464a7108555639e59fdcd8fe91764a8261f02e0
resources/views/support/show.blade.php|f59d1c3ab2b5fbfb62065baae571dd70413b173e|ec10eb715c871bd0f8ec4e6f8176e7ba376ec3e8
MANIFEST

: > "$STAGE/changed.list"
: > "$STAGE/files.list"
: > "$STAGE/live.list"

while IFS='|' read -r file oldsha newsha; do
  [[ "$file" != /* && "$file" != *..* ]] || { echo "STOP: invalid path"; exit 2; }
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: live source missing or symlinked: $file"; exit 2; }
  for dir in base target candidate; do
    mkdir -p "$STAGE/$dir/$(dirname "$file")"
  done
  download "$BASE" "$file" "$STAGE/base/$file"
  download "$TARGET" "$file" "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$oldsha" &&
     "$(git hash-object "$STAGE/target/$file")" == "$newsha" ]] ||
    { echo "STOP: source SHA mismatch: $file"; exit 2; }
  printf '%s\n' "$file" >> "$STAGE/files.list"
  livehash="$(git hash-object "$SITE/$file")"
  printf '%s|%s\n' "$file" "$livehash" >> "$STAGE/live.list"
  cp -p "$SITE/$file" "$STAGE/candidate/$file"
  if [[ "$livehash" == "$newsha" ]]; then
    echo "PASS: already current $file"
    continue
  fi

  python3 - "$STAGE" "$file" <<'PY_PATCH'
import shutil, subprocess, sys, tempfile
from pathlib import Path
from difflib import unified_diff
stage=Path(sys.argv[1]); rel=sys.argv[2]
base=(stage/"base"/rel).read_text()
target=(stage/"target"/rel).read_text()
live=(stage/"candidate"/rel)
original=live.read_text()
diff="".join(unified_diff(base.splitlines(keepends=True),
    target.splitlines(keepends=True),fromfile="a/"+rel,tofile="b/"+rel,n=3))
if not diff: raise SystemExit("STOP: no patch: "+rel)
with tempfile.TemporaryDirectory(prefix="bambodia-ticket-patch-") as tmp:
    root=Path(tmp); dst=root/rel;dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_text(original)
    def patch(reverse=False):
        args=["patch","--batch","--fuzz=0","--no-backup-if-mismatch","-p1","-d",str(root)]
        args.insert(2,"--reverse" if reverse else "--forward")
        return subprocess.run(args,input=diff,text=True,capture_output=True)
    result=patch()
    if result.returncode==0:
        shutil.copyfile(dst,live)
        print("PASS: exact-context compatible "+rel)
        raise SystemExit(0)
    dst.write_text(original)
    backward=patch(reverse=True)
    if backward.returncode==0 and patch().returncode==0 and dst.read_text()==original:
        print("PASS: already applied with retained customizations "+rel)
        raise SystemExit(0)
    raise SystemExit("STOP: incompatible live drift "+rel+"\n"+result.stdout+result.stderr)
PY_PATCH
  if [[ "$(git hash-object "$STAGE/candidate/$file")" != "$livehash" ]]; then
    echo "$file" >> "$STAGE/changed.list"
  fi
done < "$STAGE/manifest"

"$PHP" -l "$STAGE/candidate/app/Support/SupportTicketFiles.php" >/dev/null
health / && health /support-center ||
  { echo "STOP: public health check changed"; exit 2; }
echo "PASS: private scanner diagnostic, SHA-pinned code, exact-context patch and PHP syntax."
echo "Files needing installation: $(wc -l < "$STAGE/changed.list")."
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT ONLY: production application code and database unchanged."
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-ticket-upload-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/files.list"
echo "Backup: $BACKUP/original-code.tar"
while IFS='|' read -r file livehash; do
  [[ "$(git hash-object "$SITE/$file")" == "$livehash" ]] ||
    { echo "STOP: source changed during preflight: $file"; exit 2; }
done < "$STAGE/live.list"
DEPLOYING=1
while IFS= read -r file; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.bambodia-upload.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/candidate/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
done < "$STAGE/changed.list"
(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi)
health / && health /support-center ||
  { echo "STOP: site health check failed after installation"; exit 1; }
DEPLOYING=0
echo "SUCCESS: private Support Ticket attachment scan and SVG alignment corrected."
echo "RETAIN BACKUP: $BACKUP/original-code.tar"
echo "No database migration or customer ticket data changes."
