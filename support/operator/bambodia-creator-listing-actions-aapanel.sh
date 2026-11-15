#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=c295639948407770ba428b51d57569932480288e
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
DOMAIN=bambodia.com
test "$(id -u)" -eq 0
test "$(realpath -e -- "$APP")" = "$APP"
cd "$APP"
test -f artisan
test -r vendor/autoload.php
test -x "$PHP"
command -v gh >/dev/null

exec 9>/run/lock/bambodia-creator-listing-actions.lock
flock -n 9
WORK="$(mktemp -d /root/bambodia-creator-listings.XXXXXXXX)"
BACKUP=
PENDING=
COMPLETE=0
ROUTES_CACHED=0
declare -a STAGED=() CHANGED=()
declare -A ORIGINAL_HASH=()

artisan() {
    runuser -u www -- "$PHP" artisan "$@" --no-interaction
}

refresh_caches() {
    artisan view:clear || return
    if (( ROUTES_CACHED )); then
        artisan route:cache || return
    else
        artisan route:clear || return
    fi
}

cleanup() {
    local result=$?
    trap - EXIT
    if [[ -n "$PENDING" ]]; then
        rm -f -- "$PENDING"
    fi
    if (( ! COMPLETE && ${#CHANGED[@]} > 0 )); then
        local path
        for path in "${CHANGED[@]}"; do
            cp -aT -- "$BACKUP/$path" "$path" || echo "RESTORE_FAILED=$path" >&2
        done
        refresh_caches || echo CACHE_RESTORE_FAILED >&2
    fi
    rm -rf -- "$WORK"
    exit "$result"
}
trap cleanup EXIT

stage() {
    local path="$1" before="$2" after="$3" merge="${4:-}"
    local incoming="$WORK/$path" installed
    test "$(realpath -e -- "$path")" = "$APP/$path"
    test -f "$path" && test ! -L "$path"
    installed="$(git hash-object -- "$path")"
    if [[ "$installed" == "$after" ]]; then
        return
    fi
    if [[ -z "$merge" && "$installed" != "$before" ]]; then
        printf 'CREATOR_LISTING_SOURCE_CONFLICT=%s %s\n' "$path" "$installed" >&2
        return 1
    fi

    mkdir -p -- "$(dirname -- "$incoming")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$incoming"
    test "$(git hash-object -- "$incoming")" = "$after"
    if [[ -n "$merge" && "$installed" != "$before" ]]; then
        python3 - "$path" "$incoming" "$merge" <<'PY'
from pathlib import Path
import re
import sys

installed, incoming = map(Path, sys.argv[1:3])
kind = sys.argv[3]
existing = installed.read_bytes()
tested = incoming.read_bytes().splitlines(keepends=True)


def require(condition, description):
    if not condition:
        sys.exit(f'CREATOR_LISTING_MERGE_CONFLICT={installed} {description}')


if kind == 'audit':
    names = [b'listing.creator.archived', b'listing.creator.draft', b'listing.creator.deleted']
    block = re.search(rb'(?m)^    public const EVENTS = \[\r?\n(?P<events>.*?)^    \];', existing, re.S)
    require(block is not None, 'audit event list missing')
    anchor = re.search(rb"(?m)^        'listing\.resubmitted',\r?$", block['events'])
    require(anchor is not None, 'audit event insert point missing')
    require(len(re.findall(rb"(?m)^        'listing\.resubmitted',\r?$", block['events'])) == 1,
            'audit event insert point ambiguous')
    offset = block.start('events') + anchor.end() + 1
elif kind == 'routes':
    names = [b'studio.listings.status', b'studio.listings.destroy']
    group, = (line.rstrip() for line in tested if b"prefix('studio')->group(" in line)
    anchor, = (line.rstrip() for line in tested if b"name('studio.listings.update')" in line)
    require(existing.count(group) == 1 and existing.count(anchor) == 1,
            'creator route group or insert point missing or ambiguous')
    start = existing.index(group)
    offset = existing.index(anchor) + len(anchor)
    end = re.search(rb'(?m)^    \}\);\r?$', existing[start:])
    require(end is not None and start < offset < start + end.start(),
            'listing route is outside the creator route group')
    newline_at = existing.find(b'\n', offset)
    require(newline_at >= 0, 'route line ending missing')
    offset = newline_at + 1
else:
    sys.exit(f'Unexpected merge type {kind}')

newline = b'\r\n' if existing[offset - 2:offset] == b'\r\n' else b'\n'
missing = []
for name in names:
    token = b"'" + name + b"'"
    line, = (line.rstrip() for line in tested if token in line)
    scope = block['events'] if kind == 'audit' else existing
    occurrences = scope.count(token) + scope.count(b'"' + name + b'"')
    if occurrences == 0:
        missing.append(line + newline)
    else:
        require(occurrences == 1 and line in scope.splitlines(), f'{name.decode()} already differs')
incoming.write_bytes(existing[:offset] + b''.join(missing) + existing[offset:])
PY
        if cmp -s -- "$path" "$incoming"; then
            return
        fi
    fi
    "$PHP" -l "$incoming"
    ORIGINAL_HASH["$path"]="$installed"
    STAGED+=("$path")
}

stage app/Http/Controllers/AccountController.php 31a3f15d0a2fe384cfd7b85f7c9858f3a59ffe58 0498f128369a5ee494769c071d86b08a77bac694
stage app/Http/Controllers/BuilderController.php cb3e829e2c841a6c86d373bd35fd535654f36293 c0b744e73465bd42154be72a194a3b9c66ad1ba6
stage app/Http/Controllers/Creator/ListingController.php 8b4906c0f0d131e0dceee1edf69d0639e80ed81b 52c1d6f231dc5b4816fa0c2c4d73d729cc0d814f
stage app/Http/Controllers/Creator/ProductController.php 5a5b08579ac43433af970c66aef0cea9ceb09d69 931bc84656c50a9b052753a553144076d6a49a51
stage app/Models/AuditEvent.php 17e28df0b7aa5aa047b90050c5d23dbc93d3d7fd 80236bd6ac99afe78c0e378f79ee5811a2379db3 audit
stage app/Models/MarketplaceListing.php 90ebc3c095e5a6370a4beb88a1113459496fb5b9 ef031f5407725d9f883e88cab1d22802b28fd4ff
stage resources/views/account/listings.blade.php 43c43305b33088033ea5b11391cdd6cc9d9b3d7d 51ff72e05f852aaab0ab26a1e45400953db39f57
stage resources/views/account/studio.blade.php dce95a60b73a8faf1bf7b6643f18e526dc2098c1 ff7abb27853a22a1dd4ef374a36b5cefd8b4df1b
stage routes/web.php 59881d8a6ef03b710121e55fb50cdb8e4773bb65 ca75b832b1ec59813f1081ffc16cae4f310ddd2f routes

if compgen -G 'bootstrap/cache/routes-*.php' >/dev/null; then
    ROUTES_CACHED=1
fi

if (( ${#STAGED[@]} > 0 )); then
    BACKUP="$(mktemp -d /root/bambodia-creator-listing-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for FILE in "${STAGED[@]}"; do
        test "$(git hash-object -- "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        cp -a --parents -- "$FILE" "$BACKUP/"
    done
    for FILE in "${STAGED[@]}"; do
        PENDING="$(mktemp "$(dirname -- "$FILE")/.creator-listing-update.XXXXXXXX")"
        cat -- "$WORK/$FILE" > "$PENDING"
        chown --reference="$FILE" -- "$PENDING"
        chmod --reference="$FILE" -- "$PENDING"
        test "$(git hash-object -- "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        CHANGED+=("$FILE")
        mv -fT -- "$PENDING" "$FILE"
        PENDING=
    done
    refresh_caches
fi

artisan route:list --path=studio/listings --json > "$WORK/routes.json"
python3 - "$WORK/routes.json" <<'PY'
import json
import sys

with open(sys.argv[1]) as response:
    routes = {row['name']: row for row in json.load(response)}
for name, verb, uri, action in [
    ('studio.listings', 'GET', 'studio/listings', 'index'),
    ('studio.listings.status', 'PATCH', 'studio/listings/{listing}/status', 'changeStatus'),
    ('studio.listings.destroy', 'DELETE', 'studio/listings/{listing}', 'destroy'),
]:
    row = routes[name]
    assert verb in row['method'].split('|')
    assert row['uri'].lstrip('/') == uri
    assert row['action'].endswith(f'Creator\\ListingController@{action}')
    middleware = row['middleware']
    if isinstance(middleware, list):
        middleware = ' '.join(middleware)
    assert 'auth' in middleware.lower()
    assert 'freelancer' in middleware and 'super_admin' in middleware
print('CREATOR_LISTING_ROUTES=PASS')
PY

homepage_status="$(curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
    --output /dev/null --write-out '%{http_code}' "https://$DOMAIN/")"
studio_status="$(curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
    --output /dev/null --write-out '%{http_code}' "https://$DOMAIN/studio/listings")"
test "$homepage_status" = 200
test "$studio_status" = 302
COMPLETE=1
printf 'BAMBODIA_CREATOR_LISTING_ACTIONS=PASS\n'
