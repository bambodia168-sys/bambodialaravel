#!/usr/bin/env bash
# Bambodia OAuth Step 26J: staged source integrity + Composer dependencies.
# --check: read-only (including QA MySQL metadata SELECT).
# --install-deps: requires typed operator confirmation and installs locked,
# production-only packages inside PRIVATE staging dir with scripts/plugins off.
# No .env, APP_KEY, Passport keys, migrations, public Nginx, production changes.
set -Eeuo pipefail
set +x
umask 077
export GIT_NO_LAZY_FETCH=1
export GIT_OPTIONAL_LOCKS=0
MODE="${1:-}"
case "$MODE" in --check|--install-deps) ;; *)
  echo 'Usage: bash script --check|--install-deps' >&2
  exit 64 ;;
esac

SOURCE="/var/lib/bambodia-oauth-browser-qa"
DEST="/var/lib/bambodia-oauth-https-qa"
SOURCE_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
ATTR_SHA="fcb21d396d657f597ef8b6729f73d89b0a871c9b"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
QA_DB="bambodia_oauthqa_https"
QA_USER="bambodiaqa_https"
MYSQL_CNF="/root/bambodia-oauth-https-mysql.cnf"
PHP="/www/server/php/85/bin/php"
fail(){ printf 'STEP26J_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in git sha256sum realpath stat python3 cmp; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && ! -L "$SOURCE/.git" &&
  "$(realpath -e "$SOURCE")" == "$SOURCE" ]] || fail ACCEPTED_SOURCE_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SOURCE_SHA" ]] ||
  fail APPROVED_SOURCE_SHA_CHANGED
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail ACCEPTED_SOURCE_TRACKED_CHANGES
[[ "$(git -C "$SOURCE" rev-parse "$SOURCE_SHA:.gitattributes")" == "$ATTR_SHA" ]] ||
  fail GITATTRIBUTES_CHANGED
[[ -d "$DEST" && ! -L "$DEST" &&
  "$(realpath -e "$DEST")" == "$DEST" &&
  "$(stat -c %u "$DEST")" == 0 &&
  "$(stat -c %a "$DEST")" == 700 ]] ||
  fail STAGED_ROOT_NOT_PROTECTED
[[ ! -e "$DEST/.git" && ! -L "$DEST/.git" ]] ||
  fail STAGING_MUST_BE_GIT_ARCHIVE_SNAPSHOT
[[ -f "$DEST/composer.lock" && ! -L "$DEST/composer.lock" &&
  "$(sha256sum "$DEST/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail STAGED_COMPOSER_LOCK_MISMATCH
[[ -f "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" &&
  "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail ACCEPTED_COMPOSER_LOCK_MISMATCH
cmp -s "$SOURCE/composer.lock" "$DEST/composer.lock" ||
  fail STAGED_COMPOSER_LOCK_NOT_BYTE_IDENTICAL
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail EXISTING_NGINX_CHANGED
for name in .env .env.testing storage/oauth-private.key storage/oauth-public.key \
  vendor/autoload.php bootstrap/cache/config.php public/storage; do
  [[ ! -e "$DEST/$name" && ! -L "$DEST/$name" ]] ||
    fail "STAGED_RUNTIME_ALREADY_EXISTS_$name"
done

export GIT_NO_LAZY_FETCH=1
export GIT_OPTIONAL_LOCKS=0

echo '========== VERIFY STAGED SNAPSHOT AGAINST APPROVED COMMIT =========='
git -C "$SOURCE" archive --format=tar "$SOURCE_SHA" |
python3 -c '
import hashlib,pathlib,stat,sys,tarfile
root=pathlib.Path(sys.argv[1])
seen=set()
with tarfile.open(fileobj=sys.stdin.buffer,mode="r|") as tar:
    for member in tar:
        rel=pathlib.PurePosixPath(member.name)
        if rel.is_absolute() or not rel.parts or any(p in (".","..",".git") for p in rel.parts):
            raise SystemExit("STEP26J_BLOCKED=UNSAFE_ARCHIVE_PATH")
        if member.isdir(): continue
        if not member.isfile():
            raise SystemExit("STEP26J_BLOCKED=UNSAFE_ARCHIVE_MEMBER")
        if rel in seen:
            raise SystemExit("STEP26J_BLOCKED=DUPLICATE_ARCHIVE_ENTRY")
        target=root.joinpath(*rel.parts)
        info=target.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_size!=member.size:
            raise SystemExit("STEP26J_BLOCKED=STAGED_FILE_MISMATCH")
        h=hashlib.sha256()
        with target.open("rb") as f:
            while True:
                chunk=f.read(1024*1024)
                if not chunk: break
                h.update(chunk)
        archive=tar.extractfile(member)
        if archive is None: raise SystemExit("STEP26J_BLOCKED=ARCHIVE_READ_FAILED")
        ah=hashlib.sha256()
        while True:
            chunk=archive.read(1024*1024)
            if not chunk: break
            ah.update(chunk)
        if h.digest()!=ah.digest():
            raise SystemExit("STEP26J_BLOCKED=STAGED_CONTENT_CHANGED")
        seen.add(rel)
actual={p.relative_to(root).as_posix() for p in root.rglob("*") if p.is_file()}
expected={p.as_posix() for p in seen}
if len(seen)!=675 or actual!=(expected|{"composer.lock"}):
    raise SystemExit("STEP26J_BLOCKED=UNEXPECTED_SOURCE_FILE_SET")
print("STAGED_APPROVED_ARCHIVE_FILES=675")
print("STAGED_FILE_CONTENTS=BYTE_IDENTICAL_TO_APPROVED_EXPORT")
print("STAGED_EXTRA_FILES=COMPOSER_LOCK_ONLY")
' "$DEST"
echo 'STAGED_LOCK_SHA256=VERIFIED'
echo 'STAGED_SOURCE_SNAPSHOT=VERIFIED'

echo '========== VERIFY ISOLATED QA DATABASE READ ONLY =========='
MYSQL="/www/server/mysql/bin/mysql"
if [[ ! -x "$MYSQL" ]]; then MYSQL="$(command -v mysql || true)"; fi
[[ -n "$MYSQL" && -x "$MYSQL" ]] || fail MYSQL_CLIENT_UNAVAILABLE
[[ -f "$MYSQL_CNF" && ! -L "$MYSQL_CNF" &&
  "$(stat -c %u "$MYSQL_CNF")" == 0 &&
  "$(stat -c %a "$MYSQL_CNF")" == 600 ]] ||
  fail QA_DB_CLIENT_FILE_UNSAFE
RESULT="$("$MYSQL" --defaults-file="$MYSQL_CNF" --protocol=TCP \
  --host=127.0.0.1 --port=3306 --batch --skip-column-names --silent \
  --connect-timeout=8 --execute \
  "SELECT CONCAT('USER=',CURRENT_USER());
   SELECT CONCAT('TABLES=',COUNT(*)) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA='$QA_DB';")" ||
  fail QA_DB_READ_ONLY_AUTH_FAILED
[[ "$RESULT" == "$(printf 'USER=%s@127.0.0.1\nTABLES=0' "$QA_USER")" ]] ||
  fail QA_ACCOUNT_OR_EMPTY_SCHEMA_CHANGED
GRANTS="$("$MYSQL" --defaults-file="$MYSQL_CNF" --protocol=TCP \
  --host=127.0.0.1 --port=3306 --batch --skip-column-names \
  --silent --connect-timeout=8 --execute \
  'SHOW GRANTS FOR CURRENT_USER()')" ||
  fail QA_MYSQL_GRANT_READ_FAILED
printf '%s\n' "$GRANTS" | python3 -c '
import re,sys
lines=sys.stdin.read().splitlines()
if not lines: raise SystemExit("STEP26J_BLOCKED=QA_GRANTS_ABSENT")
seen=False
for line in lines:
    if not line.startswith("GRANT ") or " WITH GRANT OPTION" in line:
        raise SystemExit("STEP26J_BLOCKED=QA_MYSQL_GRANTS_UNEXPECTED")
    if re.search(r" ON \*\.\* TO ",line):
        if not line.startswith("GRANT USAGE ON *.* TO "):
            raise SystemExit("STEP26J_BLOCKED=QA_MYSQL_GLOBAL_PRIVILEGE")
    elif re.search(r" ON \x60?bambodia_oauthqa_https\x60?\.\* TO ",line):
        seen=True
    else:
        raise SystemExit("STEP26J_BLOCKED=QA_MYSQL_GRANT_OUTSIDE_SCHEMA")
if not seen: raise SystemExit("STEP26J_BLOCKED=QA_SCHEMA_GRANT_ABSENT")
'
echo 'QA_MYSQL_ACCOUNT=DEDICATED'
echo 'QA_MYSQL_GRANTS=RESTRICTED'
echo 'QA_DATABASE=EMPTY_NO_MIGRATIONS'

echo '========== VERIFY PHP AND COMPOSER =========='
[[ -x "$PHP" ]] || fail PHP85_NOT_FOUND
"$PHP" -r '
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5)
    {fwrite(STDERR,"STEP26J_BLOCKED=PHP_85_REQUIRED\n");exit(1);}
$ext=["pdo","pdo_mysql","openssl","mbstring","fileinfo","tokenizer","ctype","xml","dom"];
foreach ($ext as $x) if (!extension_loaded($x)) {
  fwrite(STDERR,"STEP26J_BLOCKED=PHP_EXTENSION_MISSING_".$x."\n");exit(1);
}
echo "PHP_RUNTIME=".PHP_VERSION.PHP_EOL;
echo "PHP_REQUIRED_EXTENSIONS=PASS\n";
'
COMPOSER="$(command -v composer || true)"
if [[ -z "$COMPOSER" ]]; then
  for x in /usr/local/bin/composer /usr/bin/composer /root/composer.phar; do
    if [[ -f "$x" && ! -L "$x" ]]; then COMPOSER="$x"; break; fi
  done
fi
[[ -n "$COMPOSER" && -f "$COMPOSER" && ! -L "$COMPOSER" ]] ||
  fail COMPOSER_ENTRYPOINT_NOT_FOUND
"$PHP" "$COMPOSER" --version --no-ansi >/dev/null ||
  fail COMPOSER_NOT_EXECUTABLE_WITH_PHP85
echo 'COMPOSER_PHP85_RUNTIME=PASS'
echo 'COMPOSER_SCRIPTS_AND_PLUGINS=WILL_BE_DISABLED'
echo 'COMPOSER_DEVELOPMENT_DEPENDENCIES=WILL_BE_EXCLUDED'

if [[ "$MODE" == --check ]]; then
  echo 'STEP26J_RUNTIME_PREFLIGHT=PASS'
  echo 'DEPENDENCIES=NOT_INSTALLED'
  echo 'APP_ENV=NOT_CREATED'
  echo 'MYSQL_WRITES=NONE'
  echo 'NGINX=UNCHANGED'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

[[ -c /dev/tty ]] || fail INTERACTIVE_TERMINAL_REQUIRED
printf 'Type INSTALL-STEP26J to install ONLY locked PHP dependencies privately: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == INSTALL-STEP26J ]] || fail OPERATOR_DECLINED
unset APPROVAL
[[ ! -e "$DEST/vendor" && ! -L "$DEST/vendor" ]] ||
  fail VENDOR_APPEARED_AFTER_PREFLIGHT
[[ ! -e "$DEST/.env" && ! -L "$DEST/.env" ]] ||
  fail ENV_APPEARED_AFTER_PREFLIGHT

echo '========== PRIVATE COMPOSER INSTALL (NO SCRIPTS, NO PLUGINS) =========='
mkdir -m 0700 -- "$DEST/.composer-home" "$DEST/.composer-cache" "$DEST/.composer-tmp"
(
  cd "$DEST"
  export COMPOSER_HOME="$DEST/.composer-home"
  export COMPOSER_CACHE_DIR="$DEST/.composer-cache"
  export TMPDIR="$DEST/.composer-tmp"
  export COMPOSER_ALLOW_SUPERUSER=1
  export COMPOSER_NO_INTERACTION=1
  "$PHP" "$COMPOSER" install --no-dev --no-scripts --no-plugins \
    --prefer-dist --no-interaction --optimize-autoloader \
    --no-progress --no-ansi
)
[[ -s "$DEST/vendor/autoload.php" && ! -L "$DEST/vendor/autoload.php" ]] ||
  fail COMPOSER_AUTOLOAD_NOT_INSTALLED
[[ "$(sha256sum "$DEST/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail COMPOSER_LOCK_MODIFIED
[[ ! -e "$DEST/.env" && ! -L "$DEST/.env" &&
  ! -e "$DEST/storage/oauth-private.key" &&
  ! -e "$DEST/storage/oauth-public.key" ]] ||
  fail RUNTIME_SECRETS_UNEXPECTED
[[ "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail NGINX_VHOST_CHANGED
echo 'STEP26J_VENDOR_INSTALL=PASS'
echo 'COMPOSER_LOCK=EXACT_MATCH'
echo 'VENDOR_AUTOLOAD=INSTALLED'
echo 'PACKAGE_SCRIPTS=DISABLED'
echo 'COMPOSER_PLUGINS=DISABLED'
echo 'APP_ENV=NOT_CREATED'
echo 'APP_KEY=NOT_GENERATED'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'QA_DATABASE=UNMODIFIED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
