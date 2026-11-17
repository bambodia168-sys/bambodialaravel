#!/usr/bin/env bash
# Bambodia MCP: production hosting observations ONLY. No deployment.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--inspect" ]] || {
  echo "Usage: bash $0 --inspect" >&2; exit 64;
}
[[ "$(id -u)" == 0 ]] || { echo "MCP_HOST_AUDIT=BLOCKED_NOT_ROOT"; exit 1; }
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
NGINX=/www/server/nginx/sbin/nginx
EXPECTED_VHOST_SHA=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
  -f "$APP/storage/app/installed.lock" ]] || {
  echo "MCP_HOST_AUDIT=BLOCKED_LIVE_ROOT_CHANGED"; exit 1;
}
[[ -f "$VHOST" && ! -L "$VHOST" ]] || {
  echo "MCP_HOST_AUDIT=BLOCKED_VHOST_MISSING"; exit 1;
}
if [[ "$(sha256sum "$VHOST" | awk '{print $1}')" != "$EXPECTED_VHOST_SHA" ]]; then
  echo "MCP_HOST_AUDIT=BLOCKED_VHOST_HASH_CHANGED"
  echo "PRODUCTION_RELEASE_GATE=BLOCKED"
  exit 1
fi
echo "HOST_VHOST_INTEGRITY=PASS"
echo "HOST_INSTALLED_MARKER=EXISTS"
for php in /www/server/php/84/bin/php /www/server/php/85/bin/php; do
  [[ -x "$php" ]] || continue
  "$php" -r 'echo "HOST_PHP_".PHP_MAJOR_VERSION.PHP_MINOR_VERSION."=".PHP_VERSION.PHP_EOL;'
done
[[ -d "$APP/vendor/laravel/mcp" ]] &&
  echo "HOST_INSTALLED_MCP_PACKAGE=YES" ||
  echo "HOST_INSTALLED_MCP_PACKAGE=NO"
[[ -d "$APP/vendor/laravel/passport" ]] &&
  echo "HOST_INSTALLED_PASSPORT_PACKAGE=YES" ||
  echo "HOST_INSTALLED_PASSPORT_PACKAGE=NO"

# Nginx -T may contain secrets. Collect privately and never dump its contents.
TMP="$(mktemp -d /root/Bambodia-MCP-HostAudit.XXXXXXXX)"
cleanup() {
  if [[ "$TMP" == /root/Bambodia-MCP-HostAudit.* &&
    -d "$TMP" && ! -L "$TMP" ]]; then
    rm -rf --one-file-system -- "$TMP"
  fi
}
trap cleanup EXIT
if [[ -x "$NGINX" ]] && "$NGINX" -t > "$TMP/nginx-test" 2>&1; then
  echo "HOST_NGINX_SYNTAX=PASS"
else
  echo "HOST_NGINX_SYNTAX=NOT_VERIFIED"
fi
if [[ -x "$NGINX" ]] && "$NGINX" -T > "$TMP/nginx-config" 2>&1; then
  python3 - "$TMP/nginx-config" "$VHOST" <<'PY'
from pathlib import Path
import re
import sys
cfg = Path(sys.argv[1]).read_text(errors="replace")
vh = Path(sys.argv[2]).read_text(errors="replace")
active = "\n".join(l for l in cfg.splitlines() if not l.lstrip().startswith("#"))
vhost = "\n".join(l for l in vh.splitlines() if not l.lstrip().startswith("#"))
def report(name, value):
    print(name + "=" + ("CONFIG_PRESENT" if value else "NOT_PROVEN"))
report("HOST_SERVER_NAME", bool(re.search(
    r"\bserver_name\s+[^;]*\bbambodia\.com\b[^;]*;", vhost)))
report("HOST_DOCUMENT_ROOT", bool(re.search(
    r"\broot\s+/www/wwwroot/bambodia-staging-qa/public\s*;", vhost)))
report("HOST_FASTCGI_AUTHORIZATION", bool(re.search(
    r"\bfastcgi_param\s+HTTP_AUTHORIZATION\s+\$http_authorization\s*;", active, re.I)))
report("HOST_FASTCGI_UPSTREAM", bool(re.search(
    r"\bfastcgi_pass\s+(?:unix:|127\.0\.0\.1:)", active)))
report("HOST_EXACT_RESOURCE_RULE", bool(re.search(
    r"location\s*=\s*/\.well-known/oauth-protected-resource\s*[{]", vhost)))
PY
else
  echo "HOST_NGINX_CONFIG_DUMP=NOT_VERIFIED"
fi

# Unauthenticated GET only. No credentials, tokens or MCP tool operations.
for item in \
  "ROOT|https://bambodia.com/" \
  "RESOURCE_ROOT|https://bambodia.com/.well-known/oauth-protected-resource" \
  "RESOURCE_NESTED|https://bambodia.com/.well-known/oauth-protected-resource/mcp" \
  "AUTH_METADATA|https://bambodia.com/.well-known/oauth-authorization-server"
do
  key="$(printf '%s' "$item" | cut -d'|' -f1)"
  url="$(printf '%s' "$item" | cut -d'|' -f2-)"
  status="$(curl --silent --connect-timeout 5 --max-time 12 \
    --output /dev/null --write-out '%{http_code}' "$url" 2>/dev/null || true)"
  [[ "$status" =~ ^[0-9]{3}$ ]] || status=UNAVAILABLE
  printf 'HOST_PUBLIC_%s_HTTP=%s\n' "$key" "$status"
done
# A read-only inventory cannot prove backups are restorable or safe to deploy.
echo "HOST_BACKUP_RESTORE_INDEPENDENT_ACCEPTANCE=NOT_VERIFIED"
echo "HOST_LIVE_SOURCE_RECONCILIATION=NOT_VERIFIED_BY_THIS_SCRIPT"
echo "HOST_OAUTH_SIGNING_KEY_HANDLING=NOT_VERIFIED"
echo "HOST_REAL_CHATGPT_LINKING=NOT_ATTEMPTED"
echo "MCP_HOST_AUDIT=READ_ONLY_COMPLETED"
echo "PRODUCTION_RELEASE_GATE=BLOCKED_PENDING_INDEPENDENT_ACCEPTANCE"
echo "PRODUCTION_FILES_NGINX_DATABASE_SECRETS=UNCHANGED"
