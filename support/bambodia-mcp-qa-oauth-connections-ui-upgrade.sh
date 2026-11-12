#!/usr/bin/env bash
# QA-only OAuth Connections page upgrade; no OAuth logic or DB changes.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == --apply ]] || { echo "Usage: bash script --apply" >&2; exit 64; }
APP=/var/lib/bambodia-oauth-https-qa
PHP=/www/server/php/85/bin/php
VHOST=/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf
REPO=bambodia168-sys/bambodialaravel
FEATURE=b50f2bc20ed0bdc7d7ce683297f4613d3a51a407
VIEW=resources/views/developer/oauth-connections.blade.php
CSS=public/css/mcp-oauth-connections.css
VIEW_BEFORE=cac991e62ee1d0956268b018f7585375eb63d20e
VIEW_AFTER=a133fcc2ef8d02ea5f0e99a25424fefb4c85df98
CSS_AFTER=25134071825b6fa49ad0a593bb0651bdb80eced9
fail(){ echo "MCP_OAUTH_UI_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for tool in gh git stat python3 runuser cp mktemp install; do
  command -v "$tool" >/dev/null || fail "MISSING_$tool"
done
[[ -x "$PHP" && -d "$APP" && ! -L "$APP" &&
   "$(stat -c %u:%g "$APP")" == "0:$(id -g www)" ]] ||
  fail QA_INSTALLATION_ROOT_CHANGED
[[ -f "$APP/storage/app/installed.lock" && ! -L "$APP/storage/app/installed.lock" ]] ||
  fail QA_INSTALLATION_NOT_SEALED
[[ -f "$APP/resources/views/layouts/workspace.blade.php" &&
   ! -L "$APP/resources/views/layouts/workspace.blade.php" ]] ||
  fail QA_WORKSPACE_LAYOUT_MISSING
grep -Fq "@stack('workspace-head')" "$APP/resources/views/layouts/workspace.blade.php" ||
  fail SHARED_WORKSPACE_STYLESHEET_HOOK_MISSING
[[ -f "$APP/$VIEW" && ! -L "$APP/$VIEW" &&
   "$(git hash-object "$APP/$VIEW")" == "$VIEW_BEFORE" ]] ||
  fail QA_OAUTH_CONNECTION_VIEW_HAS_UNREVIEWED_CHANGES
[[ ! -e "$APP/$CSS" && ! -L "$APP/$CSS" ]] ||
  fail QA_OAUTH_CONNECTION_STYLESHEET_ALREADY_EXISTS
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail QA_HTTPS_VHOST_MISSING
python3 - "$VHOST" <<'PY'
import pathlib,re,sys
s="\n".join(line for line in pathlib.Path(sys.argv[1]).read_text().splitlines()
            if not line.lstrip().startswith("#"))
if not re.search(r'\bserver_name\s+[^;]*\boauth-qa\.bambodia\.com\b',s):
    raise SystemExit("MCP_OAUTH_UI_BLOCKED=WRONG_QA_DOMAIN")
if s.count("root /var/lib/bambodia-oauth-https-qa/public;") != 1:
    raise SystemExit("MCP_OAUTH_UI_BLOCKED=WRONG_QA_ROOT")
print("QA_HTTPS_SITE=VERIFIED")
PY

WORK="$(mktemp -d /root/bambodia-mcp-oauth-ui.XXXXXXXX)"
chmod 700 "$WORK"
echo "OAUTH_UI_PRIVATE_BACKUP_DIRECTORY=$WORK"
mkdir -p "$WORK/new/$(dirname "$VIEW")" "$WORK/new/$(dirname "$CSS")"
gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/$VIEW?ref=$FEATURE" > "$WORK/new/$VIEW" ||
  fail OAUTH_VIEW_DOWNLOAD_FAILED
gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/$CSS?ref=$FEATURE" > "$WORK/new/$CSS" ||
  fail OAUTH_CSS_DOWNLOAD_FAILED
[[ "$(git hash-object "$WORK/new/$VIEW")" == "$VIEW_AFTER" &&
   "$(git hash-object "$WORK/new/$CSS")" == "$CSS_AFTER" ]] ||
  fail PINNED_OAUTH_VIEW_OR_STYLESHEET_HASH_MISMATCH
grep -Fq "route('developer.oauth.revoke'" "$WORK/new/$VIEW" ||
  fail OAUTH_REVOCATION_ACTION_MISSING
grep -Fq "route('developer.mcp.index')" "$WORK/new/$VIEW" ||
  fail MCP_CONNECTION_ENTRY_POINT_MISSING
grep -Fq "mcp-oauth-connections.css" "$WORK/new/$VIEW" ||
  fail CSS_PAGE_LINK_MISSING
mkdir -p "$WORK/original/$(dirname "$VIEW")"
cp -p "$APP/$VIEW" "$WORK/original/$VIEW"
echo "QA_OAUTH_UI_SOURCE_AND_BACKUP=VERIFIED"

[[ -c /dev/tty ]] || fail INTERACTIVE_APPROVAL_REQUIRED
printf 'Type APPLY-OAUTH-CONNECTIONS-UI to change ONLY the QA OAuth Connections page: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == APPLY-OAUTH-CONNECTIONS-UI ]] || fail OPERATOR_DECLINED
unset APPROVAL

ATTEMPTED=0
SUCCESS=0
rollback(){
  code="$?"
  trap - EXIT
  if [[ "$ATTEMPTED" == 1 && "$SUCCESS" != 1 ]]; then
    cp -p "$WORK/original/$VIEW" "$APP/$VIEW" || true
    if [[ -f "$APP/$CSS" && ! -L "$APP/$CSS" ]]; then rm -f "$APP/$CSS"; fi
    echo "MCP_OAUTH_UI_ROLLBACK=ATTEMPTED" >&2
  fi
  exit "$code"
}
trap rollback EXIT
ATTEMPTED=1
install -D -o root -g www -m 0640 "$WORK/new/$VIEW" "$APP/$VIEW" ||
  fail QA_OAUTH_VIEW_INSTALL_FAILED
install -D -o root -g www -m 0640 "$WORK/new/$CSS" "$APP/$CSS" ||
  fail QA_OAUTH_CSS_INSTALL_FAILED
[[ "$(git hash-object "$APP/$VIEW")" == "$VIEW_AFTER" &&
   "$(git hash-object "$APP/$CSS")" == "$CSS_AFTER" ]] ||
  fail DEPLOYED_OAUTH_FILES_HASH_MISMATCH
runuser -u www -- test -r "$APP/$VIEW" || fail QA_WWW_CANNOT_READ_VIEW
runuser -u www -- test -r "$APP/$CSS" || fail QA_WWW_CANNOT_READ_CSS
(
  cd "$APP"
  runuser -u www -- "$PHP" artisan view:clear --no-interaction --no-ansi
) || fail QA_OAUTH_BLADE_CACHE_CLEAR_FAILED
SUCCESS=1
trap - EXIT
echo "MCP_OAUTH_CONNECTIONS_UI_UPGRADE=PASS"
echo "QA_OAUTH_VIEW_BACKUP=$WORK/original/$VIEW"
echo "OAUTH_CONTROLLER_AND_TOKEN_LOGIC=UNCHANGED"
echo "MCP_READ_WRITE_FUNCTIONS=UNCHANGED"
echo "QA_DATABASE_AND_PASSPORT_KEYS=UNCHANGED"
echo "NGINX_AND_PRODUCTION=UNCHANGED"
