#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

: "$DB_HOST"
: "$DB_PORT"
: "$DB_NAME"
: "$DB_USERNAME"
: "$DB_PASSWORD"

SITE="http://127.0.0.1:8092"
JAR="$(mktemp)"
RESPONSE="$(mktemp)"
PAGE="$(mktemp)"
SERVER_LOG="$(mktemp)"

php -S 127.0.0.1:8092 -t public public/index.php > "$SERVER_LOG" 2>&1 &
SERVER_PID="$!"

cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    rm -f "$JAR" "$RESPONSE" "$PAGE" "$SERVER_LOG"
}
trap cleanup EXIT

for attempt in $(seq 1 20); do
    if curl -fsS -b "$JAR" -c "$JAR" "$SITE/installer?step=1" > "$PAGE"; then
        break
    fi
    sleep 1
done

grep -q 'Requirements' "$PAGE"
test -s storage/app/installer-access.txt
test ! -e storage/app/installed.lock
test ! -e .env
test -f vendor/autoload.php
test -f public/build/manifest.json

csrf="$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$PAGE" | head -1 | cut -d '"' -f 4)"
test -n "$csrf"
access_code="$(cat storage/app/installer-access.txt)"

submit() {
    local step="$1"
    shift
    local status
    status="$(curl -sS -o "$RESPONSE" -w '%{http_code}' \
        -b "$JAR" -c "$JAR" \
        --data-urlencode "_csrf=$csrf" \
        "$@" "$SITE/installer?step=$step")"
    if [ "$status" != "303" ]; then
        echo "Installer step $step failed with status $status"
        grep -o 'class="error"[^>]*>[^<]*' "$RESPONSE" || true
        exit 1
    fi
}

submit 1 --data-urlencode 'action=access' --data-urlencode "access=$access_code"
curl -fsS -b "$JAR" -c "$JAR" "$SITE/installer?step=2" > "$PAGE"
grep -q 'Database' "$PAGE"

submit 2 \
    --data-urlencode 'action=database' \
    --data-urlencode "host=$DB_HOST" \
    --data-urlencode "port=$DB_PORT" \
    --data-urlencode "name=$DB_NAME" \
    --data-urlencode "username=$DB_USERNAME" \
    --data-urlencode "password=$DB_PASSWORD"
curl -fsS -b "$JAR" -c "$JAR" "$SITE/installer?step=3" > "$PAGE"
grep -q 'Application' "$PAGE"

submit 3 \
    --data-urlencode 'action=application' \
    --data-urlencode 'app_name=Bambodia Acceptance' \
    --data-urlencode "app_url=$SITE"
curl -fsS -b "$JAR" -c "$JAR" "$SITE/installer?step=4" > "$PAGE"
grep -q 'Administrator' "$PAGE"

submit 4 \
    --data-urlencode 'action=administrator' \
    --data-urlencode 'admin_name=Initial Administrator' \
    --data-urlencode 'admin_email=admin@acceptance.test' \
    --data-urlencode 'admin_password=VeryStrongAcceptancePassword123!' \
    --data-urlencode 'confirm_password=VeryStrongAcceptancePassword123!'
curl -fsS -b "$JAR" -c "$JAR" "$SITE/installer?step=5" > "$PAGE"
grep -q 'Finish' "$PAGE"

submit 5 --data-urlencode 'action=install'
test -f storage/app/installed.lock
test -f .env
test ! -e storage/app/installer-access.txt
grep -q '^APP_DEBUG=false$' .env
grep -q '^APP_ENV=production$' .env

http_login="$(curl -sS -o "$RESPONSE" -w '%{http_code}' "$SITE/login")"
test "$http_login" = "200"

http_installer="$(curl -sS -o "$RESPONSE" -w '%{http_code}' "$SITE/installer")"
test "$http_installer" = "404"

php -r '
    $db = new PDO(
        "mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_NAME").";charset=utf8mb4",
        getenv("DB_USERNAME"),
        getenv("DB_PASSWORD"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $query = $db->prepare("SELECT public_id, role FROM users WHERE email=? LIMIT 1");
    $query->execute(["admin@acceptance.test"]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if (! $user || $user["role"] !== "super_admin" || ! preg_match("/^[0-9]{8}$/", $user["public_id"])) {
        throw new RuntimeException("The super administrator was not correctly created.");
    }
    $migrations = (int) $db->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
    if ($migrations < 8) {
        throw new RuntimeException("Expected Laravel migrations have not completed.");
    }
    echo "PASS: ". $migrations ." migrations, protected administrator and locked installer.\n";
'

echo 'Bambodia MySQL browser installation acceptance passed.'
