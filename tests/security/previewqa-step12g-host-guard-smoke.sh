#!/usr/bin/env bash
# Synthetic CI test for the fail-closed canonical Host header gate.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12g-origin-host-guard-preflight-20261113.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12G_HOST_GUARD_PREFLIGHT=PASS'" "$SRC")" -eq 1
grep -Fq 'return 421;' "$SRC"
grep -Fq 'UNPRIVILEGED_QA_NGINX_HOST_GUARD_SYNTAX=PASS' "$SRC"
grep -Fq 'UNKNOWN_HOST_BASELINE=HTTP_200_CURRENTLY_UNRESTRICTED' "$SRC"
grep -Fq 'PERSISTENT_WRITES=NONE' "$SRC"
grep -Fq 'chmod 770 "$TMP"' "$SRC"
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
ANCHOR='        server_name preview-qa.bambodia.com localhost 127.0.0.1;'
cat > "$TMP/base.conf" <<'BASE'
worker_processes 1;
events {}
http {
    server {
        listen unix:/tmp/not-activated.sock;
        server_name preview-qa.bambodia.com localhost 127.0.0.1;
        location / { return 200; }
    }
}
BASE
cat > "$TMP/guard" <<'GUARD'
        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {
            return 421;
        }
GUARD
awk -v anchor="$ANCHOR" -v insert="$TMP/guard" '
  $0 == anchor {
    print
    while ((getline line < insert) > 0) print line
    close(insert)
    count++
    next
  }
  { print }
  END { if (count != 1) exit 2 }
' "$TMP/base.conf" > "$TMP/new.conf"
test "$(grep -Fxc '        if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {' "$TMP/new.conf")" -eq 1
test "$(grep -Fxc '            return 421;' "$TMP/new.conf")" -eq 1
test "$(grep -Fxc "$ANCHOR" "$TMP/new.conf")" -eq 1
printf 'http {\n}\n' > "$TMP/bad.conf"
if awk -v anchor="$ANCHOR" -v insert="$TMP/guard" '
  $0 == anchor {
    print
    while ((getline line < insert) > 0) print line
    close(insert)
    count++
    next
  }
  { print }
  END { if (count != 1) exit 2 }
' "$TMP/bad.conf" >/dev/null; then
  echo 'BLOCKED=missing_expected_server_anchor_accepted' >&2; exit 2
fi
echo 'STEP12G_HOST_GUARD_REGRESSION=PASS'
