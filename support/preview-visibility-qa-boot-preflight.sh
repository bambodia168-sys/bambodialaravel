#!/usr/bin/env bash
# Bambodia Issue #35 / Stage 5: read-only QA Laravel boot + MySQL migration preflight.
# NO migrate, schema creation, DDL, test seeding, production reads or vhost changes.
set -Eeuo pipefail
set +x
umask 077
[[ $# == 1 && "$1" == "--check" ]] || { echo 'Usage: --check' >&2; exit 2; }
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

QA=/www/wwwroot/bambodia-preview-qa
PROD=/www/wwwroot/bambodia.com
PHP=/www/server/php/85/bin/php
SHA=133f82a3e8681f3efbc8de9424a83dc3306e15d5
DB=bambodia_pvqa_29c824170b10
DBUSER=b_pvqa_29c824170b10

[[ "$(id -u)" == 0 ]] || fail 'Authorized root aaPanel shell required'
for bin in git runuser realpath stat sha256sum mktemp; do
  command -v "$bin" >/dev/null || fail "Missing command: $bin"
done
[[ -x "$PHP" && ! -L /www && ! -L /www/wwwroot &&
   ! -L "$QA" && ! -L "$PROD" ]] || fail 'Unexpected PHP or linked application paths'
[[ -f "$PROD/artisan" && -f "$QA/artisan" && -d "$QA/.git" ]] ||
  fail 'QA source or protected production baseline absent'
[[ "$(realpath -e "$QA")" != "$(realpath -e "$PROD")" ]] ||
  fail 'QA path resolves to protected production path'
[[ "$(git -C "$QA" rev-parse HEAD)" == "$SHA" &&
   -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail 'QA source is not the approved clean merge commit'
[[ -f "$QA/.env" && ! -L "$QA/.env" &&
   "$(stat -c %a "$QA/.env")" == 600 &&
   "$(stat -c %U:%G "$QA/.env")" == www:www ]] ||
  fail 'QA environment missing or incorrect ownership/permissions'
for rel in vendor/autoload.php vendor/composer/platform_check.php \
  composer.lock package-lock.json public/build/manifest.json \
  config/preview_visibility.php \
  database/migrations/2026_11_11_000020_create_preview_visibility_actions_table.php; do
  [[ -s "$QA/$rel" && ! -L "$QA/$rel" ]] ||
    fail "Missing or linked QA file: $rel"
done
[[ ! -e "$QA/public/hot" && ! -e "$QA/bootstrap/cache/config.php" &&
   ! -e "$QA/storage/app/installed.lock" ]] ||
  fail 'Pre-existing Vite/config/installed marker requires independent review'
[[ ! -L "$QA/storage" && ! -L "$QA/storage/framework/sessions" &&
   ! -L "$QA/storage/framework/cache" ]] ||
  fail 'QA session/cache storage is linked'
if [[ -d /www/server/panel/vhost/nginx ]] &&
  grep -rFq -- "$QA" /www/server/panel/vhost/nginx; then
  fail 'QA public Nginx vhost already enabled before host isolation acceptance'
fi
id www >/dev/null || fail 'aaPanel www user missing'

for ext in pdo_mysql mbstring openssl fileinfo; do
  "$PHP" -r 'exit(extension_loaded($argv[1])?0:1);' "$ext" ||
    fail "PHP extension unavailable: $ext"
done
echo '========== BAMBODIA QA STAGE 5 READ-ONLY PREFLIGHT =========='
echo "SOURCE_SHA=$SHA"
echo 'PHP_EXTENSIONS=PASS'
echo 'QA_SOURCE=VERIFIED'
echo 'QA_ENV_PERMISSIONS=PASS'

# Execute QA-only validation as the service user in a clean process environment.
PHP_CHECK="$(cat <<'PHP'
$qa=$argv[1]; $expectedDb=$argv[2]; $expectedUser=$argv[3];
function block($x){fwrite(STDERR,"BLOCKED=".$x."\n");exit(2);}
$env=@parse_ini_file($qa."/.env",false,INI_SCANNER_RAW);
if (!is_array($env)) block("cannot parse QA environment");
$expected=[
  "APP_ENV"=>"staging", "APP_DEBUG"=>"false", "APP_URL"=>"http://127.0.0.1",
  "DB_CONNECTION"=>"mysql", "DB_HOST"=>"127.0.0.1", "DB_PORT"=>"3306",
  "DB_DATABASE"=>$expectedDb, "DB_USERNAME"=>$expectedUser,
  "SESSION_DRIVER"=>"file", "CACHE_STORE"=>"file", "QUEUE_CONNECTION"=>"sync",
  "MAIL_MAILER"=>"array", "FILESYSTEM_DISK"=>"local",
  "MEDIA_LIBRARY_DISK"=>"local", "PREVIEW_VISIBILITY_WRITES_ENABLED"=>"false"
];
foreach($expected as $k=>$v){ if(($env[$k]??null)!==$v) block("unsafe QA setting: ".$k); }
foreach(["DB_URL","DB_SOCKET","SESSION_CONNECTION","DB_CACHE_CONNECTION","REDIS_URL"] as $k) {
  if(isset($env[$k])&&$env[$k]!=="") block("unexpected connection override: ".$k);
}
if(!preg_match("~^base64:[A-Za-z0-9+/]{43}=$~D",$env["APP_KEY"]??"")) block("QA APP_KEY missing");
if(!preg_match("~^bambodia_pvqa_[a-f0-9]{12}$~D",$env["SESSION_COOKIE"]??"")) block("QA cookie namespace missing");
if(!preg_match("~^bambodia_pvqa_[a-f0-9]{12}$~D",$env["CACHE_PREFIX"]??"")) block("QA cache namespace missing");
if(empty($env["DB_PASSWORD"])) block("QA DB password absent");

$dsn="mysql:host=127.0.0.1;port=3306;dbname=".$expectedDb.";charset=utf8mb4";
try{
  $pdo=new PDO($dsn,$env["DB_USERNAME"],$env["DB_PASSWORD"],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT=>5,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
  $pdo->exec("SET SESSION TRANSACTION READ ONLY");
  $stmt=$pdo->query("SELECT DATABASE() AS db, CURRENT_USER() AS actor");
  $identity=$stmt->fetch();
  if(($identity["db"]??"")!==$expectedDb) block("unexpected MySQL schema identity");
  $actor=explode("@",$identity["actor"]??"",2)[0];
  if($actor!==$expectedUser) block("unexpected authenticated MySQL account");
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?");
  $stmt->execute([$expectedDb]); $tables=(int)$stmt->fetchColumn();
  if($tables!==0) block("QA schema is not empty: stop before migration");
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?");
  $stmt->execute([$expectedDb]);
  if((int)$stmt->fetchColumn()!==1) block("QA schema does not exist");
}catch(Throwable $e){block("QA MySQL read-only connection or identity check failed");}
echo "QA_MYSQL_IDENTITY=EXACT_SCHEMA_AND_ACCOUNT\n";
echo "QA_MYSQL_SCHEMA=EMPTY_VERIFIED\n";
echo "QA_MYSQL_WRITES=NONE\n";
echo "QA_ENV_ISOLATION=PASS\n";
PHP
)"
(cd "$QA" && runuser -u www -- env -i HOME="$QA" PATH=/usr/bin:/bin:/usr/local/bin \
    "$PHP" -r "$PHP_CHECK" "$QA" "$DB" "$DBUSER") ||
  fail 'QA service-account MySQL preflight failed'

echo '========== VERIFY PHP DEPENDENCIES AND LARAVEL BOOT =========='
BOOT_CHECK="$(cat <<'PHP'
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("preview_visibility.enabled")!==false) exit(2);
if(config("database.default")!=="mysql") exit(3);
if(config("database.connections.mysql.database")!==$argv[1]) exit(4);
if(config("database.connections.mysql.username")!==$argv[2]) exit(5);
if(config("session.driver")!=="file"||config("cache.default")!=="file") exit(6);
if(config("mail.default")!=="array"||config("queue.default")!=="sync") exit(7);
echo "LARAVEL_BOOT=PASS\n";
echo "LARAVEL_CONFIG=ISOLATED_DEFAULT_OFF\n";
PHP
)"
(cd "$QA" && runuser -u www -- env -i HOME="$QA" PATH=/usr/bin:/bin:/usr/local/bin \
  "$PHP" -r "$BOOT_CHECK" "$DB" "$DBUSER") ||
    fail 'QA Laravel bootstrap or effective configuration failed'

TMP="$(mktemp -d /tmp/bambodia-preview-bootstrap.XXXXXXXX)"
trap 'rc=$?; trap - EXIT; if [[ -d "$TMP" && ! -L "$TMP" && "$TMP" == /tmp/bambodia-preview-bootstrap.* ]]; then rm -rf -- "$TMP"; fi; exit "$rc"' EXIT
(cd "$QA" && runuser -u www -- env -i HOME="$QA" PATH=/usr/bin:/bin:/usr/local/bin \
  "$PHP" artisan list --raw) > "$TMP/artisan-commands" 2>"$TMP/artisan-errors" ||
    fail 'Laravel Artisan CLI bootstrap failed (no migrations attempted)'
grep -Fq 'migrate ' "$TMP/artisan-commands" ||
  fail 'Artisan migrations command unavailable'
(cd "$QA" && runuser -u www -- env -i HOME="$QA" PATH=/usr/bin:/bin:/usr/local/bin \
  "$PHP" artisan route:list --name=dashboard.preview-visibility) \
  > "$TMP/preview-routes" 2>"$TMP/preview-errors" ||
    fail 'QA preview route inspection failed'
grep -Fq 'dashboard.preview-visibility' "$TMP/preview-routes" ||
  fail 'Preview management routes absent'
echo 'ARTISAN_CLI=PASS'
echo 'PREVIEW_ROUTES=REGISTERED'

MIGRATIONS="$(find "$QA/database/migrations" -maxdepth 1 -type f -name '*.php' | wc -l)"
[[ "$MIGRATIONS" == 62 ]] || fail "Expected 62 vetted migration files, found $MIGRATIONS"
echo "MIGRATION_FILES=$MIGRATIONS"
echo 'MIGRATION_EXECUTION=NOT_STARTED'
echo 'BACKUP_RESTORE=NOT_STARTED'
echo 'QA_HTTP_VHOST=NOT_CHANGED'
echo 'PRODUCTION=NOT_MODIFIED'
echo 'PREVIEW_VISIBILITY_WRITES_ENABLED=FALSE'
echo 'RESULT=QA_LARAVEL_AND_EMPTY_DB_PREFLIGHT_PASS'
echo 'NEXT=REVIEW_BACKUP_FIRST_MIGRATION_AND_INDEPENDENT_RESTORE'
