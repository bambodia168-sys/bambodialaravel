#!/usr/bin/env bash
# Synthetic encrypted Restic backup + restore acceptance.
# Ephemeral CI only; no Cloudflare/R2, real DB/site files or live host contact.
set -Eeuo pipefail
set +x
umask 077

command -v restic >/dev/null
command -v python3 >/dev/null
test "$CI" = "true"
test -n "$RUNNER_TEMP"
test -d "$RUNNER_TEMP"

SCRATCH="$(mktemp -d "$RUNNER_TEMP/bambodia-restic-synthetic.XXXXXXXX")"
cleanup() {
  case "$SCRATCH" in
    "$RUNNER_TEMP"/bambodia-restic-synthetic.*)
      test ! -L "$SCRATCH" && rm -rf --one-file-system -- "$SCRATCH" ;;
    *) echo "SYNTHETIC_CLEANUP_GUARD=BLOCKED" >&2; exit 1 ;;
  esac
}
trap cleanup EXIT

python3 - "$SCRATCH/password" <<'PY'
import os
import secrets
import sys
with open(sys.argv[1],"x",encoding="ascii") as f:
    os.chmod(sys.argv[1],0o600)
    f.write(secrets.token_urlsafe(64))
    f.write("\n")
PY

export RESTIC_REPOSITORY="$SCRATCH/encrypted-repo"
export RESTIC_PASSWORD_FILE="$SCRATCH/password"
export RESTIC_CACHE_DIR="$SCRATCH/cache"
mkdir -m 700 "$SCRATCH/media"

printf '%s\n' "SYNTHETIC_MEDIA_NO_CUSTOMER_DATA" > "$SCRATCH/media/synthetic-media.txt"

restic init --quiet >/dev/null
echo "SYNTHETIC_REPOSITORY_INIT=PASS"

restic backup --quiet --tag synthetic-db \
  --stdin-filename synthetic-database.sql \
  --stdin-from-command -- \
  python3 -c 'import sys; sys.stdout.write("CREATE TABLE SYNTHETIC_ONLY (id INT);\n")' >/dev/null

restic backup --quiet --tag synthetic-media "$SCRATCH/media" >/dev/null
echo "SYNTHETIC_ENCRYPTED_BACKUP=PASS"

restic check --quiet >/dev/null
echo "SYNTHETIC_REPOSITORY_INTEGRITY=PASS"

restic snapshots --json > "$SCRATCH/inventory.json"
python3 - "$SCRATCH/inventory.json" "$SCRATCH/db-id" "$SCRATCH/media-id" <<'PY'
import json
from pathlib import Path
import sys
snapshots=json.loads(Path(sys.argv[1]).read_text())
assert len(snapshots)==2, "Expected exactly two synthetic snapshots"
for label,path in (("synthetic-db",sys.argv[2]),("synthetic-media",sys.argv[3])):
    matches=[s["id"] for s in snapshots if label in s.get("tags",[])]
    assert len(matches)==1, "Expected one snapshot for "+label
    Path(path).write_text(matches[0])
PY

restic restore "$(cat "$SCRATCH/db-id")" --target "$SCRATCH/restore-db" --quiet >/dev/null
restic restore "$(cat "$SCRATCH/media-id")" --target "$SCRATCH/restore-media" --quiet >/dev/null

python3 - "$SCRATCH/restore-db" "$SCRATCH/restore-media" <<'PY'
import pathlib
import sys
db=list(pathlib.Path(sys.argv[1]).rglob("synthetic-database.sql"))
media=list(pathlib.Path(sys.argv[2]).rglob("synthetic-media.txt"))
assert len(db)==1 and len(media)==1
assert db[0].read_text()=="CREATE TABLE SYNTHETIC_ONLY (id INT);\n"
assert media[0].read_text()=="SYNTHETIC_MEDIA_NO_CUSTOMER_DATA\n"
PY
echo "SYNTHETIC_INDEPENDENT_RESTORE=PASS"

if restic backup --quiet --tag synthetic-failing-dump \
     --stdin-filename should-not-exist.sql --stdin-from-command -- \
     python3 -c 'import sys;sys.exit(23)' >/dev/null 2>&1
then
  echo "SYNTHETIC_CHILD_FAILURE_GUARD=FAILED"
  exit 1
fi
restic snapshots --json > "$SCRATCH/after-failure.json"
python3 - "$SCRATCH/after-failure.json" <<'PY'
import json,sys
items=json.load(open(sys.argv[1],encoding="utf-8"))
assert len(items)==2
assert not any("synthetic-failing-dump" in s.get("tags",[]) for s in items)
PY
echo "SYNTHETIC_CHILD_FAILURE_GUARD=PASS"

echo "PRODUCTION_DATABASE=NOT_TOUCHED"
echo "PRODUCTION_R2_BUCKET=NOT_ACCESSED"
echo "PRODUCTION_RESTIC_KEYS=NOT_CREATED"
echo "PRODUCTION_BACKUP_RESTORE=NOT_PERFORMED"
echo "MCP_RELEASE_GATE=BLOCKED"
