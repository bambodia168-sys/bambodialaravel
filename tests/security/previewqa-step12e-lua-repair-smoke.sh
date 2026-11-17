#!/usr/bin/env bash
# CI-only synthetic smoke: validate exact Lua directive/ExecStart transformation.
# Does NOT require Nginx, a real server, services or a database.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SRC='support/previewqa-step12e-private-nginx-lua-recovery-20261112.sh'
test -f "$SRC"
test "$(grep -Fc "echo 'STEP12E_CHECK=PASS'" "$SRC")" -eq 1
test "$(grep -Fc "echo 'STEP12E=PASS'" "$SRC")" -eq 1
grep -Fq "lua_package_path" "$SRC"
grep -Fq "'daemon off;'" "$SRC"
grep -Fq -- "-e stderr" "$SRC"
grep -Fq "runuser -u" "$SRC"
if grep -Fq 'lua_load_resty_core off;' "$SRC"; then
  echo 'BLOCKED=disabled_required_lua_module' >&2; exit 2
fi
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
cat > "$tmp/nginx.conf" <<'NGINX'
worker_processes 1;
http {
    error_log stderr warn;
    server { listen unix:/tmp/qa-example.sock; }
}
NGINX
LUA_DIRECTIVE='    lua_package_path "/www/server/nginx/lib/lua/?.lua;;";'
awk -v directive="$LUA_DIRECTIVE" '
  $0 == "http {" { print; print directive; count++; next }
  { print }
  END { if (count != 1) exit 2 }
' "$tmp/nginx.conf" > "$tmp/nginx.after.conf"
test "$(grep -Fxc "$LUA_DIRECTIVE" "$tmp/nginx.after.conf")" -eq 1
grep -Fxq '    server { listen unix:/tmp/qa-example.sock; }' "$tmp/nginx.after.conf"
printf 'http {\nhttp {\n' > "$tmp/invalid.conf"
if awk -v directive="$LUA_DIRECTIVE" '
  $0 == "http {" { print; print directive; count++; next }
  { print }
  END { if (count != 1) exit 2 }
' "$tmp/invalid.conf" >/dev/null; then
  echo 'BLOCKED=multiple_http_contexts_not_rejected' >&2; exit 2
fi
BEFORE="ExecStart=/usr/bin/nginx -c /etc/bambodia-previewqa-http/nginx.conf -g 'daemon off;'"
AFTER="ExecStart=/usr/bin/nginx -e stderr -c /etc/bambodia-previewqa-http/nginx.conf -g 'daemon off;'"
printf '[Service]\n%s\nUser=bambodia_pvhttp\n' "$BEFORE" > "$tmp/original.service"
awk -v before="$BEFORE" -v after="$AFTER" '
  $0 == before { print after; count++; next }
  { print }
  END { if (count != 1) exit 2 }
' "$tmp/original.service" > "$tmp/new.service"
grep -Fxq "$AFTER" "$tmp/new.service"
grep -Fxq 'User=bambodia_pvhttp' "$tmp/new.service"
echo 'STEP12E_LUA_RECOVERY_REGRESSION=PASS'
