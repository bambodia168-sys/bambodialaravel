#!/usr/bin/env bash
# Bambodia Step 34B.1: READ-ONLY hostname/DNS/Nginx/TLS collision inventory.
# No public endpoint is created. No DNS, vhost, certificate or DB changes.
set -Eeuo pipefail
set +x
umask 077

HOST="oauth-qa.bambodia.com"
PARENT="bambodia.com"
APP="/var/lib/bambodia-oauth-browser-qa"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
LOCK_HASH="01b00d0d0644a151c7fe526c17a0658594707e5f1393e041a81aa74bebfa23e3"
DB="bambodia_oauthqa_browser"
CNF="/root/bambodia-oauth-browser-qa.cnf"
MYSQL="/www/server/mysql/bin/mysql"

echo '========== VERIFY READ-ONLY QA BOUNDARY =========='
test "$(id -u)" -eq 0
test -d "$APP/.git" && test ! -L "$APP"
test "$(realpath -e "$APP")" = "$APP"
test "$(git -C "$APP" rev-parse HEAD)" = "$SHA"
test -z "$(git -C "$APP" status --porcelain --untracked-files=no)"
test -f "$APP/.env" && test ! -L "$APP/.env"
test -f "$APP/composer.lock" && test ! -L "$APP/composer.lock"
test "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" = "$LOCK_HASH"
test -s "$APP/public/build/manifest.json" && test ! -e "$APP/public/hot"
test -f "$CNF" && test ! -L "$CNF"
test "$(stat -c '%u:%a' "$CNF")" = '0:600'
test "$(stat -c '%U:%G:%a' "$APP/.env")" = 'root:www:640'
test -x "$MYSQL"
command -v python3 >/dev/null
command -v openssl >/dev/null
for expected in 'APP_ENV=staging' 'APP_DEBUG=false' \
  'APP_URL=https://oauth-browser-qa.invalid' \
  "DB_DATABASE=$DB" 'DB_CONNECTION=mysql' 'DB_HOST=127.0.0.1' \
  'DB_USERNAME=bambodiaqa_browser'; do
  grep -Fxq "$expected" "$APP/.env"
done
QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL -e "SELECT COUNT(*) FROM users WHERE email='oauth-admin@oauth-browser-qa.invalid' AND role='super_admin' AND account_status='active'")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
echo 'FROZEN_QA_AND_DATABASE_BOUNDARY=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== PUBLIC DNS READ-ONLY CHECKS =========='
echo "CANDIDATE_HOST=$HOST"
echo "PARENT_ZONE=$PARENT"
echo 'DNS_STATUS=OBSERVATIONAL_ONLY'
# The wildcard control is intentionally a nonexistent subdomain. If it
# resolves, an existing wildcard may affect interpretation of a positive A.
PROBE="qa-unallocated-probe-20261112-34b.bambodia.com"
if command -v dig >/dev/null 2>&1; then
  for resolver in 1.1.1.1 8.8.8.8; do
    for qname in "$HOST" "$PROBE"; do
      for rtype in A AAAA CNAME; do
        ANSWER="$(dig @"$resolver" +time=3 +tries=1 +short "$qname" "$rtype" 2>/dev/null || true)"
        if [ -z "$ANSWER" ]; then
          echo "DNS_RESULT resolver=$resolver name=$qname type=$rtype answer=NONE_OR_UNREACHABLE"
        else
          echo "DNS_RESULT resolver=$resolver name=$qname type=$rtype answer=PRESENT"
          printf '%s\n' "$ANSWER" | head -n 8 | sed 's/^/DNS_VALUE /'
        fi
      done
    done
  done
  for type in NS CAA; do
    ANSWER="$(dig @1.1.1.1 +time=3 +tries=1 +short "$PARENT" "$type" 2>/dev/null || true)"
    if [ -n "$ANSWER" ]; then
      echo "PARENT_DNS_TYPE_$type=PRESENT"
      printf '%s\n' "$ANSWER" | head -n 8 | sed 's/^/PARENT_DNS_VALUE /'
    else
      echo "PARENT_DNS_TYPE_$type=NONE_OR_UNREACHABLE"
    fi
  done
else
  echo 'DIG=UNAVAILABLE'
  if command -v getent >/dev/null 2>&1; then
    if getent ahosts "$HOST" >/dev/null 2>&1; then
      echo 'SYSTEM_RESOLVER_CANDIDATE=PRESENT'
    else
      echo 'SYSTEM_RESOLVER_CANDIDATE=NONE_OR_UNREACHABLE'
    fi
  fi
  echo 'INDEPENDENT_DNS_RESOLVERS=NOT_TESTED'
fi

echo '========== NGINX CONFIGURATION COLLISION CHECK =========='
NGINX=''
if [ -x /www/server/nginx/sbin/nginx ]; then
  NGINX='/www/server/nginx/sbin/nginx'
elif command -v nginx >/dev/null 2>&1; then
  NGINX="$(command -v nginx)"
fi
if [ -z "$NGINX" ]; then
  echo 'NGINX_CONFIG_SCAN=UNAVAILABLE'
  exit 34
fi
CONF="$(mktemp /root/bambodia-step34b-nginx.XXXXXXXX)"
ERR="$(mktemp /root/bambodia-step34b-errors.XXXXXXXX)"
cleanup() {
  rm -f -- "$CONF" "$ERR"
}
trap cleanup EXIT
if "$NGINX" -T >"$CONF" 2>"$ERR"; then
  echo 'NGINX_ACTIVE_CONFIGURATION_DUMP=PASS'
else
  echo 'NGINX_ACTIVE_CONFIGURATION_DUMP=FAIL'
  exit 34
fi

python3 - "$CONF" "$HOST" "$APP" <<'PY'
import fnmatch
import re
import sys

path, host, app = sys.argv[1:]
text = open(path, encoding='utf-8', errors='replace').read()
# nginx -T emits source-file banners. Never echo full config or credentials.
banners = list(re.finditer(r'(?m)^# configuration file ([^:\n]+):', text))
directives = list(re.finditer(r'(?m)^\s*server_name\s+([^;]+);', text))
matches = []
regex_count = 0
for directive in directives:
    pre = [m for m in banners if m.start() < directive.start()]
    source = pre[-1].group(1) if pre else 'unknown'
    raw = re.sub(r'#[^\n]*', '', directive.group(1))
    terms = raw.split()
    if any(t.startswith('~') for t in terms):
        regex_count += 1
        matches.append(('REGEX_SERVER_NAME_REVIEW', source))
        continue
    for token in terms:
        value = token.lower().rstrip('.')
        if value == host:
            matches.append(('EXACT_SERVER_NAME', source))
        elif value.startswith('*.') and host.endswith(value[1:]):
            matches.append(('WILDCARD_SERVER_NAME', source))
        elif value.startswith('.') and (host == value[1:] or host.endswith(value)):
            matches.append(('SUFFIX_SERVER_NAME', source))
        elif '*' in value and fnmatch.fnmatchcase(host, value):
            matches.append(('OTHER_WILDCARD_SERVER_NAME', source))

for name, source in matches[:30]:
    print('NGINX_MATCH type='+name+' source='+source)
if len(matches) > 30:
    print('NGINX_ADDITIONAL_MATCHES='+str(len(matches)-30))
exact = sum(name == 'EXACT_SERVER_NAME' for name, _ in matches)
wild = len(matches)-exact
print('EXACT_NGINX_HOST_COLLISIONS='+str(exact))
print('WILDCARD_OR_REGEX_NGINX_REVIEW='+str(wild))
print('NGINX_DEFAULT_SERVER_LISTENERS='+str(
    len(re.findall(r'(?m)^\s*listen\s+[^;]*\bdefault_server\b[^;]*;',text))))
print('FROZEN_QA_PATH_ALREADY_IN_ACTIVE_NGINX='+(
    'YES' if app in text else 'NO'))
print('NGINX_HOSTNAME_SCAN=COMPLETE')
PY

echo '========== EXISTING VHOST AND TLS PATHS =========='
for path in \
  "/www/server/panel/vhost/nginx/$HOST.conf" \
  "/www/server/nginx/conf/vhost/$HOST.conf" \
  "/etc/nginx/sites-enabled/$HOST" \
  "/etc/nginx/sites-available/$HOST" \
  "/www/server/panel/vhost/cert/$HOST" \
  "/www/server/panel/vhost/ssl/$HOST" \
  "/etc/letsencrypt/live/$HOST"; do
  if [ -e "$path" ] || [ -L "$path" ]; then
    echo "RESERVED_PATH_EXISTS=$path"
  else
    echo "RESERVED_PATH_ABSENT=$path"
  fi
done
echo 'EXISTING_CERTIFICATE_VALIDITY=NOT_ASSERTED'
echo 'PUBLIC_HOST_CONTROL_AND_DNS_OWNERSHIP=NOT_ASSERTED'
echo 'PROVISIONING_DECISION=REQUIRES_REVIEW'
echo 'DNS_WRITES=NONE'
echo 'NGINX_OR_CERT_WRITES=NONE'
echo 'DATABASE_WRITES=NONE'
echo 'GITHUB_MAIN_OR_PRODUCTION_WRITES=NONE'
echo 'STEP34B1_READ_ONLY_COLLISION_INVENTORY=COMPLETE'
