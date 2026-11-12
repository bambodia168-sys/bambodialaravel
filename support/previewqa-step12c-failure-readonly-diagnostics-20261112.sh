#!/usr/bin/env bash
# QA-only Step 12C incomplete-apply inspection. No service restart, no writes.
set -Eeuo pipefail
set +x
umask 077

fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

echo '========== STEP 12C — READ-ONLY FAILURE DIAGNOSTICS =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for command_name in systemctl journalctl stat id getent grep find wc \
  runuser namei; do
  command -v "$command_name" >/dev/null || fail "missing_command:$command_name"
done

APP='/var/lib/bambodia-preview-restore-20261112'
HTTP_ROOT='/var/lib/bambodia-previewqa-http-static-20261112'
HTTP_SVC='bambodia-previewqa-http.service'
FPM_SVC='bambodia-previewqa-fpm.service'
HTTP_UNIT='/etc/systemd/system/bambodia-previewqa-http.service'
HTTP_CONF='/etc/bambodia-previewqa-http/nginx.conf'
FPM_CONF='/etc/bambodia-previewqa/php-fpm.conf'
FPM_SOCK='/run/bambodia-previewqa/php-fpm.sock'
HTTP_SOCK='/run/bambodia-previewqa-http/http.sock'
BACKUP='/root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf'
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'

[[ -d "$APP" && ! -L "$APP" &&
  "$(stat -c '%U:%G:%a' "$APP")" == "$WORKER:$WORKER:700" ]] ||
  fail recovered_qa_app_permissions_changed
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" == "$WORKER:$WORKER:600" ]] ||
  fail recovered_qa_env_permissions_changed

echo '========== ISOLATED QA SERVICES — CURRENT STATE =========='
for svc in "$HTTP_SVC" "$FPM_SVC"; do
  printf 'UNIT=%s\n' "$svc"
  systemctl show "$svc" \
    -p LoadState -p ActiveState -p SubState -p Result \
    -p ExecMainCode -p ExecMainStatus \
    -p FragmentPath -p User -p Group \
    --no-pager || true
  printf 'ENABLED_STATE=%s\n' "$(systemctl is-enabled "$svc" 2>/dev/null || true)"
done

echo '========== PRIVATE INSTALLATION FILES =========='
for p in "$HTTP_ROOT" "$HTTP_ROOT/public" "$HTTP_CONF" \
         "$HTTP_UNIT" "$FPM_CONF" "$BACKUP"; do
  if [[ -L "$p" ]]; then
    printf 'PATH_SYMLINK_BLOCKED=%s\n' "$p"
  elif [[ -e "$p" ]]; then
    printf 'PATH=%s OWNER_MODE=%s TYPE=%s\n' "$p" \
      "$(stat -c '%U:%G:%a' "$p")" "$(stat -c '%F' "$p")"
  else
    printf 'PATH_MISSING=%s\n' "$p"
  fi
done
if [[ -d "$HTTP_ROOT/public" && ! -L "$HTTP_ROOT/public" ]]; then
  printf 'PRIVATE_STATIC_FILE_COUNT=%s\n' \
    "$(find "$HTTP_ROOT/public" -type f | wc -l | tr -d ' ')"
  for restricted in "$HTTP_ROOT/public/index.php" "$HTTP_ROOT/public/.htaccess"; do
    if [[ -e "$restricted" || -L "$restricted" ]]; then
      echo 'BLOCKED=PHP_OR_APACHE_CONFIG_EXPOSED_IN_STATIC_COPY'
    fi
  done
fi

echo '========== DEDICATED SOCKET ACCESS POLICY =========='
if getent passwd "$INGRESS" >/dev/null; then
  echo 'DEDICATED_HTTP_ACCOUNT=EXISTS'
else
  echo 'DEDICATED_HTTP_ACCOUNT=MISSING'
fi
if getent group "$INGRESS" >/dev/null; then
  echo 'DEDICATED_HTTP_GROUP=EXISTS'
else
  echo 'DEDICATED_HTTP_GROUP=MISSING'
fi
if id -nG www 2>/dev/null | tr ' ' '\n' | grep -Fxq "$INGRESS"; then
  echo 'SECURITY_WARNING_SHARED_WWW_IN_QA_INGRESS_GROUP=YES'
else
  echo 'SHARED_WWW_IN_QA_INGRESS_GROUP=NO'
fi
if [[ -f "$FPM_CONF" && ! -L "$FPM_CONF" ]]; then
  for directive in "listen.owner = $WORKER" "listen.group = $INGRESS" \
                   'listen.mode = 0660'; do
    if grep -Fxq "$directive" "$FPM_CONF"; then
      echo "FPM_DIRECTIVE_PRESENT=$directive"
    else
      echo "FPM_DIRECTIVE_MISSING=$directive"
    fi
  done
fi
for sock in "$FPM_SOCK" "$HTTP_SOCK"; do
  if [[ -S "$sock" ]]; then
    printf 'SOCKET=%s OWNER_MODE=%s\n' "$sock" \
      "$(stat -c '%U:%G:%a' "$sock")"
  elif [[ -e "$sock" || -L "$sock" ]]; then
    echo "SOCKET_UNEXPECTED_FILE=$sock"
  else
    echo "SOCKET_ABSENT_AFTER_QA_SERVICE_STOP=$sock"
  fi
done

echo '========== DEDICATED HTTP SERVICE ERROR MESSAGES =========='
# The journal is bounded and redacted at the operator console. Never print
# environment variables, tokens, full configuration contents, or private media.
journalctl -u "$HTTP_SVC" --no-pager -n 65 -o short-iso 2>&1 |
  grep -Ei 'nginx|permission|denied|failed|error|emerg|alert|bind|listen|status|socket|pid|exited|starting|started|stopping|stopped|result|address|directory|read-only' |
  sed -E 's/([A-Za-z_]*(SECRET|TOKEN|PASSWORD|API_KEY|CREDENTIAL)[A-Za-z_]*=)[^ ]+/\1[REDACTED]/Ig' |
  tail -n 40 || true

echo '========== DEDICATED FPM STOP/RESTART MESSAGES =========='
journalctl -u "$FPM_SVC" --no-pager -n 35 -o short-iso 2>&1 |
  grep -Ei 'started|stopped|failed|error|socket|permission|ready|exited|status' |
  tail -n 15 || true

echo '========== RECOVERY BOUNDARIES =========='
echo 'NO_SERVICES_RESTARTED_OR_ENABLED=YES'
echo 'NO_NGINX_FPM_CONFIG_WRITTEN=YES'
echo 'NO_DNS_TLS_CLOUDFLARE_OR_PRODUCTION_CHANGES=YES'
echo 'DO_NOT_RERUN_STEP12C_APPLY=YES'
echo 'STEP12C_FAILURE_DIAGNOSTICS=COMPLETE'
