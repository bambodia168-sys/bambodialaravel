#!/usr/bin/env bash
# Operator-only Phase 4E.5 QA. No deployment mode exists.
set -Eeuo pipefail
set +x
umask 077
LIVE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
REPO=bambodia168-sys/bambodialaravel
RELEASE=fd91345ed14fea36ed62f60d0cf43d3dc26024f2
CONTROLLER=app/Http/Controllers/Api/BambodiaMcpController.php
CONTROLLER_BLOB=a302fa364f1f52b6320b77688b7595b91894ca13
PATCH=support/bambodia-mcp-stage-invoice-write.py
HARNESS_BLOB=34a735f87c58d0f3cee5611307ecfc87771f7cea
AUDIT=app/Models/AuditEvent.php
AUDIT_BASE=478ab1fd7d6ed75028aca3e64ced4a572f286a27
AUDIT_CANDIDATE=4812c5d412c4711e37237d4e2f83bf0daf98657a
ADDITIONS=(
  app/Models/MarketplaceInvoice.php
  app/Models/MarketplaceInvoiceItem.php
  app/Policies/MarketplaceInvoicePolicy.php
  app/Support/Invoices/InvoiceDraftService.php
  app/Support/Invoices/InvoiceMoney.php
  app/Support/Mcp/InvoiceReadTools.php
  app/Support/Mcp/InvoiceWriteTools.php
  config/invoices.php
  database/migrations/2026_11_28_120000_create_marketplace_invoice_foundation.php
)
BLOBS=(
  a791525667e3ab195c62bce7e42a29817680a678
  629bb895e6d970b5bee54cbc472d7b86f3f071ad
  3f16123ee1794933dbca1a7ada4a54fb2e052fad
  86e043a56bf764952f7d6cf1988b3b7088fc3b09
  d393ddf8dc7f2a471a3404022dc8e98118447da6
  3a1830746923cc7a78a20970143a57ec87119e05
  259f8cfe1c60d261584199cf6483c57fa5636ccc
  7f86f47b819152e628ee999ad88dd3efe54d70d5
  c70ab314663276fa5a715513f7576068835461df
)
MYSQL_BASE=/www/server/mysql

fail(){ echo "PHASE4E5_BLOCKED=$*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || fail ROOT_REQUIRED
[[ -x "$PHP" ]] || fail PHP85_MISSING
for cmd in git curl python3 sha256sum realpath stat find openssl unshare mount setpriv getent cmp; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
check_blob(){
  local file=$1 expected=$2 got
  [[ -f "$file" && ! -L "$file" ]] || fail "MISSING_OR_SYMLINK_$file"
  got=$(git hash-object --no-filters "$file")
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
  check_blob "$LIVE/app/Support/Mcp/AdvancedContentTools.php" 96ba1240af97e557d9345847b3b58d1ecf2b788a
  check_blob "$LIVE/database/migrations/2026_11_28_080000_add_description_to_media_assets.php" b96cedca6729eaf36c1d30d0a598c589410955be
  check_blob "$LIVE/app/Support/Mcp/CommerceReadTools.php" a9e5cf8e596769ca0cc90f9ca6ee4721710b9d5c
  check_blob "$LIVE/app/Support/Mcp/ProjectReadTools.php" 84036ebe85a7c906911a343720dc8b5e43b6e1b2
  check_blob "$LIVE/app/Support/Mcp/ProjectStateVersion.php" 40159625dff463d6911e9f61cfe542dd61b05497
  check_blob "$LIVE/app/Support/Mcp/ProjectWriteTools.php" 7c31cf5acd15669a2fbf5b718d13298fdb34ca4c
  check_blob "$LIVE/database/migrations/2026_11_28_100000_create_mcp_project_operations.php" 982b0ca4816ffe5ae69b1961c34c18fa276bc08f
  check_blob "$LIVE/$AUDIT" "$AUDIT_BASE"
  for path in "${ADDITIONS[@]}"; do
    [[ ! -e "$LIVE/$path" && ! -L "$LIVE/$path" ]] || fail INVOICE_FILES_ALREADY_LIVE
  done
}
prepare(){
  [[ $# == 1 ]] || fail PREPARE_REQUIRES_VERIFIED_HARNESS_PATH
  local harness=$1
  check_blob "$harness" "$HARNESS_BLOB"
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
  stage=$(mktemp -d /var/tmp/bambodia-phase4e5-review.XXXXXXXX)
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
  # Record only copied live source hashes; later ensure the same source remains installed.
  (cd "$stage/source"; find . -type f -print0 | sort -z | xargs -0 sha256sum > "$stage/verified/live-source.sha256")
  for i in "${!ADDITIONS[@]}"; do
    download "${ADDITIONS[$i]}" "${BLOBS[$i]}" "$stage/candidate/${ADDITIONS[$i]}"
  done
  download "$AUDIT" "$AUDIT_CANDIDATE" "$stage/candidate/$AUDIT"
  download "$PATCH" e546fd64a5b21f8aa596c9f0796b3cdd2ef2f "$stage/verified/$(basename "$PATCH")"
  download support/bambodia-mcp-stage-invoice-read.py f609302a172c8bd2bb23a92706e10b47adfe8f9c "$stage/verified/bambodia-mcp-stage-invoice-read.py"
  download support/bambodia-mcp-stage-project-write.py b987de1ae5e3c7986df16abb66d4d4315eeafd4c "$stage/verified/bambodia-mcp-stage-project-write.py"
  download support/bambodia-mcp-stage-commerce-read.py f3da978caeef2f94ea3fa5b7718d5f1a2f1969f2 "$stage/verified/bambodia-mcp-stage-commerce-read.py"
  download support/bambodia-mcp-stage-project-read.py d3eb5e727c8357a320d48c27279ff1af4b09cc80 "$stage/verified/bambodia-mcp-stage-project-read.py"
  download support/bambodia-mcp-stage-advanced-content.py 3576045f430e59b61e88870c33e80e3d448f1343 "$stage/verified/bambodia-mcp-stage-advanced-content.py"
  download support/bambodia-mcp-phase3-controller-fixture.php 662a4e2a66adee0c769b36348b4d29c514842911 "$stage/verified/bambodia-mcp-phase3-controller-fixture.php"
  download support/bambodia-mcp-phase4e3-controller-harness.php 82a580836c42a2d92e38f3196b1a22928be12805 "$stage/verified/bambodia-mcp-phase4e3-controller-harness.php"
  download tests/TestCase.php fe1ffc2ff1abc1c23522418994879623c5647859 "$stage/source/tests/TestCase.php"
  download phpunit.xml e7f0a48df619422a48dd39ceac63c30f998fe389 "$stage/source/phpunit.xml"
  mkdir -p "$stage/source/tests/Feature" "$stage/candidate/$(dirname "$CONTROLLER")"
  cp -- "$harness" "$stage/source/tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php"
  mkdir "$stage/verified/bin"
  ln -s "$PHP" "$stage/verified/bin/php"
  PATH="$stage/verified/bin:$PATH" PYTHONDONTWRITEBYTECODE=1 python3 "$stage/verified/$(basename "$PATCH")" --self-test
  rm "$stage/verified/bin/php"; rmdir "$stage/verified/bin"
  PYTHONDONTWRITEBYTECODE=1 python3 "$stage/verified/$(basename "$PATCH")" --source "$stage/source/$CONTROLLER" --output "$stage/candidate/$CONTROLLER"
  for path in "$CONTROLLER" "$AUDIT" "${ADDITIONS[@]}"; do "$PHP" -l "$stage/candidate/$path"; done
  "$PHP" -l "$stage/source/tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php"
  echo "APPROVED_CONTROLLER_BLOB=$CONTROLLER_BLOB"
  echo "STAGED_CONTROLLER_BLOB=$(git hash-object --no-filters "$stage/candidate/$CONTROLLER")"
  (cd "$LIVE"; sha256sum --check --status "$stage/verified/live-source.sha256") || fail LIVE_SOURCE_DRIFT
  verify_live
  (cd "$stage"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum > manifest.sha256)
  echo "REVIEW_MANIFEST_SHA256=$(sha256sum "$stage/manifest.sha256"|cut -d' ' -f1)"
  echo "COMPOSER_MANIFEST_BLOB=$(git hash-object "$stage/source/composer.json")"
  echo "COMPOSER_LOCK_SHA256=$(sha256sum "$stage/source/composer.lock"|cut -d' ' -f1)"
  echo "ROUTES_BLOB=$(git hash-object "$stage/source/routes/web.php")"
  echo "BOOTSTRAP_BLOB=$(git hash-object "$stage/source/bootstrap/app.php")"
  echo "PROVIDER_BLOB=$(git hash-object "$stage/source/app/Providers/AppServiceProvider.php")"
  echo "PHASE4E5_PREPARE=PASS"
  echo "ACTUAL_OAUTH_ACCEPTANCE=NOT_RUN"
  echo "PRODUCTION_FILES_MODIFIED=0"
}
accept(){
  [[ $# == 2 ]] || fail ACCEPT_REQUIRES_STAGE_AND_REVIEWED_MANIFEST_SHA256
  local stage=$1 reviewed=$2 run uid key composer_hash lock_hash
  [[ "$stage" =~ ^/var/tmp/bambodia-phase4-review\.[A-Za-z0-9]+$ && -d "$stage" && ! -L "$stage" && $(stat -c '%u:%a' "$stage") == 0:700 ]] || fail UNSAFE_STAGE
  [[ "$reviewed" =~ ^[a-f0-9]{64}$ && $(sha256sum "$stage/manifest.sha256"|cut -d' ' -f1) == "$reviewed" ]] || fail REVIEWED_MANIFEST_MISMATCH
  [[ -z $(find "$stage/source" "$stage/candidate" "$stage/verified" -type l -print -quit) ]] || fail STAGED_SYMLINK
  (cd "$stage"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum | cmp -s - manifest.sha256) || fail STAGED_FILE_SET_OR_CONTENT_DRIFT
  (cd "$stage"; sha256sum --check --status manifest.sha256) || fail STAGED_SOURCE_DRIFT
  verify_live
  for path in "${ADDITIONS[@]}"; do [[ ! -e "$stage/source/$path" ]] || fail BASELINE_ALREADY_HAS_INVOICE_FILES; done
  check_blob "$stage/verified/$(basename "$PATCH")" e546fd64a5b21f8aa596c9f0796b3cdd2ef2f
  check_blob "$stage/source/tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php" "$HARNESS_BLOB"
  (cd "$LIVE"; sha256sum --check --status "$stage/verified/live-source.sha256") || fail LIVE_SOURCE_DRIFT
  check_blob "$stage/source/$CONTROLLER" "$CONTROLLER_BLOB"
  check_blob "$stage/source/$AUDIT" "$AUDIT_BASE"
  check_blob "$stage/candidate/$AUDIT" "$AUDIT_CANDIDATE"
  for i in "${!ADDITIONS[@]}"; do check_blob "$stage/candidate/${ADDITIONS[$i]}" "${BLOBS[$i]}"; done
  [[ -x "$MYSQL_BASE/bin/mysqld" && -x "$MYSQL_BASE/bin/mysql" ]] || fail PRIVATE_MYSQL_BINARY_MISSING
  # Restage from the pinned source and compare exact bytes before execution.
  local reproduced
  reproduced=$(mktemp -d "$stage/recheck.XXXXXXXX")
  PYTHONDONTWRITEBYTECODE=1 python3 "$stage/verified/$(basename "$PATCH")" --source "$stage/source/$CONTROLLER" --output "$reproduced/controller.php"
  cmp -s "$reproduced/controller.php" "$stage/candidate/$CONTROLLER" || fail CONTROLLER_REPRODUCTION_MISMATCH
  rm -- "$reproduced/controller.php"; rmdir "$reproduced"
  "$PHP" -r 'exit(PHP_VERSION_ID>=80500 && extension_loaded("pdo_sqlite") && extension_loaded("pdo_mysql") && extension_loaded("openssl") ? 0 : 1);' || fail PHP_EXTENSIONS
  [[ -f /usr/bin/composer && ! -L /usr/bin/composer ]] || fail COMPOSER_PHP_ENTRY_MISSING
  "$PHP" -r 'exit(preg_match("/(?:\A|\n)<\?php\b/",file_get_contents("/usr/bin/composer",false,null,0,1024))?0:1);' || fail COMPOSER_NOT_PHP
  # Use a numeric ephemeral UID/GID without creating a host account.
  uid=62000
  while getent passwd "$uid" >/dev/null || getent group "$uid" >/dev/null; do uid=$((uid+1)); [[ "$uid" -lt 63000 ]] || fail NO_UNUSED_UID; done
  run=$(mktemp -d /var/tmp/bambodia-phase4e5-runtime.XXXXXXXX)
  cleanup(){
    local rc=$1 runtime=$2
    trap - EXIT
    if [[ ! "$runtime" =~ ^/var/tmp/bambodia-phase4-runtime\.[A-Za-z0-9]+$ || -L "$runtime" ]]; then
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
  mkdir -p "$run"/{bootstrap/cache,storage/app/private,storage/app/public,storage/framework/cache/data,storage/framework/sessions,storage/framework/views,storage/logs,public,.composer-home,.composer-cache,.mask,mysql}
  key="base64:$(openssl rand -base64 32)"
  composer_hash=$(git hash-object "$run/composer.json")
  lock_hash=$(sha256sum "$run/composer.lock"|cut -d' ' -f1)
  chown -hR "$uid:$uid" "$run"
  # Always mount-isolate live webroot and common database sockets. PHP tests
  # additionally run without network. No weaker fallback if unshare fails.
  isolated(){
    local network=$1 mode=$2 db=$3; shift 3
    local flags=(--mount --fork)
    [[ "$network" == off ]] && flags+=(--net)
    unshare "${flags[@]}" bash -c '
      set -Eeuo pipefail
      run=$1; uid=$2; key=$3; mode=$4; php=$5; db=$6; mysqlbase=$7; shift 7
      mount --make-rprivate /
      for target in /www/wwwroot /tmp /www/server/data /run/mysqld; do
        if [[ -d "$target" ]]; then mount -t tmpfs -o mode=1777,nosuid,nodev tmpfs "$target"; fi
      done
      cd "$run"
      exec setpriv --reuid="$uid" --regid="$uid" --clear-groups --no-new-privs env -i PATH=/usr/bin:/bin HOME="$run" \
       APP_ENV=testing APP_DEBUG=false APP_URL=https://bambodia.com APP_KEY="$key" \
       BAMBODIA_MCP_ENABLED=false BAMBODIA_MCP_WRITES_ENABLED=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
       CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array FILESYSTEM_DISK=local LOG_CHANNEL=stderr \
       COMPOSER_HOME="$run/.composer-home" COMPOSER_CACHE_DIR="$run/.composer-cache" COMPOSER_NO_INTERACTION=1 QA_PHASE4_MODE="$mode" \
       QA_PHASE4E5_ISOLATED=1 QA_PHASE4E5_DB="$db" INVOICES_ENABLED=false INVOICES_DRAFT_WRITES_ENABLED=false \
       bash "$run/qa-isolated-db.sh" "$db" "$mysqlbase" "$php" "$@"
    ' _ "$run" "$uid" "$key" "$mode" "$PHP" "$db" "$MYSQL_BASE" "$@"
  }
  cat >"$run/qa-isolated-db.sh" <<'DBSH'
#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
db=$1; mysqlbase=$2; php=$3; shift 3
if [[ "$db" == sqlite ]]; then exec "$php" "$@"; fi
[[ "$db" == mysql && "$QA_PHASE4E5_ISOLATED" == 1 && "$APP_ENV" == testing ]] || exit 91
[[ "$PWD" =~ ^/var/tmp/bambodia-phase4e5-runtime\.[A-Za-z0-9]+$ ]] || exit 92
# A completely new private InnoDB instance for every PHPUnit invocation.
# No default config, network listener, shared data, password or production socket.
base="$PWD/mysql"; data="$base/data"; sock="$base/mysql.sock"
[[ -d "$base" && ! -L "$base" && ! -e "$data" && ! -e "$sock" ]] || exit 93
mkdir -m 700 "$data"
pid=
cleanup_db(){
  rc=$?
  trap - EXIT
  if [[ -n "$pid" ]]; then kill -TERM "$pid" 2>/dev/null || true; wait "$pid" || true; fi
  rm -rf --one-file-system -- "$data"
  rm -f -- "$sock" "$base/mysqld.pid" "$base/mysqlx.sock" "$base/mysql.sock.lock" "$base/mysqlx.sock.lock"
  exit "$rc"
}
trap cleanup_db EXIT
"$mysqlbase/bin/mysqld" --no-defaults --basedir="$mysqlbase" --datadir="$data" --initialize-insecure --log-error="$base/init.log"
"$mysqlbase/bin/mysqld" --no-defaults --basedir="$mysqlbase" --datadir="$data" --socket="$sock" \
  --pid-file="$base/mysqld.pid" --log-error="$base/server.log" --skip-networking --mysqlx=OFF --skip-log-bin \
  --tmpdir="$base" --innodb-buffer-pool-size=128M &
pid=$!
ready=no
for _ in $(seq 1 60); do
  if "$mysqlbase/bin/mysql" --no-defaults --protocol=SOCKET --socket="$sock" -uroot -Nse 'SELECT 1' >/dev/null 2>&1; then ready=yes; break; fi
  kill -0 "$pid" || exit 94
  sleep 1
done
[[ "$ready" == yes ]] || exit 95
"$mysqlbase/bin/mysql" --no-defaults --protocol=SOCKET --socket="$sock" -uroot <<'SQL'
CREATE DATABASE bambodia_invoice_oauthqa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'invoice_qa'@'localhost' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON bambodia_invoice_oauthqa.* TO 'invoice_qa'@'localhost';
SQL
export DB_CONNECTION=mysql DB_DATABASE=bambodia_invoice_oauthqa DB_HOST=localhost DB_SOCKET="$sock" DB_USERNAME=invoice_qa DB_PASSWORD= DB_URL=
"$php" "$@"
DBSH
  chmod 700 "$run/qa-isolated-db.sh"
  chown "$uid:$uid" "$run/qa-isolated-db.sh"
  echo "=== Install locked dev dependencies in private runtime ==="
  isolated on baseline sqlite -d include_path=/usr/share/php:. /usr/bin/composer install --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress >"$stage/composer-qa.log" 2>&1 || fail PRIVATE_COMPOSER_INSTALL
  [[ $(git hash-object "$run/composer.json") == "$composer_hash" && $(sha256sum "$run/composer.lock"|cut -d' ' -f1) == "$lock_hash" ]] || fail COMPOSER_LOCK_DRIFT
  [[ -f "$run/vendor/bin/phpunit" ]] || fail PHPUNIT_MISSING
  echo "=== Check isolated runtime before generating keys ==="
  isolated off baseline sqlite -r '
    require "vendor/autoload.php"; $app=require "bootstrap/app.php";
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config("database.default")!=="sqlite" || config("database.connections.sqlite.database")!==":memory:" ||
        app()->environment()!=="testing" || config("mcp.production_enabled")!==false ||
        config("mcp.production_writes_enabled")!==false || config("mail.default")!=="array" ||
        \App\Support\Mcp\McpResource::uri()!=="https://bambodia.com/mcp") exit(9);
    echo "ISOLATED_SQLITE_MEMORY_AND_DISABLED_MCP=PASS\n";
  '
  isolated off baseline sqlite artisan passport:keys --no-interaction --no-ansi >"$stage/keys-qa.log" 2>&1 || fail EPHEMERAL_PASSPORT_KEYS
  [[ -s "$run/storage/oauth-private.key" && -s "$run/storage/oauth-public.key" ]] || fail QA_KEYS_MISSING
  chmod 600 "$run/storage/oauth-private.key" "$run/storage/oauth-public.key"
  test_run(){
    local mode=$1 db=$2; shift 2
    local log="$stage/$mode-$db-qa.log"
    isolated off "$mode" "$db" vendor/bin/phpunit --colors=never tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php "$@" >"$log" 2>&1 || { echo "PRIVATE_FAILURE_LOG=$log"; fail "${mode^^}_${db^^}_OAUTH_TESTS"; }
    grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions?\)' "$log" || fail TEST_SUMMARY_MISSING
    echo "${mode^^}_${db^^}_OAUTH_TESTS=PASS"
  }
  for db in sqlite mysql; do test_run baseline "$db" --filter=test_registry_snapshot; done
  echo "BASELINE_REGISTRY=38 READ=25 WRITE=13"
  echo "=== Install invoice MCP candidate into disposable runtime ONLY ==="
  for path in "$CONTROLLER" "$AUDIT" "${ADDITIONS[@]}"; do
    mkdir -p "$run/$(dirname "$path")"
    install -o "$uid" -g "$uid" -m 600 "$stage/candidate/$path" "$run/$path"
  done
  # New directories created by root are traversable; PHP files are fixture-UID owned.
  for db in sqlite mysql; do test_run candidate "$db"; done
  echo "CANDIDATE_REGISTRY=45 READ=29 WRITE=16"
  echo "=== Rehearse code rollback and baseline discovery ==="
  for path in "$CONTROLLER" "$AUDIT"; do
    install -o "$uid" -g "$uid" -m 600 "$stage/source/$path" "$run/$path"
    cmp -s "$run/$path" "$stage/source/$path" || fail ROLLBACK_FILE_MISMATCH
  done
  for path in "${ADDITIONS[@]}"; do rm -- "$run/$path"; done
  for db in sqlite mysql; do test_run rollback "$db" --filter=test_registry_snapshot; done
  echo "ROLLBACK_REGISTRY=38 READ=25 WRITE=13"
  echo "ROLLBACK_CODE_FINGERPRINTS=PASS"
  (cd "$LIVE"; sha256sum --check --status "$stage/verified/live-source.sha256") || fail LIVE_SOURCE_DRIFT
  verify_live
  echo "PHASE4E5_ACTUAL_OAUTH_ACCEPTANCE=PASS"
  echo "LIVE_REQUEST_FPM_PROBE=NOT_PERFORMED"
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
