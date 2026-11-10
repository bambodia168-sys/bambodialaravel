#!/usr/bin/env bash
# Scoped aaPanel upgrade: only the shared profile button captions.
set -Eeuo pipefail
umask 077
MODE="$*"
[[ -n "$MODE" ]] || MODE="--check"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Use --check or --apply" >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
BRANCH="feature/premium-creator-profile-editor-20261110"
BASE="d7fc7d0eb32cca2b82a710a67beeb00fdc733497"
RELEASE="08b6631500ae45f6a0d9ff5f7404df9511a76f38"
SOURCE="/root/bambodia-release-audit"
APP="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
FILE="resources/views/account/profile-editor.blade.php"
HELPER="support/creator-profile-button-labels-upgrade.sh"
BACKUPS="/root/Bambodia-Profile-ButtonLabels-Backups"

fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == "0" ]] || fail "Run as root on aaPanel."
for c in git gh python3 cmp curl tar runuser install mktemp sha256sum; do
  command -v "$c" >/dev/null || fail "Missing command: $c"
done
[[ -d "$SOURCE/.git" && -f "$APP/artisan" && -f "$APP/vendor/autoload.php" && -x "$PHP" ]] ||
  fail "Unexpected repository/runtime."
[[ -f "$APP/$FILE" && ! -L "$APP/$FILE" && -f "$APP/.env" ]] || fail "Profile editor or .env missing."
[[ -f "$APP/storage/app/installed.lock" ]] || fail "Installer lock not found."
[[ ! -e "$APP/storage/framework/down" ]] || fail "Site is already in maintenance mode."
[[ "$(git -C "$SOURCE" remote get-url origin)" == "https://github.com/$REPO.git" ]] || fail "Unexpected Git remote."
[[ -z "$(git -C "$SOURCE" status --porcelain)" ]] || fail "Local Git checkout has changes."
id www >/dev/null || fail "Web user www missing."

TMP="$(mktemp -d /root/bambodia-profile-labels.XXXXXXXX)"
BACKUP=""
CHANGED=0
MAINT=0
done_cleanup(){
  code=$?
  trap - EXIT
  if (( code != 0 && CHANGED == 1 )); then
    echo "INSTALL FAILED — restoring original profile Blade template" >&2
    tar -xzf "$BACKUP" -C "$APP" || echo "CRITICAL: file restoration failed" >&2
    (cd "$APP" && runuser -u www -- "$PHP" artisan view:clear &&
      runuser -u www -- "$PHP" artisan view:cache) || true
    echo "ROLLBACK_FILES=$BACKUP" >&2
  fi
  if (( MAINT == 1 )); then
    (cd "$APP" && runuser -u www -- "$PHP" artisan up) || true
  fi
  rm -rf -- "$TMP"
  exit "$code"
}
trap done_cleanup EXIT

echo "========== CREATOR PROFILE BUTTON LABELS ($MODE) =========="
git -C "$SOURCE" fetch origin "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
CURRENT="$(git -C "$SOURCE" rev-parse "refs/remotes/origin/$BRANCH")"
git -C "$SOURCE" merge-base --is-ancestor "$RELEASE" "$CURRENT" ||
  fail "Feature branch no longer descends from verified release."
git -C "$SOURCE" diff --name-only "$RELEASE" "$CURRENT" | LC_ALL=C sort > "$TMP/post"
printf '%s\n' ".github/workflows/quality.yml" "$HELPER" | LC_ALL=C sort > "$TMP/post-expected"
cmp -s "$TMP/post" "$TMP/post-expected" || fail "Unexpected post-release modifications."
[[ "$(git hash-object "$0")" == "$(git -C "$SOURCE" rev-parse "$CURRENT:$HELPER")" ]] ||
  fail "Installer does not match its GitHub source."
git -C "$SOURCE" cat-file -e "$BASE^{commit}" || fail "Baseline missing."
git -C "$SOURCE" diff --name-only "$BASE" "$RELEASE" | LC_ALL=C sort > "$TMP/scope"
printf '%s\n' "$FILE" "tests/Feature/PremiumCreatorProfileCrudTest.php" | LC_ALL=C sort > "$TMP/scope-expected"
cmp -s "$TMP/scope" "$TMP/scope-expected" ||
  fail "Release changes something other than profile labels and their tests."
for wf in quality.yml installer-acceptance.yml browser-emulation.yml operational-acceptance.yml isolated-authenticated-journeys.yml; do
  count="$(gh api "repos/$REPO/actions/workflows/$wf/runs?head_sha=$RELEASE&per_page=30" \
    --jq '[.workflow_runs[] | select(.head_sha == "'"$RELEASE"'" and .conclusion == "success")] | length')" ||
    fail "Cannot verify CI for $wf."
  [[ "$count" =~ ^[0-9]+$ && "$count" -ge 1 ]] || fail "CI is not green: $wf."
  echo "PASS $wf"
done

git -C "$SOURCE" show "$RELEASE:$FILE" > "$TMP/profile-editor.blade.php"
python3 - "$SOURCE" "$BASE" "$RELEASE" "$FILE" <<'PY'
import subprocess,sys
src,base,rel,path=sys.argv[1:]
def read(sha):
    return subprocess.check_output(['git','-C',src,'show',f'{sha}:{path}'],text=True)
old,new=read(base),read(rel)
changes=[
('><x-icon name="save" size="17"/> Save profile</button>', ' aria-label="Save profile"><x-icon name="save" size="17"/> Save</button>'),
('><x-icon name="plus" size="16"/> Add {{ $kind===\'custom\' ? \'entry\' : $kind }}</button>',
 ' aria-label="Add {{ $kind===\'custom\' ? \'entry\' : $kind }} to {{ $section->title }}"><x-icon name="plus" size="16"/> Add</button>'),
('><x-icon name="plus" size="16"/> Add entry</button>',
 ' aria-label="Add entry to {{ $section->title }}"><x-icon name="plus" size="16"/> Add</button>')
]
for a,b in changes:
    if old.count(a)!=1: raise SystemExit('BLOCKED: button label source mismatch')
    old=old.replace(a,b)
if old!=new: raise SystemExit('BLOCKED: unexpected UI change beyond requested labels')
print('LABELS_ONLY=VERIFIED')
PY
if cmp -s "$APP/$FILE" "$TMP/profile-editor.blade.php"; then
  echo "LIVE_PROFILE=ALREADY_UPDATED"
elif cmp -s "$APP/$FILE" <(git -C "$SOURCE" show "$BASE:$FILE"); then
  echo "LIVE_PROFILE=KNOWN_BASELINE"
else
  echo "LIVE_BLOB=$(git hash-object "$APP/$FILE")" >&2
  fail "Production profile has unknown changes; refusing overwrite."
fi
if [[ "$MODE" == "--check" ]]; then
  echo "PREFLIGHT=PASS; production unchanged."
  exit 0
fi

install -d -m 0700 "$BACKUPS"
BACKUP_DIR="$(mktemp -d "$BACKUPS/$(date -u +%Y%m%dT%H%M%SZ).XXXXXXXX")"
BACKUP="$BACKUP_DIR/original-profile-editor.tar.gz"
tar -czf "$BACKUP" -C "$APP" "$FILE"
chmod 0600 "$BACKUP"
tar -tzf "$BACKUP" >/dev/null || fail "Backup invalid."
ENV_HASH="$(sha256sum "$APP/.env" | cut -d' ' -f1)"
LOCK_HASH="$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)"
(cd "$APP" && runuser -u www -- "$PHP" artisan down --retry=30)
MAINT=1
CHANGED=1
install -o www -g www -m 0644 "$TMP/profile-editor.blade.php" "$APP/$FILE"
cmp -s "$APP/$FILE" "$TMP/profile-editor.blade.php" || fail "Installed file differs."
(cd "$APP" && runuser -u www -- "$PHP" artisan view:clear &&
  runuser -u www -- "$PHP" artisan view:cache)
[[ "$(sha256sum "$APP/.env" | cut -d' ' -f1)" == "$ENV_HASH" ]] || fail ".env changed."
[[ "$(sha256sum "$APP/storage/app/installed.lock" | cut -d' ' -f1)" == "$LOCK_HASH" ]] || fail "Installer lock changed."
(cd "$APP" && runuser -u www -- "$PHP" artisan up)
MAINT=0
for url in "https://bambodia.com/" "https://bambodia.com/creators/34302348"; do
  curl -fsSL --max-time 35 -o /dev/null "$url" || fail "Public URL unavailable: $url"
  echo "HTTP_OK=$url"
done
CHANGED=0
echo "========== BUTTON LABELS INSTALLED =========="
echo "RELEASE=$RELEASE"
echo "ROLLBACK_FILES=$BACKUP"
echo "PRESERVED=Layout, SVGs, badges, profile content, Published work, CSS, JS and database."
