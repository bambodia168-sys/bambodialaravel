#!/usr/bin/env bash
# Create a fresh, protected full MySQL backup using the installed Bambodia DB identity.
# Prints only the archive path to stdout. Do not expose database credentials in logs.
set -Eeuo pipefail
umask 077
APP='/www/wwwroot/bambodia-staging-qa'
PHP='/www/server/php/85/bin/php'
BACKUPS='/root/Bambodia-BuilderStudio-Backups'
DUMP='/www/server/mysql/bin/mysqldump'
fail(){ echo "BLOCKED: $*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail 'Run as root'
[[ -f "$APP/.env" && -f "$APP/vendor/autoload.php" && -x "$PHP" ]] || fail 'Installed Laravel runtime missing'
[[ -x "$DUMP" ]] || DUMP="$(command -v mysqldump || true)"
[[ -x "$DUMP" ]] || fail 'MySQL dump binary missing'
for bin in gzip mktemp install; do command -v "$bin" >/dev/null || fail "Missing $bin"; done
install -d -m 0700 "$BACKUPS"
TMP="$(mktemp -d /root/bambodia-db-credentials.XXXXXXXX)"
trap 'rm -rf -- "$TMP"' EXIT

# Laravel resolves encrypted/escaped .env values and database configuration itself.
# Secrets remain in temporary files accessible only by root and are always removed.
export BAMBODIA_DB_CNF="$TMP/client.cnf"
export BAMBODIA_DB_NAME="$TMP/name.txt"
(cd "$APP" && "$PHP" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = config('database.connections.'.config('database.default'));
if (($c['driver'] ?? '') !== 'mysql') {
    fwrite(STDERR, "BLOCKED: Installed DB driver is not MySQL.\n");
    exit(1);
}
foreach (['host', 'username', 'database'] as $name) {
    if (!is_string($c[$name] ?? null) || $c[$name] === '') exit(1);
}
$db = $c['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/D', $db)) exit(1);
$quote = static function ($value): string {
    $value = (string) $value;
    if (str_contains($value, "\r") || str_contains($value, "\n")) exit(1);
    return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
};
$ini = "[client]\n"
    ."host=".$quote($c['host'])."\n"
    ."user=".$quote($c['username'])."\n"
    ."password=".$quote($c['password'] ?? '')."\n"
    ."port=".$quote($c['port'] ?? 3306)."\n";
if (!empty($c['unix_socket'])) {
    $ini .= "socket=".$quote($c['unix_socket'])."\n";
}
if (file_put_contents(getenv('BAMBODIA_DB_CNF'), $ini) === false) exit(1);
chmod(getenv('BAMBODIA_DB_CNF'),0600);
if (file_put_contents(getenv('BAMBODIA_DB_NAME'), $db) === false) exit(1);
chmod(getenv('BAMBODIA_DB_NAME'),0600);
PHP
) || fail 'Database configuration could not be read safely'

DB="$(cat "$TMP/name.txt")"
OUT="$(mktemp "$BACKUPS/database-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX.sql.gz")"
# mysqldump may fail with insufficient DB permissions; pipefail aborts delivery.
if ! "$DUMP" --defaults-extra-file="$TMP/client.cnf" \
    --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
    --triggers "$DB" | gzip -c > "$OUT"; then
  rm -f -- "$OUT"
  fail 'MySQL snapshot was not completed. Check dump privileges.'
fi
if [[ ! -s "$OUT" ]] || ! gzip -t "$OUT"; then
  rm -f -- "$OUT"
  fail 'Database snapshot is empty or invalid'
fi
chmod 0600 "$OUT"
echo "Full MySQL snapshot saved with private permissions." >&2
printf '%s\n' "$OUT"
