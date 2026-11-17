#!/usr/bin/env bash
# Independent operator-gated, masked MySQL information_schema inspection ONLY.
# Not a database dump, not a backup and not a deployment tool.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--db-readonly" ]] || {
 echo "Usage: --db-readonly" >&2; exit 64;
}
fail(){ printf 'MCP_DB_METADATA_OPERATOR_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for cmd in gh git python3 sha256sum stat; do
 command -v "$cmd" >/dev/null || fail "REQUIRED_COMMAND_MISSING"
done
REPO="bambodia168-sys/bambodialaravel"
SOURCE_COMMIT="7cf67d5999495468fc398fedc99845c0d1f4702b"
SOURCE_BLOB="b056a5616f52da5725dac18bc1415b65025ddaca"
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
PHP=/www/server/php/85/bin/php
[[ -x "$PHP" && -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
   -f "$APP/storage/app/installed.lock" && -f "$APP/vendor/autoload.php" ]] ||
  fail LIVE_APP_OR_PHP_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" &&
   "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
  fail VHOST_CHANGED
[[ "$(git hash-object "$APP/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
  fail COMPOSER_CHANGED
[[ "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
  fail LOCK_CHANGED
[[ -f "$APP/.env" && ! -L "$APP/.env" ]] || fail ENV_FILE_UNSAFE
[[ ! -e "$APP/bootstrap/cache/config.php" && ! -L "$APP/bootstrap/cache/config.php" ]] ||
 fail CACHED_CONFIG_NOT_REVIEWED
[[ ! -L "$APP/vendor/autoload.php" ]] || fail VENDOR_LINK_UNSAFE

echo "========== PRIVATE MEDIA PERMISSION CLASSIFICATION =========="
python3 - "$APP" <<'PY'
from pathlib import Path
import os,stat,sys
app=Path(sys.argv[1])
root=app/"storage/app/private"
chain=[Path("/"),Path("/www"),Path("/www/wwwroot"),app,
       app/"storage", app/"storage/app", root]
if any(path.is_symlink() for path in chain):
    print("MCP_PRIVATE_PERMISSIONS=BLOCKED_SYMLINK")
    sys.exit(1)
try:
    modes=[path.lstat().st_mode for path in chain]
    if not all(stat.S_ISDIR(mode) for mode in modes):
        raise OSError("non-directory")
    rootmode=modes[-1]
    all_traverse=all(bool(mode & stat.S_IXOTH) for mode in modes)
    for name,bit in (("OTHER_READ",stat.S_IROTH),
                     ("OTHER_WRITE",stat.S_IWOTH),
                     ("OTHER_EXECUTE",stat.S_IXOTH)):
        print("MCP_PRIVATE_ROOT_"+name+"="+
              ("YES" if rootmode & bit else "NO"))
    print("MCP_PRIVATE_ROOT_OTHER_TRAVERSAL_CHAIN="+
          ("ALL_TRAVERSABLE" if all_traverse else "NOT_ALL_TRAVERSABLE"))
    print("MCP_PRIVATE_ACLS_AND_SERVING_RULES=NOT_VERIFIED")
    print("MCP_PRIVATE_PERMISSIONS=METADATA_ONLY")
except OSError:
    print("MCP_PRIVATE_PERMISSIONS=BLOCKED_MISSING_OR_UNREADABLE")
    sys.exit(1)
PY

echo "========== PIN AND LINT READ-ONLY DB METADATA CLIENT =========="
TEMP="$(mktemp -d /root/bambodia-mcp-dbmeta.XXXXXXXX)"
cleanup(){
 if [[ "$TEMP" == /root/bambodia-mcp-dbmeta.* &&
       -d "$TEMP" && ! -L "$TEMP" ]]; then
   rm -rf --one-file-system -- "$TEMP"
 fi
}
trap cleanup EXIT
CLIENT="$TEMP/read-only.php"
gh api -H "Accept: application/vnd.github.raw+json" \
 "repos/$REPO/contents/support/bambodia-mcp-db-metadata-readonly-20261113.php?ref=$SOURCE_COMMIT" > "$CLIENT"
[[ -s "$CLIENT" && "$(git hash-object "$CLIENT")" == "$SOURCE_BLOB" ]] ||
 fail VERIFIED_PHP_SOURCE_MISMATCH
"$PHP" -l "$CLIENT" >/dev/null || fail PHP_LINT_FAILED
echo "MCP_DB_METADATA_CLIENT_INTEGRITY=PASS"

echo "========== EXPLICIT LIVE METADATA ACCESS CONSENT =========="
echo "This ONLY runs SELECT statements against the configured local MySQL schema."
echo "It reads credentials privately from .env without printing them."
echo "It will not change production data, create an archive, or activate MCP."
echo "To authorize this restricted live database connection, type:"
echo "AUDIT-MYSQL-METADATA-READ-ONLY"
printf 'Confirmation: ' > /dev/tty
IFS= read -r ANSWER < /dev/tty
[[ "$ANSWER" == "AUDIT-MYSQL-METADATA-READ-ONLY" ]] ||
 fail OPERATOR_DID_NOT_AUTHORIZE_LIVE_METADATA
unset ANSWER

echo "========== MASKED MYSQL INFORMATION_SCHEMA INSPECTION =========="
env -i PATH="/usr/bin:/bin" HOME="$TEMP" \
 "$PHP" -d display_errors=0 -d log_errors=0 "$CLIENT"
echo "MCP_RELEASE_GATE=BLOCKED"
echo "PRODUCTION_MCP=NOT_ACTIVATED"
