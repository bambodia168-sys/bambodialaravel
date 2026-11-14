#!/usr/bin/env bash
# Identify real main-domain runtime before a controlled MCP package upgrade.
# READ ONLY: does not run Artisan, Composer, NPM, MySQL, migrations, or reload Nginx.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--inventory" ]] || { echo 'Usage: bash script --inventory' >&2; exit 64; }
[[ "$(id -u)" -eq 0 ]] || { echo 'BLOCKED=ROOT_REQUIRED'; exit 1; }
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
APPROVED=5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116
for tool in python3 git sha256sum; do
  command -v "$tool" >/dev/null || { echo "BLOCKED=MISSING_$tool"; exit 1; }
done
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" ]] || { echo 'BLOCKED=APP_ROOT_CHANGED'; exit 1; }
[[ -f "$VHOST" && ! -L "$VHOST" ]] || { echo 'BLOCKED=VHOST_MISSING_OR_SYMLINK'; exit 1; }
HASH="$(sha256sum "$VHOST" | awk '{print $1}')"
[[ "$HASH" == "$APPROVED" ]] || {
  echo "BLOCKED=VHOST_CHANGED_SINCE_FIRST_INSPECTION"
  echo "CURRENT_VHOST_SHA256=$HASH"
  exit 1
}

echo '========== VERIFIED MAIN-DOMAIN RELEASE TARGET =========='
python3 - "$APP" "$VHOST" <<'PY'
import json,pathlib,re,sys
app=pathlib.Path(sys.argv[1])
vhost=pathlib.Path(sys.argv[2])
active="\n".join(line for line in vhost.read_text().splitlines() if not line.lstrip().startswith("#"))
roots=[v.strip() for v in re.findall(r"(?m)^\s*root\s+([^;]+);",active)]
if roots != [str(app/"public")]:
    raise SystemExit("BLOCKED=PRODUCTION_ROOT_HAS_CHANGED")
print("LIVE_DOCUMENT_ROOT=VERIFIED")
print("LIVE_APPLICATION="+str(app))
paths={
  "INSTALLED_LOCK":"storage/app/installed.lock",
  "COMPOSER_LOCK":"composer.lock",
  "VENDOR_AUTOLOAD":"vendor/autoload.php",
  "MCP_PACKAGE":"vendor/laravel/mcp",
  "PASSPORT_PACKAGE":"vendor/laravel/passport",
  "MCP_CONTROLLER":"app/Http/Controllers/Api/BambodiaMcpController.php",
  "PASSPORT_PRIVATE_KEY":"storage/oauth-private.key",
  "PASSPORT_PUBLIC_KEY":"storage/oauth-public.key",
  "CACHED_CONFIG":"bootstrap/cache/config.php",
}
for name,rel in paths.items():
    p=app/rel
    print("LIVE_"+name+"="+("YES" if p.exists() else "NO"))
env=app/".env"
if env.is_file() and not env.is_symlink():
    values={}
    allowed="APP_URL|APP_ENV|APP_DEBUG|BAMBODIA_MCP_ENABLED|BAMBODIA_MCP_WRITES_ENABLED"
    for line in env.read_text(errors="replace").splitlines():
        m=re.match(r"^\s*("+allowed+r")\s*=\s*(.*?)\s*$",line)
        if m: values[m.group(1)]=m.group(2).strip(" \t'\"")
    print("LIVE_ENV_FILE=REGULAR")
    print("LIVE_APP_URL_IS_MAIN_DOMAIN="+("YES" if values.get("APP_URL")=="https://bambodia.com" else "NO_OR_UNKNOWN"))
    print("LIVE_APP_ENV_PRODUCTION="+("YES" if values.get("APP_ENV")=="production" else "NO_OR_UNKNOWN"))
    print("LIVE_DEBUG_DISABLED="+("YES" if values.get("APP_DEBUG","").lower() in ("false","0","off") else "NO_OR_UNKNOWN"))
    for name in ("BAMBODIA_MCP_ENABLED","BAMBODIA_MCP_WRITES_ENABLED"):
        val=values.get(name,"").lower()
        print("LIVE_"+name+"="+("DISABLED_OR_UNSET" if val in ("","false","0","off") else "EXPLICIT_OR_REQUIRES_REVIEW"))
else:
    print("LIVE_ENV_FILE=NOT_A_REGULAR_FILE")
    print("LIVE_APP_URL_IS_MAIN_DOMAIN=UNKNOWN")
    print("LIVE_APP_ENV_PRODUCTION=UNKNOWN")
    print("LIVE_DEBUG_DISABLED=UNKNOWN")
    print("LIVE_BAMBODIA_MCP_ENABLED=UNKNOWN")
    print("LIVE_BAMBODIA_MCP_WRITES_ENABLED=UNKNOWN")
lock=app/"composer.lock"
if lock.is_file() and not lock.is_symlink():
    try:
        obj=json.loads(lock.read_text())
        names={p.get("name") for p in obj.get("packages",[]) if isinstance(p,dict)}
        print("LIVE_LOCK_HAS_MCP="+("YES" if "laravel/mcp" in names else "NO"))
        print("LIVE_LOCK_HAS_PASSPORT="+("YES" if "laravel/passport" in names else "NO"))
    except (OSError,ValueError,TypeError):
        print("LIVE_LOCK_PARSE=FAILED")
else:
    print("LIVE_LOCK_HAS_MCP=UNKNOWN")
    print("LIVE_LOCK_HAS_PASSPORT=UNKNOWN")
print("VHOST_WELL_KNOWN_RULE_PRESENT="+("YES" if ".well-known" in active else "NO"))
print("VHOST_DIRECT_FASTCGI_PASS_COUNT="+str(len(re.findall(r"\bfastcgi_pass\s+[^;]+;",active))))
PY

echo '========== CURRENT INSTALLED SOURCE (READ ONLY) =========='
if git -C "$APP" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "LIVE_GIT_HEAD=$(git -C "$APP" rev-parse --verify HEAD)"
  echo "LIVE_GIT_BRANCH=$(git -C "$APP" symbolic-ref --quiet --short HEAD || echo DETACHED)"
  echo "LIVE_GIT_TRACKED_CHANGES=$(git -C "$APP" status --porcelain --untracked-files=no | wc -l | tr -d ' ')"
else
  echo 'LIVE_GIT_HEAD=NOT_A_GIT_WORKTREE'
fi
if [[ -f "$APP/composer.json" && ! -L "$APP/composer.json" ]]; then
  echo "LIVE_COMPOSER_JSON_GIT_BLOB=$(git hash-object "$APP/composer.json")"
fi
if [[ -f "$APP/composer.lock" && ! -L "$APP/composer.lock" ]]; then
  echo "LIVE_COMPOSER_LOCK_SHA256=$(sha256sum "$APP/composer.lock" | awk '{print $1}')"
fi
echo '========== INSTALLED PHP RUNTIME OPTIONS =========='
for minor in 84 85; do
  P="/www/server/php/$minor/bin/php"
  if [[ -x "$P" && ! -L "$P" ]]; then
    echo "PHP_$minor=$("$P" -r 'echo PHP_VERSION;' 2>/dev/null || echo ERROR)"
  else
    echo "PHP_$minor=NOT_PRESENT"
  fi
done
echo '========== NO-CHANGE BOUNDARY =========='
echo 'SECRETS_AND_DATABASE=NOT_PRINTED_OR_ACCESSED'
echo 'ARTISAN_COMPOSER_NPM=NOT_EXECUTED'
echo 'PRODUCTION_FILES_AND_NGINX=NOT_MODIFIED'
echo 'MCP_MAIN_DOMAIN_RELEASE=NOT_DEPLOYED'
echo 'BAMBODIA_INSTALL_TARGET_INVENTORY=COMPLETE'
