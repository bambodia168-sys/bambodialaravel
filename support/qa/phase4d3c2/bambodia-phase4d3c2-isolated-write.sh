#!/usr/bin/env bash
# Phase 4D.3C.2: disposable fixtures only; no production deployment mode.
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
RELEASE=c1db97f9798cdd0e9291fb943d5a1a697c2b9f48
TREE=fab3c6c0611b8f54955673cd2e110ef23994bcf7
STAGE=/var/tmp/bambodia-phase4-review.H49cOiGy
STAGE_HASH=608457a44dcdf87cb5438881d6fb102b151ce8408cd7adcac72637b51e43d434
PACKAGE=/root/bambodia-phase4d2-preparation.2PmfQTGk
PACKAGE_HASH=95e0bc0309565982a0544b769178805a2979b71a2832c66193a0ff51dea4ef90
LIVE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
MYSQLBASE=/www/server/mysql
CTRL=app/Http/Controllers/Api/BambodiaMcpController.php
NEW=a302fa364f1f52b6320b77688b7595b91894ca13
fail(){ echo "PHASE4D3C2_BLOCKED=$*" >&2; exit 1; }
[[ $# == 1 && "$1" == --isolated-acceptance && $(id -u) == 0 ]] || fail MODE_OR_ROOT
for cmd in git curl python3 sha256sum realpath stat find openssl unshare mount setpriv getent cmp timeout flock gh; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
[[ -x "$PHP" && -x "$MYSQLBASE/bin/mysql" && -x "$MYSQLBASE/bin/mysqld" && -f /usr/bin/composer && ! -L /usr/bin/composer ]] || fail RUNTIME_MISSING
"$PHP" -r 'exit(PHP_VERSION_ID>=80500 && extension_loaded("pdo_mysql") && extension_loaded("openssl") ? 0 : 1);' || fail PHP_EXTENSIONS
"$PHP" -r 'exit(preg_match("/(?:\A|\n)<\?php\b/",file_get_contents("/usr/bin/composer",false,null,0,1024))?0:1);' || fail COMPOSER_NOT_PHP
exec 9>/run/lock/bambodia-mcp-operational-release.lock
flock -n 9 || fail RELEASE_LOCK_BUSY
exec 8>/run/lock/bambodia-account-overview.lock
flock -n 8 || fail ACCOUNT_LOCK_BUSY
exec 7>/run/lock/bambodia-mcp-phase3c.lock
flock -n 7 || fail PHASE3_LOCK_BUSY
exec 6>/run/lock/bambodia-mcp-phase4d2.lock
flock -n 6 || fail PHASE4_LOCK_BUSY
WORK=$(mktemp -d /root/bambodia-phase4d3c2-evidence.XXXXXXXX)
RUNTIME=""
cleanup(){
  local rc=$?
  trap - EXIT
  if [[ -n "$RUNTIME" ]]; then
    [[ "$RUNTIME" =~ ^/var/tmp/bambodia-phase4d3c2-runtime\.[A-Za-z0-9]+$ && ! -L "$RUNTIME" ]] || exit 2
    rm -rf --one-file-system -- "$RUNTIME" || exit 2
    [[ ! -e "$RUNTIME" ]] || exit 2
  fi
  echo "ISOLATED_RUNTIME_AND_TEST_KEYS_REMOVED=YES"
  echo "PRIVATE_EVIDENCE=$WORK"
  if [[ "$rc" != 0 ]]; then echo "PHASE4D3C2_ACCEPTANCE=FAILED_OR_BLOCKED"; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
check_blob(){
  [[ -f "$1" && ! -L "$1" && $(git hash-object "$1") == "$2" ]] || fail BLOB_DRIFT
}
verify_evidence(){
  for dir in "$STAGE" "$PACKAGE"; do
    [[ -d "$dir" && ! -L "$dir" && $(stat -c '%u:%a' "$dir") == 0:700 ]] || fail UNSAFE_EVIDENCE
  done
  [[ $(sha256sum "$STAGE/manifest.sha256"|cut -d' ' -f1) == "$STAGE_HASH" ]] || fail STAGE_MANIFEST
  (cd "$STAGE"; sha256sum --check --status manifest.sha256) || fail STAGE_BYTES
  [[ $(sha256sum "$PACKAGE/package.sha256"|cut -d' ' -f1) == "$PACKAGE_HASH" ]] || fail PACKAGE_MANIFEST
  (cd "$PACKAGE"; sha256sum --check --status package.sha256) || fail PACKAGE_BYTES
  [[ -z $(find "$STAGE/source" "$PACKAGE/source" -type l -print -quit) ]] || fail EVIDENCE_SYMLINK
  (cd "$LIVE"; sha256sum --check --status "$PACKAGE/evidence/protected.sha256") || fail PROTECTED_CONFIG_DRIFT
  sha256sum --check --status "$PACKAGE/evidence/vhost.sha256" || fail VHOST_DRIFT
  python3 - "$STAGE/verified/live-source.sha256" "$CTRL" "$LIVE" <<'PY'
import pathlib,sys,hashlib
manifest,ctrl,live=sys.argv[1:]
root=pathlib.Path(live); seen=set(); n=0
for line in pathlib.Path(manifest).read_text().splitlines():
    digest,name=line.split('  ',1)
    name=name.removeprefix('./')
    assert name not in seen and all(p not in ('','.','..') for p in name.split('/'))
    seen.add(name)
    if name==ctrl: continue
    p=root/name
    assert p.is_file() and not any(x.is_symlink() for x in (p,*p.parents))
    assert hashlib.sha256(p.read_bytes()).hexdigest()==digest
    n+=1
assert len(seen)==653 and ctrl in seen and n==652
print('LIVE_UNCHANGED_BASELINE=652')
PY
  local n=0 blob path
  while read -r blob path; do
    check_blob "$PACKAGE/source/$path" "$blob"
    check_blob "$LIVE/$path" "$blob"
    n=$((n+1))
  done <"$PACKAGE/source-blobs.txt"
  [[ "$n" == 6 ]] || fail OVERLAY_COUNT
  check_blob "$LIVE/$CTRL" "$NEW"
  echo "ACCEPTED_SIX_FILE_OVERLAY_AND_PROTECTED_CONFIGURATION=PASS"
}
verify_evidence
# Read-only authenticated release checks; no token output.
unset GH_DEBUG DEBUG
export GH_PROMPT_DISABLED=1 GH_PAGER=cat GH_HOST=github.com
timeout 60s gh api --hostname github.com --method GET "repos/$REPO/commits/$RELEASE" >"$WORK/release.json" 2>"$WORK/github.private.log"
timeout 60s gh api --hostname github.com --method GET "repos/$REPO/actions/runs?head_sha=$RELEASE&event=push" >"$WORK/ci.json" 2>>"$WORK/github.private.log"
python3 - "$WORK" "$RELEASE" "$TREE" <<'PY'
import json,pathlib,sys
p=pathlib.Path(sys.argv[1]); release,tree=sys.argv[2:]
r=json.loads((p/'release.json').read_text()); c=json.loads((p/'ci.json').read_text())['workflow_runs']
assert r['sha']==release and r['commit']['tree']['sha']==tree
expected={46132893142,46132893168,46132893148,46132893110,46132893454,46132893144}
assert {x['id'] for x in c}==expected and all(x['head_sha']==release and x['status']=='completed' and x['conclusion']=='success' for x in c)
print('EXACT_RELEASE_AND_SIX_CI=PASS')
PY
HERE=$(dirname "$(realpath "$0")")
check_blob "$HERE/guard.php" c459bc92a4fa13af2007e13001503770841c13b1
check_blob "$HERE/oauth-worker.php" e91b35c8011a1e9eb3f00830ac7a67e536ed1f05
check_blob "$HERE/BambodiaPhase4D3C2MysqlOAuthTest.php" 8c4b62663628d2b40ec75cf62a88e4f81556b5fc
# Private source-only copy from already sealed evidence, not a live deployment.
RUNTIME=$(mktemp -d /var/tmp/bambodia-phase4d3c2-runtime.XXXXXXXX)
chmod 700 "$RUNTIME"
cp -a "$STAGE/source/." "$RUNTIME/"
while read -r blob path; do
  install -D -m 600 "$PACKAGE/source/$path" "$RUNTIME/$path"
  check_blob "$RUNTIME/$path" "$blob"
done <"$PACKAGE/source-blobs.txt"
mkdir -p "$RUNTIME"/{qa-phase4d3c2,tests/Feature,tests/installer,support,bin,bootstrap/cache,storage/app/private,storage/app/public,storage/framework/cache/data,storage/framework/sessions,storage/framework/views,storage/logs,public,.composer-home,.composer-cache,tmp}
for p in guard.php oauth-worker.php; do install -m 600 "$HERE/$p" "$RUNTIME/qa-phase4d3c2/$p"; done
install -m 600 "$HERE/BambodiaPhase4D3C2MysqlOAuthTest.php" "$RUNTIME/tests/Feature/BambodiaPhase4D3C2MysqlOAuthTest.php"
download(){
  local path=$1 sha=$2
  curl -q -fLsS --retry 3 --connect-timeout 15 --max-time 120 "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" -o "$RUNTIME/$path"
  check_blob "$RUNTIME/$path" "$sha"
}
download tests/Feature/McpProjectWriteToolsTest.php 2924afa61cd84ef22848b1e0d9c94d0e183a67f8
download tests/installer/mcp-project-concurrency.php 71f58e8dad4b02125f82e0a97235109cc7bbe34f
for p in bambodia-mcp-stage-project-write.py bambodia-mcp-stage-commerce-read.py bambodia-mcp-stage-project-read.py bambodia-mcp-stage-advanced-content.py bambodia-mcp-phase3-controller-fixture.php bambodia-mcp-phase4c-write-controller-harness.php; do
  install -m 600 "$STAGE/verified/$p" "$RUNTIME/support/$p"
done
check_blob "$RUNTIME/tests/TestCase.php" fe1ffc2ff1abc1c23522418994879623c5647859
check_blob "$RUNTIME/phpunit.xml" e7f0a48df619422a48dd39ceac63c30f998fe389
[[ ! -e "$RUNTIME/.env" && ! -e "$RUNTIME/vendor" && ! -e "$RUNTIME/storage/oauth-private.key" && ! -e "$RUNTIME/bootstrap/cache/config.php" ]] || fail UNSAFE_RUNTIME_CONTENT
for p in "$RUNTIME/qa-phase4d3c2/"*.php "$RUNTIME/tests/Feature/BambodiaPhase4D3C2MysqlOAuthTest.php" "$RUNTIME/tests/Feature/McpProjectWriteToolsTest.php" "$RUNTIME/tests/installer/mcp-project-concurrency.php"; do "$PHP" -n -l "$p"; done
ln -s "$PHP" "$RUNTIME/bin/php"
uid=62000
while getent passwd "$uid" >/dev/null || getent group "$uid" >/dev/null; do uid=$((uid+1)); [[ "$uid" -lt 63000 ]] || fail NO_UNUSED_UID; done
# Test worker is generated before dropping privileges. It never sees root credentials.
cat >"$RUNTIME/run-isolated.sh" <<'RUNNER'
#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
cd "$QA_ROOT"
export PATH="$QA_ROOT/bin:/usr/bin:/bin"
export APP_ENV=testing APP_DEBUG=false APP_URL=https://bambodia.com
export APP_KEY="base64:$(openssl rand -base64 32)"
export BAMBODIA_MCP_ENABLED=false BAMBODIA_MCP_WRITES_ENABLED=false
export DB_CONNECTION=mysql DB_URL= DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=bambodia_acceptance DB_USERNAME=bambodia_acceptance
export DB_SOCKET="$QA_ROOT/mysql.sock" DB_PASSWORD="$(openssl rand -hex 24)"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array FILESYSTEM_DISK=local LOG_CHANNEL=stderr
export BCRYPT_ROUNDS=4 COMMERCE_LIVE_GATEWAYS=false PYTHONDONTWRITEBYTECODE=1 TMPDIR="$QA_ROOT/tmp"
mkdir data
"$MYSQLBASE/bin/mysqld" --no-defaults --initialize-insecure --basedir="$MYSQLBASE" --datadir="$QA_ROOT/data" --log-error="$QA_ROOT/mysql-init.log" </dev/null >"$QA_ROOT/mysql-init.stdout" 2>&1
"$MYSQLBASE/bin/mysqld" --no-defaults --basedir="$MYSQLBASE" --datadir="$QA_ROOT/data" \
  --socket="$DB_SOCKET" --pid-file="$QA_ROOT/mysql.pid" --log-error="$QA_ROOT/mysql.log" \
  --tmpdir="$TMPDIR" --skip-networking --mysqlx=OFF --event-scheduler=OFF --skip-log-bin --skip-replica-start \
  --local-infile=0 --secure-file-priv=NULL --innodb-buffer-pool-size=64M --max-connections=16 \
  </dev/null >"$QA_ROOT/mysql.stdout" 2>&1 &
pid=$!
trap 'kill -TERM "$pid" 2>/dev/null || true; wait "$pid" || true' EXIT
cli(){ "$MYSQLBASE/bin/mysql" --no-defaults --protocol=SOCKET --socket="$DB_SOCKET" --user=root --batch --skip-column-names --local-infile=0 "$@"; }
ready=0
for i in $(seq 1 90); do
  if cli -e 'SELECT 1' </dev/null >/dev/null 2>&1; then ready=1; break; fi
  kill -0 "$pid"; sleep 1
done
[[ "$ready" == 1 ]]
# Before creating anything, prove this is the private new server, not a deployment.
test "$(cli -e 'SELECT @@datadir, @@socket, @@skip_networking, @@event_scheduler, @@local_infile')" = "$(printf '%s\t%s\t1\tOFF\t0' "$QA_ROOT/data/" "$DB_SOCKET")"
cli <<SQL
CREATE DATABASE bambodia_acceptance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bambodia_acceptance'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON bambodia_acceptance.* TO 'bambodia_acceptance'@'localhost';
SQL
touch .isolated-ready
php -r 'require "qa-phase4d3c2/guard.php"; phase4d3c2Environment(); require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); phase4d3c2Database();'
echo "PRIVATE_MYSQL_IDENTITY_AND_NETWORK_DISABLED=PASS"
php artisan passport:keys --no-interaction --no-ansi >keys.private.log 2>&1
[[ -s storage/oauth-private.key && -s storage/oauth-public.key ]]
chmod 600 storage/oauth-private.key storage/oauth-public.key
echo "INDEPENDENT_TEST_OAUTH_KEYS=PASS"
fresh(){
  php -r 'require "qa-phase4d3c2/guard.php"; phase4d3c2Environment(); require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); phase4d3c2Database();'
  php artisan migrate:fresh --force --no-interaction --no-ansi >migration.private.log 2>&1
  [[ $(cli --database=bambodia_acceptance -e 'SELECT COUNT(*) FROM mcp_project_operations') == 0 ]]
}
run_test(){
  local filter=$1 label=$2
  fresh
  php vendor/bin/phpunit --colors=never tests/Feature/BambodiaPhase4D3C2MysqlOAuthTest.php --filter "$filter" >"$label.private.log" 2>&1
  grep -E '^OK \(1 test, [0-9]+ assertions?\)' "$label.private.log"
  echo "$label=PASS"
}
run_test test_all_fourteen_new_tools_through_real_oauth_and_all_write_scope_gates MYSQL_OAUTH_ALL_FOURTEEN
run_test test_mysql_oauth_concurrent_and_strict_confirmation MYSQL_OAUTH_CONCURRENCY_AND_STRICT_CONFIRMATION
# Same eight service tests and six independent MySQL lock-contention scenarios
# already accepted in CI. Compatibility flags select the isolated fixture only.
fresh
php vendor/bin/phpunit --colors=never tests/Feature/McpProjectWriteToolsTest.php >service.private.log 2>&1
grep -E '^OK \(8 tests, [0-9]+ assertions?\)' service.private.log
echo "MYSQL_PROJECT_SERVICE_TESTS=PASS"
fresh
GITHUB_ACTIONS=true RUN_MCP_PROJECT_CONCURRENCY=1 php tests/installer/mcp-project-concurrency.php >concurrency.private.log 2>&1
test "$(grep -Fxc 'MCP_PROJECT_CONCURRENCY=PASS scenarios=6' concurrency.private.log)" -eq 1
grep -E '^PASS |^MCP_PROJECT_CONCURRENCY=PASS scenarios=6$' concurrency.private.log
echo "ISOLATED_MYSQL_TEST_SUITES=PASS"
RUNNER
chmod 700 "$RUNTIME/run-isolated.sh"
chown -hR "$uid:$uid" "$RUNTIME"
# No production credentials/environment are inherited. Only Composer needs
# outbound access for locked dependency downloads; tests run in separate
# network/PID/mount namespaces without network, with private Unix socket.
namespace_run(){
  local mode=$1; shift
  local ns=(--mount --pid --fork --kill-child=SIGKILL --mount-proc)
  [[ "$mode" == test ]] && ns+=(--net)
  timeout --signal=TERM --kill-after=15s 1800 unshare "${ns[@]}" bash -c '
    set -Eeuo pipefail
    run=$1; uid=$2; php=$3; base=$4; mode=$5; shift 5
    mount --make-rprivate /
    for target in /www/wwwroot /www/server/data /www/server/mysql/data /var/lib/mysql /run /root /home /tmp; do
      if [[ -d "$target" && ! -L "$target" ]]; then
        perm=0700; [[ "$target" == /tmp ]] && perm=1777
        mount -t tmpfs -o "mode=$perm,nosuid,nodev" tmpfs "$target"
      fi
    done
    [[ $(findmnt -n -o FSTYPE --target /www/wwwroot) == tmpfs ]]
    [[ $(findmnt -n -o FSTYPE --target /root) == tmpfs ]]
    cd "$run"
    exec setpriv --reuid="$uid" --regid="$uid" --clear-groups --no-new-privs --bounding-set=-all --inh-caps=-all --ambient-caps=-all \
      env -i PATH="$run/bin:/usr/bin:/bin" HOME="$run" QA_ROOT="$run" MYSQLBASE="$base" LC_ALL=C \
      COMPOSER_HOME="$run/.composer-home" COMPOSER_CACHE_DIR="$run/.composer-cache" COMPOSER_NO_INTERACTION=1 TMPDIR="$run/tmp" \
      "$@"
  ' _ "$RUNTIME" "$uid" "$PHP" "$MYSQLBASE" "$mode" "$@"
}
composer_before=$(sha256sum "$RUNTIME/composer.lock")
namespace_run composer "$PHP" -d include_path=/usr/share/php:. /usr/bin/composer install --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress >"$WORK/composer.private.log" 2>&1 || fail LOCKED_COMPOSER_INSTALL
[[ $(sha256sum "$RUNTIME/composer.lock") == "$composer_before" && -f "$RUNTIME/vendor/bin/phpunit" ]] || fail COMPOSER_LOCK_DRIFT
echo "LOCKED_DEPENDENCIES=PASS"
namespace_run test bash "$RUNTIME/run-isolated.sh" >"$WORK/test-summary.private.log" 2>&1 || fail ISOLATED_TEST_EXECUTION
for marker in 'PRIVATE_MYSQL_IDENTITY_AND_NETWORK_DISABLED=PASS' 'INDEPENDENT_TEST_OAUTH_KEYS=PASS' 'MYSQL_OAUTH_ALL_FOURTEEN=PASS' 'MYSQL_OAUTH_CONCURRENCY_AND_STRICT_CONFIRMATION=PASS' 'MYSQL_PROJECT_SERVICE_TESTS=PASS' 'MCP_PROJECT_CONCURRENCY=PASS scenarios=6' 'ISOLATED_MYSQL_TEST_SUITES=PASS'; do
  grep -Fxq "$marker" "$WORK/test-summary.private.log" || fail TEST_MARKER
done
grep -E '^OK \(|^PASS |^[A-Z0-9_]+=(PASS|PASS scenarios=6)$' "$WORK/test-summary.private.log"
verify_evidence
rm -rf --one-file-system -- "$RUNTIME"
[[ ! -e "$RUNTIME" ]] || fail CLEANUP
RUNTIME=""
echo "PHASE4D3C2_ACCEPTANCE=PASS"
echo "PRODUCTION_DATABASE_ACCESSED=NO"
echo "PRODUCTION_FILES_MODIFIED=0"
echo "EXTERNAL_DELIVERY_ATTEMPTED_BY_TEST_OPERATOR=NO"
echo "PRODUCTION_SERVICES_RESTARTED=NO"
