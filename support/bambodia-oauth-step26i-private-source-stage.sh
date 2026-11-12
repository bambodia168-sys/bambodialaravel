#!/usr/bin/env bash
# Bambodia OAuth Step 26I: stage approved source in a NEW, PRIVATE /var/lib tree.
# --check is read-only. --stage-source requires explicit typed approval and
# writes ONLY into a previously nonexistent destination at an exact path.
# No .env, vendor, Passport key, DB schema, migration, Nginx or production writes.
set -Eeuo pipefail
set +x
umask 077

MODE="${1:-}"
case "$MODE" in --check|--stage-source) ;; *)
  echo 'Usage: bash script --check|--stage-source' >&2; exit 64 ;;
esac

SOURCE="/var/lib/bambodia-oauth-browser-qa"
DEST="/var/lib/bambodia-oauth-https-qa"
APPROVED_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
REPO="bambodia168-sys/bambodialaravel"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
WEBROOT="/www/wwwroot/oauth-qa.bambodia.com"
MYSQL_CNF="/root/bambodia-oauth-https-mysql.cnf"
DB="bambodia_oauthqa_https"
DB_USER="bambodiaqa_https"
fail(){ printf 'STEP26I_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for command_name in git sha256sum realpath stat cmp runuser; do
  command -v "$command_name" >/dev/null || fail "MISSING_$command_name"
done
[[ -d /var/lib && ! -L /var/lib &&
   "$(realpath -e /var/lib)" == /var/lib &&
   "$(stat -c %u /var/lib)" == 0 ]] ||
  fail PRIVATE_STAGING_PARENT_UNSAFE
[[ "$DEST" == /var/lib/bambodia-oauth-https-qa &&
   "$DEST" != "$SOURCE" &&
   ! -e "$DEST" && ! -L "$DEST" ]] ||
  fail STAGING_ALREADY_EXISTS_OR_PATH_UNSAFE
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && ! -L "$SOURCE/.git" &&
   "$(realpath -e "$SOURCE")" == "$SOURCE" ]] ||
  fail ACCEPTED_SOURCE_PATH_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$APPROVED_SHA" ]] ||
  fail SOURCE_COMMIT_NOT_ACCEPTED
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail SOURCE_TRACKED_FILES_MODIFIED
# Browser-QA was originally cloned from the accepted local CLI checkout.
# Subsequent read-only QA work may have reconciled its remote to the canonical
# repository. Accept ONLY those two verifiable provenance identities. Never
# accept an arbitrary remote, GitHub fork, credential-in-URL or rewritten URL.
ORIGIN_RAW="$(git -C "$SOURCE" config --local --get remote.origin.url 2>/dev/null)" ||
  fail SOURCE_ORIGIN_MISSING
ORIGIN_EFFECTIVE="$(git -C "$SOURCE" remote get-url origin 2>/dev/null)" ||
  fail SOURCE_ORIGIN_NOT_RESOLVABLE
case "$ORIGIN_RAW" in
  "/var/lib/bambodia-oauth-qa-acceptance")
    [[ "$ORIGIN_EFFECTIVE" == "$ORIGIN_RAW" ]] ||
      fail SOURCE_ORIGIN_REWRITTEN_UNEXPECTEDLY
    echo 'ACCEPTED_SOURCE_ORIGIN=PINNED_LOCAL_QA'
    ;;
  "https://github.com/$REPO"|"https://github.com/$REPO.git"|"git@github.com:$REPO"|"git@github.com:$REPO.git")
    [[ "$ORIGIN_EFFECTIVE" == "$ORIGIN_RAW" ]] ||
      fail SOURCE_ORIGIN_REWRITTEN_UNEXPECTEDLY
    echo 'ACCEPTED_SOURCE_ORIGIN=CANONICAL_GITHUB'
    ;;
  *)
    # Do not print the actual origin: it may embed authentication material.
    fail SOURCE_ORIGIN_NOT_ALLOWLISTED
    ;;
esac
unset ORIGIN_RAW ORIGIN_EFFECTIVE
[[ -s "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" &&
   "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail ACCEPTED_COMPOSER_LOCK_INVALID
for name in artisan composer.json phpunit.xml public/index.php; do
  [[ -s "$SOURCE/$name" && ! -L "$SOURCE/$name" ]] ||
    fail "SOURCE_FILE_MISSING_OR_SYMLINK_$name"
done
for name in .env .env.testing storage/oauth-private.key storage/oauth-public.key vendor/autoload.php; do
  if git -C "$SOURCE" ls-files --error-unmatch "$name" >/dev/null 2>&1; then
    fail "SECRET_OR_RUNTIME_ARTIFACT_TRACKED_$name"
  fi
done
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail EXISTING_NGINX_VHOST_CHANGED
[[ -d "$WEBROOT" && ! -L "$WEBROOT" &&
   "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] ||
  fail CURRENT_WEBROOT_PATH_UNSAFE
[[ -f "$MYSQL_CNF" && ! -L "$MYSQL_CNF" &&
   "$(stat -c %u "$MYSQL_CNF")" == 0 &&
   "$(stat -c %a "$MYSQL_CNF")" == 600 ]] ||
  fail QA_MYSQL_CREDENTIAL_FILE_NOT_PROTECTED
id www >/dev/null || fail WWW_ACCOUNT_MISSING
MYSQL="/www/server/mysql/bin/mysql"
if [[ ! -x "$MYSQL" ]]; then MYSQL="$(command -v mysql || true)"; fi
[[ -n "$MYSQL" && -x "$MYSQL" ]] || fail MYSQL_CLIENT_UNAVAILABLE

echo '========== VERIFY ISOLATED MYSQL ACCOUNT AND EMPTY QA SCHEMA =========='
# All SQL statements below are read-only. No passwords or account hostnames
# are printed; credentials are read by MySQL from the root-only QA file.
IDENTITY="$("$MYSQL" --defaults-file="$MYSQL_CNF" --protocol=TCP \
  --host=127.0.0.1 --port=3306 --batch --skip-column-names \
  --silent --connect-timeout=8 --execute \
  "SELECT CONCAT('USER=',CURRENT_USER());
   SELECT CONCAT('TABLES=',COUNT(*)) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA='$DB';")" ||
  fail NEW_QA_MYSQL_LOGIN_UNAVAILABLE
[[ "$IDENTITY" == "$(printf 'USER=%s@127.0.0.1\nTABLES=0' "$DB_USER")" ]] ||
  fail QA_MYSQL_IDENTITY_OR_EMPTY_SCHEMA_MISMATCH
GRANTS="$("$MYSQL" --defaults-file="$MYSQL_CNF" --protocol=TCP \
  --host=127.0.0.1 --port=3306 --batch --skip-column-names \
  --silent --connect-timeout=8 --execute \
  'SHOW GRANTS FOR CURRENT_USER()')" ||
  fail QA_MYSQL_GRANTS_UNAVAILABLE
printf '%s\n' "$GRANTS" | python3 -c '
import re,sys
lines=sys.stdin.read().splitlines()
if not lines: raise SystemExit("BLOCKED: MISSING_GRANTS")
schema=False
for line in lines:
    if not line.startswith("GRANT ") or " WITH GRANT OPTION" in line:
        raise SystemExit("BLOCKED: UNEXPECTED_GRANT")
    if re.search(r" ON \*\.\* TO ",line):
        if not line.startswith("GRANT USAGE ON *.* TO "):
            raise SystemExit("BLOCKED: GLOBAL_MYSQL_PRIVILEGE")
    elif re.search(r" ON \x60?bambodia_oauthqa_https\x60?\.\* TO ",line):
        schema=True
    else:
        raise SystemExit("BLOCKED: GRANT_OUTSIDE_QA_SCHEMA")
if not schema: raise SystemExit("BLOCKED: MISSING_QA_GRANT")
print("QA_MYSQL_GRANTS=RESTRICTED")
'
echo 'QA_DB_EMPTY=VERIFIED'
echo 'QA_MYSQL_CURRENT_USER=DEDICATED_LOCAL_ACCOUNT'

echo '========== VERIFY PRIVATE SOURCE STAGING BOUNDARY =========='
echo "SOURCE_SHA=$APPROVED_SHA"
echo 'SOURCE_TRACKED_FILES=CLEAN'
echo 'APPROVED_COMPOSER_LOCK=VERIFIED'
echo 'EXISTING_VHOST_SHA256=UNCHANGED'
echo 'QA_STAGE_DESTINATION=AVAILABLE_PRIVATE_VAR_LIB'
echo 'CURRENT_AAPANEL_WEBROOT=UNCHANGED'

if [[ "$MODE" == --check ]]; then
  echo 'STEP26I_SOURCE_STAGING_PREFLIGHT=PASS'
  echo 'SOURCE_STAGE=NOT_STARTED'
  echo 'SOURCE_AND_ACCEPTED_BROWSER_QA=UNCHANGED'
  echo 'QA_DATABASE=NOT_MODIFIED'
  echo 'NGINX=UNCHANGED'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

[[ -c /dev/tty ]] || fail INTERACTIVE_TERMINAL_REQUIRED
printf 'Type STAGE-STEP26I to clone source ONLY into new private directory: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == STAGE-STEP26I ]] || fail OPERATOR_DECLINED
unset APPROVAL
[[ ! -e "$DEST" && ! -L "$DEST" ]] || fail TARGET_APPEARED_AFTER_PREFLIGHT

echo '========== CREATE PRIVATE SOURCE-ONLY APPLICATION CHECKOUT =========='
git -c core.hooksPath=/dev/null clone --local --no-hardlinks --no-checkout \
  -- "$SOURCE" "$DEST"
[[ -d "$DEST/.git" && ! -L "$DEST" && ! -L "$DEST/.git" &&
   "$(realpath -e "$DEST")" == "$DEST" ]] ||
  fail CREATED_DESTINATION_PATH_UNSAFE
git -c core.hooksPath=/dev/null -C "$DEST" checkout --detach "$APPROVED_SHA"
git -C "$DEST" remote set-url origin "https://github.com/$REPO.git"
git -C "$DEST" config core.hooksPath /dev/null

echo '========== COPY EXACT ACCEPTED QA COMPOSER LOCK =========='
[[ ! -e "$DEST/composer.lock" && ! -L "$DEST/composer.lock" ]] ||
  fail TARGET_COMPOSER_LOCK_ALREADY_PRESENT
install -m 600 -o root -g root "$SOURCE/composer.lock" "$DEST/composer.lock"
[[ "$(sha256sum "$DEST/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail STAGED_DEPENDENCY_LOCK_MISMATCH
cmp -s "$SOURCE/composer.lock" "$DEST/composer.lock" ||
  fail STAGED_LOCK_NOT_BYTE_IDENTICAL

echo '========== RESTRICT SOURCE CHECKOUT UNTIL NEXT GATE =========='
chown root:root "$DEST"
chmod 700 "$DEST"
[[ "$(git -C "$DEST" rev-parse HEAD)" == "$APPROVED_SHA" &&
   -z "$(git -C "$DEST" status --porcelain --untracked-files=no)" ]] ||
  fail STAGED_SOURCE_COMMIT_OR_WORKTREE_MISMATCH
[[ "$(git -C "$DEST" remote get-url origin)" == "https://github.com/$REPO.git" ]] ||
  fail STAGED_REMOTE_UNEXPECTED
for name in .env .env.testing storage/oauth-private.key storage/oauth-public.key \
            vendor/autoload.php public/storage bootstrap/cache/config.php; do
  [[ ! -e "$DEST/$name" && ! -L "$DEST/$name" ]] ||
    fail "UNEXPECTED_STAGED_SECRET_OR_RUNTIME_$name"
done
[[ -s "$DEST/artisan" && -s "$DEST/public/index.php" ]] ||
  fail STAGED_LARAVEL_SOURCE_INCOMPLETE
[[ "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail NGINX_VHOST_CHANGED_DURING_STAGING

echo 'STEP26I_PRIVATE_SOURCE_STAGING=PASS'
echo 'STAGED_SOURCE=APPROVED_PINNED_COMMIT'
echo 'STAGED_COMPOSER_LOCK=EXACT_MATCH'
echo 'STAGED_DIRECTORY_MODE=0700'
echo 'STAGED_APP_ENV=ABSENT'
echo 'STAGED_VENDOR=ABSENT'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'QA_MYSQL_SCHEMA=NOT_MODIFIED'
echo 'ORIGINAL_AAPANEL_WEBROOT=UNCHANGED'
echo 'ACCEPTED_BROWSER_QA=UNCHANGED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
