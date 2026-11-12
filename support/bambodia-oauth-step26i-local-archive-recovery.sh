#!/usr/bin/env bash
# Step26I recovery: approved COMMIT TREE archive, not shallow git clone.
# --check probes the local HEAD archive stream and validates QA boundaries.
# --stage-source writes only to a NEW private destination, after typed consent.
# The snapshot has NO .git. No Composer, env, keys, migrations, or Nginx changes.
set -Eeuo pipefail
set +x
umask 077
# Avoid Git refresh/index writes and implicit fetches throughout preflight.
export GIT_NO_LAZY_FETCH=1
export GIT_OPTIONAL_LOCKS=0
MODE="${1:-}"
case "$MODE" in --check|--stage-source) ;; *)
  echo 'Usage: bash script --check|--stage-source' >&2; exit 64 ;;
esac

SOURCE="/var/lib/bambodia-oauth-browser-qa"
DEST="/var/lib/bambodia-oauth-https-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
REPO="bambodia168-sys/bambodialaravel"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
WEBROOT="/www/wwwroot/oauth-qa.bambodia.com"
MYSQL_CNF="/root/bambodia-oauth-https-mysql.cnf"
QA_DB="bambodia_oauthqa_https"
QA_USER="bambodiaqa_https"

fail(){ printf 'STEP26IA_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in git python3 sha256sum realpath stat cmp; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d /var/lib && ! -L /var/lib &&
   "$(realpath -e /var/lib)" == /var/lib ]] || fail PRIVATE_PARENT_UNSAFE
[[ "$DEST" == /var/lib/bambodia-oauth-https-qa &&
   ! -e "$DEST" && ! -L "$DEST" ]] || fail DESTINATION_ALREADY_PRESENT
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" && ! -L "$SOURCE/.git" &&
   "$(realpath -e "$SOURCE")" == "$SOURCE" ]] || fail SOURCE_PATH_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] ||
   fail SOURCE_SHA_NOT_APPROVED
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
   fail SOURCE_TRACKED_FILES_CHANGED
RAW_ORIGIN="$(git -C "$SOURCE" config --local --get remote.origin.url 2>/dev/null)" ||
  fail SOURCE_ORIGIN_UNAVAILABLE
EFFECTIVE_ORIGIN="$(git -C "$SOURCE" remote get-url origin 2>/dev/null)" ||
  fail SOURCE_ORIGIN_UNRESOLVABLE
[[ "$RAW_ORIGIN" == "$EFFECTIVE_ORIGIN" ]] ||
  fail SOURCE_ORIGIN_REWRITTEN
case "$RAW_ORIGIN" in
  /var/lib/bambodia-oauth-qa-acceptance)
    echo 'SOURCE_ORIGIN=APPROVED_LOCAL'
    ;;
  "https://github.com/$REPO"|"https://github.com/$REPO.git"|"git@github.com:$REPO"|"git@github.com:$REPO.git")
    echo 'SOURCE_ORIGIN=CANONICAL_GITHUB'
    ;;
  *) fail SOURCE_ORIGIN_NOT_ALLOWLISTED ;;
esac
unset RAW_ORIGIN EFFECTIVE_ORIGIN

[[ -f "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" &&
   "$(sha256sum "$SOURCE/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail ACCEPTED_LOCK_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail ORIGINAL_NGINX_VHOST_CHANGED
[[ -d "$WEBROOT" && ! -L "$WEBROOT" &&
   "$(realpath -e "$WEBROOT")" == "$WEBROOT" ]] ||
  fail ORIGINAL_WEBROOT_UNSAFE
[[ -f "$MYSQL_CNF" && ! -L "$MYSQL_CNF" &&
   "$(stat -c %u "$MYSQL_CNF")" == 0 &&
   "$(stat -c %a "$MYSQL_CNF")" == 600 ]] ||
  fail QA_MYSQL_CLIENT_CREDENTIALS_UNSAFE

# Prevent implicit hydration of missing objects from Git partial-clone remotes.
export GIT_NO_LAZY_FETCH=1
export GIT_OPTIONAL_LOCKS=0

[[ "$(git -C "$SOURCE" cat-file -t "$SHA" 2>/dev/null)" == commit ]] ||
  fail SOURCE_COMMIT_OBJECT_UNAVAILABLE
[[ "$(git -C "$SOURCE" cat-file -t "$SHA^{tree}" 2>/dev/null)" == tree ]] ||
  fail SOURCE_ROOT_TREE_UNAVAILABLE

# Preflight ensures archive format uses regular files and directories only,
# with no traversal, unsafe destinations or Git archive export substitutions.
# Full tracked content is streamed and consumed; no archive is written to disk.
echo '========== TEST GIT ARCHIVE FROM APPROVED LOCAL HEAD =========='
git -C "$SOURCE" archive --format=tar "$SHA" |
python3 -c '
import pathlib,sys,tarfile
count=0
size=0
with tarfile.open(fileobj=sys.stdin.buffer, mode="r|") as arc:
    for item in arc:
        parts=pathlib.PurePosixPath(item.name).parts
        if (not parts or item.name.startswith("/") or
                "." in parts or ".." in parts or ".git" in parts or
                not (item.isfile() or item.isdir())):
            raise SystemExit("STEP26IA_BLOCKED=UNSAFE_ARCHIVE_ENTRY")
        if item.isfile():
            f=arc.extractfile(item)
            if f is None: raise SystemExit("STEP26IA_BLOCKED=MISSING_ARCHIVE_FILE")
            while True:
                piece=f.read(1024*1024)
                if not piece: break
                size+=len(piece)
            count+=1
if count < 10: raise SystemExit("STEP26IA_BLOCKED=ARCHIVE_TOO_SMALL")
print("ARCHIVE_REGULAR_FILES="+str(count))
print("ARCHIVE_BYTES="+str(size))
print("LOCAL_HEAD_ARCHIVE=STREAM_READ_PASS")
'
# Confirm that the archive contains the unmodified source Git tree (no
# export-ignore or export-subst substitutions) in read-only metadata form.
git -C "$SOURCE" ls-tree -rz --full-tree "$SHA" |
python3 -c '
import sys
entries=sys.stdin.buffer.read().split(b"\0")
count=0
for row in entries:
    if not row: continue
    meta,sep,name=row.partition(b"\t")
    if sep!=b"\t" or b"/../" in name or name.startswith(b"/"):
        raise SystemExit("STEP26IA_BLOCKED=UNSAFE_TREE_ENTRY")
    parts=meta.split()
    if len(parts)!=3 or parts[0] not in (b"100644",b"100755") or parts[1]!=b"blob":
        raise SystemExit("STEP26IA_BLOCKED=UNSUPPORTED_TRACKED_TREE_MODE")
    count+=1
if count<10: raise SystemExit("STEP26IA_BLOCKED=INSUFFICIENT_TRACKED_FILES")
print("APPROVED_TREE_REGULAR_FILES="+str(count))
'
# The pinned .gitattributes contains export-ignore for .github/,
# CHANGELOG.md and .styleci.yml. Git archive intentionally omits them.
# Verify exact allowed omissions and every included blob BEFORE creating a
# destination, or a later stage verifier could leave a partial QA tree.
echo '========== VERIFY EXPORTED SNAPSHOT MATCHES APPROVED GIT BLOBS =========='
[[ "$(git -C "$SOURCE" rev-parse "$SHA:.gitattributes")" == \
   "fcb21d396d657f597ef8b6729f73d89b0a871c9b" ]] ||
  fail SOURCE_GITATTRIBUTES_MISMATCH
git -C "$SOURCE" archive --format=tar "$SHA" |
python3 -c '
import hashlib,pathlib,subprocess,sys,tarfile
source,sha=sys.argv[1:]
tree=subprocess.check_output(["git","-C",source,"ls-tree","-rz",
                              "--full-tree",sha])
expected={}
for raw in tree.split(b"\0"):
    if not raw: continue
    meta,sep,name=raw.partition(b"\t")
    fields=meta.split()
    if sep!=b"\t" or len(fields)!=3 or fields[1]!=b"blob" or fields[0] not in (b"100644",b"100755"):
        raise SystemExit("STEP26IA_BLOCKED=UNSUPPORTED_TREE_ENTRY")
    path=pathlib.PurePosixPath(name.decode("utf-8","surrogateescape"))
    if path.is_absolute() or not path.parts or ".." in path.parts or "." in path.parts or ".git" in path.parts:
        raise SystemExit("STEP26IA_BLOCKED=UNSAFE_TREE_PATH")
    expected[path]=fields[2].decode("ascii")
found=set()
with tarfile.open(fileobj=sys.stdin.buffer,mode="r|") as arc:
    for item in arc:
        path=pathlib.PurePosixPath(item.name)
        if path.is_absolute() or not path.parts or ".." in path.parts or "." in path.parts or ".git" in path.parts:
            raise SystemExit("STEP26IA_BLOCKED=UNSAFE_ARCHIVE_PATH")
        if item.isdir(): continue
        if not item.isfile() or path not in expected or path in found:
            raise SystemExit("STEP26IA_BLOCKED=UNKNOWN_OR_DUPLICATE_ARCHIVE_FILE")
        f=arc.extractfile(item)
        if f is None: raise SystemExit("STEP26IA_BLOCKED=ARCHIVE_READ_FAILED")
        digest=hashlib.sha1()
        digest.update(("blob "+str(item.size)+"\0").encode("ascii"))
        while True:
            chunk=f.read(1024*1024)
            if not chunk: break
            digest.update(chunk)
        if digest.hexdigest()!=expected[path]:
            raise SystemExit("STEP26IA_BLOCKED=ARCHIVE_BLOB_HASH_MISMATCH")
        found.add(path)
missing=set(expected)-found
def permitted(p):
    s=p.as_posix()
    return s in ("CHANGELOG.md",".styleci.yml") or s.startswith(".github/")
if len(missing)!=7 or not all(map(permitted,missing)):
    raise SystemExit("STEP26IA_BLOCKED=UNAPPROVED_ARCHIVE_OMISSIONS")
if len(found)!=675 or len(expected)!=682:
    raise SystemExit("STEP26IA_BLOCKED=ARCHIVE_TREE_COUNT_CHANGED")
print("ARCHIVE_FILES_MATCH_APPROVED_GIT_BLOBS="+str(len(found)))
print("GITATTRIBUTES_EXPORT_IGNORED_FILES="+str(len(missing)))
print("GIT_ARCHIVE_MANIFEST_RECONCILED=PASS")
' "$SOURCE" "$SHA"
for name in artisan composer.json phpunit.xml public/index.php; do
  [[ -s "$SOURCE/$name" && ! -L "$SOURCE/$name" ]] ||
    fail "EXPECTED_SOURCE_FILE_MISSING_$name"
done

MYSQL="/www/server/mysql/bin/mysql"
if [[ ! -x "$MYSQL" ]]; then MYSQL="$(command -v mysql || true)"; fi
[[ -n "$MYSQL" && -x "$MYSQL" ]] || fail MYSQL_CLIENT_NOT_FOUND
IDENTITY="$("$MYSQL" --defaults-file="$MYSQL_CNF" --protocol=TCP \
  --host=127.0.0.1 --port=3306 --batch --skip-column-names \
  --silent --connect-timeout=8 --execute \
  "SELECT CONCAT('USER=',CURRENT_USER());
   SELECT CONCAT('TABLES=',COUNT(*)) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA='$QA_DB';")" ||
  fail QA_MYSQL_IDENTITY_CHECK_UNAVAILABLE
[[ "$IDENTITY" == "$(printf 'USER=%s@127.0.0.1\nTABLES=0' "$QA_USER")" ]] ||
  fail QA_DB_NOT_EMPTY_OR_WRONG_ACCOUNT
GRANTS="$("$MYSQL" --defaults-file="$MYSQL_CNF" --protocol=TCP \
  --host=127.0.0.1 --port=3306 --batch --skip-column-names \
  --silent --connect-timeout=8 --execute \
  'SHOW GRANTS FOR CURRENT_USER()')" ||
  fail QA_GRANTS_UNAVAILABLE
printf '%s\n' "$GRANTS" | python3 -c '
import re,sys
lines=sys.stdin.read().splitlines()
if not lines: raise SystemExit("STEP26IA_BLOCKED=EMPTY_GRANTS")
schema=False
for line in lines:
    if not line.startswith("GRANT ") or " WITH GRANT OPTION" in line:
        raise SystemExit("STEP26IA_BLOCKED=UNEXPECTED_GRANT")
    if re.search(r" ON \*\.\* TO ",line):
        if not line.startswith("GRANT USAGE ON *.* TO "):
            raise SystemExit("STEP26IA_BLOCKED=GLOBAL_GRANT")
    elif re.search(r" ON \x60?bambodia_oauthqa_https\x60?\.\* TO ",line):
        schema=True
    else:
        raise SystemExit("STEP26IA_BLOCKED=GRANT_OUTSIDE_NEW_QA_SCHEMA")
if not schema: raise SystemExit("STEP26IA_BLOCKED=NO_QA_SCHEMA_GRANT")
'
echo 'QA_MYSQL_ACCOUNT_AND_EMPTY_DATABASE=VERIFIED'
echo 'QA_MYSQL_GRANTS=RESTRICTED'
echo 'ORIGINAL_NGINX_VHOST=UNCHANGED'
echo 'ACCEPTED_SOURCE_AND_LOCK=UNCHANGED'
echo 'PRIVATE_STAGE_DESTINATION=ABSENT'

if [[ "$MODE" == --check ]]; then
  echo 'STEP26IA_ARCHIVE_PREFLIGHT=PASS'
  echo 'STAGING_ACTION=NOT_EXECUTED'
  echo 'SOURCE_GIT_HISTORY=UNMODIFIED'
  echo 'MYSQL_WRITES=NONE'
  echo 'NGINX=UNCHANGED'
  echo 'PRODUCTION=UNCHANGED'
  exit 0
fi

[[ -c /dev/tty ]] || fail INTERACTIVE_TERMINAL_REQUIRED
printf 'Type STAGE-STEP26I-ARCHIVE for approved tracked-tree snapshot ONLY: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == STAGE-STEP26I-ARCHIVE ]] || fail OPERATOR_DECLINED
unset APPROVAL
[[ ! -e "$DEST" && ! -L "$DEST" ]] || fail TARGET_APPEARED

# Read-only Git archive is decompressed by a custom extractor. Never use tar
# extraction that could follow symlinks, honor owner/mode or traverse outside.
# A failed extraction leaves a private partial directory for manual review.
echo '========== CREATE IMMUTABLE QA SOURCE SNAPSHOT =========='
mkdir -m 0700 -- "$DEST"
[[ -d "$DEST" && ! -L "$DEST" ]] || fail DEST_CREATE_UNSAFE
git -C "$SOURCE" archive --format=tar "$SHA" |
python3 -c '
import os,pathlib,sys,tarfile
dest=pathlib.Path(sys.argv[1])
with tarfile.open(fileobj=sys.stdin.buffer,mode="r|") as arc:
    for entry in arc:
        p=pathlib.PurePosixPath(entry.name)
        if (not p.parts or entry.name.startswith("/") or
            "." in p.parts or ".." in p.parts or ".git" in p.parts or
            not (entry.isfile() or entry.isdir())):
            raise SystemExit("STEP26IA_BLOCKED=UNSAFE_EXTRACTION_ENTRY")
        path=dest.joinpath(*p.parts)
        if entry.isdir():
            path.mkdir(mode=0o700,parents=True,exist_ok=True)
            continue
        path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,"wb") as out:
            src=arc.extractfile(entry)
            if src is None: raise SystemExit("STEP26IA_BLOCKED=ARCHIVE_READ_FAILED")
            while True:
                block=src.read(1024*1024)
                if not block: break
                out.write(block)
        os.chmod(path,0o700 if (entry.mode & 0o111) else 0o600)
print("PRIVATE_TRACKED_SNAPSHOT=EXTRACTED")
' "$DEST"

# Verify every tracked file content against the APPROVED TREE object hashes.
git -C "$SOURCE" ls-tree -rz --full-tree "$SHA" |
python3 -c '
import hashlib,os,pathlib,stat,sys
root=pathlib.Path(sys.argv[1])
seen=set()
omitted=set()
expected_count=0
for entry in sys.stdin.buffer.read().split(b"\0"):
    if not entry: continue
    meta,sep,filename=entry.partition(b"\t")
    fields=meta.split()
    if sep!=b"\t" or len(fields)!=3 or fields[1]!=b"blob":
        raise SystemExit("STEP26IA_BLOCKED=UNEXPECTED_TREE_ENTRY")
    mode,obj=fields[0],fields[2].decode("ascii")
    if mode not in (b"100644",b"100755"):
        raise SystemExit("STEP26IA_BLOCKED=UNSUPPORTED_TREE_MODE")
    rel=pathlib.PurePosixPath(os.fsdecode(filename))
    if rel.is_absolute() or ".." in rel.parts or "." in rel.parts or ".git" in rel.parts:
        raise SystemExit("STEP26IA_BLOCKED=UNSAFE_TREE_PATH")
    path=root.joinpath(*rel.parts)
    expected_count+=1
    if not path.exists() and not path.is_symlink():
        name=rel.as_posix()
        if name not in ("CHANGELOG.md",".styleci.yml") and not name.startswith(".github/"):
            raise SystemExit("STEP26IA_BLOCKED=REQUIRED_TRACKED_FILE_ABSENT")
        omitted.add(name)
        continue
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode):
        raise SystemExit("STEP26IA_BLOCKED=STAGED_OBJECT_NOT_REGULAR")
    digest=hashlib.sha1()
    digest.update(("blob "+str(st.st_size)+"\0").encode("ascii"))
    with path.open("rb") as f:
        while True:
            b=f.read(1024*1024)
            if not b: break
            digest.update(b)
    if digest.hexdigest()!=obj:
        raise SystemExit("STEP26IA_BLOCKED=GIT_TREE_CONTENT_MISMATCH")
    seen.add(path.relative_to(root))
actual={p.relative_to(root) for p in root.rglob("*") if p.is_file()}
if actual!=seen or len(seen)!=675 or len(omitted)!=7 or expected_count!=682:
    raise SystemExit("STEP26IA_BLOCKED=ARCHIVE_TREE_FILE_SET_MISMATCH")
print("APPROVED_TREE_BLOB_HASHES=ALL_MATCH")
print("VERIFIED_TRACKED_FILES="+str(len(seen)))
print("APPROVED_EXPORT_IGNORED_FILES="+str(len(omitted)))
' "$DEST"

[[ ! -e "$DEST/.git" && ! -L "$DEST/.git" &&
   ! -e "$DEST/.env" && ! -L "$DEST/.env" &&
   ! -e "$DEST/vendor/autoload.php" &&
   ! -e "$DEST/storage/oauth-private.key" &&
   ! -e "$DEST/storage/oauth-public.key" ]] ||
  fail FORBIDDEN_RUNTIME_ARTIFACT
[[ ! -e "$DEST/composer.lock" && ! -L "$DEST/composer.lock" ]] ||
  fail UNEXPECTED_COMPOSER_LOCK_IN_TREE
install -m 600 -o root -g root "$SOURCE/composer.lock" "$DEST/composer.lock"
[[ "$(sha256sum "$DEST/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail STAGED_COMPOSER_LOCK_MISMATCH
cmp -s "$SOURCE/composer.lock" "$DEST/composer.lock" ||
  fail STAGED_COMPOSER_LOCK_NOT_BYTE_IDENTICAL
[[ -s "$DEST/artisan" && -s "$DEST/public/index.php" ]] ||
  fail STAGED_LARAVEL_FILES_MISSING
[[ "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail NGINX_CHANGED_DURING_STAGING

echo 'STEP26IA_SOURCE_ONLY_STAGE=PASS'
echo "APPROVED_SOURCE_COMMIT=$SHA"
echo 'APPROVED_EXPORTED_FILE_BLOBS=VERIFIED'
echo 'GITATTRIBUTES_EXPORT_IGNORED_FILES=7'
echo 'APPROVED_LOCK=BYTE_IDENTICAL'
echo 'STAGED_SOURCE_FORMAT=GIT_ARCHIVE_SNAPSHOT_WITHOUT_GIT_HISTORY'
echo 'STAGED_ROOT_MODE=0700'
echo 'QA_APP_ENV=NOT_CREATED'
echo 'VENDOR=NOT_INSTALLED'
echo 'PASSPORT_KEYS=NOT_GENERATED'
echo 'LARAVEL_MIGRATIONS=NOT_RUN'
echo 'MYSQL_SCHEMA=UNCHANGED'
echo 'ACCEPTED_BROWSER_QA=UNCHANGED'
echo 'CURRENT_AAPANEL_WEBROOT=UNCHANGED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
