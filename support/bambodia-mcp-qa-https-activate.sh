#!/usr/bin/env bash
# Activate ONLY the already-installed isolated Bambodia MCP QA hostname.
# Requires explicit terminal confirmation. Preserves the original ACME
# webroot and certificate directives; never edits other vhosts or production.
set -Eeuo pipefail
set +x
umask 077

[[ "$#" -eq 1 && "$1" == "--activate" ]] || {
  echo "Usage: bash this-script --activate" >&2; exit 64;
}
APP="/var/lib/bambodia-oauth-https-qa"
OLDROOT="/www/wwwroot/oauth-qa.bambodia.com"
VHOST="/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf"
VHASH="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
NGINX="/www/server/nginx/sbin/nginx"
PHP="/www/server/php/85/bin/php"
SOCKET="/tmp/php-cgi-85.sock"
fail() { printf 'MCP_QA_ACTIVATION_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for exe in python3 sha256sum stat runuser cp mktemp; do
  command -v "$exe" >/dev/null || fail "MISSING_$exe"
done
[[ -x "$NGINX" && -x "$PHP" && -S "$SOCKET" ]] ||
  fail EXPECTED_NGINX_PHP85_FPM_NOT_READY
[[ -d "$APP" && ! -L "$APP" && -f "$APP/public/index.php" ]] ||
  fail PRIVATE_QA_APP_NOT_READY
[[ -d "$OLDROOT" && ! -L "$OLDROOT" &&
   -d "$OLDROOT/.well-known" && ! -L "$OLDROOT/.well-known" ]] ||
  fail OLD_CERTIFICATE_CHALLENGE_ROOT_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "$VHASH" ]] ||
  fail ORIGINAL_QA_VHOST_HASH_CHANGED
for file in "$APP/.env" "$APP/vendor/autoload.php" \
  "$APP/storage/oauth-private.key" "$APP/storage/oauth-public.key" \
  "$APP/public/build/manifest.json" \
  "$APP/resources/views/developer/mcp-connection.blade.php"; do
  [[ -s "$file" && ! -L "$file" ]] || fail "QA_APP_FILE_NOT_READY_$(basename "$file")"
done
runuser -u www -- test -r "$APP/public/index.php" ||
  fail PHP_FPM_CANNOT_READ_PUBLIC_INDEX
runuser -u www -- test -r "$APP/.env" ||
  fail PHP_FPM_CANNOT_READ_QA_ENV
runuser -u www -- test -r "$APP/storage/oauth-private.key" ||
  fail PHP_FPM_CANNOT_READ_PASSPORT_KEY
if runuser -u www -- test -w "$APP/.env"; then
  fail PHP_FPM_MUST_NOT_WRITE_QA_ENV
fi
if runuser -u www -- test -w "$APP/storage/oauth-private.key"; then
  fail PHP_FPM_MUST_NOT_WRITE_PASSPORT_KEY
fi
"$NGINX" -t >/dev/null || fail CURRENT_NGINX_SYNTAX_INVALID
echo "QA_PRIVATE_APP_AND_PHP_FPM=READY"
echo "ORIGINAL_QA_VHOST=UNCHANGED"
echo "ORIGINAL_ACME_WEBROOT=RETAINED"

BACKUP_DIR="$(mktemp -d /root/Bambodia-MCP-QA-Nginx.XXXXXXXX)"
chmod 700 "$BACKUP_DIR"
cp -p -- "$VHOST" "$BACKUP_DIR/original.conf"
[[ "$(sha256sum "$BACKUP_DIR/original.conf" | cut -d' ' -f1)" == "$VHASH" ]] ||
  fail ORIGINAL_BACKUP_HASH_FAILED

python3 - "$VHOST" "$BACKUP_DIR/candidate.conf" <<'PY'
import pathlib,re,sys
src=pathlib.Path(sys.argv[1])
out=pathlib.Path(sys.argv[2])
s=src.read_text()
no_comments="\n".join(
    "" if line.lstrip().startswith("#") else line
    for line in s.splitlines()
)
if len(re.findall(r'\bserver\s*\{',no_comments))!=1:
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=UNEXPECTED_VHOST_SERVER_LAYOUT")
if not re.search(r'\bserver_name\s+[^;]*\boauth-qa\.bambodia\.com\b',no_comments):
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=WRONG_SERVER_NAME")
if not re.search(r'\blisten\s+[^;]*443[^;]*;',no_comments):
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=HTTPS_LISTENER_NOT_FOUND")
oldroot="root /www/wwwroot/oauth-qa.bambodia.com;"
if no_comments.count(oldroot)!=1:
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=UNEXPECTED_ORIGINAL_ROOT")
if re.search(r'\bfastcgi_pass\s+',no_comments):
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=EXISTING_PHP_HANDLER_REVIEW_REQUIRED")
if re.search(r'\blocation\s+(?:=|\^~|~\*?|@\w+)?\s*/\s*\{',no_comments):
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=EXISTING_ROOT_LOCATION_REVIEW_REQUIRED")
if not re.search(r'\}\s*\Z',s):
    raise SystemExit("MCP_QA_ACTIVATION_BLOCKED=UNEXPECTED_FINAL_SERVER_BRACE")
s=s.replace(oldroot,"root /var/lib/bambodia-oauth-https-qa/public;",1)
extra=r"""
    # QA-only OAuth/MCP Laravel routing. Other domains are unchanged.
    # ACME files remain served from the ORIGINAL aaPanel webroot.
    location ^~ /.well-known/acme-challenge/ {
        root /www/wwwroot/oauth-qa.bambodia.com;
        try_files $uri =404;
    }
    # OAuth discovery metadata must reach Laravel, even if an aaPanel
    # inherited include has a broader /.well-known/ prefix handler.
    location = /.well-known/oauth-authorization-server {
        try_files $uri /index.php?$query_string;
    }
    location = /.well-known/oauth-protected-resource/mcp {
        try_files $uri /index.php?$query_string;
    }
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/lib/bambodia-oauth-https-qa/public/index.php;
        fastcgi_param DOCUMENT_ROOT /var/lib/bambodia-oauth-https-qa/public;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTPS $https;
        fastcgi_pass unix:/tmp/php-cgi-85.sock;
        fastcgi_read_timeout 60s;
    }
    # Never execute arbitrary PHP files under the QA document root.
    location ~ \.php(?:$|/) { return 404; }
    location ~ /\.(?!well-known/) { deny all; }
"""
i=s.rfind("}")
s=s[:i]+extra+s[i:]
out.write_text(s)
print("QA_NGINX_CANDIDATE=PREPARED")
print("ORIGINAL_CERTIFICATE_AND_SSL_DIRECTIVES=RETAINED")
PY

echo "ORIGINAL_VHOST_BACKUP=$BACKUP_DIR/original.conf"
echo "CANDIDATE_CONFIG=$BACKUP_DIR/candidate.conf"
echo "QA_NGINX_TARGET=$VHOST"
[[ -c /dev/tty ]] || fail INTERACTIVE_TERMINAL_REQUIRED
printf 'Type ACTIVATE-BAMBODIA-MCP-QA to enable ONLY the QA HTTPS host: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == ACTIVATE-BAMBODIA-MCP-QA ]] || fail OPERATOR_DECLINED
unset APPROVAL

# Restore the original config on all failures until the new config has
# passed nginx -t and a reload signal has completed successfully.
COMMITTED=0
restore() {
  local code="$?"
  trap - EXIT
  if [[ "$COMMITTED" -ne 1 ]]; then
    cp -p -- "$BACKUP_DIR/original.conf" "$VHOST" || true
    "$NGINX" -t >/dev/null 2>&1 || true
    "$NGINX" -s reload >/dev/null 2>&1 || true
    echo "MCP_QA_VHOST=RESTORED_ORIGINAL_AFTER_FAILURE" >&2
  fi
  exit "$code"
}
trap restore EXIT

cp -p -- "$BACKUP_DIR/candidate.conf" "$VHOST"
"$NGINX" -t || fail CANDIDATE_NGINX_SYNTAX_FAILED_ROLLBACK
"$NGINX" -s reload || fail NGINX_RELOAD_FAILED_ROLLBACK
COMMITTED=1
trap - EXIT
echo "MCP_QA_HTTPS_ACTIVATION=PASS"
echo "MCP_QA_LARAVEL_PUBLIC_ROOT=/var/lib/bambodia-oauth-https-qa/public"
echo "MCP_QA_BACKUP_FILE=$BACKUP_DIR/original.conf"
echo "MCP_QA_LOGIN=https://oauth-qa.bambodia.com/login"
echo "MCP_QA_CONNECTION_CENTER=https://oauth-qa.bambodia.com/developer/mcp-connection"
echo "MCP_QA_PUBLIC_FUNCTIONAL_TEST=NOT_YET_PERFORMED"
echo "ORIGINAL_AAPANEL_WEBROOT=RETAINED"
echo "PRODUCTION_CONFIGS=UNCHANGED"
