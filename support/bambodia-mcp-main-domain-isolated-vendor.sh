#!/usr/bin/env bash
# Isolated vendor build for Bambodia main-domain MCP + Passport.
# ONLY root-private filesystem changes; no live vendor/app, DB, Nginx, env,
# installed keys, QA, production app files, or OAuth activation.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--build" ]] || {
  echo "Usage: bash this-script --build" >&2; exit 64;
}

REPO="bambodia168-sys/bambodialaravel"
SOURCE="/root/Bambodia-MCP-Main-Lock.BpgOtvFv"
APP="/www/wwwroot/bambodia-staging-qa"
VHOST="/www/server/panel/vhost/nginx/bambodia.com.conf"
VHOST_SHA="5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116"
LIVE_JSON_BLOB="457992551f4f234ad442d9db5ad73c8b54689e7b"
LIVE_LOCK_SHA="ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03"
CANDIDATE_JSON_BLOB="fc687c2f0f82e034af38b6183b0c58502a0c7948"
CANDIDATE_LOCK_SHA="4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8"
PHP="/www/server/php/84/bin/php"
COMPOSER="/usr/bin/composer"
fail(){ printf 'MCP_PRIVATE_VENDOR_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for cmd in git python3 sha256sum stat mktemp cp tar; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -x "$PHP" && ! -L "$PHP" && -f "$COMPOSER" && ! -L "$COMPOSER" ]] ||
  fail PHP84_OR_COMPOSER_SOURCE_MISSING
[[ "$(stat -c '%u' "$COMPOSER")" == 0 ]] || fail COMPOSER_SOURCE_NOT_ROOT_OWNED
[[ -d "$SOURCE" && ! -L "$SOURCE" &&
  "$(stat -c '%u:%a' "$SOURCE")" == "0:700" ]] ||
  fail PRIVATE_LOCK_DIRECTORY_NOT_PROTECTED
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" ]] ||
  fail MAIN_APPLICATION_PATH_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHOST_SHA" ]] ||
  fail PRODUCTION_VHOST_CHANGED
[[ -f "$APP/storage/app/installed.lock" ]] ||
  fail LIVE_SITE_NOT_INSTALLED
[[ -f "$APP/composer.json" && ! -L "$APP/composer.json" &&
  "$(git hash-object "$APP/composer.json")" == "$LIVE_JSON_BLOB" ]] ||
  fail LIVE_COMPOSER_MANIFEST_CHANGED
[[ -f "$APP/composer.lock" && ! -L "$APP/composer.lock" &&
  "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LIVE_LOCK_SHA" ]] ||
  fail LIVE_LOCK_CHANGED
[[ -f "$SOURCE/composer.json" && ! -L "$SOURCE/composer.json" &&
  "$(git hash-object "$SOURCE/composer.json")" == "$CANDIDATE_JSON_BLOB" ]] ||
  fail PRIVATE_CANDIDATE_MANIFEST_CHANGED
[[ -f "$SOURCE/composer.lock" && ! -L "$SOURCE/composer.lock" &&
  "$(sha256sum "$SOURCE/composer.lock" | cut -d' ' -f1)" == "$CANDIDATE_LOCK_SHA" ]] ||
  fail PRIVATE_CANDIDATE_LOCK_CHANGED
[[ -f "$SOURCE/composer.baseline.lock" && ! -L "$SOURCE/composer.baseline.lock" &&
  "$(sha256sum "$SOURCE/composer.baseline.lock" | cut -d' ' -f1)" == "$LIVE_LOCK_SHA" ]] ||
  fail PRIVATE_BASELINE_LOCK_CHANGED
[[ -f "$SOURCE/composer.baseline.json" && ! -L "$SOURCE/composer.baseline.json" &&
  "$(git hash-object "$SOURCE/composer.baseline.json")" == "$LIVE_JSON_BLOB" ]] ||
  fail PRIVATE_BASELINE_MANIFEST_CHANGED

echo "========== REVIEW PRIVATE DEPENDENCY DELTA =========="
python3 - "$SOURCE" "$VHOST" <<'PY'
import json,pathlib,re,sys
w=pathlib.Path(sys.argv[1])
vhost=pathlib.Path(sys.argv[2])
active="\n".join(line for line in vhost.read_text().splitlines()
                 if not line.lstrip().startswith("#"))
roots=[v.strip() for v in re.findall(r'(?m)^\s*root\s+([^;]+);',active)]
if roots != ["/www/wwwroot/bambodia-staging-qa/public"]:
    raise SystemExit("MCP_PRIVATE_VENDOR_BLOCKED=PRODUCTION_ROOT_CHANGED")
print("BAMBODIA_MAIN_DOMAIN_VHOST=BASELINE_VERIFIED")

base=json.loads((w/"composer.baseline.lock").read_text())
candidate=json.loads((w/"composer.lock").read_text())
manifest=json.loads((w/"composer.json").read_text())
old_manifest=json.loads((w/"composer.baseline.json").read_text())
expected={"laravel/mcp":"^1.1","laravel/passport":"^13.10"}
old_require=old_manifest.get("require",{})
new_require=manifest.get("require",{})
if {**old_manifest, "require":{**old_require,**expected}} != manifest:
    raise SystemExit("MCP_PRIVATE_VENDOR_BLOCKED=COMPOSER_JSON_NOT_EXACT_TWO_PACKAGE_DELTA")

def versions(doc):
    return {row["name"]:row["version"]
            for group in ("packages","packages-dev")
            for row in doc.get(group,[])
            if isinstance(row,dict) and "name" in row and "version" in row}
a,b=versions(base),versions(candidate)
added=set(b)-set(a)
removed=set(a)-set(b)
updated={p for p in set(a)&set(b) if a[p]!=b[p]}
expected_changed={"guzzlehttp/guzzle","guzzlehttp/psr7","laravel/framework",
                  "laravel/prompts","laravel/serializable-closure",
                  "nunomaduro/termwind"}
if len(a)!=110 or len(b)!=129 or len(added)!=19 or removed or updated!=expected_changed:
    raise SystemExit("MCP_PRIVATE_VENDOR_BLOCKED=LOCK_PACKAGE_DRIFT_NOT_APPROVED")
if b.get("laravel/mcp")!="v1.2.0" or b.get("laravel/passport")!="v13.11.0":
    raise SystemExit("MCP_PRIVATE_VENDOR_BLOCKED=MCP_OR_PASSPORT_VERSION_CHANGED")
print("PRIVATE_LOCK_OLD_PACKAGES=110")
print("PRIVATE_LOCK_NEW_PACKAGES=129")
print("PRIVATE_LOCK_ADDED_PACKAGES=19")
print("PRIVATE_LOCK_REMOVED_PACKAGES=0")
print("PRIVATE_LOCK_UPDATED_PACKAGES=6")
for name in sorted(updated):
    print("UPDATED_PACKAGE="+name+": "+a[name]+" -> "+b[name])
print("LOCK_CANDIDATE=EXPECTED_APPROVED_PACKAGE_SET")
PY

if [[ -c /dev/tty ]]; then
  printf 'Type BUILD-BAMBODIA-MCP-VENDOR to create ONLY an isolated private vendor tree: ' > /dev/tty
  IFS= read -r APPROVAL < /dev/tty
  [[ "$APPROVAL" == "BUILD-BAMBODIA-MCP-VENDOR" ]] || fail OPERATOR_DECLINED
else
  fail INTERACTIVE_OPERATOR_APPROVAL_REQUIRED
fi
unset APPROVAL

WORK="$(mktemp -d /root/Bambodia-MCP-Main-Vendor.XXXXXXXX)"
chmod 700 "$WORK"
echo "PRIVATE_VENDOR_WORKSPACE=$WORK"
cp -- "$SOURCE/composer.json" "$WORK/composer.json"
cp -- "$SOURCE/composer.lock" "$WORK/composer.lock"
for directory in .composer-home .composer-cache .composer-tmp; do
  mkdir -m 700 "$WORK/$directory"
done
echo "========== BUILD VENDOR OFF LIVE SITE =========="
echo "COMPOSER_PLUGINS_AND_SCRIPTS=DISABLED"
echo "DEVELOPMENT_DEPENDENCIES=EXCLUDED"
echo "PRODUCTION_ARTISAN=NOT_EXECUTED"
echo "PHP_RESOLVER_VERSION=$("$PHP" -r 'echo PHP_VERSION;')"
if ! (
  cd "$WORK"
  export COMPOSER_HOME="$WORK/.composer-home"
  export COMPOSER_CACHE_DIR="$WORK/.composer-cache"
  export TMPDIR="$WORK/.composer-tmp"
  export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
  "$PHP" -d 'include_path=/usr/share/php:.' "$COMPOSER" \
    install --no-dev --no-scripts --no-plugins --prefer-dist \
    --no-interaction --optimize-autoloader --no-progress --no-ansi \
    > "$WORK/composer-install.log" 2>&1
); then
  echo "MCP_PRIVATE_VENDOR_BLOCKED=ISOLATED_COMPOSER_INSTALL_FAILED"
  echo "PRIVATE_COMPOSER_LOG=$WORK/composer-install.log"
  echo "LOG_CONTENTS=NOT_PRINTED"
  exit 1
fi
[[ -f "$WORK/vendor/autoload.php" && ! -L "$WORK/vendor/autoload.php" &&
  -f "$WORK/vendor/composer/installed.json" ]] ||
  fail VENDOR_AUTOLOAD_OR_MANIFEST_NOT_CREATED
[[ "$(sha256sum "$WORK/composer.lock" | cut -d' ' -f1)" == "$CANDIDATE_LOCK_SHA" ]] ||
  fail VENDOR_BUILD_CHANGED_PINNED_LOCK
[[ "$(git hash-object "$WORK/composer.json")" == "$CANDIDATE_JSON_BLOB" ]] ||
  fail VENDOR_BUILD_CHANGED_PINNED_MANIFEST

echo "========== REVIEW INSTALLED PRODUCTION PACKAGES =========="
python3 - "$WORK" <<'PY'
import json,pathlib,sys
work=pathlib.Path(sys.argv[1])
installed=json.loads((work/"vendor/composer/installed.json").read_text())
rows=installed if isinstance(installed,list) else installed.get("packages",[])
versions={p["name"]:p["version"] for p in rows if isinstance(p,dict)
          and p.get("name") and p.get("version")}
for pkg,expected in {
    "laravel/mcp":"v1.2.0",
    "laravel/passport":"v13.11.0",
}.items():
    if versions.get(pkg)!=expected:
        raise SystemExit("MCP_PRIVATE_VENDOR_BLOCKED=INSTALLED_PACKAGE_VERSION_MISMATCH_"+pkg)
print("MCP_PACKAGE_INSTALLED=v1.2.0")
print("PASSPORT_PACKAGE_INSTALLED=v13.11.0")
print("ISOLATED_VENDOR_PACKAGES="+str(len(versions)))
print("ISOLATED_VENDOR_AUTOLOADER=CREATED")
PY

echo "PRIVATE_VENDOR_LOCK_SHA256=$(sha256sum "$WORK/composer.lock" | cut -d' ' -f1)"
echo "ISOLATED_VENDOR_BUILD=PASS"
echo "SOURCE_RELEASE_ARCHIVE=NOT_ASSEMBLED"
echo "PRODUCTION_APP_VENDOR_AND_COMPOSER_LOCK=UNCHANGED"
echo "PRODUCTION_DATABASE_AND_PASSPORT_KEYS=UNCHANGED"
echo "PRODUCTION_NGINX=UNCHANGED"
echo "MCP_PRODUCTION_DEPLOYMENT=NOT_STARTED"
