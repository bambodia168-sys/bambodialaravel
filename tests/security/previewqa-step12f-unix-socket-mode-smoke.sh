#!/usr/bin/env bash
# CI synthetic regression: Nginx intentionally chmods bound Unix sockets 0666.
# Effective isolation depends on a private directory and denied connections.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SOURCE='support/previewqa-step12f-local-ingress-audit-20261112.sh'
test -f "$SOURCE"
test "$(grep -Fc "echo 'STEP12F_LOCAL_INGRESS_AUDIT=PASS'" "$SOURCE")" -eq 1
test "$(grep -Fc "echo 'PUBLIC_HEALTH_AND_LOGIN_HEADERS=PASS'" "$SOURCE")" -eq 1
grep -Fq 'HTTP_SOCKET_MODE=666_NGINX_DEFAULT_PRIVATE_0700_PARENT' "$SOURCE"
grep -Fq 'fail private_HTTP_socket_directory_changed' "$SOURCE"
grep -Fq 'fail shared_www_could_connect:' "$SOURCE"
grep -Fq 'fail nobody_could_connect_private_HTTP_socket' "$SOURCE"
grep -Fq 'fail unexpected_HTTP_unix_socket_mode' "$SOURCE"
grep -Fq 'HTTP_unix_socket_owner_changed' "$SOURCE"

accepted_mode() {
  local mode="$1" parent="$2" cross_user_can_connect="$3"
  [[ "$parent" == '700' && "$cross_user_can_connect" == no ]] || return 1
  case "$mode" in 600|660|666) return 0;; *) return 1;; esac
}
accepted_mode 666 700 no
accepted_mode 600 700 no
accepted_mode 660 700 no
if accepted_mode 666 755 no; then
  echo 'BLOCKED=public_parent_accepted' >&2; exit 2
fi
if accepted_mode 666 700 yes; then
  echo 'BLOCKED=nonprivileged_connection_accepted' >&2; exit 2
fi
if accepted_mode 777 700 no; then
  echo 'BLOCKED=unexpected_socket_mode_accepted' >&2; exit 2
fi
echo 'STEP12F_SOCKET_MODE_REGRESSION=PASS'
