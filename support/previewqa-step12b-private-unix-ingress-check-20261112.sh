#!/usr/bin/env bash
# Step 12B — offline, read-only assessment of private QA HTTP Unix ingress.
# No creation of users/groups/services, no copying files and no public DNS.
# nginx -t only checks an ephemeral candidate configuration; it never starts it.
set -Eeuo pipefail
set +x
umask 077
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

APP='/var/lib/bambodia-preview-restore-20261112'
QA='/var/lib/bambodia-preview-qa-20261112'
ASSETS='/var/lib/bambodia-previewqa-static-20261112'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
INGRESS_ROOT='/var/lib/bambodia-previewqa-http-static-20261112'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
PHP='/www/server/php/85/bin/php'
TMP=''
cleanup() {
  [[ -z "$TMP" ]] || rm -rf -- "$TMP"
}
trap cleanup EXIT

echo '========== STEP 12B — OFFLINE PRIVATE UNIX HTTP INGRESS REVIEW =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for command_name in git stat find nginx sha256sum runuser systemctl \
  grep wc du basename getent mktemp; do
  command -v "$command_name" >/dev/null || fail "missing_binary:$command_name"
done
[[ -d "$QA/.git" && ! -L "$QA" ]] || fail pinned_qa_source_missing
[[ "$(git -C "$QA" rev-parse HEAD)" == '584e1b29697fd87227dc42c987342fa405ea5c09' ]] ||
  fail pinned_qa_commit_changed
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail original_qa_source_modified
[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail recovered_app_directory_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail recovered_private_env_changed
[[ -x "$PHP" ]] || fail php85_cli_unavailable

runuser -u "$WORKER" -- "$PHP" -r '
$r=$argv[1];require $r."/vendor/autoload.php";
$a=require $r."/bootstrap/app.php";
$a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=\Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS db, CURRENT_USER() AS usr");
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("preview_visibility.enabled")!==false ||
    config("app.url")!=="http://127.0.0.1:18097" ||
    $db->db!=="bambodia_previewrestore_20261112" ||
    !str_starts_with($db->usr,"pvrs_20261112@") ||
    \App\Models\User::query()->count()!==6) exit(2);
echo "RESTORED_QA_IDENTITY=PASS_DEFAULT_OFF\n";
' "$APP" || fail recovered_qa_configuration_changed

[[ -S "$FPM_SOCK" && ! -L "$FPM_SOCK" &&
  "$(stat -c '%U:%G:%a' "$FPM_SOCK")" == "$WORKER:$WORKER:600" ]] ||
  fail private_fastcgi_socket_changed
[[ -f "$FPM_CONF" && ! -L "$FPM_CONF" ]] || fail fpm_config_missing
grep -Fxq 'listen.mode = 0600' "$FPM_CONF" || fail private_fastcgi_mode_changed
systemctl is-active --quiet bambodia-previewqa-fpm.service ||
  fail dedicated_qa_fpm_not_running
if runuser -u www -- test -w "$FPM_SOCK"; then
  fail shared_www_can_write_to_private_fastcgi
fi
echo 'PRIVATE_FASTCGI_SHARED_WWW_ACCESS=DENIED'

[[ -d "$ASSETS" && ! -L "$ASSETS" &&
  "$(stat -c '%U:%G:%a' "$ASSETS")" == 'root:root:700' ]] ||
  fail staged_static_assets_missing_or_changed
[[ -s "$ASSETS/build.sha256" ]] || fail static_asset_manifest_missing
(cd "$ASSETS/build" && sha256sum --check --status "$ASSETS/build.sha256") ||
  fail verified_static_asset_checksums
echo 'PRIVATE_BUILT_ASSET_SHA256=PASS'

echo '========== PUBLIC TREE INVENTORY FOR SEPARATE STATIC COPY =========='
PUB="$APP/public"
[[ -d "$PUB" && ! -L "$PUB" &&
  "$(stat -c '%U:%G:%a' "$PUB")" == "$WORKER:$WORKER:700" ]] ||
  fail recovered_public_directory_changed
[[ -s "$PUB/index.php" && ! -L "$PUB/index.php" ]] || fail front_controller_missing
# The Apache-only root rewrite file is NOT a static asset. Permit ONLY the
# known, byte-for-byte baseline and exclude it from any future static copy.
# Other hidden files (including nested .htaccess) remain prohibited.
[[ -f "$PUB/.htaccess" && ! -L "$PUB/.htaccess" ]] ||
  fail expected_root_apache_rewrite_file_missing_or_symlinked
[[ "$(git hash-object "$PUB/.htaccess")" == 'a165eb1fb39bf08883ea61b91e543c871b7b8b64' ]] ||
  fail unexpected_root_apache_rewrite_file_contents
[[ -z "$(find "$PUB" -type l -print -quit)" ]] || fail symlink_in_public_tree
[[ -z "$(find "$PUB" -mindepth 1 -type d -name '.*' -print -quit)" ]] ||
  fail hidden_directory_in_public_tree
[[ -z "$(find "$PUB" ! -type d ! -type f -print -quit)" ]] ||
  fail special_file_in_public_tree
FILE_COUNT="$(find "$PUB" -type f | wc -l | tr -d ' ')"
[[ "$FILE_COUNT" -gt 1 && "$FILE_COUNT" -lt 2000 ]] ||
  fail unexpected_public_file_count
KBYTES="$(du -sk "$PUB" | awk '{print $1}')"
[[ "$KBYTES" -lt 204800 ]] || fail public_static_tree_exceeds_200mb
PHP_COUNT="$(find "$PUB" -type f -iname '*.php' | wc -l | tr -d ' ')"
[[ "$PHP_COUNT" -eq 1 ]] || fail other_php_files_in_public_tree
while IFS= read -r -d '' file; do
  base="$(basename -- "$file")"
  if [[ "$file" == "$PUB/index.php" || "$file" == "$PUB/.htaccess" ]]; then
    continue
  fi
  # No hidden files, even with otherwise allowlisted file extensions.
  if [[ "$base" == .* ]]; then
    printf 'HIDDEN_PUBLIC_FILE_NOT_ALLOWED=%s\n' "$base" >&2
    fail unapproved_hidden_file_in_public_tree
  fi
  case "$base" in
    *.js|*.css|*.map|*.png|*.jpg|*.jpeg|*.webp|*.gif|*.svg|*.woff|*.woff2|*.ttf|*.otf|*.ico|*.avif|*.webmanifest|robots.txt|sitemap.xml|manifest.json|fonts-manifest.json)
      ;;
    *)
      printf 'UNRECOGNIZED_PUBLIC_STATIC_FILENAME=%s\n' "$base" >&2
      fail public_static_extension_requires_independent_review
      ;;
  esac
done < <(find "$PUB" -type f -print0)
echo "PUBLIC_TREE_FILE_COUNT=$FILE_COUNT"
echo 'PUBLIC_TREE_PHP_FILE_COUNT=1_FRONT_CONTROLLER_NOT_FOR_STATIC_COPY'
echo 'ROOT_APACHE_HTACCESS=EXACT_BASELINE_EXCLUDED_FROM_STATIC_COPY'
echo 'STATIC_COPY_EXCLUSIONS=ROOT_INDEX_PHP_AND_ROOT_HTACCESS'
echo "STATIC_COPY_ALLOWLIST_COUNT=$((FILE_COUNT - 2))"
echo 'PUBLIC_TREE_SYMLINKS=ZERO'
echo 'STATIC_ONLY_ASSET_ALLOWLIST=PASS'

echo '========== PROPOSED DEDICATED INGRESS COLLISION CHECK =========='
for target in "$INGRESS_ROOT" "$HTTP_CONF" "$HTTP_UNIT" "$HTTP_SOCK"; do
  if [[ -e "$target" || -L "$target" ]]; then
    echo "TARGET=OCCUPIED:$target"
    fail proposed_private_ingress_target_already_exists
  fi
  echo "TARGET=FREE:$target"
done
if getent passwd "$INGRESS" >/dev/null || getent group "$INGRESS" >/dev/null; then
  fail proposed_dedicated_ingress_identity_exists
fi
echo 'DEDICATED_INGRESS_USER_AND_GROUP=AVAILABLE'
if systemctl is-active --quiet bambodia-previewqa-http.service; then
  fail dedicated_http_service_already_active
fi

echo '========== OFFLINE NGINX UNIX SOCKET CONFIG TEST =========='
TMP="$(mktemp -d /root/bambodia-previewqa-ingress-check.XXXXXXXX)"
chmod 700 "$TMP"
cat > "$TMP/nginx.conf" <<'NGINXCONFIG'
worker_processes 1;
pid /run/bambodia-previewqa-http/nginx.pid;
error_log stderr warn;
events { worker_connections 128; }
http {
    access_log off;
    server_tokens off;
    default_type application/octet-stream;
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
        server_name preview-qa.bambodia.com 127.0.0.1;
        root /var/lib/bambodia-previewqa-http-static-20261112/public;
        autoindex off;
        client_max_body_size 8m;
        location ^~ /storage/ { return 404; }
        location ^~ /installer/ { return 404; }
        location ~ /\. { return 404; }
        location ~* \.(php|phtml|phar)(/|$) { return 404; }
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
NGINXCONFIG
# nginx -t also opens the PID file and binds the listening Unix socket.
# The real /run paths deliberately do not exist yet. In a syntax-only test,
# redirect BOTH side effects into the root-private temporary directory.
# Keep the proposed service configuration in nginx.conf unchanged.
sed -e "s#^pid /run/bambodia-previewqa-http/nginx.pid;#pid $TMP/nginx.pid;#" \
    -e "s#listen unix:/run/bambodia-previewqa-http/http.sock;#listen unix:$TMP/http.sock;#" \
    "$TMP/nginx.conf" > "$TMP/nginx.offline.conf"
grep -Fxq "pid $TMP/nginx.pid;" "$TMP/nginx.offline.conf" ||
  fail offline_pid_path_rewrite_missing
grep -Fq "listen unix:$TMP/http.sock;" "$TMP/nginx.offline.conf" ||
  fail offline_http_socket_path_rewrite_missing
if ! nginx -t -c "$TMP/nginx.offline.conf" > "$TMP/nginx-test.log" 2>&1; then
  sed -n '1,25p' "$TMP/nginx-test.log" >&2
  fail standalone_unix_http_nginx_candidate_syntax
fi
echo 'OFFLINE_NGINX_PID_AND_LISTEN_SOCKET=EPHEMERAL_PRIVATE_PATHS'
echo 'DEPLOYED_NGINX_PID_AND_LISTEN_SOCKET=UNCHANGED_NOT_INSTALLED'
echo 'CANDIDATE_DEDICATED_UNIX_HTTP_NGINX_SYNTAX=PASS'
echo 'DEDICATED_INGRESS_IDENTITY=NOT_YET_CREATED'
echo 'PRIVATE_FASTCGI_GROUP_SHARING=NOT_YET_AUTHORIZED'
echo 'CLOUDFLARE_ACCESS_PROTECTION=NOT_CONFIGURED'
echo 'PUBLIC_HTTPS_DNS_ROUTE=NOT_CONFIGURED'
echo 'PERSISTENT_WRITES=NONE'
echo 'STEP12B_OFFLINE_PREFLIGHT=PASS'
