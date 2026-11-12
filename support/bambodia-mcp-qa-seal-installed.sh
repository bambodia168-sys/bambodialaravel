#!/usr/bin/env bash
# Complete the verified terminal-based Bambodia MCP QA installation:
# write the missing installed.lock marker ONLY after independent QA app checks.
# Do not invoke the installer, Composer, migrations, Nginx or production.
set -Eeuo pipefail
set +x
umask 077

[[ "$#" -eq 1 && "$1" == "--seal" ]] || {
  echo 'Usage: bash this-script --seal' >&2; exit 64;
}
APP=/var/lib/bambodia-oauth-https-qa
PHP=/www/server/php/85/bin/php
MARKER="$APP/storage/app/installed.lock"
VHOST=/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf
fail(){ echo "QA_INSTALL_SEAL_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in python3 sha256sum stat runuser; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done
[[ -x "$PHP" ]] || fail QA_PHP85_UNAVAILABLE
id www >/dev/null || fail WWW_ACCOUNT_UNAVAILABLE
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%u:%g' "$APP")" == "0:$(id -g www)" ]] ||
  fail PRIVATE_QA_APP_DIRECTORY_MISMATCH
[[ -d "$APP/storage/app" && ! -L "$APP/storage/app" ]] ||
  fail LARAVEL_STORAGE_APP_DIRECTORY_MISSING_OR_UNSAFE
[[ ! -e "$MARKER" && ! -L "$MARKER" ]] ||
  fail MARKER_ALREADY_EXISTS_REVIEW_BEFORE_RETRY
for path in \
  "$APP/.env" \
  "$APP/vendor/autoload.php" \
  "$APP/public/index.php" \
  "$APP/storage/oauth-private.key" \
  "$APP/storage/oauth-public.key" \
  "$APP/public/build/manifest.json" \
  "$APP/resources/views/developer/mcp-connection.blade.php"; do
  [[ -s "$path" && ! -L "$path" ]] ||
    fail "REQUIRED_QA_FILE_MISSING_$(basename "$path")"
done
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail QA_VHOST_UNAVAILABLE
# Ensure the QA HTTPS vhost is still configured for ONLY this application's
# public directory before making the Laravel front controller reachable.
python3 - "$VHOST" <<'PY'
import pathlib,re,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
active="\n".join(line for line in s.splitlines()
                 if not line.lstrip().startswith("#"))
if len(re.findall(r'\broot\s+/var/lib/bambodia-oauth-https-qa/public\s*;',active))!=1:
    raise SystemExit("QA_INSTALL_SEAL_BLOCKED=NOT_EXPECTED_QA_PUBLIC_ROOT")
if not re.search(r'\bserver_name\s+[^;]*\boauth-qa\.bambodia\.com\b',active):
    raise SystemExit("QA_INSTALL_SEAL_BLOCKED=NOT_EXPECTED_QA_HOST")
if not re.search(r'\bfastcgi_pass\s+unix:/tmp/php-cgi-85\.sock\s*;',active):
    raise SystemExit("QA_INSTALL_SEAL_BLOCKED=NOT_EXPECTED_PHP85_FPM")
print("QA_HTTPS_VHOST=EXPECTED_ISOLATED_LARAVEL_ROOT")
PY

echo '========== VERIFY REAL INSTALLED QA APPLICATION =========='
(
  cd "$APP"
  env -i HOME=/root PATH=/usr/bin:/bin "$PHP" -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $db = \Illuminate\Support\Facades\DB::connection();
    if (config("app.env") !== "staging"
        || rtrim((string)config("app.url"),"/") !== "https://oauth-qa.bambodia.com"
        || config("app.debug") !== false
        || config("database.default") !== "mysql"
        || $db->getDatabaseName() !== "bambodia_oauthqa_https"
        || config("database.connections.mysql.username") !== "bambodiaqa_https"
        || config("database.connections.mysql.host") !== "127.0.0.1") {
       fwrite(STDERR,"QA_INSTALL_SEAL_BLOCKED=WRONG_APPLICATION_OR_DATABASE\n");
       exit(21);
    }
    foreach (["users", "migrations", "oauth_clients", "oauth_access_tokens", "api_tokens"] as $table) {
      if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
        fwrite(STDERR,"QA_INSTALL_SEAL_BLOCKED=EXPECTED_TABLE_MISSING\n");
        exit(22);
      }
    }
    $users = \App\Models\User::query()->get(["email","role","account_status"]);
    if ($users->count() !== 1
        || (string)$users[0]->email !== "tonsamith168@gmail.com"
        || $users[0]->role !== "super_admin"
        || $users[0]->account_status !== "active") {
       fwrite(STDERR,"QA_INSTALL_SEAL_BLOCKED=QA_ADMIN_IDENTITY_MISMATCH\n");
       exit(23);
    }
    echo "QA_ENV_DATABASE_AND_ADMIN=VERIFIED\n";
    echo "QA_OLD_PASSWORD_AND_KEYS=RETAINED\n";
  '
) || fail EXISTING_INSTALLATION_VERIFICATION_FAILED

echo '========== SECURE THE COMPLETED QA INSTALLATION =========='
python3 - "$MARKER" "$(id -g www)" <<'PY'
import datetime,os,pathlib,stat,sys
p=pathlib.Path(sys.argv[1])
parent=p.parent
if parent.is_symlink() or not parent.is_dir():
    raise SystemExit("QA_INSTALL_SEAL_BLOCKED=STORAGE_PARENT_NOT_DIRECTORY")
if p.exists() or p.is_symlink():
    raise SystemExit("QA_INSTALL_SEAL_BLOCKED=INSTALL_MARKER_ALREADY_PRESENT")
fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o640)
with os.fdopen(fd,"w") as f:
    now=datetime.datetime.now(datetime.timezone.utc).isoformat()
    f.write("Bambodia 0.0.1 - "+now+"\n")
os.chown(p,0,int(sys.argv[2]))
os.chmod(p,0o640)
print("QA_INSTALL_LOCK=CREATED")
PY

[[ -f "$MARKER" && ! -L "$MARKER" ]] || fail INSTALL_LOCK_CREATION_NOT_VERIFIED
echo 'QA_INSTALLER_REDIRECT_LOCK=INSTALLED'
echo 'QA_INSTALLATION_STATE=SEALED'
echo 'QA_LOGIN_URL=https://oauth-qa.bambodia.com/login'
echo 'QA_MCP_CONNECTION_URL=https://oauth-qa.bambodia.com/developer/mcp-connection'
echo 'QA_DATABASE=UNCHANGED'
echo 'APP_KEY_AND_PASSPORT_KEYS=UNCHANGED'
echo 'INSTALLER_AND_MIGRATIONS=NOT_RUN'
echo 'NGINX=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
