#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=cc7daf0e013059d4d0a8c52e34e52c651b2f348f
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
NGINX=/www/server/nginx/sbin/nginx
DOMAIN=bambodia.com
HTML=public/404.html
BLADE=resources/views/errors/404.blade.php

fail() { printf 'BAMBODIA_404_ERROR=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail 'Use the aaPanel root terminal.'
[[ "$(realpath -e -- "$APP")" == "$APP" && -f "$APP/artisan" && -x "$PHP" ]] ||
    fail 'Expected Bambodia application or PHP binary unavailable.'
[[ "$(realpath -e -- "$VHOST")" == "$VHOST" && -f "$VHOST" ]] ||
    fail 'Expected Bambodia Nginx vhost unavailable.'

cd "$APP"
WORK="$(mktemp -d /root/bambodia-branded-404.XXXXXXXX)"
BACKUP="$(mktemp -d /root/bambodia-branded-404-backup.XXXXXXXX)"
CHANGED=()
CONFIG_CHANGED=0
COMPLETE=0
TEMP=
cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$TEMP" ]]; then rm -f -- "$TEMP"; fi
    if (( ! COMPLETE )); then
        if (( CONFIG_CHANGED )); then
            cp -aT -- "$BACKUP/bambodia.com.conf" "$VHOST" &&
                "$NGINX" -t && "$NGINX" -s reload ||
                printf 'BAMBODIA_404_RESTORE_FAILED=%s\n' "$VHOST" >&2
        fi
        local path
        for path in "${CHANGED[@]}"; do
            if [[ -f "$BACKUP/$path" ]]; then
                cp -aT -- "$BACKUP/$path" "$path" || printf 'BAMBODIA_404_RESTORE_FAILED=%s\n' "$path" >&2
            else
                rm -f -- "$path" || printf 'BAMBODIA_404_RESTORE_FAILED=%s\n' "$path" >&2
            fi
        done
        if (( ${#CHANGED[@]} )); then
            runuser -u www -- "$PHP" artisan view:clear >/dev/null || true
        fi
        printf 'BACKUP=%s\n' "$BACKUP" >&2
    fi
    rm -rf -- "$WORK"
    exit "$status"
}
trap cleanup EXIT

origin() {
    curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "$@"
}
is_branded() {
    local status
    status="$(origin --output "$WORK/response.html" --write-out '%{http_code}' "https://$DOMAIN$1")" &&
        [[ "$status" == 404 ]] && grep -Fq '<title>Page not found · Bambodia</title>' "$WORK/response.html"
}

LICENSE_URL='/licenses?=246398403-regular-license'
[[ "$(origin -o /dev/null -w '%{http_code}' "https://$DOMAIN$LICENSE_URL")" == 200 ]] ||
    fail 'The existing Regular License could not be verified on the local HTTPS origin.'
JSON_URL='/api/bambodia-404-acceptance-missing'
JSON_BEFORE="$(origin -H 'Accept: application/json' -o /dev/null -w '%{http_code} %{content_type}' "https://$DOMAIN$JSON_URL")"

while read -r path hash; do
    [[ "$(realpath -m -- "$path")" == "$APP/$path" && ! -L "$path" ]] ||
        fail "Unexpected path: $path"
    mkdir -p -- "$WORK/$(dirname -- "$path")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$WORK/$path"
    [[ "$(git hash-object -- "$WORK/$path")" == "$hash" ]] || fail "Checksum mismatch: $path"
    if [[ "$path" == *.php ]]; then "$PHP" -l "$WORK/$path"; fi
done <<'FILES'
public/404.html a06cd92384431270e93a2e536304928f37ce611a
resources/views/errors/404.blade.php 84a2436b02df13d81e033f13471a03e81c85cfce
FILES

install -d -m 0755 -- "$(dirname -- "$BLADE")"
for path in "$HTML" "$BLADE"; do
    if [[ -e "$path" ]]; then
        [[ -f "$path" ]] || fail "Expected a regular file: $path"
        if cmp -s -- "$WORK/$path" "$path"; then continue; fi
        cp -a --parents -- "$path" "$BACKUP/"
    fi
    TEMP="$(mktemp "$(dirname -- "$path")/.bambodia-404.XXXXXXXX")"
    cat -- "$WORK/$path" > "$TEMP"
    chmod 0644 "$TEMP"
    CHANGED+=("$path")
    mv -fT -- "$TEMP" "$path"
    TEMP=
done

runuser -u www -- "$PHP" artisan view:clear
MISSING_PAGE='/bambodia-404-acceptance-missing'
MISSING_ASSET='/bambodia-404-acceptance-missing.css'
if ! is_branded '/licenses' || ! is_branded "$MISSING_PAGE" || ! is_branded "$MISSING_ASSET"; then
    [[ -x "$NGINX" ]] || fail 'Expected aaPanel Nginx binary unavailable.'
    "$NGINX" -t
    cp -a -- "$VHOST" "$BACKUP/bambodia.com.conf"
    python3 - "$VHOST" "$WORK/bambodia.com.conf" "$APP" <<'PY'
from pathlib import Path
import re
import sys

source, destination, app = sys.argv[1:]
content = Path(source).read_text()
roots = list(re.finditer(rf'(?m)^(?P<indent>[ \t]*)root[ \t]+{re.escape(app)}/public;[^\n]*$', content))
if len(roots) != 1:
    raise SystemExit('Expected one Bambodia public document root')
root = roots[0]
# Select only the server block that owns the verified document root.
starts = list(re.finditer(r'(?m)^[ \t]*server[ \t]*(?:\n[ \t]*)?\{', content[:root.start()]))
if not starts:
    raise SystemExit('Bambodia server block was not found')
server = starts[-1]
indent = re.match(r'[ \t]*', server[0])[0]
end = re.search(rf'(?m)^{re.escape(indent)}\}}[ \t]*(?:#.*)?$', content[root.end():])
if not end:
    raise SystemExit('Could not locate the end of the Bambodia server block')
end_offset = root.end() + end.start()
section = content[server.start():end_offset]
if re.search(r'(?m)^\s*location\s*=\s*/404\.html\s*\{', section):
    raise SystemExit('An existing exact /404.html location needs inspection before applying')
directives = [match for match in re.finditer(r'(?m)^([ \t]*)error_page[ \t]+([^;\n]+);', content)
              if server.start() <= match.start() < end_offset and '404' in match[2].split()[:-1]]
if len(directives) > 1:
    raise SystemExit('Multiple existing 404 error_page rules need inspection')
if directives:
    rule = directives[0]
    if rule[1] != root['indent']:
        raise SystemExit('A location-level 404 error_page rule needs inspection')
    if not re.fullmatch(r'404\s+(?:=[ \t]*404[ \t]+)?/[^\s;]+', rule[2]):
        raise SystemExit('Existing 404 rule has a combined or non-404 status mapping')
    content = content[:rule.start()] + rule[1] + 'error_page 404 /404.html;' + content[rule.end():]
else:
    line = f'\n{root["indent"]}error_page 404 /404.html;'
    content = content[:root.end()] + line + content[root.end():]
Path(destination).write_text(content)
PY
    TEMP="$(mktemp "$(dirname -- "$VHOST")/.bambodia-404.XXXXXXXX")"
    cat "$WORK/bambodia.com.conf" > "$TEMP"
    chown --reference="$VHOST" "$TEMP"
    chmod --reference="$VHOST" "$TEMP"
    CONFIG_CHANGED=1
    mv -fT -- "$TEMP" "$VHOST"
    TEMP=
    "$NGINX" -t
    "$NGINX" -s reload
    for attempt in {1..10}; do
        if is_branded "$MISSING_ASSET"; then break; fi
        sleep 1
    done
fi

is_branded '/licenses' || fail 'The incomplete license URL did not return branded HTTP 404.'
is_branded "$MISSING_PAGE" || fail 'An unknown web route did not return branded HTTP 404.'
is_branded "$MISSING_ASSET" || fail 'An unknown asset did not return branded HTTP 404.'
[[ "$(origin -o /dev/null -w '%{http_code}' "https://$DOMAIN$LICENSE_URL")" == 200 ]] ||
    fail 'The existing license URL stopped returning HTTP 200.'
JSON_AFTER="$(origin -H 'Accept: application/json' -o /dev/null -w '%{http_code} %{content_type}' "https://$DOMAIN$JSON_URL")"
if [[ "$JSON_BEFORE" == *application/json* && "$JSON_AFTER" != "$JSON_BEFORE" ]]; then
    fail 'The API error status or JSON content type changed.'
fi

COMPLETE=1
printf 'BACKUP=%s\nBAMBODIA_BRANDED_404=PASS\n' "$BACKUP"
