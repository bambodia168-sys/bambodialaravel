#!/usr/bin/env bash
# Complete Step 7 after proven CLI boot; installer-lock repair for the restored QA app only.
# No schema writes, production changes, vhost changes, or persistent HTTP server.
set -Eeuo pipefail
set +x
umask 077

QA='/var/lib/bambodia-preview-qa-20261112'
SHA='584e1b29697fd87227dc42c987342fa405ea5c09'
RECOVER='/var/lib/bambodia-preview-restore-20261112'
SYSUSER='bambodia_pvrsqa'
ORIGIN='http://127.0.0.1:18097'
PHP='/www/server/php/85/bin/php'
BACKUP_ENV='/root/Bambodia-PreviewQA-Private-Config-20261112/qa-app.env'
EVIDENCE='/root/Bambodia-PreviewQA-Restore-Proof/restore-20261112T032915Z.2GXtfqP6'
LOCK="$RECOVER/storage/app/installed.lock"

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

echo '========== STEP 7 — RECOVERED APPLICATION PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for binary in git runuser setsid curl sha256sum stat find mktemp; do
  command -v "$binary" >/dev/null || fail "binary_missing:$binary"
done
[[ -x "$PHP" ]] || fail php85_missing
[[ -d "$QA/.git" && ! -L "$QA" && "$(realpath -e "$QA")" = "$QA" ]] || fail original_qa_path
[[ "$(git -C "$QA" rev-parse HEAD)" = "$SHA" ]] || fail qa_commit_changed
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail qa_source_changed
[[ -d "$RECOVER" && ! -L "$RECOVER" && "$(realpath -e "$RECOVER")" = "$RECOVER" ]] || fail recovered_app_path
[[ "$RECOVER" != /www/wwwroot/* ]] || fail public_website_path_forbidden
id "$SYSUSER" >/dev/null || fail dedicated_linux_user_missing
[[ "$(stat -c '%U:%G:%a' "$RECOVER")" = "$SYSUSER:$SYSUSER:700" ]] || fail recovered_app_permissions
[[ -s "$RECOVER/artisan" && -s "$RECOVER/vendor/autoload.php" && -s "$RECOVER/public/build/manifest.json" ]] || fail recovered_runtime_missing
[[ -f "$RECOVER/.env" && ! -L "$RECOVER/.env" &&
   "$(stat -c '%U:%G:%a' "$RECOVER/.env")" = "$SYSUSER:$SYSUSER:600" ]] || fail recovered_env_permissions
[[ -f "$BACKUP_ENV" && ! -L "$BACKUP_ENV" &&
   "$(stat -c '%U:%G:%a' "$BACKUP_ENV")" = 'root:root:600' ]] || fail original_key_backup_missing
[[ -d "$EVIDENCE/restored-files" && -s "$EVIDENCE/qa-media.sha256" ]] || fail restore_evidence_missing
[[ -d "$RECOVER/storage/app" && ! -L "$RECOVER/storage" && ! -L "$RECOVER/storage/app" ]] || fail recovered_storage
[[ -z "$(find "$RECOVER/storage" -type l -print -quit)" ]] || fail recovered_storage_symlink
[[ ! -e "$LOCK" && ! -L "$LOCK" ]] || fail install_lock_already_present_do_not_rerun
[[ ! -e "$RECOVER/storage/app/installer-access.txt" ]] || fail unexpected_installer_secret
[[ ! -e "$QA/storage/app/installed.lock" ]] || fail original_qa_installation_lock_changed

(cd "$RECOVER" && sha256sum --check --status "$EVIDENCE/qa-media.sha256") || fail recovered_media_integrity

"$PHP" -r '
function value($path, $key) {
    $body=file_get_contents($path); if (!is_string($body)) exit(2);
    preg_match("/^".preg_quote($key,"/")."=(.*)$/m",$body,$m);
    return $m[1]??"";
}
if (value($argv[1],"APP_KEY")==="" ||
    !hash_equals(value($argv[1],"APP_KEY"),value($argv[2],"APP_KEY"))) exit(2);
' "$BACKUP_ENV" "$RECOVER/.env" || fail original_encryption_key_mismatch

"$PHP" -r '$s=@stream_socket_client("tcp://127.0.0.1:18097",$e,$m,1); if($s){fclose($s);exit(2);} exit(0);' ||
  fail test_port_18097_in_use

echo '========== VERIFY RESTORED QA LARAVEL AND DATABASE IDENTITY =========='
runuser -u "$SYSUSER" -- "$PHP" -r '
$r=$argv[1];
require $r."/vendor/autoload.php";
$a=require $r."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    config("preview_visibility.enabled")!==false ||
    config("database.default")!=="mysql" ||
    config("database.connections.mysql.database")!=="bambodia_previewrestore_20261112" ||
    config("database.connections.mysql.username")!=="pvrs_20261112" ||
    config("session.driver")!=="file" || config("cache.default")!=="file" ||
    config("queue.default")!=="sync" || config("filesystems.default")!=="local" ||
    config("filesystems.media_library_disk")!=="local" || config("mail.default")!=="log") exit(2);
$identity=\Illuminate\Support\Facades\DB::selectOne(
  "SELECT DATABASE() AS db, CURRENT_USER() AS usr");
if ($identity->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($identity->usr,"pvrs_20261112@")) exit(2);
foreach (["users"=>2,"marketplace_listings"=>1,"marketplace_jobs"=>1,
          "preview_visibility_actions"=>0,"migrations"=>62] as $table=>$count) {
  if (\Illuminate\Support\Facades\DB::table($table)->count()!==$count) exit(2);
}
foreach (["marketplace_listings","marketplace_jobs"] as $table) {
  if (!\Illuminate\Support\Facades\DB::table($table)
      ->where("title","like","%[QA PREVIEW]%")->exists()) exit(2);
}
foreach (["dashboard.preview-visibility.index","dashboard.preview-visibility.withdraw",
          "dashboard.preview-visibility.restore"] as $name) {
  if (!\Illuminate\Support\Facades\Route::getRoutes()->getByName($name)) exit(2);
}
echo "RECOVERED_APP_DB_ROUTES_AND_SWITCH=PASS\n";
' "$RECOVER" || fail restored_app_identity

echo '========== CREATE INSTALLER LOCK IN RESTORED QA ONLY =========='
# public/index.php redirects all requests to /installer if this lock is absent.
# A manual migration rehearsal never runs the browser installer.
runuser -u "$SYSUSER" -- "$PHP" -r '
$f=@fopen($argv[1],"x"); if ($f===false) exit(2);
$bytes=fwrite($f,"Bambodia 0.0.1 - ".gmdate("c")." QA restoration rehearsal\n");
fclose($f);
if ($bytes===false || !chmod($argv[1],0600)) exit(2);
' "$LOCK" || fail recovered_qa_lock_creation
[[ -f "$LOCK" && ! -L "$LOCK" &&
   "$(stat -c '%U:%G:%a' "$LOCK")" = "$SYSUSER:$SYSUSER:600" ]] || fail qa_lock_integrity
[[ ! -e "$QA/storage/app/installed.lock" ]] || fail source_lock_changed
echo 'RECOVERED_QA_INSTALLATION_LOCK=PASS'

echo '========== LOCALHOST-ONLY HTTP SMOKE TEST =========='
LOG="$(mktemp /root/bambodia-previewqa-http-recovery.XXXXXXXX.log)"
chmod 600 "$LOG"
SERVER_PID=''
cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill -TERM -- "-$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT
setsid runuser -u "$SYSUSER" -- "$PHP" -S 127.0.0.1:18097 \
  -t "$RECOVER/public" "$RECOVER/public/index.php" > "$LOG" 2>&1 &
SERVER_PID=$!

http_check() {
  local path="$1" expected="$2" status='' i
  for i in 1 2 3 4 5 6 7 8; do
    status="$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code}' \
      --max-time 6 "$ORIGIN$path" 2>/dev/null || true)"
    if [[ "$status" = "$expected" ]]; then
      printf 'HTTP_PASS=%s STATUS=%s\n' "$path" "$status"
      return 0
    fi
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then break; fi
    sleep 1
  done
  printf 'HTTP_SMOKE_PATH=%s EXPECTED=%s ACTUAL=%s\n' "$path" "$expected" "$status" >&2
  printf 'PRIVATE_HTTP_LOG=%s\n' "$LOG" >&2
  return 2
}
http_check '/up' 200 || fail qa_recovered_http_health
http_check '/' 200 || fail qa_recovered_http_home
http_check '/login' 200 || fail qa_recovered_http_login
http_check '/dashboard/preview-content' 302 || fail qa_recovered_guest_auth
echo 'RECOVERED_LARAVEL_HTTP=PASS'

cleanup
SERVER_PID=''
trap - EXIT
"$PHP" -r '$s=@stream_socket_client("tcp://127.0.0.1:18097",$e,$m,1); if($s){fclose($s);exit(2);} exit(0);' ||
  fail test_http_server_not_stopped

echo '========== STEP 7 RECOVERY COMPLETED =========='
echo 'RESTORED_QA_APP=BOOTED_AND_LOCKED'
echo 'RESTORED_MEDIA_SHA256=PASS'
echo 'RESTORED_DB_IDENTITY=PASS'
echo 'ORIGINAL_QA_INSTALLATION=UNCHANGED'
echo 'WITHDRAWAL_WRITES=DISABLED'
echo 'HTTP_SERVER=LOCALHOST_ONLY_AND_STOPPED'
echo 'HTTPS_BROWSER_ACCEPTANCE=NOT_TESTED'
echo 'PHYSICAL_DEVICE_ACCEPTANCE=NOT_TESTED'
echo 'STEP7_HTTP_RECOVERY=PASS'
