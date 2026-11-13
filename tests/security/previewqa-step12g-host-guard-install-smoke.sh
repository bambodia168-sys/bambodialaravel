#!/usr/bin/env bash
# CI-only static regression; never calls systemctl or touches aaPanel.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12g-origin-host-guard-install-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12G_HOST_GUARD=PASS'" "$SRC")" -eq 1
test "$(grep -Fc "echo 'STEP12G_HOST_GUARD_INSTALL_PREFLIGHT=PASS'" "$SRC")" -eq 1
grep -Fq "MODE=" "$SRC"
grep -Fq 'APPROVE_PRIVATE_QA_HOST_GUARD_ONLY' "$SRC"
grep -Fq 'return 421;' "$SRC"
grep -Fq 'chmod 770 "$TMP"' "$SRC"
grep -Fq "fail one_time_recovery_backup_path_not_free" "$SRC"
grep -Fq 'fail synthetic_QA_database_or_flag_changed' "$SRC"
grep -Fq 'systemctl stop bambodia-previewqa-http.service' "$SRC"
grep -Fq 'systemctl start bambodia-previewqa-http.service' "$SRC"
grep -Fq 'QA_FPM=UNCHANGED' "$SRC"
grep -Fq 'QA_HTTP_ORIGINAL_CONFIG=RESTORED' "$SRC"
grep -Fq 'CRITICAL_QA_HTTP_CONFIG_ROLLBACK_FAILED_INSPECT_ROOT_BACKUP' "$SRC"
grep -Fq 'CRITICAL_QA_HTTP_STILL_ACTIVE_MANUAL_ISOLATION_REQUIRED' "$SRC"

grep -Fq 'ACCEPTED=yes' "$SRC"
grep -Fq 'fail shared_www_accessible_private_http_socket' "$SRC"
grep -Fq 'missing_host_not_rejected:' "$SRC"
grep -Fq 'UNRECOGNIZED_LOCALHOST_IP_AND_PORT_QUALIFIED_HOSTS=HTTP_421' "$SRC"
if grep -Fq 'systemctl restart nginx' "$SRC"; then
  echo 'BLOCKED=shared_nginx_restart_detected' >&2; exit 2
fi
if grep -Fq 'systemctl restart bambodia-previewqa-fpm' "$SRC"; then
  echo 'BLOCKED=dedicated_php_fpm_restart_detected' >&2; exit 2
fi
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
ANCHOR='        server_name preview-qa.bambodia.com localhost 127.0.0.1;'
printf '%s\n' 'http {' '    server {' "$ANCHOR" '    }' '}' > "$tmp/baseline"
cat > "$tmp/guard" <<'GUARD'
        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {
            return 421;
        }
GUARD
awk -v anchor="$ANCHOR" -v insert="$tmp/guard" '
  $0 == anchor {
    print
    while ((getline line < insert) > 0) print line
    close(insert)
    count++
    next
  }
  { print }
  END { if (count != 1) exit 2 }
' "$tmp/baseline" > "$tmp/restricted"
test "$(grep -Fxc '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$tmp/restricted")" -eq 1
test "$(grep -Fxc '            return 421;' "$tmp/restricted")" -eq 1
echo 'STEP12G_GUARDED_INSTALL_REGRESSION=PASS'
