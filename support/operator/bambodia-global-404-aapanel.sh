#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=02fbb121898a2c15364a07d23630fb6758fd766b
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
NGINX=/www/server/nginx/sbin/nginx
DOMAIN=bambodia.com
HTML=public/404.html
BLADE=resources/views/errors/404.blade.php
SEO=resources/views/layouts/partials/site-seo-head.blade.php
CSS=public/css/error-404.css
PHP_INCLUDE=/www/server/nginx/conf/enable-php-85.conf
LOCAL_INCLUDE=/www/server/panel/vhost/nginx/bambodia-php85-error-passthrough.inc

fail() { printf 'BAMBODIA_404_ERROR=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail 'Use the aaPanel root terminal.'
[[ "$(realpath -e -- "$APP")" == "$APP" && -f "$APP/artisan" && -x "$PHP" ]] ||
    fail 'Expected Bambodia application or PHP binary unavailable.'
[[ "$(realpath -e -- "$VHOST")" == "$VHOST" && -f "$VHOST" ]] ||
    fail 'Expected Bambodia Nginx vhost unavailable.'

cd "$APP"
WORK="$(mktemp -d /root/bambodia-global-404.XXXXXXXX)"
BACKUP="$(mktemp -d /root/bambodia-global-404-backup.XXXXXXXX)"
CHANGED=()
CONFIG_CHANGED=0
INCLUDE_CREATED=0
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
        if (( INCLUDE_CREATED )); then rm -f -- "$LOCAL_INCLUDE"; fi
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

while read -r path previous hash; do
    [[ "$(realpath -m -- "$path")" == "$APP/$path" && ! -L "$path" ]] ||
        fail "Unexpected path: $path"
    actual=absent
    if [[ -e "$path" ]]; then
        [[ -f "$path" ]] || fail "Expected a regular file: $path"
        actual="$(git hash-object -- "$path")"
    fi
    [[ "$actual" == "$previous" || "$actual" == "$hash" ]] || fail "Unexpected installed version: $path ($actual)"
    mkdir -p -- "$WORK/$(dirname -- "$path")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$WORK/$path"
    [[ "$(git hash-object -- "$WORK/$path")" == "$hash" ]] || fail "Checksum mismatch: $path"
    if [[ "$path" == *.php ]]; then "$PHP" -l "$WORK/$path"; fi
done <<'FILES'
public/404.html a06cd92384431270e93a2e536304928f37ce611a 9b0219747c237ac5b681f81d01435b2f9c12a215
resources/views/errors/404.blade.php 84a2436b02df13d81e033f13471a03e81c85cfce 50b663c8796e9d7caadb25e981769d3aa9019860
resources/views/layouts/partials/site-seo-head.blade.php 6c6321ee385e79ad465d90a2ab2b0af0cab53b8b ad7892a79dcf2f773d681df5e94d969efeb5ff55
public/css/error-404.css absent 861b6c8485aa9f3014811b240ee067ea2b4d75a8
FILES

install -d -m 0755 -- "$(dirname -- "$BLADE")"
for path in "$HTML" "$CSS" "$SEO" "$BLADE"; do
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
is_global_404() {
    is_branded "$1" &&
        grep -Fq 'class="site-not-found-mark"' "$WORK/response.html" &&
        grep -Fq '<header class="site-header">' "$WORK/response.html" &&
        grep -Fq '<footer class="site-footer">' "$WORK/response.html"
}

if ! is_global_404 '/licenses' || ! is_global_404 "$MISSING_PAGE"; then
    [[ -x "$NGINX" ]] || fail 'Expected aaPanel Nginx binary unavailable.'
    "$NGINX" -t
    cp -a -- "$VHOST" "$BACKUP/bambodia.com.conf"
    python3 - "$VHOST" "$WORK/bambodia.com.conf" "$APP" "$PHP_INCLUDE" "$LOCAL_INCLUDE" "$WORK/local-php.inc" <<'PY_NGINX'
from pathlib import Path
import re
import sys

source, destination, app, php_include, local_include, staged_include = sys.argv[1:]
config = Path(source).read_text()
root = list(re.finditer(rf'(?m)^([ \t]*)root[ \t]+{re.escape(app)}/public;[^\n]*$', config))
if len(root) != 1:
    raise SystemExit('Expected one Bambodia public document root')
root = root[0]
starts = list(re.finditer(r'(?m)^[ \t]*server[ \t]*(?:\n[ \t]*)?\{', config[:root.start()]))
if not starts:
    raise SystemExit('Bambodia server block was not found')
start = starts[-1].start()
indent = re.match(r'[ \t]*', starts[-1][0])[0]
end = re.search(rf'(?m)^{re.escape(indent)}\}}[ \t]*(?:#.*)?$', config[root.end():])
if not end:
    raise SystemExit('Could not locate the Bambodia server block end')
end = root.end() + end.start()
block = config[start:end]
explicit_on = re.compile(r'(?m)^([ \t]*fastcgi_intercept_errors[ \t]+)on([ \t]*;)')
include = re.compile(r'(?m)^([ \t]*include[ \t]+)(?:/www/server/nginx/conf/)?enable-php-85\.conf([ \t]*;)')
if include.search(block):
    if len(include.findall(block)) != 1:
        raise SystemExit('Expected one PHP 8.5 include in the Bambodia server block')
    template = Path(php_include).read_text()
    if explicit_on.search(template):
        if Path(local_include).exists() or Path(local_include).is_symlink():
            raise SystemExit('An existing Bambodia PHP include needs inspection')
        Path(staged_include).write_text(explicit_on.sub(r'\g<1>off\g<2>', template))
        block = include.sub(lambda match: f'{match[1]}{local_include}{match[2]}', block)
server_level = re.compile(rf'(?m)^{re.escape(root[1])}fastcgi_intercept_errors[ \t]+(?:on|off)[ \t]*;')
if not server_level.search(block):
    relative = root.end() - start
    block = block[:relative] + '\n' + root[1] + 'fastcgi_intercept_errors off;' + block[relative:]
block = explicit_on.sub(r'\g<1>off\g<2>', block)
Path(destination).write_text(config[:start] + block + config[end:])
PY_NGINX
    if [[ -f "$WORK/local-php.inc" ]]; then
        [[ ! -e "$LOCAL_INCLUDE" && ! -L "$LOCAL_INCLUDE" ]] || fail 'The Bambodia PHP include already exists.'
        install -m 0644 -- "$WORK/local-php.inc" "$LOCAL_INCLUDE"
        INCLUDE_CREATED=1
    fi
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
        if is_global_404 /licenses; then break; fi
        sleep 1
    done
fi

is_global_404 '/licenses' || fail 'The incomplete license URL did not return HTTP 404 with the global layout.'
is_global_404 "$MISSING_PAGE" || fail 'An unknown page did not return HTTP 404 with the global layout.'
is_branded "$MISSING_ASSET" || fail 'An unknown asset did not return branded HTTP 404.'
[[ "$(origin -o /dev/null -w '%{http_code}' "https://$DOMAIN$LICENSE_URL")" == 200 ]] ||
    fail 'The existing license URL stopped returning HTTP 200.'
JSON_AFTER="$(origin -H 'Accept: application/json' -o /dev/null -w '%{http_code} %{content_type}' "https://$DOMAIN$JSON_URL")"
if [[ "$JSON_BEFORE" == *application/json* && "$JSON_AFTER" != "$JSON_BEFORE" ]]; then
    fail 'The API error status or JSON content type changed.'
fi

COMPLETE=1
printf 'BACKUP=%s\nBAMBODIA_GLOBAL_404=PASS\n' "$BACKUP"
