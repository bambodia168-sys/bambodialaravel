#!/usr/bin/env bash
# Operator-only Phase 3B.5 QA. No deployment mode exists.
set -Eeuo pipefail
set +x
umask 077
LIVE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
REPO=bambodia168-sys/bambodialaravel
RELEASE=45fcb968809be8c7abbdaa0d9d4bc46c9a2f64ff
CONTROLLER=app/Http/Controllers/Api/BambodiaMcpController.php
CONTROLLER_BLOB=766d8dbafbcd8c2ab4c742916488b217587da4bf
SERVICE=app/Support/Mcp/AdvancedContentTools.php
MIGRATION=database/migrations/2026_11_28_080000_add_description_to_media_assets.php
PATCH=support/bambodia-mcp-stage-advanced-content.py
fail(){ echo "PHASE3B5_BLOCKED=$*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || fail ROOT_REQUIRED
[[ -x "$PHP" ]] || fail PHP85_MISSING
for cmd in git curl python3 sha256sum realpath stat find openssl unshare mount setpriv getent cmp; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
check_blob(){
  local file=$1 expected=$2 got
  [[ -f "$file" && ! -L "$file" ]] || fail "MISSING_OR_SYMLINK_$file"
  got=$(git hash-object "$file")
  if [[ "$got" != "$expected" ]]; then
    echo "SOURCE_DRIFT=$file expected=$expected actual=$got" >&2
    fail SOURCE_DRIFT_REVIEW_REQUIRED
  fi
}
download(){
  local path=$1 blob=$2 dest=$3
  mkdir -p "$(dirname "$dest")"
  curl -fLsS --retry 3 --connect-timeout 15 --max-time 120 "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" -o "$dest"
  check_blob "$dest" "$blob"
}
verify_live(){
  [[ -d "$LIVE" && ! -L "$LIVE" && $(realpath "$LIVE") == "$LIVE" && -f "$LIVE/artisan" ]] || fail LIVE_PATH_CHANGED
  check_blob "$LIVE/$CONTROLLER" "$CONTROLLER_BLOB"
  check_blob "$LIVE/app/Support/Mcp/OperationalReadTools.php" 040bd589cf03c6d6c2d51a2fc42e9fd334ff1311
}
prepare(){
  [[ $# == 1 ]] || fail PREPARE_REQUIRES_VERIFIED_HARNESS_PATH
  local harness=$1
  [[ -f "$harness" && ! -L "$harness" ]] || fail HARNESS_MISSING
  verify_live
  # Independently reviewed historical OAuth components. Stop rather than adapt
  # silently to a different installed security boundary.
  check_blob "$LIVE/app/Http/Middleware/AuthenticateMcpToken.php" a1121c7c33e04a854dee24f12673d773b6caaaba
  check_blob "$LIVE/app/Support/Mcp/McpAccessGrant.php" f2aad9c181ad83557a07931f1a2c720a7f7c588a
  check_blob "$LIVE/app/Support/Mcp/McpResource.php" cc44dc7a22f2eb0e780f4c568bc01578c7dbf479
  check_blob "$LIVE/app/Support/Mcp/BambodiaPassportAccessToken.php" efe76294baf83b71cd477943bec261d617703306
  check_blob "$LIVE/app/Http/Middleware/RequireMcpOAuthResource.php" ae8922d4a1a82a5dabf11df4877d5aab8fb8093d
  check_blob "$LIVE/app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php" 641e625f4a12765a3e8da54916235ccb7b337835
  check_blob "$LIVE/app/Http/Controllers/Api/BambodiaOAuthRegisterController.php" 290f3d66998315235c9c6350e3b878d458344331
  check_blob "$LIVE/config/mcp.php" 215d9b15714e0a6b687191bdab44af54ace10450
  local stage
  stage=$(mktemp -d /var/tmp/bambodia-phase3-review.XXXXXXXX)
  chmod 700 "$stage"
  echo "STAGE=$stage"
  mkdir "$stage/source" "$stage/candidate" "$stage/verified"
  # PHP source files only; never copy .env, storage, public uploads, vendor,
  # cached configuration, databases, signing keys or production credentials.
  python3 - "$LIVE" "$stage/source" <<'PY'
import os, pathlib, shutil, sys
live,dst=map(pathlib.Path,sys.argv[1:])
def copy(rel):
    src=live/rel
    if any(p.is_symlink() for p in [src,*src.parents] if p!=live.parent and p!=live.parent.parent):
        raise SystemExit("SYMLINK_SOURCE_REFUSED: "+str(rel))
    if not src.is_file(): raise SystemExit("MISSING_SOURCE: "+str(rel))
    target=dst/rel
    target.parent.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(src,target)
for rel in ("artisan","composer.json","composer.lock","bootstrap/app.php","bootstrap/providers.php"):
    copy(pathlib.Path(rel))
for sub in ("app","config","database/migrations","database/factories","database/seeders","routes","resources/views","resources/lang","lang"):
    root=live/sub
    if not root.exists(): continue
    if root.is_symlink(): raise SystemExit("SYMLINK_SOURCE_ROOT_REFUSED: "+sub)
    for directory,dirs,files in os.walk(root,followlinks=False):
        for name in dirs:
            if (pathlib.Path(directory)/name).is_symlink(): raise SystemExit("SYMLINK_DIRECTORY_REFUSED")
        for name in files:
            if name.endswith(".php"):
                copy((pathlib.Path(directory)/name).relative_to(live))
print("SOURCE_ONLY_SNAPSHOT=COMPLETE")
PY
  check_blob "$stage/source/$CONTROLLER" "$CONTROLLER_BLOB"
  check_blob "$stage/source/app/Support/Mcp/OperationalReadTools.php" 040bd589cf03c6d6c2d51a2fc42e9fd334ff1311
  download "$SERVICE" 96ba1240af97e557d9345847b3b58d1ecf2b788a "$stage/candidate/$SERVICE"
  download "$MIGRATION" b96cedca6729eaf36c1d30d0a598c589410955be "$stage/candidate/$MIGRATION"
  download "$PATCH" 3576045f430e59b61e88870c33e80e3d448f1343 "$stage/verified/$(basename "$PATCH")"
  download support/bambodia-mcp-phase3-controller-fixture.php 662a4e2a66adee0c769b36348b4d29c514842911 "$stage/verified/bambodia-mcp-phase3-controller-fixture.php"
  download support/bambodia-mcp-phase3-controller-harness.php 8759a085b5eaf3931ca729bb160a4dad4e0f37e1 "$stage/verified/bambodia-mcp-phase3-controller-harness.php"
  download tests/TestCase.php fe1ffc2ff1abc1c23522418994879623c5647859 "$stage/source/tests/TestCase.php"
  download phpunit.xml e7f0a48df619422a48dd39ceac63c30f998fe389 "$stage/source/phpunit.xml"
  mkdir -p "$stage/source/tests/Feature" "$stage/candidate/$(dirname "$CONTROLLER")"
  cp -- "$harness" "$stage/source/tests/Feature/BambodiaPhase3IsolatedOAuthTest.php"
  mkdir "$stage/verified/bin"
  ln -s "$PHP" "$stage/verified/bin/php"
  PATH="$stage/verified/bin:$PATH" python3 "$stage/verified/$(basename "$PATCH")" --self-test
  rm "$stage/verified/bin/php"; rmdir "$stage/verified/bin"
  python3 "$stage/verified/$(basename "$PATCH")" --source "$stage/source/$CONTROLLER" --output "$stage/candidate/$CONTROLLER" --expected-source-blob "$CONTROLLER_BLOB"
  for f in "$stage/candidate/$CONTROLLER" "$stage/candidate/$SERVICE" "$stage/candidate/$MIGRATION" "$stage/source/tests/Feature/BambodiaPhase3IsolatedOAuthTest.php"; do "$PHP" -l "$f"; done
  verify_live
  (cd "$stage"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum > manifest.sha256)
  echo "REVIEW_MANIFEST_SHA256=$(sha256sum "$stage/manifest.sha256"|cut -d' ' -f1)"
  echo "COMPOSER_MANIFEST_BLOB=$(git hash-object "$stage/source/composer.json")"
  echo "COMPOSER_LOCK_SHA256=$(sha256sum "$stage/source/composer.lock"|cut -d' ' -f1)"
  echo "ROUTES_BLOB=$(git hash-object "$stage/source/routes/web.php")"
  echo "BOOTSTRAP_BLOB=$(git hash-object "$stage/source/bootstrap/app.php")"
  echo "PROVIDER_BLOB=$(git hash-object "$stage/source/app/Providers/AppServiceProvider.php")"
  echo "PHASE3B5_PREPARE=PASS"
  echo "ACTUAL_OAUTH_ACCEPTANCE=NOT_RUN"
  echo "PRODUCTION_FILES_MODIFIED=0"
}
accept(){
  [[ $# == 2 ]] || fail ACCEPT_REQUIRES_STAGE_AND_REVIEWED_MANIFEST_SHA256
  local stage=$1 reviewed=$2 run uid key composer_hash lock_hash
  [[ "$stage" =~ ^/var/tmp/bambodia-phase3-review\.[A-Za-z0-9]+$ && -d "$stage" && ! -L "$stage" && $(stat -c '%u:%a' "$stage") == 0:700 ]] || fail UNSAFE_STAGE
  [[ "$reviewed" =~ ^[a-f0-9]{64}$ && $(sha256sum "$stage/manifest.sha256"|cut -d' ' -f1) == "$reviewed" ]] || fail REVIEWED_MANIFEST_MISMATCH
  [[ -z $(find "$stage/source" "$stage/candidate" "$stage/verified" -type l -print -quit) ]] || fail STAGED_SYMLINK
  (cd "$stage"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum | cmp -s - manifest.sha256) || fail STAGED_FILE_SET_OR_CONTENT_DRIFT
  (cd "$stage"; sha256sum --check --status manifest.sha256) || fail STAGED_SOURCE_DRIFT
  verify_live
  [[ ! -e "$stage/source/$SERVICE" && ! -e "$stage/source/$MIGRATION" ]] || fail BASELINE_ALREADY_HAS_PHASE3_FILES
  check_blob "$stage/verified/$(basename "$PATCH")" 3576045f430e59b61e88870c33e80e3d448f1343
  check_blob "$stage/source/$CONTROLLER" "$CONTROLLER_BLOB"
  check_blob "$stage/candidate/$SERVICE" 96ba1240af97e557d9345847b3b58d1ecf2b788a
  check_blob "$stage/candidate/$MIGRATION" b96cedca6729eaf36c1d30d0a598c589410955be
  "$PHP" -r 'exit(PHP_VERSION_ID>=80500 && extension_loaded("pdo_sqlite") && extension_loaded("openssl") ? 0 : 1);' || fail PHP_EXTENSIONS
  [[ -f /usr/bin/composer && ! -L /usr/bin/composer ]] || fail COMPOSER_PHP_ENTRY_MISSING
  "$PHP" -r 'exit(preg_match("/(?:\A|\n)<\?php\b/",file_get_contents("/usr/bin/composer",false,null,0,1024))?0:1);' || fail COMPOSER_NOT_PHP
  # Use a numeric ephemeral UID/GID without creating a host account.
  uid=62000
  while getent passwd "$uid" >/dev/null || getent group "$uid" >/dev/null; do uid=$((uid+1)); [[ "$uid" -lt 63000 ]] || fail NO_UNUSED_UID; done
  run=$(mktemp -d /var/tmp/bambodia-phase3-runtime.XXXXXXXX)
  cleanup(){
    local rc=$1 runtime=$2
    trap - EXIT
    if [[ ! "$runtime" =~ ^/var/tmp/bambodia-phase3-runtime\.[A-Za-z0-9]+$ || -L "$runtime" ]]; then
      echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=NO" >&2
      exit 1
    fi
    if [[ -e "$runtime" ]]; then
      if ! rm -rf --one-file-system -- "$runtime"; then
        echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=NO" >&2
        exit 1
      fi
    fi
    if [[ -e "$runtime" || -L "$runtime" ]]; then
      echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=NO" >&2
      exit 1
    fi
    if [[ "$rc" != 0 ]]; then echo "ACTUAL_OAUTH_ACCEPTANCE=FAILED_OR_BLOCKED"; fi
    echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=YES"
    exit "$rc"
  }
  # Capture the path now; accept() locals are out of scope at shell EXIT.
  trap 'cleanup "$?" '"$(printf '%q' "$run")" EXIT
  echo "ISOLATED_RUNTIME=$run"
  cp -a "$stage/source/." "$run/"
  mkdir -p "$run"/{bootstrap/cache,storage/app/private,storage/app/public,storage/framework/cache/data,storage/framework/sessions,storage/framework/views,storage/logs,public,.composer-home,.composer-cache,.mask}
  key="base64:$(openssl rand -base64 32)"
  composer_hash=$(git hash-object "$run/composer.json")
  lock_hash=$(sha256sum "$run/composer.lock"|cut -d' ' -f1)
  chown -hR "$uid:$uid" "$run"
  # Always mount-isolate live webroot and common database sockets. PHP tests
  # additionally run without network. No weaker fallback if unshare fails.
  isolated(){
    local network=$1 mode=$2; shift 2
    local flags=(--mount --fork)
    [[ "$network" == off ]] && flags+=(--net)
    unshare "${flags[@]}" bash -c '
      set -Eeuo pipefail
      run=$1; uid=$2; key=$3; mode=$4; php=$5; shift 5
      mount --make-rprivate /
      for target in /www/wwwroot /tmp /www/server/data /run/mysqld; do
        if [[ -d "$target" ]]; then mount -t tmpfs -o mode=1777,nosuid,nodev tmpfs "$target"; fi
      done
      cd "$run"
      exec setpriv --reuid="$uid" --regid="$uid" --clear-groups --no-new-privs env -i PATH=/usr/bin:/bin HOME="$run" \
       APP_ENV=testing APP_DEBUG=false APP_URL=https://bambodia.com APP_KEY="$key" \
       BAMBODIA_MCP_ENABLED=false BAMBODIA_MCP_WRITES_ENABLED=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
       CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array FILESYSTEM_DISK=local LOG_CHANNEL=stderr \
       COMPOSER_HOME="$run/.composer-home" COMPOSER_CACHE_DIR="$run/.composer-cache" COMPOSER_NO_INTERACTION=1 QA_PHASE3_MODE="$mode" \
       "$php" "$@"
    ' _ "$run" "$uid" "$key" "$mode" "$PHP" "$@"
  }
  echo "=== Install locked dev dependencies in private runtime ==="
  isolated on baseline -d include_path=/usr/share/php:. /usr/bin/composer install --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress >"$stage/composer-qa.log" 2>&1 || fail PRIVATE_COMPOSER_INSTALL
  [[ $(git hash-object "$run/composer.json") == "$composer_hash" && $(sha256sum "$run/composer.lock"|cut -d' ' -f1) == "$lock_hash" ]] || fail COMPOSER_LOCK_DRIFT
  [[ -f "$run/vendor/bin/phpunit" ]] || fail PHPUNIT_MISSING
  echo "=== Check isolated runtime before generating keys ==="
  isolated off baseline -r '
    require "vendor/autoload.php"; $app=require "bootstrap/app.php";
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config("database.default")!=="sqlite" || config("database.connections.sqlite.database")!==":memory:" ||
        app()->environment()!=="testing" || config("mcp.production_enabled")!==false ||
        config("mcp.production_writes_enabled")!==false || config("mail.default")!=="array" ||
        \App\Support\Mcp\McpResource::uri()!=="https://bambodia.com/mcp") exit(9);
    echo "ISOLATED_SQLITE_MEMORY_AND_DISABLED_MCP=PASS\n";
  '
  isolated off baseline artisan passport:keys --no-interaction --no-ansi >"$stage/keys-qa.log" 2>&1 || fail EPHEMERAL_PASSPORT_KEYS
  [[ -s "$run/storage/oauth-private.key" && -s "$run/storage/oauth-public.key" ]] || fail QA_KEYS_MISSING
  chmod 600 "$run/storage/oauth-private.key" "$run/storage/oauth-public.key"
  test_run(){
    local mode=$1; shift
    local log="$stage/$mode-qa.log"
    isolated off "$mode" vendor/bin/phpunit --colors=never tests/Feature/BambodiaPhase3IsolatedOAuthTest.php "$@" >"$log" 2>&1 || { echo "PRIVATE_FAILURE_LOG=$log"; fail "${mode^^}_OAUTH_TESTS"; }
    grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions?\)' "$log" || fail TEST_SUMMARY_MISSING
    echo "${mode^^}_OAUTH_TESTS=PASS"
  }
  test_run baseline --filter=test_registry_snapshot
  echo "=== Install candidate into disposable runtime ONLY ==="
  for path in "$CONTROLLER" "$SERVICE" "$MIGRATION"; do
    install -o "$uid" -g "$uid" -m 600 "$stage/candidate/$path" "$run/$path"
  done
  test_run candidate
  echo "=== Rehearse code rollback and baseline discovery ==="
  install -o "$uid" -g "$uid" -m 600 "$stage/source/$CONTROLLER" "$run/$CONTROLLER"
  rm -- "$run/$SERVICE" "$run/$MIGRATION"
  test_run rollback --filter=test_registry_snapshot
  verify_live
  echo "PHASE3B5_ACTUAL_OAUTH_ACCEPTANCE=PASS"
  echo "PRODUCTION_FILES_MODIFIED=0"
  echo "PRODUCTION_DATABASE_ACCESSED=NO"
  echo "PRODUCTION_DEPLOYMENT=NOT_STARTED"
}
mode=${1:-}
shift || true
case "$mode" in
  --prepare) prepare "$@";;
  --accept) accept "$@";;
  *) fail USAGE_PREPARE_HARNESS_OR_ACCEPT_STAGE_MANIFEST;;
esac
