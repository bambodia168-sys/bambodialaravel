#!/usr/bin/env bash
# Bambodia #35: stage only a pinned CI build's vendor/build/lockfiles into isolated QA.
# Does NOT modify source, .env, database, Nginx or production, nor run Artisan.
set -Eeuo pipefail
set +x
umask 077

MODE="--check"
if (( $# == 1 )); then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--check" || "$MODE" == "--apply" ) ]] || {
  echo 'Usage: --check|--apply' >&2; exit 2;
}
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

REPO="bambodia168-sys/bambodialaravel"
RUN="43059243796"
SHA="133f82a3e8681f3efbc8de9424a83dc3306e15d5"
ZIP_SHA256="46025670bdb96b2b32fdb229276d184d43b63bff2cbb63779bb237ae548e21a2"
ARTIFACT="Bambodia-Installer-v0.0.1"
QA="/www/wwwroot/bambodia-preview-qa"
PROD="/www/wwwroot/bambodia.com"

[[ "$(id -u)" == 0 ]] || fail 'Run from authorized aaPanel root shell'
for bin in git gh python3 sha256sum mktemp realpath stat; do
  command -v "$bin" >/dev/null || fail "Missing command: $bin"
done
[[ ! -L /www && ! -L /www/wwwroot && ! -L "$QA" && ! -L "$PROD" ]] ||
  fail 'Unexpected linked directory'
[[ -f "$PROD/artisan" && -f "$QA/artisan" && -d "$QA/.git" ]] ||
  fail 'Production or isolated QA checkout missing'
[[ "$(realpath -e "$QA")" != "$(realpath -e "$PROD")" ]] ||
  fail 'QA resolves to production'
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" ]] ||
  fail 'QA source commit differs from verified CI build'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'QA tracked source modified'
[[ -f "$QA/.env" && ! -L "$QA/.env" && "$(stat -c %a "$QA/.env")" == "600" ]] ||
  fail 'QA private environment missing or permissions not 0600'
[[ "$(stat -c %U:%G "$QA/.env")" == "www:www" ]] ||
  fail 'QA private environment ownership differs from expected www:www'
for path in composer.lock package-lock.json vendor public/build; do
  [[ ! -e "$QA/$path" && ! -L "$QA/$path" ]] ||
    fail "Runtime destination already exists: $path (refuse overwrite)"
done
[[ ! -e "$QA/public/hot" ]] || fail 'Vite dev-server marker exists'
[[ ! -e "$QA/bootstrap/cache/config.php" ]] || fail 'Cached configuration present'
if [[ -d /www/server/panel/vhost/nginx ]] &&
   grep -rFq -- "$QA" /www/server/panel/vhost/nginx; then
  fail 'An aaPanel Nginx vhost references QA before acceptance'
fi
id www >/dev/null || fail 'aaPanel www account missing'

python3 - "$QA/.env" <<'PY'
from pathlib import Path
import re, sys
env = Path(sys.argv[1]).read_text()
kv = {}
for line in env.splitlines():
    if not line or line.lstrip().startswith("#") or "=" not in line:
        continue
    k,v = line.split("=", 1)
    kv[k.strip()] = v.strip().strip('"').strip("'")
expect = {
    "APP_ENV":"staging", "APP_DEBUG":"false",
    "APP_URL":"http://127.0.0.1",
    "DB_CONNECTION":"mysql", "DB_HOST":"127.0.0.1",
    "DB_DATABASE":"bambodia_pvqa_29c824170b10",
    "DB_USERNAME":"b_pvqa_29c824170b10",
    "SESSION_DRIVER":"file","CACHE_STORE":"file",
    "QUEUE_CONNECTION":"sync","MAIL_MAILER":"array",
    "FILESYSTEM_DISK":"local","MEDIA_LIBRARY_DISK":"local",
    "PREVIEW_VISIBILITY_WRITES_ENABLED":"false"
}
for k, wanted in expect.items():
    if kv.get(k) != wanted:
        raise SystemExit("BLOCKED=unsafe QA env setting: "+k)
if not re.fullmatch(r"base64:[A-Za-z0-9+/]{43}=", kv.get("APP_KEY","")):
    raise SystemExit("BLOCKED=missing or invalid independent APP_KEY")
if not re.fullmatch(r"bambodia_pvqa_[a-f0-9]{12}",kv.get("SESSION_COOKIE","")):
    raise SystemExit("BLOCKED=QA session cookie isolation not demonstrated")
if not kv.get("DB_PASSWORD") or "DB_URL" in kv or "SESSION_CONNECTION" in kv:
    raise SystemExit("BLOCKED=QA database connection is missing or ambiguous")
print("QA_PRIVATE_ENV=SAFE_STAGING_DEFAULTS")
PY

echo '========== VERIFY EXACT GITHUB CI BUILD =========='
[[ "$(gh api "repos/$REPO/actions/runs/$RUN" --jq .head_sha)" == "$SHA" ]] ||
  fail 'CI build run SHA mismatch'
[[ "$(gh api "repos/$REPO/actions/runs/$RUN" --jq .conclusion)" == "success" ]] ||
  fail 'CI build not successful'
[[ "$(gh api "repos/$REPO/actions/runs/$RUN" --jq .name)" == "Build Bambodia Installer" ]] ||
  fail 'Unexpected CI workflow'
N="$(gh api "repos/$REPO/actions/runs/$RUN/artifacts" \
    --jq '[.artifacts[] | select(.name == "Bambodia-Installer-v0.0.1" and .expired == false)] | length')" ||
  fail 'Artifact metadata inaccessible'
[[ "$N" == 1 ]] || fail 'Exact CI artifact is not available'
echo 'CI_BUILD_SHA_AND_ARTIFACT=VERIFIED'

TMP="$(mktemp -d /root/bambodia-preview-runtime.XXXXXXXX)"
cleanup(){
  rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-preview-runtime.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT
gh run download "$RUN" --repo "$REPO" --name "$ARTIFACT" --dir "$TMP" >/dev/null ||
  fail 'Could not download exact CI artifact'
ZIP="$TMP/Bambodia-Installer.zip"
[[ -f "$ZIP" && ! -L "$ZIP" ]] || fail 'CI artifact missing expected ZIP'
ACTUAL="$(sha256sum "$ZIP" | cut -d' ' -f1)"
[[ "$ACTUAL" == "$ZIP_SHA256" ]] ||
  fail 'Installer ZIP does not match operator-verified immutable checksum'
echo "ZIP_SHA256=$ACTUAL"

# Full tracked-file comparison plus restricted extraction to four runtime paths.
python3 - "$ZIP" "$QA" "$MODE" <<'PY'
from pathlib import Path, PurePosixPath
import grp
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile

archive, qa, mode = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
prefix = "Bambodia/"
runtime_prefixes = ("vendor/", "public/build/")
runtime_files = ("composer.lock", "package-lock.json")
critical = (
    "artisan", "bootstrap/app.php","public/index.php", "routes/web.php",
    "app/Support/PreviewVisibilityService.php", "config/preview_visibility.php",
    "database/migrations/2026_11_11_000020_create_preview_visibility_actions_table.php",
    "composer.json", "package.json", "installer/entry.php"
)
def die(message):
    raise SystemExit("BLOCKED="+message)
def digest(fileobj):
    h = hashlib.sha256()
    for chunk in iter(lambda: fileobj.read(1024 * 1024), b""):
        h.update(chunk)
    return h.digest()
def runtime_path(p):
    return p in runtime_files or any(p.startswith(pre) for pre in runtime_prefixes)

with zipfile.ZipFile(archive) as z:
    infos = z.infolist()
    if len(infos) == 0 or len(infos) > 300000:
        die("unexpected artifact entry count")
    if sum(x.file_size for x in infos) > 3 * 1024**3:
        die("artifact uncompressed size over 3 GiB cap")
    entries = {}
    for info in infos:
        name = info.filename
        if not name.startswith(prefix) or name.startswith("/") or "\\" in name:
            die("unsafe zip prefix")
        rel = name[len(prefix):]
        parts = PurePosixPath(rel).parts
        if not rel or rel.startswith("/") or any(p in (".", "..") for p in parts):
            if rel != "":
                die("unsafe zip path")
        if rel in entries:
            die("duplicate path in zip")
        entries[rel] = info
        code = stat.S_IFMT(info.external_attr >> 16)
        if code not in (0, stat.S_IFDIR, stat.S_IFREG):
            die("non-file or symlink in artifact")
        if rel in (".env","storage/app/installed.lock"):
            die("artifact accidentally contains sensitive or installation state")
    for rel in critical + runtime_files + (
        "vendor/autoload.php","public/build/manifest.json"
    ):
        if rel not in entries or entries[rel].is_dir():
            die("missing required CI file: "+rel)
    tracked = subprocess.check_output(
        ["git","-C",str(qa),"ls-files","-z"]
    ).decode().strip("\0").split("\0")
    matches = 0
    for rel in tracked:
        info = entries.get(rel)
        if info is None or info.is_dir():
            continue
        expected = qa / rel
        if not expected.is_file() or expected.is_symlink():
            die("staged source missing or linked: "+rel)
        with z.open(info) as src, expected.open("rb") as dst:
            if digest(src) != digest(dst):
                die("CI artifact source differs from reviewed git: "+rel)
        matches += 1
    if matches < 50:
        die("too few matching tracked files to establish archive source integrity")
    pkg = json.loads(z.read(prefix+"package.json"))
    npm_lock = json.loads(z.read(prefix+"package-lock.json"))
    if (npm_lock.get("lockfileVersion") not in (2,3) or
        npm_lock.get("packages",{}).get("",{}).get("devDependencies",{})
            != pkg.get("devDependencies",{})):
        die("npm lockfile does not match source package devDependencies")
    composer = json.loads(z.read(prefix+"composer.lock"))
    if not isinstance(composer.get("packages"),list) or not composer["packages"]:
        die("Composer lockfile package set invalid")
    manifest = json.loads(z.read(prefix+"public/build/manifest.json"))
    if not isinstance(manifest,dict) or not manifest:
        die("compiled frontend manifest empty")
    if z.testzip() is not None:
        die("CI ZIP CRC failure")
    print("ALL_INCLUDED_TRACKED_SOURCE=EXACT_MATCH")
    print("MATCHING_SOURCE_FILES="+str(matches))
    print("COMPOSER_AND_NPM_LOCKS=VERIFIED_PRESENT")
    print("VENDOR_AND_COMPILED_ASSETS=VERIFIED_PRESENT")
    print("ZIP_CRC=PASS")
    if mode == "--check":
        print("RESULT=READY_FOR_RUNTIME_ONLY_STAGE_NO_CHANGES")
        sys.exit(0)

    for rel in runtime_files + ("vendor","public/build"):
        if os.path.lexists(qa / rel):
            die("unexpected preexisting runtime destination: "+rel)
    www_gid = grp.getgrnam("www").gr_gid
    stage_dir = None
    installed = []
    try:
        stage_dir = Path(tempfile.mkdtemp(prefix=".preview-runtime-incoming.", dir=str(qa)))
        os.chmod(stage_dir,0o700)
        selected = []
        for rel,info in entries.items():
            if runtime_path(rel) and not info.is_dir():
                if rel not in runtime_files and not any(rel.startswith(x) for x in runtime_prefixes):
                    die("unsupported runtime path")
                selected.append((rel,info))
        if len(selected) < 20:
            die("runtime payload unexpectedly small")
        total = 0
        for rel,info in selected:
            target = stage_dir / rel
            target.parent.mkdir(parents=True,exist_ok=True)
            with z.open(info) as stream, target.open("xb") as output:
                shutil.copyfileobj(stream,output,1024*1024)
            if target.stat().st_size != info.file_size:
                die("extracted size mismatch: "+rel)
            total += target.stat().st_size
            os.chown(target,0,www_gid)
            executable = bool((info.external_attr >> 16) & 0o111)
            os.chmod(target,0o750 if executable else 0o640)
        for path in sorted(stage_dir.rglob("*")):
            if path.is_dir():
                os.chown(path,0,www_gid)
                os.chmod(path,0o750)
        for rel in runtime_files + ("vendor","public/build"):
            from_path = stage_dir / rel
            to_path = qa / rel
            if not from_path.exists() or os.path.lexists(to_path):
                die("runtime stage destination conflict: "+rel)
            os.rename(from_path, to_path)
            installed.append(rel)
        print("RUNTIME_FILES_INSTALLED="+str(len(selected)))
        print("RUNTIME_BYTES_INSTALLED="+str(total))
        print("RUNTIME_PATHS="+",".join(installed))
    except BaseException:
        if installed:
            print("PARTIAL_QA_RUNTIME_INSTALLED="+",".join(installed),file=sys.stderr)
            print("BLOCKED=manual QA repair required; no production impact",file=sys.stderr)
        raise
    finally:
        if stage_dir is not None and stage_dir.exists():
            shutil.rmtree(stage_dir)
print("RESULT=QA_RUNTIME_STAGED_NO_DB_OR_SOURCE_CHANGES")
PY

echo 'PRODUCTION=UNTOUCHED_BY_THIS_SCRIPT'
echo 'QA_ENV=UNMODIFIED'
echo 'QA_DATABASE=UNTOUCHED'
echo 'MIGRATIONS=NOT_RUN'
echo 'PREVIEW_WRITES=OFF'
echo 'NEXT=HOST_QA_BOOT_AND_MIGRATION_PREFLIGHT'
