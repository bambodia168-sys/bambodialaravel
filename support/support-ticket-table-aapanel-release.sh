#!/usr/bin/env bash
# Guarded Bambodia Support Ticket table release: role-based CRUD, assignment, statuses.
# Preflight is read-only. Apply requires a recent full database backup.
# Live-only modifications are preserved by zero-fuzz, exact-context patches.
set -Eeuo pipefail
umask 077

[[ $# -ge 1 && $# -le 2 ]] || { echo "Usage: sudo bash script --preflight | --apply /path/to/database-backup.sql.gz"; exit 2; }
MODE="$1"
[[ "$MODE" == --preflight || "$MODE" == --apply ]] || exit 2
if [[ "$MODE" == --apply ]]; then [[ $# == 2 ]] || exit 2; fi
[[ "$(id -u)" == 0 ]] || { echo "STOP: run as root with sudo"; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BASE="55b6c0daf75b2c8768d06c16b75f0bc05dc713bd"
TARGET="1770c044dc43f3965a327b666ab6f2059231aa27"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MIGRATION="database/migrations/2026_11_24_150000_add_support_ticket_workflow_fields.php"

for binary in curl git python3 patch tar runuser install stat; do
  command -v "$binary" >/dev/null || { echo "STOP: missing $binary"; exit 2; }
done
[[ -f "$SITE/artisan" && -f "$SITE/.env" && -d "$SITE/vendor" && -x "$PHP" ]] ||
  { echo "STOP: unexpected site layout"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" ||
  { echo "STOP: APP_ENV is not production"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' \
  /www/server/panel/vhost/nginx/bambodia.com.conf ||
  { echo "STOP: nginx virtual host root differs"; exit 2; }
http() {
  curl -4 -sS --connect-timeout 8 --max-time 15 \
    --resolve bambodia.com:443:127.0.0.1 -o /dev/null \
    -w '%{http_code}' "https://bambodia.com$1"
}
[[ "$(http /)" == 200 && "$(http /support-center)" == 200 ]] ||
  { echo "STOP: origin or public Support Center is unhealthy"; exit 2; }

if [[ "$MODE" == --apply ]]; then
  DB_BACKUP="$2"
  [[ -f "$DB_BACKUP" && ! -L "$DB_BACKUP" && -s "$DB_BACKUP" ]] ||
    { echo "STOP: full database backup file is required before deployment"; exit 2; }
  [[ $(( $(date +%s) - $(stat -c %Y "$DB_BACKUP") )) -le 86400 ]] ||
    { echo "STOP: database backup is older than 24 hours"; exit 2; }
  echo "Operator-supplied recent database backup verified: $DB_BACKUP"
fi

STAGE="$(mktemp -d /root/bambodia-support-tables-stage.XXXXXXXX)"
BACKUP=""
DEPLOYING=0
MIGRATION_INSTALLED=0
cleanup() {
  result=$?
  trap - EXIT
  if [[ "$DEPLOYING" == 1 ]]; then
    echo "ROLLBACK: restoring original source files (database migration left intact)" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    while IFS= read -r new_path; do
      if [[ "$new_path" != "$MIGRATION" ]]; then rm -f -- "$SITE/$new_path"; fi
    done < "$STAGE/new.list"
    (cd "$SITE" &&
      runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
      runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN: $BACKUP and migration file; inspect MySQL schema before retry" >&2
  fi
  rm -rf -- "$STAGE"
  exit "$result"
}
trap cleanup EXIT

mkdir -p "$STAGE"/{base,target,candidates}
cat > "$STAGE/manifest" <<'FILES'
app/Http/Controllers/Dashboard/SupportCenterController.php|5091167bd44c9688c920a849a62a879115925ef4|a9cd97c0a53c8f69ea9806e396db719440766886
app/Http/Controllers/SupportController.php|c5f97172d88deefda386fe8b2f32989d5fb35715|53a26374ae45c1529086dc9cebaf3d078e71e775
app/Models/AuditEvent.php|2e1d57a7d2eeee974017ab557a6e0483e566dc3b|f123052b914bdf29ed6f54f2b2bf45fb7abad994
app/Models/SupportTicket.php|33195331c098132115f47a37e8d6f90e2b1d71f6|eebeee21ad80f8ce46559e33c2a331ed60b4f9af
public/css/support-tickets.css|8e4f1e9fe691303f0e432547e462325543bbe2a9|023e93da77441fddd5e894ca748f2b06160f6383
resources/views/dashboard/support.blade.php|f284edc94f0604c058bf36ac31a9c7c606c738d2|359c5fde2dacf64aa7ab1ebc996de88528fba4df
resources/views/support/editor.blade.php|eb28abc6fcb8c1acb0f5ae17e6a81c686a43b85a|a48bfa14139df63249b5e7d6fdd3b9f13e3b69b2
resources/views/support/index.blade.php|e4d9f11f482d02b9c3bb07250858e730f431ef40|13093a84ef9d9a825cbc32e090711fa047500906
resources/views/support/show.blade.php|cb8463eab3339d648ec347cdbf5a568307713f0b|ab5e71aeec655136d90356114a9afb93c2fd35e9
routes/web.php|a5856ca0a14b4a0dc96331df20d22102f36f8a93|091edc229fa3629f2d4e6424c38e5e3d7a7d9a4d
app/Support/SupportTicketTable.php|NEW|0fb147f95b419605389d7a836ae01e22c85fb42d
database/migrations/2026_11_24_150000_add_support_ticket_workflow_fields.php|NEW|6e28c3c3a19497e7eabca6ae771afaf19ff615aa
resources/views/support/edit.blade.php|NEW|ac412d32612fd947de4d4a88e88f4822def2e6b6
resources/views/support/filters.blade.php|NEW|691aa43373a7ac5e29b2815e657601e4429e03fc
resources/views/support/pager.blade.php|NEW|2a2cc96c04c7c7c7a3a2ff2b7a16f69bf80723c3
resources/views/support/row-actions.blade.php|NEW|1fc6baa449074c7bf8737f2560e2ad5156c0bb53
FILES
: > "$STAGE/old.list"
: > "$STAGE/new.list"
: > "$STAGE/live-hashes"

while IFS='|' read -r file old_sha new_sha; do
  for snapshot in base target candidates; do mkdir -p "$STAGE/$snapshot/$(dirname "$file")"; done
  curl -fLsS --retry 2 --connect-timeout 8 --max-time 60 \
    "https://raw.githubusercontent.com/$REPO/$TARGET/$file" -o "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/target/$file")" == "$new_sha" ]] ||
    { echo "STOP: target integrity check failed: $file"; exit 1; }
  if [[ "$old_sha" == NEW ]]; then
    [[ ! -e "$SITE/$file" && ! -L "$SITE/$file" ]] ||
      { echo "STOP: new release path already exists: $file"; exit 1; }
    cp "$STAGE/target/$file" "$STAGE/candidates/$file"
    echo "$file" >> "$STAGE/new.list"
    echo "PASS: new file ready $file"
    continue
  fi

  [[ ! -L "$SITE/$file" && -f "$SITE/$file" ]] ||
    { echo "STOP: missing or symlinked live source $file"; exit 1; }
  curl -fLsS --retry 2 --connect-timeout 8 --max-time 60 \
    "https://raw.githubusercontent.com/$REPO/$BASE/$file" -o "$STAGE/base/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$old_sha" ]] ||
    { echo "STOP: base source integrity check failed: $file"; exit 1; }
  echo "$file" >> "$STAGE/old.list"
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
    print('PASS: already-current ' + relative)
    raise SystemExit(0)
patch = ''.join(unified_diff(
    base.decode('utf-8').splitlines(keepends=True),
    target.decode('utf-8').splitlines(keepends=True),
    fromfile='a/' + relative, tofile='b/' + relative, n=3
))
if not patch:
    raise SystemExit('STOP: empty patch ' + relative)
result = subprocess.run(
    ['patch','--batch','--forward','--fuzz=0','--no-backup-if-mismatch',
     '-p1','-d',str(stage / 'candidates')],
    input=patch,text=True,capture_output=True
)
if result.returncode != 0:
    raise SystemExit('STOP: live drift ' + relative + '\n' + result.stdout + '\n' + result.stderr)
if candidate.read_bytes() == live:
    raise SystemExit('STOP: patch was not applied ' + relative)
print('PASS: compatible live source ' + relative)
PY_PATCH
done < "$STAGE/manifest"

while IFS='|' read -r file old_sha new_sha; do
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    "$PHP" -l "$STAGE/candidates/$file" >/dev/null ||
      { echo "STOP: PHP lint failed: $file"; exit 1; }
  fi
done < "$STAGE/manifest"

[[ "$(http /)" == 200 && "$(http /support-center)" == 200 ]] ||
  { echo "STOP: origin became unhealthy during preflight"; exit 1; }
echo "PASS: staged sources, zero-fuzz compatibility and PHP syntax."
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT ONLY: no production files or databases modified."
  exit 0
fi

BACKUP="$(mktemp -d /root/bambodia-support-tables-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$STAGE/old.list"
printf '%s\n' "$DB_BACKUP" > "$BACKUP/database-backup-path.txt"
echo "Source backup: $BACKUP/original-code.tar"

while IFS='|' read -r file live_sha; do
  [[ "$(git hash-object "$SITE/$file")" == "$live_sha" ]] ||
    { echo "STOP: source changed during review: $file"; exit 1; }
done < "$STAGE/live-hashes"

DEPLOYING=1
migration_dest="$SITE/$MIGRATION"
install -d -o www -g www "$(dirname "$migration_dest")"
install -o www -g www -m 0644 "$STAGE/candidates/$MIGRATION" "$migration_dest"
MIGRATION_INSTALLED=1

echo "Applying additive support ticket schema migration..."
(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan migrate --path="$MIGRATION" --force --no-ansi)

while IFS='|' read -r file old_sha new_sha; do
  [[ "$file" != "$MIGRATION" ]] || continue
  destination="$SITE/$file"
  install -d -o www -g www "$(dirname "$destination")"
  temp="$(mktemp "$(dirname "$destination")/.support-release.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/candidates/$file" "$temp"
  mv -f "$temp" "$destination"
done < "$STAGE/manifest"

(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json --no-ansi) |
  python3 -c '
import json,sys
routes={item.get("name"):item for item in json.load(sys.stdin)}
for prefix in ("account.support","studio.support-tickets","dashboard.support-tickets"):
    for name in ("index","create","show","edit","update","destroy","reply","store"):
        assert prefix+"."+name in routes, prefix+"."+name
for name in ("dashboard.support","dashboard.support.status","dashboard.support.assign",
             "dashboard.support.edit","dashboard.support.update","dashboard.support.destroy"):
    assert name in routes, name
'
[[ "$(http /)" == 200 && "$(http /support-center)" == 200 ]] ||
  { echo "STOP: live HTTP smoke failed; restoring old code"; exit 1; }

DEPLOYING=0
echo "SUCCESS: premium Support Ticket tables and guarded workflow installed."
echo "RETAIN code backup: $BACKUP/original-code.tar"
echo "RETAIN database backup: $DB_BACKUP"
echo "No automatic database rollback: the new columns are additive and preserve records."
