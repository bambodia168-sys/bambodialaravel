#!/usr/bin/env bash
# Bambodia OAuth aaPanel QA: pinned SOURCE checkout only. Never touches MySQL,
# .env, Composer, private media, Passport keys or production websites.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
[[ "$MODE" == --check || "$MODE" == --prepare-source ]] || {
  echo 'Usage: bootstrap.sh --check|--prepare-source' >&2
  exit 64
}

fail() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
REPO='bambodia168-sys/bambodialaravel'
BRANCH='feature/bambodia-oauth21-mcp-20261111'
APP="${BAMBODIA_QA_APP:-/var/lib/bambodia-oauth-qa-acceptance}"
SHA="${BAMBODIA_QA_EXPECTED_SHA:-}"

[[ "$(id -u)" == 0 ]] || fail 'Only root may prepare the isolated source checkout.'
[[ "$APP" =~ ^/var/lib/bambodia-oauth-qa-[A-Za-z0-9_-]+$ ]] ||
  fail 'Target must be a dedicated directory directly under /var/lib.'
[[ ! -e "$APP" && ! -L "$APP" ]] ||
  fail 'Target already exists. Never overwrite an existing checkout.'
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'Pin a full 40-character reviewed PR commit SHA.'
[[ -d /var/lib && ! -L /var/lib && "$(stat -c %u /var/lib)" == 0 ]] ||
  fail '/var/lib must be a real root-owned directory.'
[[ "$(realpath -m -- "$APP")" == "$APP" ]] ||
  fail 'Target directory resolves outside the expected path.'
for cmd in gh git realpath stat; do
  command -v "$cmd" >/dev/null || fail "Required command not available: $cmd"
done

# This query is independent of the local checkout. Never silently chase the
# current PR tip: the approved SHA and reviewed branch tip must match.
PR_HEAD="$(gh api "repos/$REPO/pulls/15" --jq .head.sha)" ||
  fail 'Unable to verify the current GitHub PR head.'
[[ "$PR_HEAD" == "$SHA" ]] ||
  fail "PR changed since review (current $PR_HEAD); stop and reapprove the SHA."
PR_DRAFT="$(gh api "repos/$REPO/pulls/15" --jq .draft)" ||
  fail 'Could not verify draft PR status.'
[[ "$PR_DRAFT" == true ]] || fail 'Refusing to prepare source from a non-draft PR.'

printf 'QA_TARGET=%s\n' "$APP"
printf 'SOURCE_SHA=%s\n' "$SHA"
printf 'PR_DRAFT=YES\n'
if [[ "$MODE" == --check ]]; then
  printf 'SOURCE_CHECK=PASS\nREAD_ONLY=YES\n'
  exit 0
fi

# This is the only filesystem write mode; gh clones into a NEW isolated dir.
# Do not remove partial directories automatically on failure.
gh repo clone "$REPO" "$APP" -- --filter=blob:none --no-checkout ||
  fail 'Repository clone failed; inspect the partial QA target before retrying.'
[[ -d "$APP/.git" && ! -L "$APP" ]] || fail 'QA Git tree not created safely.'
git -C "$APP" fetch --depth=1 origin "refs/heads/$BRANCH" ||
  fail 'Cannot fetch the approved development branch.'
[[ "$(git -C "$APP" rev-parse FETCH_HEAD)" == "$SHA" ]] ||
  fail 'Fetched branch tip changed; no checkout performed.'
git -C "$APP" checkout --detach "$SHA" || fail 'Pinned checkout failed.'
[[ "$(git -C "$APP" rev-parse HEAD)" == "$SHA" ]] ||
  fail 'Pinned checkout verification failed.'
[[ -z "$(git -C "$APP" status --porcelain --untracked-files=no)" ]] ||
  fail 'Fresh QA tracked source is unexpectedly modified.'
[[ ! -e "$APP/.env" && ! -e "$APP/storage/oauth-private.key" ]] ||
  fail 'Unexpected secret material in a fresh source checkout.'
printf 'SOURCE_PREPARED=PASS\n'
printf 'MYSQL=UNTOUCHED\nPASSPORT_KEYS=NOT_GENERATED\n'
printf 'PRODUCTION=UNTOUCHED\n'
printf 'NEXT=Prepare QA-only dependencies, schema, and environment separately.\n'
