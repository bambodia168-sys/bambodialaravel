#!/usr/bin/env bash
# Step 12C — guarded local-only QA HTTP ingress install. This is NOT public.
# --check: offline/read-only except removable /root temp files.
# --apply: requires explicit QA-only authorization, private backup and fail-closed.
# No shared aaPanel Nginx, production, DNS, TLS or cloudflared modifications.
set -Eeuo pipefail
set +x
umask 077

fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" -eq 1 ]] || fail usage_check_or_apply
MODE="$1"
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || fail invalid_mode

QA='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
PUB="$APP/public"
STAGED='/var/lib/bambodia-previewqa-static-20261112'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
HTTP_DIR='/var/lib/bambodia-previewqa-http-static-20261112'
DOCROOT="$HTTP_DIR/public"
CONF_DIR='/etc/bambodia-previewqa-http'
HTTP_CONF="$CONF_DIR/nginx.conf"
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
HTTP_SVC='bambodia-previewqa-http.service'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
FPM_SVC='bambodia-previewqa-fpm.service'
FPM_DIR='/run/bambodia-previewqa'
FPM_SOCK="$FPM_DIR/php-fpm.sock"
FPM_BIN='/www/server/php/85/sbin/php-fpm'
PHP='/www/server/php/85/bin/php'
BACKUP='/root/Bambodia-PreviewQA-HTTP-Ingress-20261112'
TMP=''
APPLY_STARTED=no
ACCEPTED=no

cleanup() {
  rc=$?
  if [[ "$APPLY_STARTED" == yes && "$ACCEPTED" != yes ]]; then
    systemctl stop "$HTTP_SVC" >/dev/null 2>&1 || true
    systemctl stop "$FPM_SVC" >/dev/null 2>&1 || true
    echo 'QA_HTTP_AND_FPM=STOPPED_ON_INCOMPLETE_APPLY_REVIEW_BACKUP' >&2
  fi
  if [[ -n "$TMP" && -d "$TMP" ]]; then rm -rf -- "$TMP"; fi
  exit "$rc"
}
trap cleanup EXIT

echo '========== STEP 12C — PRIVATE UNIX HTTP INGRESS PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for tool in nginx curl git stat runuser systemctl install grep find wc \
  sha256sum useradd getent mktemp cmp sed awk realpath dirname; do
  command -v "$tool" >/dev/null || fail "missing_command:$tool"
done
NGINX_BIN="$(command -v nginx)"
[[ "$NGINX_BIN" == /* && -x "$NGINX_BIN" && -x "$FPM_BIN" && -x "$PHP" ]] ||
  fail required_nginx_or_php_binary_missing

[[ -d "$QA/.git" && ! -L "$QA" ]] || fail pinned_qa_repo_missing
[[ "$(git -C "$QA" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail original_qa_git_revision_changed
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail original_qa_tracked_source_changed
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail private_restored_app_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail private_app_env_changed
[[ -s "$APP/storage/app/installed.lock" ]] || fail private_install_lock_missing

runuser -u "$WORKER" -- "$PHP" -r '
$p=$argv[1];require $p."/vendor/autoload.php";
$app=require $p."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db, CURRENT_USER() AS usr");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    config("preview_visibility.enabled")!==false ||
    $db->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($db->usr,"pvrs_20261112@") ||
    \App\Models\User::query()->count()!==6 ||
    \Illuminate\Support\Facades\DB::table("preview_visibility_actions")->count()!==0) exit(2);
echo "ISOLATED_QA_DATABASE_AND_WRITES_OFF=PASS\n";
' "$APP" || fail app_or_database_identity_changed

[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" &&
  "$(stat -c '%U:%G:%a' "$FPM_CONF")" == 'root:root:600' ]] ||
  fail expected_private_fpm_config_missing
[[ -d "$FPM_DIR" && ! -L "$FPM_DIR" &&
  "$(stat -c '%U:%G:%a' "$FPM_DIR")" == 'root:root:755' ]] ||
  fail fpm_runtime_parent_needs_independent_mode_review
[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$WORKER:600" ]] ||
  fail private_fpm_socket_changed
systemctl is-active --quiet "$FPM_SVC" || fail standalone_fpm_not_active
[[ "$(systemctl is-enabled "$FPM_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail dedicated_fpm_boot_state_changed
for line in "listen = $FPM_SOCK" "listen.owner = $WORKER" \
            "listen.group = $WORKER" 'listen.mode = 0600'; do
  grep -Fxq "$line" "$FPM_CONF" || fail "fpm_baseline_changed:$line"
done
if runuser -u www -- test -w "$FPM_SOCK"; then fail shared_www_fpm_access_present; fi

[[ -d "$STAGED" && ! -L "$STAGED" &&
  "$(stat -c '%U:%G:%a' "$STAGED")" == 'root:root:700' ]] ||
  fail private_staged_build_missing
[[ -s "$STAGED/build.sha256" && ! -L "$STAGED/build.sha256" ]] ||
  fail hashed_build_manifest_missing
(cd "$STAGED/build" && sha256sum --check --status "$STAGED/build.sha256") ||
  fail staged_build_checksum_mismatch

echo '========== VERIFY 43 STATIC-ONLY FILES =========='
[[ -d "$PUB" && ! -L "$PUB" &&
  "$(stat -c '%U:%G:%a' "$PUB")" == "$WORKER:$WORKER:700" ]] ||
  fail restored_public_dir_changed
[[ -z "$(find "$PUB" -type l -print -quit)" ]] || fail public_tree_symlink_detected
[[ -z "$(find "$PUB" ! -type f ! -type d -print -quit)" ]] ||
  fail public_tree_special_file_detected
[[ -z "$(find "$PUB" -mindepth 1 -type d -name '.*' -print -quit)" ]] ||
  fail hidden_public_directory_detected
[[ -s "$PUB/index.php" && ! -L "$PUB/index.php" ]] ||
  fail public_front_controller_missing
[[ -f "$PUB/.htaccess" && ! -L "$PUB/.htaccess" &&
  "$(git hash-object "$PUB/.htaccess")" == 'a165eb1fb39bf08883ea61b91e543c871b7b8b64' ]] ||
  fail root_apache_config_not_pinned
[[ "$(find "$PUB" -type f | wc -l | tr -d ' ')" == 45 ]] ||
  fail expected_45_public_files_changed
[[ "$(find "$PUB" -type f -iname '*.php' | wc -l | tr -d ' ')" == 1 ]] ||
  fail unexpected_public_php_file
while IFS= read -r -d '' file; do
  if [[ "$file" == "$PUB/index.php" || "$file" == "$PUB/.htaccess" ]]; then continue; fi
  basename_only="$(basename -- "$file")"
  [[ "$basename_only" != .* ]] || fail public_hidden_file_detected
  case "$basename_only" in
    *.js|*.css|*.map|*.png|*.jpg|*.jpeg|*.webp|*.gif|*.svg|*.woff|*.woff2|*.ttf|*.otf|*.ico|*.avif|*.webmanifest|robots.txt|sitemap.xml|manifest.json|fonts-manifest.json) ;;
    *) echo "UNRECOGNIZED_STATIC_FILE=$basename_only" >&2; fail public_static_allowlist_rejected ;;
  esac
done < <(find "$PUB" -type f -print0)
echo 'STATIC_ASSETS_TO_COPY=43'
echo 'PHP_AND_APACHE_FILES_TO_EXCLUDE=2'

echo '========== VERIFY NEW PRIVATE SERVICE NAME COLLISIONS =========='
for entry in "$HTTP_DIR" "$CONF_DIR" "$HTTP_CONF" "$HTTP_UNIT" "$BACKUP" \
             /run/bambodia-previewqa-http "$HTTP_SOCK"; do
  [[ ! -e "$entry" && ! -L "$entry" ]] || fail "qa_ingress_target_not_free:$entry"
done
if getent passwd "$INGRESS" >/dev/null || getent group "$INGRESS" >/dev/null; then
  fail dedicated_ingress_identity_already_exists
fi
[[ ! -e /www/wwwroot/preview-qa.bambodia.com &&
   ! -L /www/wwwroot/preview-qa.bambodia.com ]] || fail public_qa_root_present
[[ ! -e /www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf &&
   ! -L /www/server/panel/vhost/nginx/preview-qa.bambodia.com.conf ]] ||
  fail public_qa_nginx_vhost_present
nginx -t -q >/dev/null 2>&1 || fail existing_shared_nginx_syntax_not_valid

TMP="$(mktemp -d /root/bambodia-step12c-private-http.XXXXXXXX)"
chmod 700 "$TMP"
cat > "$TMP/nginx.conf" <<'NGINXCONF'
worker_processes 1;
pid /run/bambodia-previewqa-http/nginx.pid;
error_log stderr warn;
events { worker_connections 128; }
http {
    access_log off;
    server_tokens off;
    default_type application/octet-stream;
    client_body_temp_path /run/bambodia-previewqa-http/client_temp;
    fastcgi_temp_path /run/bambodia-previewqa-http/fastcgi_temp;
    types {
        text/html html htm;
        text/css css;
        text/plain txt;
        application/javascript js mjs;
        application/json json webmanifest;
        application/xml xml;
        image/svg+xml svg;
        image/png png;
        image/jpeg jpg jpeg;
        image/webp webp;
        image/avif avif;
        image/gif gif;
        image/x-icon ico;
        font/woff woff;
        font/woff2 woff2;
        font/ttf ttf;
        font/otf otf;
    }
    server {
        listen unix:/run/bambodia-previewqa-http/http.sock;
        server_name preview-qa.bambodia.com localhost 127.0.0.1;
        root /var/lib/bambodia-previewqa-http-static-20261112/public;
        autoindex off;
        client_max_body_size 8m;
        add_header X-Content-Type-Options nosniff always;
        location ^~ /storage/ { return 404; }
        location ^~ /installer/ { return 404; }
        location ~ /\. { return 404; }
        location ~* \.(php|phtml|phar)(/|$) { return 404; }
        location = / { try_files /__missing_qa_route__ @laravel; }
        location / { try_files $uri @laravel; }
        location @laravel {
            fastcgi_pass unix:/run/bambodia-previewqa/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/lib/bambodia-preview-restore-20261112/public/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_param REQUEST_METHOD $request_method;
            fastcgi_param CONTENT_TYPE $content_type;
            fastcgi_param CONTENT_LENGTH $content_length;
            fastcgi_param QUERY_STRING $query_string;
            fastcgi_param REQUEST_URI $request_uri;
            fastcgi_param SERVER_PROTOCOL $server_protocol;
            fastcgi_param SERVER_NAME $server_name;
            fastcgi_param SERVER_PORT $server_port;
            fastcgi_param REMOTE_ADDR $remote_addr;
            fastcgi_param HTTP_HOST $http_host;
            fastcgi_param REDIRECT_STATUS 200;
        }
    }
}
NGINXCONF
sed -e "s#^pid /run/bambodia-previewqa-http/nginx.pid;#pid $TMP/nginx.pid;#" \
    -e "s#listen unix:/run/bambodia-previewqa-http/http.sock;#listen unix:$TMP/http.sock;#" \
    -e "s#/run/bambodia-previewqa-http/client_temp#$TMP/client_temp#" \
    -e "s#/run/bambodia-previewqa-http/fastcgi_temp#$TMP/fastcgi_temp#" \
    "$TMP/nginx.conf" > "$TMP/nginx.offline.conf"
if ! "$NGINX_BIN" -t -c "$TMP/nginx.offline.conf" > "$TMP/test.log" 2>&1; then
  sed -n '1,25p' "$TMP/test.log" >&2
  fail private_nginx_offline_config_invalid
fi

sed -e "s/^listen.group = $WORKER$/listen.group = $INGRESS/" \
    -e 's/^listen.mode = 0600$/listen.mode = 0660/' \
    "$FPM_CONF" > "$TMP/php-fpm.conf"
[[ "$(grep -Fc "listen.group = $INGRESS" "$TMP/php-fpm.conf")" == 1 ]] ||
  fail scoped_fpm_group_rewrite_missing
[[ "$(grep -Fc 'listen.mode = 0660' "$TMP/php-fpm.conf")" == 1 ]] ||
  fail scoped_fpm_mode_rewrite_missing
"$FPM_BIN" -t -y "$TMP/php-fpm.conf" >/dev/null 2>&1 ||
  fail scoped_fpm_config_syntax_invalid

cat > "$TMP/http.service" <<UNIT
[Unit]
Description=Bambodia isolated localhost QA Nginx HTTP Unix socket (no TCP)
After=bambodia-previewqa-fpm.service
Requires=bambodia-previewqa-fpm.service

[Service]
Type=simple
User=bambodia_pvhttp
Group=bambodia_pvhttp
UMask=0077
RuntimeDirectory=bambodia-previewqa-http
RuntimeDirectoryMode=0700
ExecStart=$NGINX_BIN -c /etc/bambodia-previewqa-http/nginx.conf -g 'daemon off;'
Restart=no
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ProtectKernelTunables=true
ProtectControlGroups=true
ProtectKernelModules=true
RestrictAddressFamilies=AF_UNIX
CapabilityBoundingSet=
LimitNOFILE=2048
TasksMax=64

[Install]
WantedBy=multi-user.target
UNIT
echo 'CANDIDATE_NGINX_OFFLINE_SYNTAX=PASS'
echo 'CANDIDATE_FPM_SCOPED_GROUP_SYNTAX=PASS'
echo 'DEDICATED_HTTP_UNIX_SOCKET=PRIVATE_NO_TCP'
echo 'SHARED_WWW_FASTCGI_ACCESS=REMAINS_DENIED'
echo 'QA_WITHDRAWAL_WRITES=DISABLED'
if [[ "$MODE" == '--check' ]]; then
  echo 'PERSISTENT_WRITES=NONE'
  echo 'STEP12C_CHECK=PASS'
  exit 0
fi

[[ "$(printenv BAMBODIA_QA_PRIVATE_HTTP_INSTALL || true)" == 'APPROVE_QA_LOCAL_UNIX_ONLY' ]] ||
  fail explicit_qa_only_install_approval_missing
APPLY_STARTED=yes
echo '========== PROVISION PRIVATE QA-ONLY STATIC INGRESS =========='
install -d -o root -g root -m 700 "$BACKUP"
install -o root -g root -m 600 "$FPM_CONF" "$BACKUP/php-fpm.conf"
cmp -s "$FPM_CONF" "$BACKUP/php-fpm.conf" || fail fpm_original_backup_mismatch
useradd --system --user-group --no-create-home \
  --home-dir /nonexistent --shell /usr/sbin/nologin "$INGRESS" ||
  fail dedicated_ingress_identity_creation_failed
[[ "$(id -u "$INGRESS")" != 0 ]] || fail ingress_account_is_root
if id -nG www | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  fail shared_www_is_member_of_dedicated_ingress_group
fi
install -d -o "$INGRESS" -g "$INGRESS" -m 700 "$HTTP_DIR"
install -d -o "$INGRESS" -g "$INGRESS" -m 700 "$DOCROOT"
while IFS= read -r -d '' file; do
  if [[ "$file" == "$PUB/index.php" || "$file" == "$PUB/.htaccess" ]]; then continue; fi
  rel="$(realpath --relative-to="$PUB" "$file")"
  dest="$DOCROOT/$rel"
  install -d -o "$INGRESS" -g "$INGRESS" -m 700 "$(dirname "$dest")"
  install -o "$INGRESS" -g "$INGRESS" -m 400 "$file" "$dest"
  cmp -s "$file" "$dest" || fail copied_static_asset_differs
done < <(find "$PUB" -type f -print0)
[[ "$(find "$DOCROOT" -type f | wc -l | tr -d ' ')" == 43 ]] ||
  fail copied_static_asset_count_not_43
[[ ! -e "$DOCROOT/index.php" && ! -e "$DOCROOT/.htaccess" ]] ||
  fail private_php_or_apache_file_copied
echo 'PRIVATE_STATIC_COPY=43_FILES_BYTE_VERIFIED'

install -d -o root -g "$INGRESS" -m 750 "$CONF_DIR"
install -o root -g "$INGRESS" -m 640 "$TMP/nginx.conf" "$HTTP_CONF"
install -o root -g root -m 644 "$TMP/http.service" "$HTTP_UNIT"
cmp -s "$TMP/nginx.conf" "$HTTP_CONF" || fail installed_nginx_conf_mismatch
cmp -s "$TMP/http.service" "$HTTP_UNIT" || fail installed_systemd_unit_mismatch

echo '========== RECONFIGURE ONLY DEDICATED QA FASTCGI SOCKET =========='
systemctl stop "$FPM_SVC" || fail dedicated_fpm_stop_failed
install -o root -g root -m 600 "$TMP/php-fpm.conf" "$FPM_CONF"
cmp -s "$TMP/php-fpm.conf" "$FPM_CONF" || fail installed_fpm_config_mismatch
"$FPM_BIN" -t -y "$FPM_CONF" >/dev/null 2>&1 ||
  fail installed_scoped_fpm_config_invalid
systemctl daemon-reload || fail systemd_unit_reload_failed
systemctl start "$FPM_SVC" || fail dedicated_fpm_restart_failed
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$FPM_SVC" &&
     [[ -S "$FPM_SOCK" ]] &&
     [[ "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]]; then
    break
  fi
  sleep 1
done
[[ -S "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$INGRESS:660" ]] ||
  fail new_scoped_fpm_socket_permissions_invalid
if runuser -u www -- test -w "$FPM_SOCK"; then
  fail shared_www_can_access_new_fpm_socket
fi
runuser -u "$INGRESS" -- "$PHP" -r '
$fp=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if (!$fp) exit(2);
fclose($fp);
' "$FPM_SOCK" || fail dedicated_ingress_cannot_connect_fpm

echo '========== START DEDICATED UNIX-ONLY QA HTTP SERVICE =========='
systemctl start "$HTTP_SVC" || fail isolated_http_nginx_start_failed
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet "$HTTP_SVC" && [[ -S "$HTTP_SOCK" ]]; then break; fi
  sleep 1
done
[[ -S "$HTTP_SOCK" &&
  "$(stat -c '%U:%G' "$HTTP_SOCK")" == "$INGRESS:$INGRESS" ]] ||
  fail isolated_http_socket_not_ready
[[ "$(stat -c '%U:%G:%a' /run/bambodia-previewqa-http)" == "$INGRESS:$INGRESS:700" ]] ||
  fail isolated_http_runtime_dir_not_private
if runuser -u www -- "$PHP" -r '
$fp=@stream_socket_client("unix://".$argv[1],$e,$m,2);
if ($fp) { fclose($fp); exit(0); }
exit(2);
' "$HTTP_SOCK"; then
  fail shared_www_could_connect_private_http_socket
fi
for url_path in /up /login; do
  code="$(curl --silent --show-error --max-time 8 --output /dev/null \
      --write-out '%{http_code}' --unix-socket "$HTTP_SOCK" \
      -H 'Host: preview-qa.bambodia.com' "http://localhost$url_path")" ||
    fail "private_http_request_failed:$url_path"
  [[ "$code" == 200 ]] || fail "private_http_expected_200_path_$url_path:got_$code"
done
[[ "$(systemctl is-enabled "$HTTP_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail dedicated_http_unit_unexpectedly_enabled_at_boot
[[ "$(systemctl is-enabled "$FPM_SVC" 2>/dev/null || true)" == disabled ]] ||
  fail dedicated_fpm_unit_unexpectedly_enabled_at_boot

ACCEPTED=yes
echo '========== STEP 12C PRIVATE QA HTTP ACCEPTANCE =========='
echo 'PRIVATE_STATIC_FILES=43'
echo 'PRIVATE_HTTP_UNIX_SOCKET=ACTIVE_NO_TCP_LISTENER'
echo 'DEDICATED_HTTP_USER=bambodia_pvhttp'
echo 'QA_FPM_SOCKET_OWNER_GROUP=bambodia_pvrsqa:bambodia_pvhttp'
echo 'QA_FPM_SOCKET_MODE=0660'
echo 'SHARED_WWW_PRIVATE_FPM_ACCESS=DENIED'
echo 'SHARED_WWW_PRIVATE_HTTP_ACCESS=DENIED'
echo 'PRIVATE_HTTP_UP_AND_LOGIN=200'
echo 'PRIVATE_HTTP_SERVICE_ENABLED_AT_BOOT=NO'
echo 'QA_FPM_ENABLED_AT_BOOT=NO'
echo 'ROOT_PRIVATE_FPM_BACKUP=CREATED'
echo 'PUBLIC_DNS_HTTPS_CLOUDFLARED=NOT_CONFIGURED'
echo 'PRODUCTION_SHARED_NGINX_AND_FPM=UNTOUCHED'
echo 'PREVIEW_VISIBILITY_WRITES=DISABLED'
echo 'STEP12C=PASS'
