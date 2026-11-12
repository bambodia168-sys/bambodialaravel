#!/usr/bin/env bash
# Resolve ONLY main-domain MCP/Passport Composer dependencies from the actual
# installed production lockfile, in a separate root-private workspace.
# No production files, databases, .env, keys, vendor or Nginx are modified.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--prepare-lock" ]] || {
  echo 'Usage: bash this-script --prepare-lock' >&2; exit 64;
}
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
VHOST_SHA=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
MAIN_JSON_BLOB=457992551f4f234ad442d9db5ad73c8b54689e7b
LIVE_LOCK_SHA=ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03
RELEASE_JSON_BLOB=fc687c2f0f82e034af38b6183b0c58502a0c7948
REPO=bambodia168-sys/bambodialaravel
RELEASE_SOURCE=c3f118842fdb7f8ae54e1df316ef4eac0c50195a
PHP=/www/server/php/84/bin/php
COMPOSER=/usr/bin/composer
fail(){ echo "MCP_LOCK_BUILD_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for cmd in gh git sha256sum stat python3 mktemp cp; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" ]] || fail LIVE_APPLICATION_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHOST_SHA" ]] ||
  fail MAIN_VHOST_CHANGED
[[ -f "$APP/composer.json" && ! -L "$APP/composer.json" &&
  "$(git hash-object "$APP/composer.json")" == "$MAIN_JSON_BLOB" ]] ||
  fail LIVE_COMPOSER_MANIFEST_NOT_APPROVED_MAIN_BASELINE
[[ -f "$APP/composer.lock" && ! -L "$APP/composer.lock" &&
  "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LIVE_LOCK_SHA" ]] ||
  fail LIVE_COMPOSER_LOCK_CHANGED
[[ -x "$PHP" && ! -L "$PHP" ]] || fail PHP84_NOT_AVAILABLE
[[ -f "$COMPOSER" && ! -L "$COMPOSER" &&
  "$(stat -c %u "$COMPOSER")" == 0 ]] || fail COMPOSER_PHP_ENTRYPOINT_UNSAFE
[[ -f "$APP/storage/app/installed.lock" ]] || fail MAIN_APP_NOT_INSTALLED
# Confirm expected path is the sole active Nginx document root.
python3 - "$VHOST" <<'PY'
import pathlib,re,sys
s="\n".join(line for line in pathlib.Path(sys.argv[1]).read_text().splitlines()
  if not line.lstrip().startswith("#"))
roots=re.findall(r'(?m)^\s*root\s+([^;]+);',s)
if [x.strip() for x in roots] != ["/www/wwwroot/bambodia-staging-qa/public"]:
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=MAIN_DOMAIN_ROOT_NOT_EXPECTED")
print("ACTIVE_BAMBODIA_DOMAIN_ROOT=VERIFIED")
PY

echo '========== PRODUCTION BASELINE (READ ONLY) =========='
echo 'LIVE_COMPOSER_JSON=EXACT_MAIN_BASELINE'
echo 'LIVE_COMPOSER_LOCK_SHA256=VERIFIED'
echo 'PRODUCTION_LARAVEL_VENDOR=UNCHANGED'
echo "PHP_ISOLATED_RESOLVER_VERSION=$("$PHP" -r 'echo PHP_VERSION;')"
# Capture version privately because older Composer may emit deprecation notices.
VER_TMP="$(mktemp /root/bambodia-main-composer-version.XXXXXXXX)"
trap 'rm -f -- "$VER_TMP"' EXIT
"$PHP" -d "include_path=/usr/share/php:." "$COMPOSER" --version --no-plugins --no-ansi > "$VER_TMP" 2>&1 ||
  fail PHP84_COMPOSER_RUNTIME_FAILED
if ! grep -Eq '^Composer( version)? 2\.' "$VER_TMP"; then
  fail PHP84_COMPOSER_VERSION_UNRECOGNIZED
fi
echo 'COMPOSER_DIRECT_PHP84_ENTRYPOINT=VERIFIED'

[[ -c /dev/tty ]] || fail INTERACTIVE_APPROVAL_REQUIRED
printf 'Type PREPARE-BAMBODIA-MCP-LOCK to build a PRIVATE dependency lock (no live changes): ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == PREPARE-BAMBODIA-MCP-LOCK ]] || fail OPERATOR_DECLINED
unset APPROVAL

WORK="$(mktemp -d /root/Bambodia-MCP-Main-Lock.XXXXXXXX)"
chmod 700 "$WORK"
echo "PRIVATE_LOCK_BUILD_DIRECTORY=$WORK"
cp -- "$APP/composer.json" "$WORK/composer.baseline.json"
cp -- "$APP/composer.lock" "$WORK/composer.baseline.lock"
cp -- "$APP/composer.lock" "$WORK/composer.lock"

echo '========== FETCH PINNED MAIN-DOMAIN MANIFEST =========='
gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/composer.json?ref=$RELEASE_SOURCE" > "$WORK/composer.json" ||
  fail PINNED_RELEASE_MANIFEST_DOWNLOAD_FAILED
[[ "$(git hash-object "$WORK/composer.json")" == "$RELEASE_JSON_BLOB" ]] ||
  fail PINNED_RELEASE_MANIFEST_GIT_BLOB_MISMATCH

python3 - "$WORK" <<'PY'
import json,pathlib,sys
w=pathlib.Path(sys.argv[1])
base=json.loads((w/"composer.baseline.json").read_text())
new=json.loads((w/"composer.json").read_text())
b=base.pop("require")
n=new.pop("require")
if base!=new or set(n)!=set(b)|{"laravel/mcp","laravel/passport"}:
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=UNEXPECTED_COMPOSER_MANIFEST_CHANGES")
if any(n.get(k)!=v for k,v in b.items()):
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=EXISTING_DEPENDENCY_CONSTRAINT_CHANGED")
if n["laravel/mcp"]!="^1.1" or n["laravel/passport"]!="^13.10":
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=NEW_DEPENDENCY_CONSTRAINT_NOT_APPROVED")
print("COMPOSER_MANIFEST_DELTA=ONLY_LARAVEL_MCP_AND_PASSPORT")
PY

for sub in .composer-home .composer-cache .composer-tmp; do
  mkdir -m 700 "$WORK/$sub"
done
echo '========== RESOLVE LOCK IN PRIVATE DIRECTORY =========='
echo 'COMPOSER_POST_INSTALL_SCRIPTS=DISABLED'
echo 'COMPOSER_PLUGINS=DISABLED'
echo 'COMPOSER_VENDOR_INSTALL=NOT_REQUESTED'
echo 'PRODUCTION_ARTISAN_AND_MYSQL=NOT_INVOKED'
(
  cd "$WORK"
  export COMPOSER_HOME="$WORK/.composer-home"
  export COMPOSER_CACHE_DIR="$WORK/.composer-cache"
  export TMPDIR="$WORK/.composer-tmp"
  export COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1
  if ! "$PHP" -d "include_path=/usr/share/php:." "$COMPOSER" \
      update laravel/mcp laravel/passport \
      --with-all-dependencies --no-install --no-scripts --no-plugins \
      --prefer-dist --no-interaction --no-progress --no-ansi \
      > "$WORK/composer-update.log" 2>&1; then
    echo 'MCP_LOCK_BUILD_BLOCKED=PRIVATE_COMPOSER_RESOLUTION_FAILED'
    echo "PRIVATE_COMPOSER_LOG=$WORK/composer-update.log"
    echo 'DO_NOT_COPY_PRIVATE_LOG_IN_FULL_WITHOUT_REVIEW'
    exit 1
  fi
) || fail ISOLATED_RESOLUTION_FAILED

echo '========== REVIEW RESOLVED DEPENDENCY CHANGES =========='
python3 - "$WORK" <<'PY'
import json,pathlib,sys
w=pathlib.Path(sys.argv[1])
base=json.loads((w/"composer.baseline.lock").read_text())
new=json.loads((w/"composer.lock").read_text())
def packages(o):
    return {p["name"]:p.get("version") for category in ("packages","packages-dev")
            for p in o.get(category,[]) if isinstance(p,dict) and "name" in p}
a,b=packages(base),packages(new)
added=set(b)-set(a)
removed=set(a)-set(b)
updated={p for p in a.keys()&b.keys() if a[p]!=b[p]}
print("COMPOSER_BASELINE_LOCK_PACKAGES="+str(len(a)))
print("COMPOSER_NEW_LOCK_PACKAGES="+str(len(b)))
print("COMPOSER_LOCK_ADDED_PACKAGES="+str(len(added)))
print("COMPOSER_LOCK_REMOVED_PACKAGES="+str(len(removed)))
print("COMPOSER_LOCK_UPDATED_PACKAGE_VERSIONS="+str(len(updated)))
if removed:
    print("COMPOSER_LOCK_REMOVED_PACKAGE_NAMES="+",".join(sorted(removed)[:40]))
if updated:
    print("COMPOSER_LOCK_UPDATED_PACKAGE_NAMES="+",".join(sorted(updated)[:40]))
if "laravel/mcp" not in b or "laravel/passport" not in b:
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=REQUIRED_PACKAGES_NOT_LOCKED")
if "laravel/framework" not in b:
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=LARAVEL_FRAMEWORK_MISSING")
if len(removed)>20 or len(updated)>45:
    raise SystemExit("MCP_LOCK_BUILD_BLOCKED=EXCESSIVE_DEPENDENCY_DRIFT")
print("NEW_MCP_PACKAGE_VERSION="+str(b["laravel/mcp"]))
print("NEW_PASSPORT_PACKAGE_VERSION="+str(b["laravel/passport"]))
print("PRIVATE_LOCK_CANDIDATE=CREATED_FOR_REVIEW")
PY

echo "PRIVATE_LOCK_CANDIDATE_SHA256=$(sha256sum "$WORK/composer.lock" | awk '{print $1}')"
echo 'MCP_ISOLATED_COMPOSER_LOCK_PREPARATION=PASS'
echo 'PRODUCTION_COMPOSER_JSON_AND_LOCK=UNCHANGED'
echo 'PRODUCTION_VENDOR=UNCHANGED'
echo 'PRODUCTION_NGINX_AND_DATABASE=UNCHANGED'
echo 'MCP_MAIN_DOMAIN_ACTIVATION=NOT_STARTED'
