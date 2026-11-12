#!/usr/bin/env bash
# Step 7 — privately boot the separately restored QA Laravel application.
# Only localhost HTTP, synthetic records, and restored QA storage.
# Does not run migrations, create a vhost, change production, or enable writes.
set -Eeuo pipefail
set +x
umask 077

QA='/var/lib/bambodia-preview-qa-20261112'
SHA='584e1b29697fd87227dc42c987342fa405ea5c09'
RECOVER='/var/lib/bambodia-preview-restore-20261112'
SOURCE_DB='bambodia_previewqa_20261112'
RESTORE_DB='bambodia_previewrestore_20261112'
DB_USER='pvrs_20261112'
SYSUSER='bambodia_pvrsqa'
RESTORE_CNF='/root/bambodia-previewqa-20261112-restore.cnf'
ORIGINAL_CNF='/root/bambodia-previewqa-20261112-mysql.cnf'
CONFIG='/root/Bambodia-PreviewQA-Private-Config-20261112/qa-app.env'
EVIDENCE='/root/Bambodia-PreviewQA-Restore-Proof/restore-20261112T032915Z.2GXtfqP6'
ORIGIN='http://127.0.0.1:8097'
PHP='/www/server/php/85/bin/php'
MYSQL="$(command -v mysql || true)"

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

printf '========== STEP 7 — PREPARE ISOLATED RECOVERED APPLICATION ==========\n'
[[ "$(id -u)" -eq 0 ]] || fail 'root_operator_required'
for cmd in git tar cmp stat sha256sum curl runuser setsid useradd getent mktemp; do
  command -v "$cmd" >/dev/null || fail "missing_binary:$cmd"
done
[[ -x "$PHP" ]] || fail 'php_85_unavailable'
[[ -d "$QA/.git" && ! -L "$QA" && "$(realpath -e -- "$QA")" = "$QA" ]] || fail 'qa_source_directory'
[[ "$(git -C "$QA" rev-parse HEAD)" = "$SHA" ]] || fail 'qa_source_commit'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail 'qa_source_files_changed'
[[ -d "$QA/vendor" && -s "$QA/public/build/manifest.json" ]] || fail 'qa_runtime_missing'
[[ ! -e "$RECOVER" && ! -L "$RECOVER" ]] || fail 'recovered_app_already_exists_do_not_rerun'
[[ "$RECOVER" != "$QA" && "$RECOVER" != /www/wwwroot/* ]] || fail 'unsafe_recovered_app_destination'
[[ ! -e /www/wwwroot/bambodia-preview-restore-20261112 ]] || fail 'unexpected_web_destination'
if id "$SYSUSER" >/dev/null 2>&1 || getent group "$SYSUSER" >/dev/null 2>&1; then
  fail 'recovery_system_user_exists_do_not_rerun'
fi
for f in "$RESTORE_CNF" "$ORIGINAL_CNF" "$CONFIG"; do
  [[ -f "$f" && ! -L "$f" && "$(stat -c %U:%G:%a "$f")" = 'root:root:600' ]] || fail 'private_qa_credentials_unavailable'
done
[[ -d "$EVIDENCE" && ! -L "$EVIDENCE" && "$(realpath -e -- "$EVIDENCE")" = "$EVIDENCE" ]] || fail 'exact_restore_evidence_missing'
[[ "$(stat -c '%U:%G' "$EVIDENCE")" = 'root:root' ]] || fail 'evidence_ownership'
for f in qa.sql.gz qa-media.tar.gz qa-media.sha256 archive-checksums.sha256; do
  [[ -s "$EVIDENCE/$f" && ! -L "$EVIDENCE/$f" ]] || fail "missing_evidence:$f"
done
[[ -d "$EVIDENCE/restored-files/storage/app/private" && -d "$EVIDENCE/restored-files/storage/app/public" ]] || fail 'restored_media_directory_missing'
[[ -z "$(find "$EVIDENCE/restored-files" -type l -print -quit)" ]] || fail 'restored_media_symlink'
(cd "$EVIDENCE" && sha256sum --check --status archive-checksums.sha256) || fail 'backup_archive_hash_mismatch'
(cd "$EVIDENCE/restored-files" && sha256sum --check --status "$EVIDENCE/qa-media.sha256") || fail 'previously_restored_media_hash_mismatch'
[[ -x "$MYSQL" ]] || MYSQL='/www/server/mysql/bin/mysql'
[[ -x "$MYSQL" ]] || fail 'mysql_client_missing'

[[ "$("$MYSQL" --defaults-extra-file="$RESTORE_CNF" --batch --skip-column-names "$RESTORE_DB" -e 'SELECT DATABASE()')" = "$RESTORE_DB" ]] || fail 'restore_database_connection'
[[ "$("$MYSQL" --defaults-extra-file="$ORIGINAL_CNF" --batch --skip-column-names "$SOURCE_DB" -e 'SELECT DATABASE()')" = "$SOURCE_DB" ]] || fail 'original_qa_database_connection'
for pair in 'users:2' 'marketplace_listings:1' 'marketplace_jobs:1' 'preview_visibility_actions:0' 'migrations:62'; do
  table="${pair%%:*}"; expected="${pair#*:}"
  count="$("$MYSQL" --defaults-extra-file="$RESTORE_CNF" --batch --skip-column-names "$RESTORE_DB" -e "SELECT COUNT(*) FROM \`$table\`")" || fail "restore_inventory_read:$table"
  [[ "$count" = "$expected" ]] || fail "restore_inventory_mismatch:$table"
done
cmp -s "$CONFIG" "$QA/.env" || fail 'original_qa_env_changed_since_backup'
# Refuse running any service when the localhost port is already in use.
"$PHP" -r '$s=@stream_socket_client("tcp://127.0.0.1:8097",$e,$m,1); if ($s) {fclose($s);exit(2);} exit(0);' || fail 'loopback_port_8097_in_use'
printf 'RESTORE_EVIDENCE=VERIFIED\nRESTORED_DB_INVENTORY=PASS\n'

printf '========== CONSTRUCT ISOLATED RECOVERY FILESYSTEM ==========\n'
install -d -m 700 "$RECOVER"
# Copy reviewed application/runtime, but NEVER original QA storage, keys or git repository.
# The recovered private files come from the validated restoration archive only.
tar -C "$QA" -cf - \
  --exclude='./.git' --exclude='./.env' --exclude='./storage' \
  --exclude='./bootstrap/cache' --exclude='./public/hot' . \
  | tar -C "$RECOVER" -xf -
[[ ! -e "$RECOVER/.git" && ! -e "$RECOVER/.env" && ! -e "$RECOVER/storage" ]] || fail 'recovery_inherited_original_private_state'
[[ -f "$RECOVER/artisan" && -s "$RECOVER/vendor/autoload.php" && -s "$RECOVER/public/build/manifest.json" ]] || fail 'recovery_runtime_incomplete'
[[ -z "$(find "$RECOVER" -type l -print -quit)" ]] || fail 'unexpected_recovery_symlink'
for dir in \
  "$RECOVER/storage/app/private" "$RECOVER/storage/app/public" \
  "$RECOVER/storage/framework/cache/data" "$RECOVER/storage/framework/sessions" \
  "$RECOVER/storage/framework/views" "$RECOVER/storage/logs" \
  "$RECOVER/bootstrap/cache"; do
  install -d -m 700 "$dir"
done
cp -a "$EVIDENCE/restored-files/storage/app/private/." "$RECOVER/storage/app/private/"
cp -a "$EVIDENCE/restored-files/storage/app/public/." "$RECOVER/storage/app/public/"
(cd "$RECOVER" && sha256sum --check --status "$EVIDENCE/qa-media.sha256") || fail 'separate_app_media_hash_mismatch'

printf '========== WRITE SEPARATE RESTORE APP ENV (KEY RETAINED) ==========\n'
# Secrets are read from private files and written straight to the new .env.
# Nothing displays a credential, key, or other private contents.
"$PHP" -r '
$original=file_get_contents($argv[1]);
$mysql=file_get_contents($argv[2]);
if (!is_string($original) || !is_string($mysql) ||
    preg_match("/^password=\"([0-9a-f]{64})\"$/m",$mysql,$m)!==1 ||
    preg_match("/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m",$original)!==1 ||
    preg_match("/^DB_URL=/m",$original)===1) exit(2);
$edits=[
 "APP_ENV"=>"testing", "APP_DEBUG"=>"false",
 "APP_URL"=>"http://127.0.0.1:8097",
 "DB_HOST"=>"127.0.0.1", "DB_DATABASE"=>"bambodia_previewrestore_20261112",
 "DB_USERNAME"=>"pvrs_20261112", "DB_PASSWORD"=>$m[1],
 "SESSION_DRIVER"=>"file", "CACHE_STORE"=>"file", "QUEUE_CONNECTION"=>"sync",
 "FILESYSTEM_DISK"=>"local", "MEDIA_LIBRARY_DISK"=>"local", "MAIL_MAILER"=>"log",
 "PREVIEW_VISIBILITY_WRITES_ENABLED"=>"false",
];
foreach ($edits as $k=>$v) {
  $original=preg_replace("/^".preg_quote($k,"/")."=.*$/m",$k."=".$v,$original,1,$count);
  if (!is_string($original) || $count!==1) exit(2);
}
if (file_put_contents($argv[3],$original,LOCK_EX)!==strlen($original)) exit(2);
chmod($argv[3],0600);
' "$CONFIG" "$RESTORE_CNF" "$RECOVER/.env" || fail 'isolated_recovery_env_creation'
[[ "$(stat -c %a "$RECOVER/.env")" = '600' ]] || fail 'restore_env_permissions'
# Confirm the encryption key survived the recovery; never print it.
"$PHP" -r '
function qa_encryption_key($p) {preg_match("/^APP_KEY=(.+)$/m",file_get_contents($p),$m);return $m[1]??"";}
if (qa_encryption_key($argv[1])==="" || !hash_equals(qa_encryption_key($argv[1]),qa_encryption_key($argv[2]))) exit(2);
' "$CONFIG" "$RECOVER/.env" || fail 'restored_app_key_differs'

printf '========== BOOT RECOVERED LARAVEL AS DEDICATED UNPRIVILEGED USER ==========\n'
useradd --system --user-group --no-create-home --home-dir "$RECOVER" --shell /usr/sbin/nologin "$SYSUSER" || fail 'isolated_linux_user_creation_failed'
chown -R "$SYSUSER:$SYSUSER" "$RECOVER"
chmod 700 "$RECOVER"

# Recovered CLI bootstrap checks actual SQL session identity and only fake records.
runuser -u "$SYSUSER" -- "$PHP" -r '
$r=$argv[1];
require $r."/vendor/autoload.php";
$a=require $r."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("app.url")!=="http://127.0.0.1:8097" ||
    config("preview_visibility.enabled")!==false ||
    config("database.default")!=="mysql" ||
    config("database.connections.mysql.database")!=="bambodia_previewrestore_20261112" ||
    config("database.connections.mysql.username")!=="pvrs_20261112" ||
    config("session.driver")!=="file" || config("cache.default")!=="file") exit(2);
$row=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db,CURRENT_USER() AS usr");
if ($row->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($row->usr,"pvrs_20261112@")) exit(2);
foreach (["users"=>2,"marketplace_listings"=>1,"marketplace_jobs"=>1,
          "preview_visibility_actions"=>0,"migrations"=>62] as $name=>$count) {
  if (\Illuminate\Support\Facades\DB::table($name)->count()!==$count) exit(2);
}
foreach (["dashboard.preview-visibility.index","dashboard.preview-visibility.withdraw",
          "dashboard.preview-visibility.restore"] as $name) {
  if (!\Illuminate\Support\Facades\Route::getRoutes()->getByName($name)) exit(2);
}
echo "RECOVERED_LARAVEL_CLI_BOOT=PASS\n";
echo "RECOVERED_LARAVEL_DB_IDENTITY=PASS\n";
' "$RECOVER" || fail 'recovered_laravel_console_boot'

# Brief loopback-only PHP HTTP smoke test. All log/evidence files remain root-private.
LOG="$(mktemp /root/bambodia-previewqa-http.XXXXXXXX.log)"
chmod 600 "$LOG"
SERVER_PID=''
cleanup(){
  if [[ -n "$SERVER_PID" ]]; then
    kill -TERM -- "-$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT
setsid runuser -u "$SYSUSER" -- "$PHP" -S 127.0.0.1:8097 \
  -t "$RECOVER/public" "$RECOVER/public/index.php" > "$LOG" 2>&1 &
SERVER_PID=$!

http_check(){
  local path="$1" expected="$2" status='' count
  for count in 1 2 3 4 5 6 7 8; do
    status="$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' \
      --max-time 5 "${ORIGIN}${path}" 2>/dev/null || true)"
    if [[ "$status" = "$expected" ]]; then return 0; fi
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then break; fi
    sleep 1
  done
  printf 'HTTP_SMOKE_PATH=%s EXPECTED=%s ACTUAL=%s\n' "$path" "$expected" "${status:-unreachable}" >&2
  return 2
}
http_check '/up' '200' || fail 'recovered_http_health'
http_check '/' '200' || fail 'recovered_http_home'
http_check '/login' '200' || fail 'recovered_http_login'
http_check '/dashboard/preview-content' '302' || fail 'recovered_super_admin_login_boundary'
printf 'RECOVERED_LARAVEL_HTTP=PASS\n'

cleanup
SERVER_PID=''
trap - EXIT
"$PHP" -r '$s=@stream_socket_client("tcp://127.0.0.1:8097",$e,$m,1); if ($s) {fclose($s);exit(2);} exit(0);' || fail 'temporary_http_server_still_listening'
printf '========== STEP 7 — VERIFIED RECOVERY ==========\n'
printf 'RECOVERED_APP_PATH=%s\n' "$RECOVER"
printf 'RECOVERED_DB=%s\n' "$RESTORE_DB"
printf 'RECOVERED_MEDIA_SHA256=PASS\n'
printf 'RESTORED_APP_ENCRYPTION_KEY=PRESERVED_NOT_DISPLAYED\n'
printf 'HTTP_ACCESS=LOCALHOST_ONLY_AND_STOPPED\n'
printf 'WITHDRAWAL_WRITES=DISABLED\n'
printf 'PRODUCTION_APPLICATIONS=NOT_TOUCHED_BY_SCRIPT\n'
printf 'HTTPS_BROWSER_ACCEPTANCE=NOT_TESTED\n'
printf 'PHYSICAL_DEVICE_ACCEPTANCE=NOT_TESTED\n'
printf 'STEP7=PASS\n'