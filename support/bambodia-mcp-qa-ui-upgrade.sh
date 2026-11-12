#!/usr/bin/env bash
# QA-only visual patch. No DB, OAuth, npm, Composer, Nginx or production writes.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == --apply ]] || { echo 'Usage: bash script --apply' >&2; exit 64; }
APP=/var/lib/bambodia-oauth-https-qa
PHP=/www/server/php/85/bin/php
VHOST=/www/server/panel/vhost/nginx/oauth-qa.bambodia.com.conf
REPO=bambodia168-sys/bambodialaravel
FEATURE=fe87ee0987fa77e15ada21504630e123b6f1e198
VIEW=resources/views/developer/mcp-connection.blade.php
LAYOUT=resources/views/layouts/workspace.blade.php
CSS=public/css/mcp-connection-center.css
fail(){ echo "MCP_QA_UI_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for c in gh git stat python3 runuser mktemp install cp; do
  command -v "$c" >/dev/null || fail "MISSING_$c"
done
[[ -x "$PHP" && -d "$APP" && ! -L "$APP" &&
   "$(stat -c %u:%g "$APP")" == "0:$(id -g www)" ]] || fail PRIVATE_QA_APP_CHANGED
[[ -f "$APP/storage/app/installed.lock" && ! -L "$APP/storage/app/installed.lock" ]] ||
  fail QA_INSTALLATION_NOT_SEALED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail QA_NGINX_VHOST_MISSING
python3 - "$VHOST" <<'PY'
import pathlib,re,sys
s="\n".join(line for line in pathlib.Path(sys.argv[1]).read_text().splitlines()
            if not line.lstrip().startswith("#"))
if not re.search(r'\bserver_name\s+[^;]*\boauth-qa\.bambodia\.com\b',s):
    raise SystemExit("MCP_QA_UI_BLOCKED=WRONG_QA_VHOST")
if s.count("root /var/lib/bambodia-oauth-https-qa/public;")!=1:
    raise SystemExit("MCP_QA_UI_BLOCKED=WRONG_QA_ROOT")
print("QA_HTTPS_SITE=VERIFIED")
PY
[[ -f "$APP/$VIEW" && ! -L "$APP/$VIEW" &&
  "$(git hash-object "$APP/$VIEW")" == 3e8fecf6c045c0cb64ece39470c5764a6b400228 ]] ||
  fail LIVE_QA_VIEW_CHANGED
[[ -f "$APP/$LAYOUT" && ! -L "$APP/$LAYOUT" &&
  "$(git hash-object "$APP/$LAYOUT")" == 349c2ef792cb232d3e9e3f94953eb50cdd37aace ]] ||
  fail LIVE_QA_LAYOUT_CHANGED
[[ ! -e "$APP/$CSS" && ! -L "$APP/$CSS" ]] || fail NEW_CSS_ALREADY_EXISTS

WORK="$(mktemp -d /root/bambodia-mcp-qa-ui.XXXXXXXX)"
chmod 700 "$WORK"
echo "QA_UI_BACKUP_LOCATION=$WORK"
for target in "$VIEW" "$LAYOUT" "$CSS"; do
  case "$target" in
    "$VIEW") expected=a8a40782a5e0489ef1365cc58a684ef5afd892d2 ;;
    "$LAYOUT") expected=c2f30d75100aea75eb4e2fa063b2e423218bd010 ;;
    "$CSS") expected=c63fe6236b95d13b563ed43081ad03ab3a3f62cf ;;
    *) fail UNEXPECTED_DEPLOY_TARGET ;;
  esac
  mkdir -p "$WORK/new/$(dirname "$target")"
  gh api -H "Accept: application/vnd.github.raw+json" \
    "repos/$REPO/contents/$target?ref=$FEATURE" > "$WORK/new/$target" ||
    fail "GITHUB_DOWNLOAD_FAILED_$target"
  [[ "$(git hash-object "$WORK/new/$target")" == "$expected" ]] ||
    fail "PINNED_FILE_INTEGRITY_FAILED_$target"
done
grep -Fq "@stack('workspace-head')" "$WORK/new/$LAYOUT" || fail STYLESHEET_HOOK_MISSING
grep -Fq "route('developer.mcp.check')" "$WORK/new/$VIEW" || fail MCP_READ_FORM_MISSING
grep -Fq "route('developer.mcp.create-draft')" "$WORK/new/$VIEW" || fail MCP_DRAFT_FORM_MISSING
echo "REVIEWED_UI_FILES=VERIFIED"

mkdir -p "$WORK/original/$(dirname "$VIEW")" "$WORK/original/$(dirname "$LAYOUT")"
cp -p "$APP/$VIEW" "$WORK/original/$VIEW"
cp -p "$APP/$LAYOUT" "$WORK/original/$LAYOUT"
[[ -c /dev/tty ]] || fail TTY_REQUIRED
printf 'Type APPLY-BAMBODIA-MCP-UI for the QA-only visual change: ' > /dev/tty
IFS= read -r APPROVAL < /dev/tty
[[ "$APPROVAL" == APPLY-BAMBODIA-MCP-UI ]] || fail OPERATOR_DECLINED
unset APPROVAL

ATTEMPTED=0
SUCCESS=0
on_exit(){
  code="$?"
  trap - EXIT
  if [[ "$ATTEMPTED" == 1 && "$SUCCESS" != 1 ]]; then
    cp -p "$WORK/original/$VIEW" "$APP/$VIEW" || true
    cp -p "$WORK/original/$LAYOUT" "$APP/$LAYOUT" || true
    if [[ -f "$APP/$CSS" && ! -L "$APP/$CSS" ]]; then rm -f "$APP/$CSS"; fi
    echo "MCP_QA_UI=ROLLBACK_ATTEMPTED" >&2
  fi
  exit "$code"
}
trap on_exit EXIT
ATTEMPTED=1
for target in "$VIEW" "$LAYOUT" "$CSS"; do
  case "$target" in
    "$VIEW") expected=a8a40782a5e0489ef1365cc58a684ef5afd892d2 ;;
    "$LAYOUT") expected=c2f30d75100aea75eb4e2fa063b2e423218bd010 ;;
    "$CSS") expected=c63fe6236b95d13b563ed43081ad03ab3a3f62cf ;;
  esac
  install -D -o root -g www -m 0640 "$WORK/new/$target" "$APP/$target" ||
    fail "QA_INSTALL_FILE_FAILED_$target"
  [[ "$(git hash-object "$APP/$target")" == "$expected" ]] ||
    fail "QA_DEPLOYED_BLOB_MISMATCH_$target"
  runuser -u www -- test -r "$APP/$target" ||
    fail "QA_WWW_READ_ACCESS_FAILED_$target"
done
(
  cd "$APP"
  runuser -u www -- "$PHP" artisan view:clear --no-ansi --no-interaction
) || fail QA_BLADE_VIEW_CACHE_CLEAR_FAILED
SUCCESS=1
trap - EXIT
echo "MCP_QA_UI_UPGRADE=PASS"
echo "MCP_QA_UI_BACKUP=$WORK/original"
echo "MCP_QA_READ_AND_DRAFT_ROUTES=UNCHANGED"
echo "MCP_QA_DATABASE_AND_OAUTH_KEYS=UNCHANGED"
echo "PUBLIC_FRONTEND=UNCHANGED"
echo "NGINX=UNCHANGED"
echo "PRODUCTION=UNCHANGED"
