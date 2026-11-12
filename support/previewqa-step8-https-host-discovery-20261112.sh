#!/usr/bin/env bash
# Read-only aaPanel discovery for candidate Bambodia preview visibility QA HTTPS hostname.
# Checks DNS, nginx names and host readiness. Does not provision DNS, a vhost or TLS.
set -Eeuo pipefail
set +x
umask 077

HOST='preview-qa.bambodia.com'
QA='/var/lib/bambodia-preview-qa-20261112'
RECOVER='/var/lib/bambodia-preview-restore-20261112'
LOCK="$RECOVER/storage/app/installed.lock"

fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

echo '========== STEP 8 — READ-ONLY QA HOSTNAME DISCOVERY =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for cmd in nginx git awk ss grep stat; do
  command -v "$cmd" >/dev/null || fail "required_binary:$cmd"
done
[[ -d "$QA/.git" && ! -L "$QA" ]] || fail qa_source_absent
[[ "$(git -C "$QA" rev-parse HEAD)" = '584e1b29697fd87227dc42c987342fa405ea5c09' ]] || fail qa_source_wrong_commit
[[ -d "$RECOVER" && ! -L "$RECOVER" && -s "$LOCK" && ! -L "$LOCK" ]] || fail recovered_app_unavailable
[[ -f "$RECOVER/.env" && ! -L "$RECOVER/.env" ]] || fail recovered_env_unavailable
[[ "$(stat -c '%U:%G:%a' "$LOCK")" = 'bambodia_pvrsqa:bambodia_pvrsqa:600' ]] || fail recovered_lock_permissions

echo "CANDIDATE_QA_HOSTNAME=$HOST"
echo 'HOSTNAME_STATUS=DISCOVERY_ONLY_NOT_APPROVED'
echo 'EXISTING_RECOVERY_APPLICATION=PRESERVED'

echo '========== PUBLIC DNS READ-ONLY LOOKUP =========='
if command -v dig >/dev/null 2>&1; then
  for type in A AAAA CNAME; do
    answer="$(dig +time=2 +tries=1 +short "$HOST" "$type" | sed '/^$/d' | head -n 8)"
    if [[ -n "$answer" ]]; then
      printf 'DNS_%s=RESULTS_PRESENT\n' "$type"
      printf '%s\n' "$answer" | sed 's/^/DNS_ANSWER=/'
    else
      printf 'DNS_%s=NO_RESULT_FROM_RESOLVER\n' "$type"
    fi
  done
else
  if command -v getent >/dev/null 2>&1; then
    result="$(getent ahosts "$HOST" 2>/dev/null | awk '{print $1}' | sort -u | head -n 8 || true)"
    if [[ -n "$result" ]]; then
      echo 'DNS_RESOLUTION=RESULTS_PRESENT'
      printf '%s\n' "$result" | sed 's/^/DNS_ANSWER=/'
    else
      echo 'DNS_RESOLUTION=NO_RESULT_FROM_RESOLVER'
    fi
  else
    echo 'DNS_RESOLUTION=TOOL_UNAVAILABLE'
  fi
fi

echo '========== CURRENT NGINX CONFIGURATION =========='
if nginx -t -q >/dev/null 2>&1; then
  echo 'NGINX_CONFIG_SYNTAX=PASS'
else
  fail nginx_config_syntax_existing_problem
fi

# Process the active nginx config in memory. Never print full configuration
# (which may contain private paths or secrets). Report matching file and type.
nginx -T 2>/dev/null | awk -v host="$HOST" '
  /^# configuration file / {
    file=$4
    sub(/:$/, "", file)
  }
  /^[ \t]*server_name[ \t]/ {
    line=$0
    sub(/#.*/, "", line)
    gsub(/[;\t]/, " ", line)
    n=split(line, parts, / +/)
    for (i=1; i<=n; i++) {
      val=parts[i]
      if (val==host) {
        printf "NGINX_EXACT_NAME=FOUND FILE=%s\n", file
        exact++
      }
      if (val=="*.bambodia.com" || val==".bambodia.com") {
        printf "NGINX_WILDCARD_NAME=PRESENT FILE=%s\n", file
        wildcard++
      }
    }
  }
  END {
    if (exact==0) print "NGINX_EXACT_NAME=NOT_DETECTED"
    if (wildcard==0) print "NGINX_WILDCARD_NAME=NOT_DETECTED"
  }
'

echo '========== AAPANEL SITE FOLDER COLLISION =========='
for target in \
  "/www/wwwroot/$HOST" \
  "/www/server/panel/vhost/nginx/$HOST.conf" \
  "/www/server/panel/vhost/ssl/$HOST"; do
  if [[ -e "$target" || -L "$target" ]]; then
    echo "SITE_COLLISION=EXISTS PATH=$target"
  else
    echo "SITE_COLLISION=NOT_PRESENT PATH=$target"
  fi
done

echo '========== HTTP/HTTPS LISTENERS =========='
for port in 80 443 18097; do
  if [[ -n "$(ss -H -ltn "sport = :$port")" ]]; then
    echo "LISTENER_$port=PRESENT"
  else
    echo "LISTENER_$port=NOT_DETECTED"
  fi
done

echo '========== QA HOST DISCOVERY RESULT =========='
echo 'DNS_OR_NGINX_CHANGED=NO'
echo 'VHOST_OR_TLS_CREATED=NO'
echo 'DATABASE_CHANGES=NONE'
echo 'QA_PREVIEW_WRITES=NOT_ENABLED'
echo 'HTTPS_HOST_ACCEPTANCE=NOT_PERFORMED'
echo 'STEP8_DISCOVERY=COMPLETE'
