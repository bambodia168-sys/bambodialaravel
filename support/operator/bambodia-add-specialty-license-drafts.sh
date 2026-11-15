#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=d7093f81bc0386634d9dd051176713aa7ae4f810
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
MODEL=app/Models/LicenseDefinition.php
VIEW=resources/views/dashboard/partials/license-definitions.blade.php
COMMAND=app/Console/Commands/AddSpecialtyLicenseDrafts.php
ACTOR=("$@")

test "$(id -u)" -eq 0
if (( ${#ACTOR[@]} > 1 )) || { (( ${#ACTOR[@]} == 1 )) && [[ ! "${ACTOR[0]}" =~ ^--admin=[0-9]{8}$ ]]; }; then
    echo 'Usage: bash script.sh [--admin=PUBLIC_ID]' >&2
    exit 2
fi

cd "$APP"
test -f artisan
test -r vendor/autoload.php
test -x "$PHP"

WORK="$(mktemp -d /root/bambodia-specialty-licenses.XXXXXXXX)"
BACKUP=
CHANGED=()
PENDING=
COMPLETED=0

cleanup() {
    local result=$?
    trap - EXIT
    if [[ -n "$PENDING" ]]; then
        rm -f -- "$PENDING"
    fi
    if (( ! COMPLETED && ${#CHANGED[@]} > 0 )); then
        local path
        for path in "${CHANGED[@]}"; do
            if [[ -f "$BACKUP/$path" ]]; then
                cp -aT -- "$BACKUP/$path" "$path" || echo "RESTORE_FAILED=$path" >&2
            else
                rm -f -- "$path" || echo "RESTORE_FAILED=$path" >&2
            fi
        done
        runuser -u www -- "$PHP" artisan view:clear >/dev/null || echo 'VIEW_CACHE_CLEAR_FAILED' >&2
    fi
    rm -rf -- "$WORK"
    exit "$result"
}
trap cleanup EXIT

stage() {
    local path="$1" before="$2" after="$3" actual=absent
    test "$(realpath -m -- "$path")" = "$APP/$path"
    test ! -L "$path"
    if [[ -e "$path" ]]; then
        test -f "$path"
        actual="$(git hash-object -- "$path")"
    fi
    if [[ "$actual" == "$after" ]]; then
        return
    fi
    if [[ "$actual" != "$before" ]]; then
        printf 'LICENSE_SOURCE_CONFLICT=%s %s\n' "$path" "$actual" >&2
        return 1
    fi
    mkdir -p "$WORK/$(dirname -- "$path")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$WORK/$path"
    test "$(git hash-object -- "$WORK/$path")" = "$after"
    "$PHP" -l "$WORK/$path"
}

stage "$MODEL" cc611d9ece884f82ac13dbee2d9c5074755e2067 0e7a84446bc4d0264dadf6937fac8388af4e704a
stage "$VIEW" 7c1926018a7cb7dea365d168377fa79579f0ca7d d42488c9afc5199675308ee031fd289942414900
stage "$COMMAND" absent 936d36d85d56a453d9631173761e56191fc9f1af

BACKUP="$(mktemp -d /root/bambodia-specialty-licenses-backup.XXXXXXXX)"
for FILE in "$MODEL" "$VIEW" "$COMMAND"; do
    if [[ ! -f "$WORK/$FILE" ]]; then
        continue
    fi
    REFERENCE="$MODEL"
    if [[ -f "$FILE" ]]; then
        cp -a --parents -- "$FILE" "$BACKUP/"
        REFERENCE="$FILE"
    fi
    PENDING="$(mktemp "$(dirname -- "$FILE")/.specialty-license.XXXXXXXX")"
    cat -- "$WORK/$FILE" > "$PENDING"
    chown --reference="$REFERENCE" -- "$PENDING"
    chmod --reference="$REFERENCE" -- "$PENDING"
    CHANGED+=("$FILE")
    mv -fT -- "$PENDING" "$FILE"
    PENDING=
done

runuser -u www -- "$PHP" artisan view:clear
runuser -u www -- "$PHP" artisan licenses:add-specialty-drafts "${ACTOR[@]}"
COMPLETED=1
printf 'BACKUP=%s\nSPECIALTY_LICENSE_DEFINITIONS=PASS\n' "$BACKUP"
