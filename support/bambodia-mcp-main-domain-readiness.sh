#!/usr/bin/env bash
# Bambodia MCP: read-only observation of REAL production hosting and URLs.
# No Nginx reload, writes, migrations, env reads, user creation, token
# registration or code deployment. Does not execute production application.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--inspect" ]] || {
  echo "Usage: bash script --inspect" >&2; exit 64;
}
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
NGINX=/www/server/nginx/sbin/nginx
QA=/var/lib/bambodia-oauth-https-qa
fail(){ printf 'BAMBODIA_MAIN_MCP_INSPECTION_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for cmd in python3 curl sha256sum stat; do
  command -v "$cmd" >/dev/null || fail "MISSING_$cmd"
done

echo '========== REAL MAIN-DOMAIN HOSTING (READ ONLY) =========='
if [[ ! -f "$VHOST" || -L "$VHOST" ]]; then
  echo 'PRODUCTION_VHOST=NOT_FOUND_AT_EXPECTED_AAPANEL_PATH'
  exit 2
fi
[[ -x "$NGINX" ]] || fail NGINX_BINARY_MISSING
echo "PRODUCTION_NGINX_VHOST_SHA256=$(sha256sum "$VHOST" | cut -d' ' -f1)"
if "$NGINX" -t >/dev/null 2>&1; then
  echo 'PRODUCTION_NGINX_SYNTAX=VALID'
else
  echo 'PRODUCTION_NGINX_SYNTAX=INVALID'
fi
python3 - "$VHOST" "$QA" <<'PY'
import pathlib,re,sys
v=pathlib.Path(sys.argv[1])
qa=pathlib.Path(sys.argv[2]).resolve()
s=v.read_text()
active="\n".join(line for line in s.splitlines() if not line.lstrip().startswith("#"))
names=re.findall(r'\bserver_name\s+([^;]+);',active)
hosts={host for line in names for host in line.split()}
print("PRODUCTION_SERVER_NAME="+("EXPECTED" if 'bambodia.com' in hosts else "REVIEW_REQUIRED"))
roots=re.findall(r'\broot\s+([^;]+);',active)
roots=[x.strip() for x in roots if x.strip()]
print("PRODUCTION_DOCUMENT_ROOT_ENTRIES="+str(len(roots)))
for root in roots[:5]:
    print("PRODUCTION_ROOT="+root)
    p=pathlib.Path(root)
    try:
        resolved=p.resolve()
        print("PRODUCTION_ROOT_EXISTS="+("YES" if p.is_dir() else "NO"))
        print("PRODUCTION_ROOT_IS_QA="+("YES" if resolved==qa or qa in resolved.parents else "NO"))
        app=p.parent if p.name=="public" else p
        print("PRODUCTION_APP_ARTISAN="+("YES" if (app/"artisan").is_file() else "NO"))
        print("PRODUCTION_INSTALLED_LOCK="+("YES" if (app/"storage/app/installed.lock").is_file() else "NO"))
        print("PRODUCTION_MCP_PACKAGE="+("YES" if (app/"vendor/laravel/mcp").is_dir() else "NO"))
        print("PRODUCTION_PASSPORT_PACKAGE="+("YES" if (app/"vendor/laravel/passport").is_dir() else "NO"))
        print("PRODUCTION_MCP_CONTROLLERS="+("YES" if (app/"app/Http/Controllers/Api/BambodiaMcpController.php").is_file() else "NO"))
    except (OSError,RuntimeError):
        print("PRODUCTION_ROOT_INSPECTION=UNAVAILABLE")
print("VHOST_EXPLICIT_ROOT_OAUTH_WELL_KNOWN="+("YES" if 'location = /.well-known/oauth-protected-resource' in active else "NO"))
print("VHOST_EXPLICIT_AUTHORIZATION_HEADER="+("YES" if 'HTTP_AUTHORIZATION' in active else "NO_OR_IN_INCLUDED_FILE"))
PY

echo '========== MAIN-DOMAIN EXTERNAL HTTPS (ANONYMOUS GET) =========='
get_code(){
  local label="$1" url="$2" code
  if code="$(curl --silent --show-error --proto '=https' --proto-redir '=https' \
    --connect-timeout 8 --max-time 16 --max-redirs 0 --output /dev/null \
    --write-out '%{http_code}' "$url")"; then
    echo "$label=$code"
  else
    echo "$label=UNREACHABLE_OR_TLS_REJECTED"
  fi
}
get_code BAMBODIA_HOME_STATUS https://bambodia.com/
get_code BAMBODIA_MCP_GET_STATUS https://bambodia.com/mcp
get_code BAMBODIA_RESOURCE_ROOT_STATUS https://bambodia.com/.well-known/oauth-protected-resource
get_code BAMBODIA_RESOURCE_MCP_STATUS https://bambodia.com/.well-known/oauth-protected-resource/mcp
get_code BAMBODIA_OAUTH_DISCOVERY_STATUS https://bambodia.com/.well-known/oauth-authorization-server

echo '========== SAFETY =========='
echo 'QA_APP_AND_QA_DATABASE=UNTOUCHED'
echo 'PRODUCTION_FILES_AND_DATABASE=NOT_MODIFIED'
echo 'COMPOSER_NPM_MIGRATIONS=NOT_EXECUTED'
echo 'NGINX=NOT_RELOADED'
echo 'BACKUP_AND_RESTORE_ACCEPTANCE=STILL_REQUIRED'
echo 'PRODUCTION_MCP_ACTIVATION=NOT_REQUESTED'
echo 'BAMBODIA_MAIN_MCP_INSPECTION=COMPLETE'
