#!/usr/bin/env bash
# Bambodia Issue #35: read-only integrity inspection of the EXACT merged-build artifact.
# Never installs dependencies, modifies .env, runs migrations, or touches production/QA.
set -Eeuo pipefail
set +x
umask 077

REPO="bambodia168-sys/bambodialaravel"
SHA="133f82a3e8681f3efbc8de9424a83dc3306e15d5"
RUN="43059243796"
ARTIFACT="Bambodia-Installer-v0.0.1"
PROD="/www/wwwroot/bambodia.com"
QA="/www/wwwroot/bambodia-preview-qa"

fail(){ echo "BLOCKED=$*" >&2; exit 2; }
[[ $# -eq 1 && "$1" == "--check" ]] || fail 'Usage: --check'
[[ "$(id -u)" == 0 ]] || fail 'Run as root on the authorized aaPanel host'
for cmd in gh git python3 mktemp sha256sum realpath df; do
  command -v "$cmd" >/dev/null || fail "Missing $cmd"
done
[[ ! -L "$PROD" && ! -L "$QA" && -f "$PROD/artisan" && -f "$QA/artisan" ]] ||
  fail 'Expected real application directories missing or linked'
[[ "$(realpath -e "$PROD")" != "$(realpath -e "$QA")" ]] ||
  fail 'QA and production resolve to same directory'
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" ]] ||
  fail 'QA is not at the approved merged SHA'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'QA source has local tracked modifications'
[[ -f "$QA/.env" && ! -L "$QA/.env" && "$(stat -c %a "$QA/.env")" == "600" ]] ||
  fail 'Expected private QA .env missing or unsafe'
[[ ! -f "$QA/composer.lock" && ! -f "$QA/package-lock.json" ]] ||
  fail 'Build locks are already present: require manual state review'
[[ ! -e "$QA/vendor" && ! -e "$QA/public/build" ]] ||
  fail 'QA already has installed dependencies or assets: require manual state review'

echo '========== VERIFY IMMUTABLE CI RUN =========='
RUN_SHA="$(gh api "repos/$REPO/actions/runs/$RUN" --jq .head_sha)"
RUN_RESULT="$(gh api "repos/$REPO/actions/runs/$RUN" --jq .conclusion)"
RUN_NAME="$(gh api "repos/$REPO/actions/runs/$RUN" --jq .name)"
[[ "$RUN_SHA" == "$SHA" && "$RUN_RESULT" == success &&
   "$RUN_NAME" == "Build Bambodia Installer" ]] ||
  fail 'CI build was not a successful build for the approved source'
COUNT="$(gh api "repos/$REPO/actions/runs/$RUN/artifacts" \
  --jq '[.artifacts[] | select(.name == "Bambodia-Installer-v0.0.1" and .expired == false)] | length')"
[[ "$COUNT" == 1 ]] || fail 'Expected one live installer artifact on exact CI run'
echo "CI_RUN=$RUN"
echo "CI_SOURCE_SHA=$SHA"
echo 'CI_BUILD=SUCCESS_VERIFIED'

TMP="$(mktemp -d /root/bambodia-build-audit.XXXXXXXX)"
cleanup(){
  rc=$?
  trap - EXIT
  if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /root/bambodia-build-audit.* ]]; then
    rm -rf -- "$TMP"
  fi
  exit "$rc"
}
trap cleanup EXIT
echo '========== DOWNLOAD CI ARTIFACT TO PRIVATE TEMP DIRECTORY =========='
gh run download "$RUN" --repo "$REPO" --name "$ARTIFACT" --dir "$TMP" >/dev/null
[[ -f "$TMP/Bambodia-Installer.zip" && ! -L "$TMP/Bambodia-Installer.zip" ]] ||
  fail 'Expected installer archive not found in GitHub Actions artifact'
sha256sum "$TMP/Bambodia-Installer.zip"
echo '========== CHECK SOURCE AND LOCKFILES WITHOUT EXTRACTION =========='
python3 - "$TMP/Bambodia-Installer.zip" "$QA" <<'PY'
import hashlib
import json
import pathlib
import stat
import sys
import zipfile

archive_path = pathlib.Path(sys.argv[1])
qa = pathlib.Path(sys.argv[2])

required = [
    "artisan",
    "bootstrap/app.php",
    "public/index.php",
    "routes/web.php",
    "config/preview_visibility.php",
    "app/Support/PreviewVisibilityService.php",
    "database/migrations/2026_11_11_000020_create_preview_visibility_actions_table.php",
    "composer.json",
    "package.json",
    "VERSION",
]
artifacts = [
    "composer.lock",
    "package-lock.json",
    "vendor/autoload.php",
    "public/build/manifest.json",
]
with zipfile.ZipFile(archive_path) as z:
    members = z.infolist()
    if not members or len(members) > 300000:
        raise SystemExit("BLOCKED=unexpected archive size or entry count")
    if sum(x.file_size for x in members) > 3 * 1024**3:
        raise SystemExit("BLOCKED=uncompressed archive size is excessive")
    names = set()
    for item in members:
        name = item.filename
        p = pathlib.PurePosixPath(name)
        if (not name.startswith("Bambodia/") or
            name.startswith("/") or
            "\\" in name or
            any(part in ("..", ".") for part in p.parts)):
            raise SystemExit("BLOCKED=unsafe archive entry path")
        if name in names:
            raise SystemExit("BLOCKED=duplicate archive path")
        names.add(name)
        mode = item.external_attr >> 16
        if stat.S_ISLNK(mode):
            raise SystemExit("BLOCKED=archive symlink is not permitted")
        if name in ("Bambodia/.env", "Bambodia/storage/app/installed.lock"):
            raise SystemExit("BLOCKED=archive contains a private environment or install lock")
    for rel in required + artifacts:
        if "Bambodia/" + rel not in names:
            raise SystemExit("BLOCKED=required archive file missing: " + rel)
    for rel in required:
        digest_artifact = hashlib.sha256(z.read("Bambodia/" + rel)).digest()
        digest_staged = hashlib.sha256((qa / rel).read_bytes()).digest()
        if digest_artifact != digest_staged:
            raise SystemExit("BLOCKED=CI source differs from approved QA checkout: " + rel)
    composer_lock = json.loads(z.read("Bambodia/composer.lock"))
    package_lock = json.loads(z.read("Bambodia/package-lock.json"))
    if not isinstance(composer_lock.get("packages"), list) or not composer_lock["packages"]:
        raise SystemExit("BLOCKED=empty or invalid Composer lockfile")
    if package_lock.get("lockfileVersion") not in (2, 3):
        raise SystemExit("BLOCKED=invalid npm lockfileVersion")
    if not isinstance(package_lock.get("packages"), dict) or not package_lock["packages"]:
        raise SystemExit("BLOCKED=empty or invalid npm lockfile")
    json.loads(z.read("Bambodia/public/build/manifest.json"))
    bad = z.testzip()
    if bad is not None:
        raise SystemExit("BLOCKED=archive CRC failure at " + bad)
    print("CI_SOURCE_FILES=EXACT_MATCH")
    print("COMPOSER_LOCKFILE=FOUND_VALID")
    print("NPM_LOCKFILE=FOUND_VALID")
    print("COMPILED_ASSETS=FOUND_VALID")
    print("RUNTIME_VENDOR=FOUND")
    print("ZIP_CRC=PASS")
print("RESULT=CI_RUNTIME_AND_LOCKFILES_VERIFIED_NO_QA_CHANGES")
PY
echo 'NEXT=Stage only verified vendor/build/lockfiles after independent scope review'
echo 'PRODUCTION=UNTOUCHED'
echo 'QA_DATABASE=UNTOUCHED'
echo 'MIGRATIONS=NOT_RUN'
echo 'WRITE_SWITCH=OFF'
