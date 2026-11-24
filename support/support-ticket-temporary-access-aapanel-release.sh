#!/usr/bin/env bash
# Bambodia: premium temporary Support Ticket access UI, code-only release.
# Requires SHA-pinned sources, exact-context compatibility, preflight and rollback.
set -Eeuo pipefail
umask 077

[[ "$#" -eq 1 && ( "$1" == "--preflight" || "$1" == "--apply" ) ]] ||
  { echo "Usage: sudo bash release.sh --preflight|--apply"; exit 2; }
MODE="$1"
[[ "$(id -u)" -eq 0 ]] || { echo "STOP: Root required"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="835508cf911ed010d52a76ecf6633054a0958fc0"
TARGET="32d4b4df5e6c35d11fc7bf106a93e2649c1ca940"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"

for cmd in curl git patch python3 tar runuser mktemp install; do
  command -v "$cmd" >/dev/null || { echo "STOP: Missing $cmd"; exit 2; }
done
[[ -x "$PHP" && -f "$SITE/artisan" && -f "$SITE/vendor/autoload.php" && ! -L "$SITE" ]] ||
  { echo "STOP: Unexpected Laravel directory"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: APP_ENV is not production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: Nginx production root changed"; exit 2; }

health() {
  curl -4 -fLsS --connect-timeout 10 --max-time 20 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null \
    "https://bambodia.com$1"
}
health / && health /support-center ||
  { echo "STOP: Public site health check failed"; exit 2; }

STAGE="$(mktemp -d /root/bambodia-access-ui-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
finish() {
  status=$?
  trap - EXIT
  if [[ "$DEPLOYING" -eq 1 && -n "$BACKUP" ]]; then
    echo "ROLLBACK: Restoring original UI code" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP: $BACKUP/original-code.tar" >&2
  fi
  rm -rf -- "$STAGE"
  exit "$status"
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
    if [[ "$attempt" -lt 5 ]]; then sleep "$((attempt * 2))"; fi
  done
  echo "STOP: Download failed: $file" >&2
  return 1
}

mkdir -p "$STAGE"/{base,target,candidate}
cat > "$STAGE/manifest" <<'MANIFEST'
resources/views/support/credential-fields.blade.php|22bf52298a01319f8dcae0b36e28a404e20b6548|cb499160568e3c421e568b14404089455f10abc8
resources/views/support/secret-vault.blade.php|4a5edc78934f852daa8ae78d6be9009ed5ebb947|bf5c66a4ed3bcea3b8f8d75c86c4b1e0d959b93d
resources/views/support/show.blade.php|ec10eb715c871bd0f8ec4e6f8176e7ba376ec3e8|08e648d3ca86431789de11625947e37225fa7fd1
resources/views/support/create.blade.php|73f5ce2a518a667b5377899caff78cc4dc671ec1|2ccee00e54352e5d42e46e8cb7b118863cbd1efc
resources/views/support/edit.blade.php|c94621e3050cdeeb6d90f20da0c4035517c65716|562f18968f022e76225769df89013fa114ef06d2
public/css/support-tickets.css|da3a9c66c8def7a9fb12bc23e4e801ab5a6449ac|f1116233bde1debd71b8122242d6e9d58fe339d5
public/js/support-ticket-secrets.js|26b6a94eddc2801e09ded99f412e14c4ff3e604c|a905be2024f95d4dba0b3d958fa20b6e4d93676e
public/js/support-ticket-editor.js|4a72b61be083b878bb52184579112df4f5e27d7c|86736a07456d7f4c37d2ae53ca1b877216a06d73
MANIFEST

: > "$STAGE/changed.list"
: > "$STAGE/files.list"
: > "$STAGE/live.list"

echo "=== Verify pinned code and exact-context compatibility ==="
while IFS='|' read -r file oldsha newsha; do
  [[ "$file" != /* && "$file" != *..* ]] || { echo "STOP: Unsafe path"; exit 2; }
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: Missing or symlinked file: $file"; exit 2; }
  for folder in base target candidate; do
    mkdir -p "$STAGE/$folder/$(dirname "$file")"
  done
  download "$BASE" "$file" "$STAGE/base/$file"
  download "$TARGET" "$file" "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$oldsha" &&
    "$(git hash-object "$STAGE/target/$file")" == "$newsha" ]] ||
    { echo "STOP: Pinned blob SHA mismatch: $file"; exit 2; }
  printf '%s\n' "$file" >> "$STAGE/files.list"
  livehash="$(git hash-object "$SITE/$file")"
  printf '%s|%s\n' "$file" "$livehash" >> "$STAGE/live.list"
  cp -p -- "$SITE/$file" "$STAGE/candidate/$file"
  if [[ "$livehash" == "$newsha" ]]; then
    echo "PASS: Already current $file"
    continue
  fi

  python3 - "$STAGE" "$file" <<'PY_PATCH'
from difflib import unified_diff
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

stage=Path(sys.argv[1]); rel=sys.argv[2]
base=(stage/"base"/rel).read_text()
target=(stage/"target"/rel).read_text()
live=stage/"candidate"/rel
original=live.read_text()
diff="".join(unified_diff(
    base.splitlines(keepends=True),target.splitlines(keepends=True),
    fromfile="a/"+rel,tofile="b/"+rel,n=3
))
if not diff:
    raise SystemExit("STOP: Missing target patch for "+rel)
with tempfile.TemporaryDirectory(prefix="bambodia-access-ui-patch-") as tmp:
    root=Path(tmp); dest=root/rel
    dest.parent.mkdir(parents=True,exist_ok=True)
    dest.write_text(original)
    def apply(reverse=False):
        command=["patch","--batch","--fuzz=0","--no-backup-if-mismatch",
                 "-p1","-d",str(root)]
        command.insert(2,"--reverse" if reverse else "--forward")
        return subprocess.run(command,input=diff,text=True,capture_output=True)
    response=apply()
    if response.returncode==0:
        shutil.copyfile(dest,live)
        print("PASS: Exact-context compatible "+rel)
        raise SystemExit(0)
    dest.write_text(original)
    if apply(reverse=True).returncode==0 and apply().returncode==0 and dest.read_text()==original:
        print("PASS: Already applied with retained local customizations "+rel)
        raise SystemExit(0)
    raise SystemExit("STOP: Incompatible live code drift in "+rel+"\n"+response.stdout+response.stderr)
PY_PATCH
  if [[ "$(git hash-object "$STAGE/candidate/$file")" != "$livehash" ]]; then
    echo "$file" >> "$STAGE/changed.list"
  fi
done < "$STAGE/manifest"

# Preserve all scanner, credential-encryption and authorization code unchanged.
health / && health /support-center ||
  { echo "STOP: Site health changed during preflight"; exit 2; }
echo "PASS: All eight UI assets are compatible and source-SHA verified"
echo "Files requiring installation: $(wc -l < "$STAGE/changed.list")"
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT ONLY: No production files or databases changed"
  exit 0
fi

if [[ ! -s "$STAGE/changed.list" ]]; then
  echo "SUCCESS: Access UI already current"
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-access-ui-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/files.list"
echo "Backup: $BACKUP/original-code.tar"
while IFS='|' read -r file livehash; do
  [[ "$(git hash-object "$SITE/$file")" == "$livehash" ]] ||
    { echo "STOP: File changed during preflight: $file"; exit 2; }
done < "$STAGE/live.list"

DEPLOYING=1
while IFS= read -r file; do
  tmp="$(mktemp "$(dirname "$SITE/$file")/.bambodia-access-ui.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/candidate/$file" "$tmp"
  mv -f -- "$tmp" "$SITE/$file"
done < "$STAGE/changed.list"

(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
health / && health /support-center ||
  { echo "STOP: Site health failed after installation"; exit 1; }
DEPLOYING=0
echo "SUCCESS: Premium Support Ticket temporary access UI installed"
echo "RETAIN BACKUP: $BACKUP/original-code.tar"
echo "No database, scanner, credential or authorization changes"
