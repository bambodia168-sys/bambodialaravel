#!/usr/bin/env bash
# Source-only browser-QA bootstrap from the operator-verified, pinned local CLI checkout.
# --check is read-only; --prepare-source writes ONLY a new Git worktree under /var/lib.
# No database, webroot, .env, Passport keys, Composer, migrations or production changes.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in
  --check|--prepare-source) ;;
  *) echo 'Usage: script --check|--prepare-source' >&2; exit 64 ;;
esac

SOURCE="/var/lib/bambodia-oauth-qa-acceptance"
DEST="/var/lib/bambodia-oauth-browser-qa"
EXPECTED_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
REPO="bambodia168-sys/bambodialaravel"

die() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die 'Root operator required.'
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" ]] || die 'Existing CLI source missing or symlinked.'
[[ "$(realpath -e "$SOURCE")" == "$SOURCE" ]] || die 'Existing CLI source path redirects.'
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$EXPECTED_SHA" ]] ||
  die 'CLI source is not the accepted pinned commit.'
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  die 'Existing CLI source has tracked changes.'
[[ -d /var/lib && ! -L /var/lib && "$(realpath -e /var/lib)" == /var/lib ]] ||
  die 'Expected /var/lib parent is missing or redirected.'
[[ ! -e "$DEST" && ! -L "$DEST" ]] ||
  die 'Browser QA destination already exists; never overwrite or reclone it.'
[[ "$DEST" != "$SOURCE" ]] || die 'QA destination may not equal CLI source.'
command -v git >/dev/null || die 'Git unavailable.'

ORIGIN="$(git -C "$SOURCE" remote get-url origin 2>/dev/null)" ||
  die 'Trusted Git origin not present.'
case "$ORIGIN" in
  "https://github.com/$REPO"|"https://github.com/$REPO.git"|"git@github.com:$REPO"|"git@github.com:$REPO.git") ;;
  *) die 'QA source Git origin is unexpected.' ;;
esac

# Source tree excludes .env and keys only if they are NOT tracked.
for path in .env storage/oauth-private.key storage/oauth-public.key vendor/autoload.php; do
  if git -C "$SOURCE" ls-files --error-unmatch "$path" >/dev/null 2>&1; then
    die "Sensitive or generated file is unexpectedly tracked: $path"
  fi
done
echo "SOURCE_SHA=$EXPECTED_SHA"
echo 'SOURCE_ORIGIN=EXPECTED'
echo 'BROWSER_QA_DESTINATION=NEW_DEDICATED_PATH'
echo 'FILESYSTEM_PREFLIGHT=PASS'

if [[ "$MODE" == --check ]]; then
  echo 'READ_ONLY=YES'
  echo 'MYSQL=UNTOUCHED'
  echo 'WEBROOT=UNTOUCHED'
  exit 0
fi

echo '========== CREATE SOURCE-ONLY BROWSER QA CHECKOUT =========='
git -c core.hooksPath=/dev/null clone --local --no-hardlinks --no-checkout -- "$SOURCE" "$DEST"
[[ -d "$DEST/.git" && ! -L "$DEST" ]] ||
  die 'Browser QA checkout directory was not created safely.'
git -c core.hooksPath=/dev/null -C "$DEST" checkout --detach "$EXPECTED_SHA"
[[ "$(git -C "$DEST" rev-parse HEAD)" == "$EXPECTED_SHA" ]] ||
  die 'Browser QA checkout source SHA mismatch.'
[[ -z "$(git -C "$DEST" status --porcelain --untracked-files=no)" ]] ||
  die 'New browser QA source unexpectedly modified.'
[[ "$(git -C "$DEST" remote get-url origin)" == "$SOURCE" ]] ||
  die 'Unexpected browser QA Git remote.'
for path in .env storage/oauth-private.key storage/oauth-public.key vendor/autoload.php; do
  [[ ! -e "$DEST/$path" && ! -L "$DEST/$path" ]] ||
    die "Forbidden application secret/runtime artifact appeared: $path"
done
chmod 700 "$DEST"
echo 'BROWSER_QA_SOURCE=PREPARED'
echo 'SOURCE_ONLY=YES'
echo 'ENV=NOT_CREATED'
echo 'VENDOR=NOT_COPIED'
echo 'MYSQL=UNTOUCHED'
echo 'PASSPORT_KEYS=NOT_COPIED'
echo 'ACCEPTED_CLI_REHEARSAL=UNCHANGED'
echo 'WEBROOT=UNTOUCHED'
echo 'PRODUCTION=UNTOUCHED'
