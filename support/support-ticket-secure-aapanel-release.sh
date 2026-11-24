#!/usr/bin/env bash
# Bambodia Support Ticket private attachment + secure credential release.
# Preflight stages only; apply requires a recent full database backup.
# Source verification uses pinned Git blob SHA and zero-fuzz live patching.
set -Eeuo pipefail
umask 077

[[ $# -ge 1 && $# -le 2 ]] || {
  echo "Usage: sudo bash script --preflight | --apply /root/verified-database.sql.gz"
  exit 2
}
MODE="$1"
[[ "$MODE" == --preflight || "$MODE" == --apply ]] || exit 2
[[ "$MODE" != --apply || $# -eq 2 ]] || exit 2
[[ "$(id -u)" == 0 ]] || { echo "STOP: run as root"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="6335e19fcac52e015698f9b36903b0930cd76c65"
TARGET="16e31e3f12a7c206575f97076da396bf7a821889"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MIGRATION="database/migrations/2026_11_24_210000_add_ticket_attachments_context_and_secure_credentials.php"

for cmd in curl git patch python3 tar gzip runuser install mktemp stat; do
  command -v "$cmd" >/dev/null || { echo "STOP: missing $cmd"; exit 2; }
done
[[ -f "$SITE/artisan" && -f "$SITE/.env" && -d "$SITE/vendor" && -x "$PHP" && ! -L "$SITE" ]] ||
  { echo "STOP: unexpected Laravel production site"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: APP_ENV must be production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: unexpected Bambodia nginx document root"; exit 2; }

local_http() {
  curl -4 -sS --connect-timeout 10 --max-time 20 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null \
    -w '%{http_code}' "https://bambodia.com$1"
}
[[ "$(local_http /)" == 200 && "$(local_http /support-center)" == 200 ]] ||
  { echo "STOP: public Bambodia origin health check failed"; exit 2; }

if [[ "$MODE" == --apply ]]; then
  DB_BACKUP="$2"
  [[ -s "$DB_BACKUP" && -f "$DB_BACKUP" && ! -L "$DB_BACKUP" ]] ||
    { echo "STOP: verified full MySQL backup path required"; exit 2; }
  gzip -t "$DB_BACKUP" || { echo "STOP: invalid SQL backup archive"; exit 2; }
  AGE=$(( $(date +%s) - $(stat -c %Y "$DB_BACKUP") ))
  [[ "$AGE" -ge 0 && "$AGE" -le 86400 ]] ||
    { echo "STOP: database backup must be less than 24 hours old"; exit 2; }
  echo "PASS: recent gzip-verified database backup: $DB_BACKUP"
fi

STAGE="$(mktemp -d /root/bambodia-support-secure-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
SCAN_PROBE=""
finish() {
  code=$?
  trap - EXIT
  if [[ "$DEPLOYING" == 1 ]]; then
    echo "ROLLBACK: restoring original application files; additive database migration is NOT auto-rolled back." >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    while IFS= read -r newfile; do
      [[ "$newfile" == "$MIGRATION" ]] || rm -f -- "$SITE/$newfile"
    done < "$STAGE/new.list"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
      runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN database backup and source archive: $BACKUP" >&2
  fi
  [[ -z "$SCAN_PROBE" ]] || rm -f -- "$SCAN_PROBE"
  rm -rf -- "$STAGE"
  exit "$code"
}
trap finish EXIT

# ClamAV is a strict requirement for any attachment acceptance.
# The inline PHP probe must load Composer's autoloader before Laravel's
# bootstrap/app.php, exactly as the Artisan entrypoint does.
[[ -r "$SITE/vendor/autoload.php" ]] ||
  { echo "STOP: Composer autoloader not readable: $SITE/vendor/autoload.php"; exit 2; }
SCAN_PROBE="$(mktemp /tmp/bambodia-support-scan.XXXXXXXX.txt)"
printf '%s\n' 'Bambodia harmless scanner readiness check' > "$SCAN_PROBE"
chmod 0644 "$SCAN_PROBE"
(cd "$SITE" && runuser -u www -- "$PHP" -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $result = app(\App\Support\VaultMalwareScanner::class)->scanFile($argv[1]);
  if (($result["status"] ?? "") !== "clean") {
    fwrite(STDERR, "STOP: ClamAV scanner unavailable, stale or failed. Uploads must fail closed.\n");
    exit(1);
  }
  echo "PASS: active ClamAV with fresh verified signatures\n";
' "$SCAN_PROBE")

download_pinned() {
  local commit="$1" file="$2" output="$3" attempt
  for attempt in 1 2 3 4 5; do
    if curl -fLsS --connect-timeout 12 --max-time 100 \
      "https://raw.githubusercontent.com/$REPO/$commit/$file" -o "$output"; then
      return 0
    fi
    rm -f -- "$output"
    echo "RETRY: GitHub source $file attempt $attempt/5" >&2
    [[ "$attempt" == 5 ]] || sleep "$(( attempt * 2 ))"
  done
  echo "STOP: failed secure download: $file" >&2
  return 1
}

mkdir -p "$STAGE"/{base,target,candidates}
cat > "$STAGE/manifest" <<'MANIFEST'
app/Http/Controllers/Dashboard/SupportCenterController.php|a9cd97c0a53c8f69ea9806e396db719440766886|450a5e0c2ea0abd89082c8079bba7c84cdf41a5b
app/Http/Controllers/SupportController.php|f40b60d4439fff479c6fb9c2b8c85342996e4e1f|79a2ad42f9790491bcf433bac14132b491540008
app/Http/Controllers/SupportTicketResourceController.php|NEW|31082634438ea686efb13b5df76d11ecb060a9c8
app/Models/AuditEvent.php|f123052b914bdf29ed6f54f2b2bf45fb7abad994|3a6cf98650a95cceb3a822dc3b4579c5dc2e4d69
app/Models/SupportMessage.php|e4247a7991ef64b7b72f57859714de0f04b53f44|44422b337f80126b41422c98b9903698c36e0f36
app/Models/SupportTicket.php|eebeee21ad80f8ce46559e33c2a331ed60b4f9af|e8b91d13886eb836c4d7359c686aec469166580a
app/Models/SupportTicketAttachment.php|NEW|d657c332ea12a20edc5f5031a719835e9a321150
app/Models/SupportTicketCredential.php|NEW|2c4eb715f4550416f6aaf9290d9c4ecbe5642052
app/Support/SupportTicketContext.php|NEW|983da9ff97c14a4e9d972b5c9d8c0b8ff1f6bded
app/Support/SupportTicketFiles.php|NEW|c1b933fa8830eb945fad23a7a9dab3ea3402a0e5
app/Support/SupportTicketSecrets.php|NEW|895458021e56e1256c949a3657134d6ad16403b3
app/Support/SupportTicketTable.php|0fb147f95b419605389d7a836ae01e22c85fb42d|3b5fc74166fb9f23ce37dbd2de9cca4945bf815f
bootstrap/app.php|d3811de4fc3e253f9699a15cd6e3869afa204ed5|14aedbbf00c32635335e173f1ea35685a9a422c4
database/migrations/2026_11_24_210000_add_ticket_attachments_context_and_secure_credentials.php|NEW|47e1cd206f4d34d960703316801344019c570cca
public/css/support-tickets.css|023e93da77441fddd5e894ca748f2b06160f6383|d75981a047c8fe9cee5e7953dc08608488b94d36
public/js/support-ticket-editor.js|01c913578138a5be3f703e97b3fa91ae9f477fd6|4a72b61be083b878bb52184579112df4f5e27d7c
public/js/support-ticket-secrets.js|NEW|26b6a94eddc2801e09ded99f412e14c4ff3e604c
resources/views/dashboard/support.blade.php|359c5fde2dacf64aa7ab1ebc996de88528fba4df|f076e1030cc249d0f7d4c04dd84979a5836ca34f
resources/views/support/attachment-fields.blade.php|NEW|8466bd7ba908fd61747da43855f32ef980620f20
resources/views/support/create.blade.php|271407e6b01b843cc7942cb1067b2cd18de7f4c9|f4d1ef850d21cb0d4ea2ecae117912318b992633
resources/views/support/credential-fields.blade.php|NEW|22bf52298a01319f8dcae0b36e28a404e20b6548
resources/views/support/edit.blade.php|cf85401dc0da6383f14724b97e6d2fef8d867762|34a3c8c042a225f18778fa12394f68ab0c32b40d
resources/views/support/editor.blade.php|a48bfa14139df63249b5e7d6fdd3b9f13e3b69b2|1d189590f7e3c472d7a6d2ef1fc1e13399182ce5
resources/views/support/filters.blade.php|691aa43373a7ac5e29b2815e657601e4429e03fc|40578f79fcc4004d07bbc68a4d428dbfbe938da4
resources/views/support/index.blade.php|b7d637290f52fefa3fdf1f7717f24832df413a75|d63ed5d885abd03f3f08a8c17bacefca6f687f74
resources/views/support/issue-fields.blade.php|NEW|c2625e639a4dae32c86f005b247c55b18dddd480
resources/views/support/secret-vault.blade.php|NEW|4a5edc78934f852daa8ae78d6be9009ed5ebb947
resources/views/support/show.blade.php|34aff3fd27b6bd93e1f2674533bfa7afaef0948a|f59d1c3ab2b5fbfb62065baae571dd70413b173e
routes/console.php|1f7b51696569bd27ffbf57a5604cb74343ce6d98|967c45ae9b3f3d46ca5c0a68e52a8bfc6ca22ffb
routes/web.php|091edc229fa3629f2d4e6424c38e5e3d7a7d9a4d|198146f5b3e6bb38b64aadaa10a511421abdb911
MANIFEST
: > "$STAGE/old.list"
: > "$STAGE/new.list"
: > "$STAGE/changed.list"
: > "$STAGE/live-hashes"

while IFS='|' read -r file oldsha newsha; do
  [[ "$file" != /* && "$file" != *..* && "$file" != *$'\r'* ]] ||
    { echo "STOP: unsafe release path"; exit 2; }
  for folder in base target candidates; do
    mkdir -p "$STAGE/$folder/$(dirname "$file")"
  done
  download_pinned "$TARGET" "$file" "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/target/$file")" == "$newsha" ]] ||
    { echo "STOP: pinned target SHA mismatch for $file"; exit 2; }

  if [[ "$oldsha" == NEW ]]; then
    if [[ -e "$SITE/$file" || -L "$SITE/$file" ]]; then
      [[ ! -L "$SITE/$file" && "$(git hash-object "$SITE/$file")" == "$newsha" ]] ||
        { echo "STOP: newly introduced path already modified: $file"; exit 2; }
      echo "PASS: already installed new file $file"
      continue
    fi
    cp "$STAGE/target/$file" "$STAGE/candidates/$file"
    echo "$file" >> "$STAGE/new.list"
    echo "$file" >> "$STAGE/changed.list"
    echo "PASS: new file staged $file"
    continue
  fi

  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: missing/symlinked production file $file"; exit 2; }
  download_pinned "$BASE" "$file" "$STAGE/base/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$oldsha" ]] ||
    { echo "STOP: pinned base SHA mismatch for $file"; exit 2; }
  echo "$file" >> "$STAGE/old.list"
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$STAGE/live-hashes"
  cp -p "$SITE/$file" "$STAGE/candidates/$file"

  python3 - "$STAGE" "$file" <<'PY_PATCH'
from pathlib import Path
from difflib import unified_diff
from tempfile import TemporaryDirectory
import shutil
import subprocess
import sys

stage = Path(sys.argv[1])
rel = sys.argv[2]
live = stage / 'candidates' / rel
base = (stage / 'base' / rel).read_text()
target = (stage / 'target' / rel).read_text()
original = live.read_text()
if original == target:
    print('PASS: already current ' + rel)
    raise SystemExit(0)

diff = ''.join(unified_diff(
    base.splitlines(keepends=True), target.splitlines(keepends=True),
    fromfile='a/' + rel, tofile='b/' + rel, n=3
))
if not diff:
    raise SystemExit('STOP: empty source patch ' + rel)

with TemporaryDirectory(prefix='bambodia-ticket-patch-') as directory:
    root = Path(directory)
    candidate = root / rel
    candidate.parent.mkdir(parents=True)
    candidate.write_text(original)
    def apply(reverse=False):
        args = ['patch', '--batch', '--forward', '--fuzz=0',
                '--no-backup-if-mismatch', '-p1', '-d', str(root)]
        if reverse:
            args.insert(1, '--reverse')
            args.remove('--forward')
        return subprocess.run(args, input=diff, text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    result = apply()
    if result.returncode == 0:
        shutil.copyfile(candidate, live)
        print('PASS: exact-context compatible ' + rel)
        raise SystemExit(0)

    # Re-running after a successful customized deployment must not attempt
    # to re-apply the patch: prove reverse + forward produces byte-identical
    # original, otherwise fail closed on genuine live drift.
    candidate.write_text(original)
    backwards = apply(reverse=True)
    if backwards.returncode == 0:
        forwards = apply()
        if forwards.returncode == 0 and candidate.read_text() == original:
            print('PASS: already patched with retained live customizations ' + rel)
            raise SystemExit(0)

    raise SystemExit('STOP: incompatible live drift: ' + rel + '\n' +
                     result.stdout + result.stderr)
PY_PATCH
  if [[ "$(git hash-object "$SITE/$file")" != "$(git hash-object "$STAGE/candidates/$file")" ]]; then
    echo "$file" >> "$STAGE/changed.list"
  fi
done < "$STAGE/manifest"

while IFS='|' read -r file oldsha newsha; do
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    if [[ -f "$STAGE/candidates/$file" ]]; then
      "$PHP" -l "$STAGE/candidates/$file" >/dev/null ||
        { echo "STOP: PHP lint failed $file"; exit 2; }
    fi
  fi
done < "$STAGE/manifest"

[[ "$(local_http /)" == 200 && "$(local_http /support-center)" == 200 ]] ||
  { echo "STOP: origin changed while checking release"; exit 2; }
echo "PASS: pinned source integrity, ClamAV, zero-fuzz compatibility and syntax."
echo "Files requiring installation: $(wc -l < "$STAGE/changed.list")."

if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT ONLY: production code and database unchanged."
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-support-secure-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/old.list"
printf '%s\n' "$DB_BACKUP" > "$BACKUP/database-backup-path.txt"
echo "Retain code backup: $BACKUP/original-code.tar"

while IFS='|' read -r file sha; do
  [[ "$(git hash-object "$SITE/$file")" == "$sha" ]] ||
    { echo "STOP: live file changed during preflight: $file"; exit 2; }
done < "$STAGE/live-hashes"
while IFS= read -r file; do
  [[ ! -e "$SITE/$file" && ! -L "$SITE/$file" ]] ||
    { echo "STOP: new file appeared during preflight: $file"; exit 2; }
done < "$STAGE/new.list"

DEPLOYING=1
install -d -o www -g www "$(dirname "$SITE/$MIGRATION")"
if [[ ! -e "$SITE/$MIGRATION" ]]; then
  install -o www -g www -m 0644 "$STAGE/candidates/$MIGRATION" "$SITE/$MIGRATION"
fi

echo "Applying additive support ticket security schema migration..."
(cd "$SITE" && runuser -u www -- "$PHP" artisan migrate \
  --path="$MIGRATION" --force --no-ansi)

while IFS= read -r file; do
  [[ "$file" == "$MIGRATION" ]] && continue
  destination="$SITE/$file"
  install -d -o www -g www "$(dirname "$destination")"
  tmp="$(mktemp "$(dirname "$destination")/.support-secure.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/candidates/$file" "$tmp"
  mv -f "$tmp" "$destination"
done < "$STAGE/changed.list"

(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)

(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json --no-ansi) |
python3 -c '
import json, sys
routes = {r.get("name") for r in json.load(sys.stdin)}
needed = {"support.attachments.download", "support.attachments.preview", "support.credentials.store",
          "support.credentials.reveal", "support.credentials.revoke",
          "account.support.create", "studio.support-tickets.create",
          "dashboard.support-tickets.create"}
missing = needed - routes
if missing:
    raise SystemExit("STOP: support routes missing: " + ", ".join(sorted(missing)))
print("PASS: secure attachment and credential routes registered")
'

[[ "$(local_http /)" == 200 && "$(local_http /support-center)" == 200 ]] ||
  { echo "STOP: production origin failed post-installation smoke test"; exit 1; }
DEPLOYING=0
echo "SUCCESS: private attachments, issue/listing contexts and encrypted ticket access installed."
echo "RETAIN: $BACKUP/original-code.tar"
echo "RETAIN: $DB_BACKUP"
echo "Database schema is additive. No automatic database rollback."
