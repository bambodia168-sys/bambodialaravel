#!/usr/bin/env bash
# Stage GitHub source + verified Composer vendor OFF the live website.
# NOT an installable production release until frontend assets and rollback gates.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == --stage ]] || { echo 'Usage: bash script --stage' >&2; exit 64; }
REPO=bambodia168-sys/bambodialaravel
RELEASE=e44227b0a886a1b65f0355a92ae7b704edf225ed
VENDOR=/root/Bambodia-MCP-Main-Vendor.93QHfsxh
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
VHOST_SHA=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
LIVE_JSON=457992551f4f234ad442d9db5ad73c8b54689e7b
LIVE_LOCK=ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03
RELEASE_JSON=fc687c2f0f82e034af38b6183b0c58502a0c7948
RELEASE_MCP=e4ab6ce81b02001232ced297dbb401318d4c164b
RELEASE_CONFIG=c6b917eec7d1573c13f3335d1ed811a212850afa
RELEASE_LOCK=4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8
fail(){ printf 'MCP_SOURCE_STAGING_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in gh git sha256sum stat python3 mktemp cp; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
  -f "$APP/storage/app/installed.lock" ]] || fail LIVE_APP_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHOST_SHA" ]] || fail LIVE_VHOST_CHANGED
[[ -f "$APP/composer.json" && ! -L "$APP/composer.json" &&
  "$(git hash-object "$APP/composer.json")" == "$LIVE_JSON" ]] || fail LIVE_MANIFEST_CHANGED
[[ -f "$APP/composer.lock" && ! -L "$APP/composer.lock" &&
  "$(sha256sum "$APP/composer.lock" | cut -d' ' -f1)" == "$LIVE_LOCK" ]] || fail LIVE_LOCK_CHANGED
[[ -d "$VENDOR" && ! -L "$VENDOR" &&
  "$(stat -c '%u:%a' "$VENDOR")" == "0:700" ]] || fail PRIVATE_VENDOR_NOT_PROTECTED
[[ -f "$VENDOR/composer.lock" && ! -L "$VENDOR/composer.lock" &&
  "$(sha256sum "$VENDOR/composer.lock" | cut -d' ' -f1)" == "$RELEASE_LOCK" ]] || fail PRIVATE_VENDOR_LOCK_CHANGED
[[ -f "$VENDOR/composer.json" && ! -L "$VENDOR/composer.json" &&
  "$(git hash-object "$VENDOR/composer.json")" == "$RELEASE_JSON" ]] || fail PRIVATE_VENDOR_MANIFEST_CHANGED
[[ -f "$VENDOR/vendor/autoload.php" &&
  -f "$VENDOR/vendor/composer/installed.json" ]] || fail PRIVATE_VENDOR_INCOMPLETE

python3 - "$VHOST" "$VENDOR" <<'PY'
import json,pathlib,re,sys
text="\n".join(s for s in pathlib.Path(sys.argv[1]).read_text().splitlines()
               if not s.lstrip().startswith("#"))
roots=[s.strip() for s in re.findall(r'(?m)^\s*root\s+([^;]+);',text)]
if roots!=["/www/wwwroot/bambodia-staging-qa/public"]:
    raise SystemExit("MCP_SOURCE_STAGING_BLOCKED=LIVE_DOCUMENT_ROOT_CHANGED")
d=json.loads((pathlib.Path(sys.argv[2])/"vendor/composer/installed.json").read_text())
rows=d if isinstance(d,list) else d.get("packages",[])
packages={p["name"]:p.get("version") for p in rows if isinstance(p,dict) and p.get("name")}
if packages.get("laravel/mcp")!="v1.2.0" or packages.get("laravel/passport")!="v13.11.0":
    raise SystemExit("MCP_SOURCE_STAGING_BLOCKED=INSTALLED_MCP_OR_PASSPORT_VERSION_CHANGED")
print("LIVE_DOCUMENT_ROOT=VERIFIED")
print("ISOLATED_VENDOR_MCP_AND_PASSPORT=VERIFIED")
PY
[[ -c /dev/tty ]] || fail INTERACTIVE_APPROVAL_REQUIRED
printf 'Type STAGE-BAMBODIA-MCP to assemble private source and vendor ONLY: ' > /dev/tty
IFS= read -r ANSWER < /dev/tty
[[ "$ANSWER" == STAGE-BAMBODIA-MCP ]] || fail OPERATOR_DECLINED
unset ANSWER
WORK="$(mktemp -d /root/Bambodia-MCP-Main-Source.XXXXXXXX)"
chmod 700 "$WORK"
echo "PRIVATE_SOURCE_WORKSPACE=$WORK"

echo '========== FETCH GITHUB SOURCE AT IMMUTABLE COMMIT =========='
gh api -H 'Accept: application/vnd.github+json' \
  "repos/$REPO/tarball/$RELEASE" > "$WORK/source.tar.gz" ||
  fail PINNED_SOURCE_ARCHIVE_DOWNLOAD_FAILED
[[ -s "$WORK/source.tar.gz" ]] || fail SOURCE_ARCHIVE_EMPTY

echo '========== VALIDATE AND EXTRACT PINNED SOURCE =========='
python3 - "$WORK/source.tar.gz" "$WORK/source" <<'PY'
import os,pathlib,sys,tarfile
archive=pathlib.Path(sys.argv[1]); target=pathlib.Path(sys.argv[2])
target.mkdir(mode=0o700)
try:
    with tarfile.open(archive,'r:gz') as tf:
        members=tf.getmembers()
        if not 200<len(members)<30000:
            raise ValueError("unexpected member count")
        root=None; total=0; nfiles=0
        for m in members:
            parts=pathlib.PurePosixPath(m.name).parts
            if not parts or m.name.startswith('/') or '\\' in m.name or '..' in parts:
                raise ValueError("unsafe member path")
            if root is None: root=parts[0]
            if parts[0]!=root or not (m.isfile() or m.isdir()):
                raise ValueError("multiple roots or symlink/special file")
            if m.isfile():
                nfiles+=1; total+=m.size
                if len(parts)<2 or total>1200*1024*1024: raise ValueError("unsafe file length")
                rel=parts[1:]
                if '.git' in rel or '.env' in rel or '.env.testing' in rel:
                    raise ValueError("source includes git data or environment secrets")
        if nfiles<200: raise ValueError("incomplete repository archive")
        for m in members:
            parts=pathlib.PurePosixPath(m.name).parts
            if len(parts)<2: continue
            p=target/pathlib.Path(*parts[1:])
            if m.isdir():
                p.mkdir(mode=0o700,parents=True,exist_ok=True)
                continue
            p.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
            fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as output,tf.extractfile(m) as inp:
                while True:
                    part=inp.read(1024*1024)
                    if not part: break
                    output.write(part)
            os.chmod(p,0o700 if m.mode & 0o111 else 0o600)
except (OSError,tarfile.TarError,ValueError) as exc:
    raise SystemExit("MCP_SOURCE_STAGING_BLOCKED=ARCHIVE_VALIDATION_FAILED: "+str(exc))
print("GITHUB_SOURCE_FILES="+str(nfiles))
print("GITHUB_ARCHIVE_EXTRACTION=SAFE")
PY

[[ -f "$WORK/source/composer.json" && ! -L "$WORK/source/composer.json" &&
  "$(git hash-object "$WORK/source/composer.json")" == "$RELEASE_JSON" ]] ||
  fail SOURCE_COMPOSER_MANIFEST_BLOB_MISMATCH
[[ -f "$WORK/source/app/Http/Controllers/Api/BambodiaMcpController.php" &&
  "$(git hash-object "$WORK/source/app/Http/Controllers/Api/BambodiaMcpController.php")" == "$RELEASE_MCP" ]] ||
  fail SOURCE_MCP_CONTROLLER_BLOB_MISMATCH
[[ -f "$WORK/source/config/mcp.php" &&
  "$(git hash-object "$WORK/source/config/mcp.php")" == "$RELEASE_CONFIG" ]] ||
  fail SOURCE_MCP_CONFIG_BLOB_MISMATCH
[[ -f "$WORK/source/artisan" && -f "$WORK/source/public/index.php" ]] ||
  fail SOURCE_LARAVEL_BOOT_FILES_MISSING
[[ ! -e "$WORK/source/composer.lock" && ! -e "$WORK/source/.env" ]] ||
  fail UNEXPECTED_SOURCE_LOCK_OR_SECRETS

echo '========== ASSEMBLE ISOLATED PHP RELEASE CANDIDATE =========='
cp "$VENDOR/composer.lock" "$WORK/source/composer.lock"
cp -a "$VENDOR/vendor" "$WORK/source/vendor"
[[ -f "$WORK/source/vendor/autoload.php" &&
  "$(sha256sum "$WORK/source/composer.lock" | cut -d' ' -f1)" == "$RELEASE_LOCK" ]] ||
  fail VENDOR_OR_LOCK_STAGE_MISMATCH
echo "RELEASE_SOURCE_COMMIT=$RELEASE"
echo "RELEASE_COMPOSER_LOCK_SHA256=$RELEASE_LOCK"
echo 'SOURCE_WITH_PHP_VENDOR=STAGED'
if [[ -f "$WORK/source/public/build/manifest.json" ]]; then
  echo 'FRONTEND_BUILD=EXISTS_REQUIRES_REVIEW'
else
  echo 'FRONTEND_BUILD=MISSING_NEEDS_PRIVATE_BUILD_OR_APPROVED_ASSET_REUSE'
fi
if [[ -f "$WORK/source/package-lock.json" ]]; then
  echo 'NPM_PACKAGE_LOCK=EXISTS_REQUIRES_REVIEW'
else
  echo 'NPM_PACKAGE_LOCK=MISSING_NEEDS_REPRODUCIBLE_ASSET_BUILD_PLAN'
fi
echo 'MCP_MAIN_SOURCE_VENDOR_STAGING=PASS'
echo 'DEPLOYABLE_RELEASE=NOT_YET_APPROVED'
echo 'SOURCE_INSTALL_AND_MIGRATIONS=NOT_EXECUTED'
echo 'PRODUCTION_APP_VENDOR_LOCK_DB_KEYS=UNCHANGED'
echo 'PRODUCTION_NGINX=UNCHANGED'
echo 'MCP_ACTIVATION=NOT_STARTED'
