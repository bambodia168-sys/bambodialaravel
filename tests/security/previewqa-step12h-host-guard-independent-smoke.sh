#!/usr/bin/env bash
# CI-only source + exact config-difference regression for independent Step12H.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12h-independent-host-guard-audit-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12H_INDEPENDENT_ORIGIN_HOST_AUDIT=PASS'" "$SRC")" -eq 1
grep -Fq 'fail installed_origin_differs_from_guard_only_change' "$SRC"
grep -Fq 'cmp -s "$TMP/expected.conf" "$HTTP_CONF"' "$SRC"
grep -Fq 'fail unexpected_http_socket_mode' "$SRC"
grep -Fq 'unprivileged_socket_connect_permitted:' "$SRC"
grep -Fq 'fail ingress_can_read_private_laravel_env' "$SRC"
grep -Fq 'unknown_host_not_421:' "$SRC"
grep -Fq 'missing_host_not_blocked:' "$SRC"
grep -Fq 'SYNTHETIC_QA_DB_AND_PREVIEW_WRITES_OFF=PASS' "$SRC"
grep -Fq 'NO_CONFIG_OR_SERVICE_MUTATIONS=YES' "$SRC"
if grep -Eq '^[[:space:]]*systemctl[[:space:]]+(start|stop|restart|enable|disable|reload)' "$SRC"; then
  echo 'BLOCKED=audit_mutates_service' >&2; exit 2
fi
if grep -Fq 'BAMBODIA_QA_HOST_GUARD_APPROVAL' "$SRC"; then
  echo 'BLOCKED=installer_approval_in_readonly_audit' >&2; exit 2
fi

tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
ANCHOR='        server_name preview-qa.bambodia.com localhost 127.0.0.1;'
cat > "$tmp/original" <<'BASE'
http {
    server {
        server_name preview-qa.bambodia.com localhost 127.0.0.1;
        location / { return 200; }
    }
}
BASE
cat > "$tmp/guard" <<'GUARD'
        # Canonical QA origin only. Not a replacement for Cloudflare Access.
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
' "$tmp/original" > "$tmp/expected"
cp "$tmp/expected" "$tmp/installed"
cmp -s "$tmp/expected" "$tmp/installed"
printf '    location = /backdoor { return 200; }\n' >> "$tmp/installed"
if cmp -s "$tmp/expected" "$tmp/installed"; then
  echo 'BLOCKED=extra_installed_nginx_config_not_detected' >&2; exit 2
fi
echo 'STEP12H_INDEPENDENT_HOST_GUARD_REGRESSION=PASS'
