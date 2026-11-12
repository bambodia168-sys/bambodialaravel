#!/usr/bin/env bash
# Bambodia Step 34B.3: existing site root and included PHP handler inspection.
# Read-only. Never writes Nginx, TLS, DNS, source, or QA database.
set -Eeuo pipefail
set +x
umask 077

HOST="oauth-qa.bambodia.com"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
ROOT="/www/wwwroot/$HOST"
QA="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"

echo '========== VERIFY EXISTING SITE AND FROZEN QA =========='
test "$(id -u)" -eq 0
test -f "$VHOST" && test ! -L "$VHOST"
test -d "$QA/.git" && test ! -L "$QA"
test "$(realpath -e "$QA")" = "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -s "$QA/public/build/manifest.json"
test -f "$QA/.env" && test ! -L "$QA/.env"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env"
command -v python3 >/dev/null
echo 'FROZEN_QA_SOURCE_UNCHANGED=PASS'

echo '========== EXISTING NGINX CONFIGURATION SYNTAX =========='
if test -x /www/server/nginx/sbin/nginx; then
  NGINX="/www/server/nginx/sbin/nginx"
elif command -v nginx >/dev/null 2>&1; then
  NGINX="$(command -v nginx)"
else
  echo 'NGINX_BINARY=NOT_FOUND'
  exit 34
fi
"$NGINX" -t >/dev/null 2>&1
echo 'EXISTING_NGINX_SYNTAX=PASS'

echo '========== DOCUMENT ROOT AND INCLUDED HANDLERS =========='
python3 - "$VHOST" "$ROOT" "$QA" "$HOST" <<'PY'
import glob
import os
from pathlib import Path
import re
import stat
import sys

vhost, root, qa, host = map(Path, sys.argv[1:])
host = str(host)
print("VHOST_PATH=" + str(vhost))
print("EXPECTED_DOCUMENT_ROOT=" + str(root))

if root.is_symlink():
    print("DOCUMENT_ROOT_SYMLINK=YES_REVIEW_REQUIRED")
elif root.is_dir():
    print("DOCUMENT_ROOT_SYMLINK=NO")
    mode = root.stat()
    print("DOCUMENT_ROOT_UID=" + str(mode.st_uid))
    print("DOCUMENT_ROOT_GID=" + str(mode.st_gid))
    print("DOCUMENT_ROOT_MODE=" + oct(stat.S_IMODE(mode.st_mode)))
else:
    print("DOCUMENT_ROOT_DIRECTORY=NOT_FOUND")

if root.is_dir():
    try:
        contents = list(root.iterdir())
        print("DOCUMENT_ROOT_ENTRY_COUNT=" + str(len(contents)))
        print("DOCUMENT_ROOT_EMPTY=" + ("YES" if not contents else "NO"))
    except PermissionError:
        print("DOCUMENT_ROOT_ENTRY_COUNT=PERMISSION_DENIED")
    for name in [
        "index.php", "index.html", "artisan", "composer.json",
        "bootstrap/app.php", "vendor/autoload.php",
        "public/index.php", ".env"
    ]:
        path = root / name
        key = name.upper().replace(".", "_").replace("/", "_")
        print("ROOT_" + key + "_PRESENT=" +
              ("YES" if path.exists() or path.is_symlink() else "NO"))
    print("DOCUMENT_ROOT_MATCHES_FROZEN_QA=" + (
        "YES" if root.resolve() == qa.resolve() else "NO"))
else:
    print("DOCUMENT_ROOT_MATCHES_FROZEN_QA=NO")

# Parse top-level vhost plus a bounded number of referenced includes.
# Print only directive classification/counts; never print config contents,
# upstream credentials, auth headers, env values, or private keys.
include_rx = re.compile(r"(?m)^\s*include\s+([^;\n]+)\s*;")
root_rx = re.compile(r"(?m)^\s*root\s+([^;\n]+)\s*;")
fastcgi_rx = re.compile(r"(?m)^\s*fastcgi_pass\s+([^;\n]+)\s*;")
proxy_rx = re.compile(r"(?m)^\s*proxy_pass\s+([^;\n]+)\s*;")
server_rx = re.compile(r"(?m)^\s*server_name\s+([^;\n]+)\s*;")

queue = [(vhost, 0)]
seen = set()
texts = []
unresolved = []
symlink_count = 0
too_large = 0
while queue and len(seen) < 80:
    path, depth = queue.pop(0)
    if str(path) in seen:
        continue
    seen.add(str(path))
    if path.is_symlink():
        symlink_count += 1
        continue
    if not path.is_file():
        unresolved.append(str(path))
        continue
    if path.stat().st_size > 1024 * 1024:
        too_large += 1
        continue
    data = path.read_text(encoding="utf-8", errors="replace")
    texts.append(data)
    if depth >= 4:
        continue
    for pattern in include_rx.findall(data):
        pattern = pattern.strip().strip('"').strip("'")
        if not pattern:
            continue
        if os.path.isabs(pattern):
            variants = [pattern]
        else:
            variants = [
                str(Path("/www/server/nginx/conf") / pattern),
                str(path.parent / pattern)
            ]
        matched = set()
        for candidate in variants:
            matched.update(glob.glob(candidate))
        if not matched:
            unresolved.append(pattern)
        for match in sorted(matched)[:80]:
            queue.append((Path(match), depth + 1))

combined = "\n".join(texts)
declared_roots = [x.strip() for x in root_rx.findall(texts[0])] if texts else []
print("VHOST_ROOT_MATCHES_EXPECTED=" + (
    "YES" if str(root) in declared_roots else "NO"))
print("VHOST_EXACT_SERVER_NAME=" + (
    "YES" if any(host in item.split() for item in server_rx.findall(texts[0])) else "NO"))
print("NGINX_INCLUDE_FILES_INSPECTED=" + str(max(0, len(texts) - 1)))
print("NGINX_INCLUDE_UNRESOLVED_COUNT=" + str(len(unresolved)))
print("NGINX_INCLUDE_SYMLINK_COUNT=" + str(symlink_count))
print("NGINX_INCLUDE_TOO_LARGE_COUNT=" + str(too_large))
print("FASTCGI_PASS_DIRECTIVE_COUNT=" + str(len(fastcgi_rx.findall(combined))))
print("PROXY_PASS_DIRECTIVE_COUNT=" + str(len(proxy_rx.findall(combined))))
php84 = bool(re.search(r"(?:php[-/_]?84|php[-/]8\.4|php-cgi-84)", combined, re.I))
php85 = bool(re.search(r"(?:php[-/_]?85|php[-/]8\.5|php-cgi-85)", combined, re.I))
print("PHP84_HANDLER_REFERENCE=" + ("DETECTED" if php84 else "NOT_DETECTED"))
print("PHP85_HANDLER_REFERENCE=" + ("DETECTED" if php85 else "NOT_DETECTED"))
print("FROZEN_QA_PATH_IN_SCANNED_CONFIG=" + (
    "YES" if str(qa) in combined else "NO"))
print("NGINX_INCLUDE_CLASSIFICATION=COMPLETE")
PY

echo '========== LOCAL HTTPS ORIGIN PROBE, HEAD ONLY =========='
if command -v curl >/dev/null 2>&1; then
  for URI in / /login /.well-known/oauth-authorization-server; do
    # --resolve preserves SNI/Host. Certificate verification remains enabled;
    # no --insecure, no redirects, no cookies, no authorization headers.
    if STATUS="$(curl --noproxy '*' --silent --show-error \
      --head --proto '=https' --tlsv1.2 \
      --resolve "$HOST:443:127.0.0.1" \
      --connect-timeout 3 --max-time 8 \
      --output /dev/null --write-out '%{http_code}' \
      "https://$HOST$URI" 2>/dev/null)"; then
      echo "ORIGIN_HEAD path=$URI status=$STATUS tls_verified=YES"
    else
      echo "ORIGIN_HEAD path=$URI status=UNAVAILABLE tls_verified=NO_OR_UNREACHABLE"
    fi
  done
else
  echo 'ORIGIN_HTTPS_PROBE=CURL_NOT_AVAILABLE'
fi
echo 'ORIGIN_HTTP_STATUS_IS_NOT_OAUTH_ACCEPTANCE'

echo '========== READ-ONLY BOUNDARY =========='
echo 'EXISTING_HOSTNAME=OCCUPIED'
echo 'EXISTING_SITE_REUSE=BLOCKED_PENDING_OWNER_REVIEW'
echo 'DNS_OR_CLOUDFLARE_WRITES=NONE'
echo 'NGINX_OR_TLS_WRITES=NONE'
echo 'FROZEN_QA_SOURCE_CHANGES=NONE'
echo 'DIRECT_QA_DATABASE_WRITES=NONE'
echo 'PRODUCTION_DEPLOYMENT=NONE'
echo 'STEP34B3_EXISTING_SITE_INSPECTION=COMPLETE'
