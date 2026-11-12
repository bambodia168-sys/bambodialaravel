#!/usr/bin/env bash
# Step 34B.2: inspect an EXISTING OAuth QA vhost and public certificate.
# No DNS, nginx, TLS, firewall, app, database or production mutations.
set -Eeuo pipefail
set +x
umask 077

HOST="oauth-qa.bambodia.com"
APP="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
CERTDIR="/www/server/panel/vhost/cert/$HOST"

echo "========== FROZEN QA AND EXISTING VHOST =========="
test "$(id -u)" -eq 0
test -d "$APP/.git" && test ! -L "$APP"
test "$(realpath -e "$APP")" = "$APP"
test "$(git -C "$APP" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
test -f "$APP/.env" && test ! -L "$APP/.env"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$APP/.env"
test -f "$VHOST" && test ! -L "$VHOST"
test -d "$CERTDIR" && test ! -L "$CERTDIR"
command -v python3 >/dev/null
command -v openssl >/dev/null
echo 'EXISTING_VHOST_AND_QA_ISOLATION=PASS'

echo "========== CHECK NGINX SYNTAX =========="
if test -x /www/server/nginx/sbin/nginx; then
    NGINX=/www/server/nginx/sbin/nginx
elif command -v nginx >/dev/null 2>&1; then
    NGINX="$(command -v nginx)"
else
    echo 'NGINX_BINARY=NOT_FOUND'
    exit 34
fi
if "$NGINX" -t >/dev/null 2>&1; then
    echo 'EXISTING_NGINX_SYNTAX=PASS'
else
    echo 'EXISTING_NGINX_SYNTAX=FAIL'
    exit 34
fi

echo "========== INSPECT EXISTING VHOST DIRECTIVES =========="
python3 - "$VHOST" "$HOST" "$APP" <<'PY'
import pathlib
import re
import sys

path, hostname, qa_app = sys.argv[1:]
content = pathlib.Path(path).read_text(encoding="utf-8", errors="replace")
# Only output allowlisted observations; never output the full configuration,
# auth headers, secrets, environment data, cookies or private keys.
def directives(keyword):
    rx = r"(?m)^\s*" + re.escape(keyword) + r"\s+([^;\n]+);"
    return [m.group(1).strip() for m in re.finditer(rx, content)]

names = [value for line in directives("server_name") for value in line.split()]
roots = directives("root")
listen = directives("listen")
proxies = directives("proxy_pass")
fastcgi = directives("fastcgi_pass")
redirect = directives("return")
includes = directives("include")
certs = directives("ssl_certificate")
keys = directives("ssl_certificate_key")

print("VHOST_EXACT_SERVER_NAME=" + ("YES" if hostname in names else "NO"))
print("VHOST_HTTP_LISTEN_80=" +
      ("YES" if any(re.search(r"(?<!\d)80(?!\d)", x) for x in listen) else "NO"))
print("VHOST_HTTPS_LISTEN_443=" +
      ("YES" if any(re.search(r"(?<!\d)443(?!\d)", x) for x in listen) else "NO"))
print("VHOST_ROOT_DIRECTIVES=" + str(len(roots)))
for root in roots[:6]:
    # Only filesystem paths, not arbitrary interpolations or config text.
    if root.startswith("/") and re.fullmatch(r"[/A-Za-z0-9._$-]+", root):
        print("DOCUMENT_ROOT=" + root)
    else:
        print("DOCUMENT_ROOT=NON_LITERAL_REVIEW_REQUIRED")
print("VHOST_PROXY_PASS_COUNT=" + str(len(proxies)))
print("VHOST_FASTCGI_PASS_COUNT=" + str(len(fastcgi)))
print("VHOST_RETURN_COUNT=" + str(len(redirect)))
print("VHOST_INCLUDE_COUNT=" + str(len(includes)))
print("VHOST_SSL_CERT_DIRECTIVES=" + str(len(certs)))
print("VHOST_SSL_KEY_DIRECTIVES=" + str(len(keys)))
print("VHOST_PHP84_REFERENCES=" + str(content.count("/www/server/php/84/")))
print("VHOST_PHP85_REFERENCES=" + str(content.count("/www/server/php/85/")))
print("QA_FROZEN_APP_PATH_IN_VHOST=" + ("YES" if qa_app in content else "NO"))
print("VHOST_PRODUCTION_ROOT_REVIEW=" + (
    "REQUIRED" if any(not root.startswith(qa_app) for root in roots) else "NONE_DETECTED"))
print("VHOST_INCLUDES_OR_UPSTREAM_REVIEW=" + (
    "REQUIRED" if includes or proxies else "NONE_DETECTED"))
print("VHOST_DIRECTIVE_INSPECTION=COMPLETE")
PY

echo "========== INSPECT PUBLIC CERTIFICATE FILE METADATA =========="
# Never open the private key, nor print complete certificate contents.
CERT=''
for candidate in \
  "$CERTDIR/fullchain.pem" \
  "$CERTDIR/cert.pem" \
  "$CERTDIR/certificate.pem" \
  "$CERTDIR/fullchain.crt" \
  "$CERTDIR/cert.crt"; do
    if test -f "$candidate" && test ! -L "$candidate" &&
       openssl x509 -in "$candidate" -noout >/dev/null 2>&1; then
        CERT="$candidate"
        break
    fi
done
if test -z "$CERT"; then
    echo 'EXISTING_PARSEABLE_CERTIFICATE=NOT_FOUND'
    echo 'CERT_VALIDITY=NOT_TESTED'
else
    echo 'EXISTING_PARSEABLE_CERTIFICATE=YES'
    echo "CERT_FILE_BASENAME=$(basename "$CERT")"
    openssl x509 -in "$CERT" -noout -dates
    if openssl x509 -in "$CERT" -noout -checkhost "$HOST" >/dev/null 2>&1; then
        echo 'CERT_HOSTNAME_MATCH=YES'
    else
        echo 'CERT_HOSTNAME_MATCH=NO_OR_UNSUPPORTED'
    fi
    if openssl x509 -in "$CERT" -noout -checkend 1209600 >/dev/null 2>&1; then
        echo 'CERT_VALID_FOR_NEXT_14_DAYS=YES'
    else
        echo 'CERT_VALID_FOR_NEXT_14_DAYS=NO'
    fi
    echo 'PUBLIC_CHAIN_AND_TRUST=NOT_VERIFIED'
fi
if test -f "$CERTDIR/privkey.pem" || test -f "$CERTDIR/key.pem"; then
    echo 'PRIVATE_KEY_FILE_PRESENT=YES_NOT_READ'
else
    echo 'PRIVATE_KEY_FILE_PRESENT=NOT_IN_STANDARD_PATH'
fi

echo "========== READ-ONLY PUBLIC EDGE HTTPS HEAD =========="
if command -v curl >/dev/null 2>&1; then
    HEADERS="$(mktemp /root/bambodia-step34b2-head.XXXXXXXX)"
    trap 'rm -f -- "$HEADERS"' EXIT
    if curl -sS -I --connect-timeout 5 --max-time 12 \
         --proto '=https' --tlsv1.2 \
         "https://$HOST/" > "$HEADERS" 2>/dev/null; then
        python3 - "$HEADERS" <<'PY'
import re, sys
headers = open(sys.argv[1], encoding="latin-1").read()
lines = re.findall(r"(?mi)^HTTP/\S+\s+(\d{3})", headers)
print("PUBLIC_EDGE_HTTPS_HEAD_STATUS=" + (lines[-1] if lines else "UNKNOWN"))
print("PUBLIC_EDGE_CLOUDFLARE_HEADER=" + (
  "PRESENT" if re.search(r"(?mi)^cf-ray\s*:", headers) else "ABSENT"))
PY
    else
        echo 'PUBLIC_EDGE_HTTPS_HEAD_STATUS=FAILED_OR_UNREACHABLE'
    fi
else
    echo 'PUBLIC_EDGE_HTTPS_HEAD_STATUS=NOT_TESTED'
fi

echo "========== END BOUNDARIES =========="
echo 'HOSTNAME_COLLISION=CONFIRMED_EXISTING_VHOST'
echo 'HOSTNAME_REUSE_DECISION=REQUIRES_OWNER_REVIEW'
echo 'ORIGIN_ROUTING_AND_CERT_TRUST=NOT_YET_ACCEPTED'
echo 'DNS_WRITES=NONE'
echo 'NGINX_RELOAD_OR_VHOST_WRITES=NONE'
echo 'CERTIFICATE_OR_PRIVATE_KEY_WRITES=NONE'
echo 'QA_DATABASE_OPERATIONS=NONE'
echo 'QA_SOURCE_CHANGES=NONE'
echo 'PRODUCTION_DEPLOYMENT=NONE'
echo 'STEP34B2_EXISTING_VHOST_INSPECTION=COMPLETE'
