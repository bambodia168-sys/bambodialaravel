#!/usr/bin/env bash
# Bambodia OAuth Step 26J-C: READ-ONLY diagnostic after Composer wrapper
# was mistakenly passed to PHP 8.5 and vendor/autoload.php was not installed.
# No Composer execution, no network, no Git fetch, no cache cleanup, no writes.
set -Eeuo pipefail
set +x
umask 077
export GIT_OPTIONAL_LOCKS=0
export GIT_NO_LAZY_FETCH=1

SOURCE="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
DEST="/var/lib/bambodia-oauth-https-qa"
LOCK_SHA="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHOST_SHA="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
fail() { printf 'STEP26JC_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in python3 sha256sum realpath stat git; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$SOURCE/.git" && ! -L "$SOURCE" &&
   "$(realpath -e "$SOURCE")" == "$SOURCE" ]] ||
  fail ACCEPTED_SOURCE_UNSAFE
[[ "$(git -C "$SOURCE" rev-parse HEAD)" == "$SHA" ]] ||
  fail APPROVED_SOURCE_CHANGED
[[ -z "$(git -C "$SOURCE" status --porcelain --untracked-files=no)" ]] ||
  fail APPROVED_SOURCE_TRACKED_CHANGES
[[ -d "$DEST" && ! -L "$DEST" && "$(realpath -e "$DEST")" == "$DEST" &&
   "$(stat -c %u "$DEST")" == 0 &&
   "$(stat -c %a "$DEST")" == 700 ]] ||
  fail PRIVATE_STAGING_ROOT_UNSAFE
[[ ! -e "$DEST/.git" && ! -L "$DEST/.git" ]] ||
  fail STAGING_NO_LONGER_ARCHIVE_SNAPSHOT
[[ -f "$DEST/composer.lock" && ! -L "$DEST/composer.lock" &&
   "$(sha256sum "$DEST/composer.lock" | cut -d ' ' -f1)" == "$LOCK_SHA" ]] ||
  fail STAGED_LOCK_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d ' ' -f1)" == "$VHOST_SHA" ]] ||
  fail NGINX_VHOST_CHANGED

echo '========== VERIFY NO APPLICATION OR OAUTH SECRET =========='
python3 - "$DEST" <<'PY'
import os, pathlib, stat, sys
root=pathlib.Path(sys.argv[1])
private = (".env",".env.testing","storage/oauth-private.key",
           "storage/oauth-public.key","bootstrap/cache/config.php")
for name in private:
    p=root/name
    if p.is_symlink():
        raise SystemExit("STEP26JC_BLOCKED=RUNTIME_SECRET_SYMLINK")
    print("STAGED_"+name.upper().replace("/","_").replace(".","_")+
          "="+("PRESENT_REVIEW_REQUIRED" if p.exists() else "ABSENT"))
for name in ("vendor",".composer-home",".composer-cache",".composer-tmp"):
    p=root/name
    key=name.upper().replace(".","_").replace("-","_")
    if p.is_symlink():
        raise SystemExit("STEP26JC_BLOCKED=COMPOSER_ARTIFACT_SYMLINK")
    if not p.exists():
        print(key+"=ABSENT")
    elif not p.is_dir():
        print(key+"=UNEXPECTED_NON_DIRECTORY")
    else:
        info=p.lstat()
        print(key+"=DIRECTORY")
        print(key+"_UID_GID={}:{}".format(info.st_uid,info.st_gid))
        print(key+"_MODE={:04o}".format(stat.S_IMODE(info.st_mode)))
        print(key+"_TOP_LEVEL_CHILDREN="+str(sum(1 for _ in p.iterdir())))
autoload=root/"vendor/autoload.php"
if autoload.is_symlink():
    raise SystemExit("STEP26JC_BLOCKED=VENDOR_AUTOLOAD_SYMLINK")
print("VENDOR_AUTOLOAD="+("PRESENT" if autoload.is_file() else "ABSENT"))
print("PRIVATE_ARTIFACT_CONTENTS=NOT_READ")
PY

echo '========== CLASSIFY COMPOSER ENTRYPOINTS WITHOUT RUNNING THEM =========='
COMPOSER_COMMAND="$(command -v composer || true)"
export COMPOSER_COMMAND
python3 <<'PY'
import os, pathlib, stat
allowed=("/usr/local/bin/composer","/usr/bin/composer",
         "/root/composer.phar","/usr/local/bin/composer.phar",
         "/usr/share/php/composer.phar")
cmd=os.environ.get("COMPOSER_COMMAND","")
print("COMPOSER_COMMAND_PATH="+(cmd if cmd in allowed else
      "OTHER_OR_UNAVAILABLE"))
for i,spelling in enumerate(allowed,1):
    p=pathlib.Path(spelling)
    prefix="COMPOSER_CANDIDATE_{:02d}_".format(i)
    print(prefix+"PATH="+spelling)
    if p.is_symlink():
        print(prefix+"TYPE=SYMLINK_NOT_OPENED")
        continue
    if not p.is_file():
        print(prefix+"TYPE=ABSENT_OR_NOT_REGULAR")
        continue
    st=p.lstat()
    if not stat.S_ISREG(st.st_mode):
        print(prefix+"TYPE=NON_REGULAR")
        continue
    with p.open("rb") as f:
        header=f.read(8192)
    shebang=header.split(b"\n",1)[0].lower()
    if shebang.startswith(b"#!") and (
        b"bash" in shebang or b"/sh" in shebang):
        typ="SHELL_WRAPPER_DO_NOT_RUN_WITH_PHP"
    elif header.startswith(b"<?php") or (
        shebang.startswith(b"#!") and b"php" in shebang and
        b"<?php" in header[:500]):
        typ="PHP_ENTRYPOINT_CANDIDATE_NOT_EXECUTED"
    elif header.startswith(b"#!/usr/bin/env php"):
        typ="PHP_ENTRYPOINT_CANDIDATE_NOT_EXECUTED"
    else:
        typ="UNKNOWN_FILE_FORMAT"
    print(prefix+"TYPE="+typ)
    print(prefix+"REFERENCES_PHP83="+
          ("YES" if b"php83" in header else "NO"))
    print(prefix+"REFERENCES_PHP85="+
          ("YES" if b"php85" in header else "NO"))
print("COMPOSER_SOURCE_CONTENTS=NOT_PRINTED")
print("COMPOSER_EXECUTION=NONE")
PY

echo '========== FINAL NO-WRITE STATUS =========='
echo 'STEP26JC_COMPOSER_ENTRYPOINT_DIAGNOSTIC=COMPLETE'
echo 'COMPOSER_INSTALL=NOT_RETRIED'
echo 'STAGING_FILES=NOT_MODIFIED'
echo 'GIT_SOURCE=NOT_MODIFIED'
echo 'MYSQL=NOT_CONNECTED'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
