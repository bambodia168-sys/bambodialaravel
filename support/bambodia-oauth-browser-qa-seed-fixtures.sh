#!/usr/bin/env bash
# Synthetic-only Bambodia OAuth browser-QA identities.
# --check: read-only; --seed: explicit operator confirmation + isolated QA-only insert.
# Never run in production/staging, reuse existing user accounts, or alter webserver state.
set -Eeuo pipefail
umask 077

MODE="${1:---check}"
case "$MODE" in --check|--seed) ;; *) echo 'Usage: script --check|--seed' >&2; exit 64 ;; esac

APP="/var/lib/bambodia-oauth-browser-qa"
CLI="/var/lib/bambodia-oauth-qa-acceptance"
SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
DB="bambodia_oauthqa_browser"
DB_USER="bambodiaqa_browser"
CNF="/root/bambodia-oauth-browser-qa.cnf"
CRED="/root/bambodia-oauth-browser-qa-fixtures.json"
MYSQL="/www/server/mysql/bin/mysql"
PHP="/www/server/php/85/bin/php"
GUARD="$APP/support/mcp-oauth-db-grant-guard.py"
GUARD_BLOB="38c90da11cd9309d8e384782b26584431d2bd582"

die() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || die 'Root required.'
for dir in "$APP" "$CLI"; do
  [[ ! -L "$dir" && -d "$dir/.git" ]] || die 'QA source missing or symlinked.'
  [[ "$(realpath -e "$dir")" == "$dir" ]] || die 'QA source redirects.'
  [[ "$(git -C "$dir" rev-parse HEAD)" == "$SHA" ]] || die 'QA source SHA mismatch.'
  [[ -z "$(git -C "$dir" status --porcelain --untracked-files=no)" ]] || die 'QA source modified.'
  [[ -f "$dir/.env" && ! -L "$dir/.env" ]] || die 'QA env unsafe.'
done
[[ "$APP" != "$CLI" ]] || die 'Browser QA and accepted CLI QA must be separate.'
[[ -x "$MYSQL" && -x "$PHP" ]] || die 'Expected aaPanel binaries missing.'
[[ -f "$CNF" && ! -L "$CNF" && "$(stat -c '%u:%a' "$CNF")" == "0:600" ]] ||
  die 'Browser QA MySQL CNF missing or unsafe.'
[[ -f "$GUARD" && ! -L "$GUARD" && "$(git hash-object "$GUARD")" == "$GUARD_BLOB" ]] ||
  die 'Restricted MySQL grant validator mismatch.'
[[ -f "$APP/vendor/autoload.php" && -f "$APP/artisan" ]] ||
  die 'Browser QA dependencies are missing.'
[[ ! -e "$APP/bootstrap/cache/config.php" ]] || die 'QA config cache detected.'
[[ ! -e "$CRED" && ! -L "$CRED" ]] || die 'Fixture credentials file already exists; never overwrite.'
# aaPanel host uses a root:root 0550 directory; root:root 0700 is also safe.
# In both cases only root may read the O_EXCL-created 0600 fixture file.
# Refuse symlinks, owner/group mismatches, and all other directory modes.
[[ -d /root && ! -L /root && "$(realpath -e /root)" == /root ]] ||
  die 'Private root directory is missing, symlinked or redirected.'
ROOT_ID_MODE="$(stat -c '%u:%g:%a' /root)"
case "$ROOT_ID_MODE" in
  0:0:550|0:0:700) ;;
  *) die 'Private root directory must be root:root with restrictive 0550 or 0700 mode.' ;;
esac
unset ROOT_ID_MODE
for name in oauth-private.key oauth-public.key; do
  b="$APP/storage/$name"
  c="$CLI/storage/$name"
  [[ -s "$b" && -s "$c" && ! -L "$b" && ! -L "$c" ]] ||
    die 'QA Passport key missing or symlinked.'
  [[ "$(stat -c '%a' "$b")" == 600 ]] || die 'Browser QA Passport key mode unsafe.'
  ! cmp -s "$b" "$c" || die 'Browser QA Passport key shared with accepted CLI QA.'
done

python3 - "$APP/.env" "$CLI/.env" <<'PY'
import sys
from pathlib import Path
def values(path):
    out={}
    for line in Path(path).read_text(errors="replace").splitlines():
        if "=" in line and not line.lstrip().startswith("#"):
            k,v=line.split("=",1)
            out[k.strip()]=v.strip().strip("'\"")
    return out
b,c=values(sys.argv[1]),values(sys.argv[2])
expected={
    "APP_URL":"https://oauth-browser-qa.invalid",
    "APP_ENV":"staging",
    "APP_DEBUG":"false",
    "DB_CONNECTION":"mysql",
    "DB_HOST":"127.0.0.1",
    "DB_DATABASE":"bambodia_oauthqa_browser",
    "DB_USERNAME":"bambodiaqa_browser",
    "SESSION_DRIVER":"file",
    "CACHE_STORE":"file",
    "MAIL_MAILER":"log",
}
for k,v in expected.items():
    if b.get(k,"").lower()!=v.lower():
        raise SystemExit("BLOCKED: browser QA "+k+" differs from isolated fixture expectation.")
if b.get("SESSION_DOMAIN","").lower() not in ("","null"):
    raise SystemExit("BLOCKED: browser QA cookies must remain host-only.")
for k in ("APP_KEY","DB_PASSWORD","SESSION_COOKIE"):
    if not b.get(k) or not c.get(k) or b[k]==c[k]:
        raise SystemExit("BLOCKED: QA "+k+" must be present and separate from accepted CLI.")
print("QA_NON_SECRET_CONFIG=PASS")
print("QA_SECRETS_AND_COOKIES=DISTINCT")
PY

mysql_qa() {
  "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP --host=127.0.0.1 \
    --batch --skip-column-names "$@"
}
schema="$(mysql_qa information_schema -e \
  "SELECT COUNT(*) FROM SCHEMATA WHERE SCHEMA_NAME='$DB'")"
[[ "$schema" == 1 ]] || die 'Isolated browser QA schema missing.'
who="$(mysql_qa "$DB" -e 'SELECT CURRENT_USER()')"
mysql_qa "$DB" -e 'SHOW GRANTS FOR CURRENT_USER()' |
  python3 "$GUARD" "$DB" "$DB_USER" "$who" ||
  die 'Browser QA database grants not adequately scoped.'
unset who

for t in users migrations audit_events oauth_clients oauth_access_tokens oauth_refresh_tokens; do
  n="$(mysql_qa information_schema -e \
    "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$t'")"
  [[ "$n" == 1 ]] || die "Missing browser QA table: $t"
done
migrations="$(mysql_qa "$DB" -e 'SELECT COUNT(*) FROM migrations')"
[[ "$migrations" == 67 ]] || die 'Expected 67 completed browser QA migrations.'
users="$(mysql_qa "$DB" -e 'SELECT COUNT(*) FROM users')"
[[ "$users" == 0 ]] || die 'Browser QA users are not empty; never seed over existing identities.'
for t in oauth_clients oauth_access_tokens oauth_refresh_tokens; do
  rows="$(mysql_qa "$DB" -e "SELECT COUNT(*) FROM \`$t\`")"
  [[ "$rows" == 0 ]] || die "Unexpected browser QA OAuth records in $t"
done
[[ -c /dev/tty ]] || die 'Operator TTY required for guarded fixture creation.'
echo 'BROWSER_QA_SCHEMA=VERIFIED'
echo 'BROWSER_QA_MIGRATIONS=67'
echo 'BROWSER_QA_USERS=0'
echo 'BROWSER_QA_OAUTH_CLIENTS=0'
echo 'BROWSER_QA_SOURCE=PINNED'
echo 'BROWSER_QA_APP_URL=NONROUTABLE'
echo 'FIXTURE_PREFLIGHT=PASS'

if [[ "$MODE" == --check ]]; then
  echo 'READ_ONLY=YES'
  exit 0
fi

echo 'Type SEED-QA to create THREE synthetic users in ONLY the isolated browser QA database:'
IFS= read -r CONFIRM < /dev/tty
[[ "$CONFIRM" == SEED-QA ]] || die 'Operator declined.'
unset CONFIRM

# Credentials are randomly generated once and kept in a root-only file.
python3 - "$CRED" <<'PY'
import json,os,secrets,sys
path=sys.argv[1]
identities=[
    ("OAuth QA Principal","qa-oauth-principal@accounts.invalid","super_admin"),
    ("OAuth QA Other Admin","qa-oauth-other@accounts.invalid","super_admin"),
    ("OAuth QA Customer","qa-oauth-customer@accounts.invalid","customer"),
]
flags=os.O_WRONLY|os.O_CREAT|os.O_EXCL
if hasattr(os,"O_NOFOLLOW"): flags|=os.O_NOFOLLOW
fd=os.open(path,flags,0o600)
try:
    with os.fdopen(fd,"w") as file:
        json.dump([{"name":name,"email":email,"role":role,
                    "password":secrets.token_urlsafe(36)} for name,email,role in identities],file)
        file.write("\n")
except BaseException:
    raise
print("PRIVATE_FIXTURE_CREDENTIALS=CREATED_ROOT_ONLY")
PY
[[ "$(stat -c '%u:%a' "$CRED")" == "0:600" ]] || die 'Credential file not securely owned.'

# Keep temporary PHP code outside both QA checkouts. Never pass passwords as argv/env.
tmp="$(mktemp -d /tmp/bambodia-browser-fixture.XXXXXXXX)"
cleanup() { rm -f -- "$tmp/seed.php"; rmdir -- "$tmp" 2>/dev/null || true; }
trap cleanup EXIT
chown root:www "$tmp"
chmod 750 "$tmp"
cat > "$tmp/seed.php" <<'PHP'
<?php
declare(strict_types=1);
$base = '/var/lib/bambodia-oauth-browser-qa';
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'staging' ||
    config('app.url') !== 'https://oauth-browser-qa.invalid' ||
    config('database.default') !== 'mysql' ||
    config('database.connections.mysql.database') !== 'bambodia_oauthqa_browser' ||
    config('mail.default') !== 'log') {
    throw new RuntimeException('BLOCKED: Laravel runtime differs from isolated QA configuration.');
}
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data) || count($data) !== 3) {
    throw new RuntimeException('BLOCKED: expected exactly three synthetic identities.');
}
$roles = ['super_admin', 'super_admin', 'customer'];
\Illuminate\Support\Facades\DB::transaction(static function () use ($data, $roles): void {
    if (\App\Models\User::query()->count() !== 0 ||
        \Illuminate\Support\Facades\DB::table('oauth_clients')->count() !== 0) {
        throw new RuntimeException('BLOCKED: QA records exist; refusing fixture insertion.');
    }
    foreach ($data as $i => $row) {
        if (!is_array($row) || ($row['role'] ?? '') !== $roles[$i] ||
            !str_ends_with((string) ($row['email'] ?? ''), '@accounts.invalid') ||
            strlen((string) ($row['password'] ?? '')) < 40) {
            throw new RuntimeException('BLOCKED: invalid synthetic QA identity specification.');
        }
        $user = \App\Models\User::query()->create([
            'name' => $row['name'],
            'email' => $row['email'],
            'password' => $row['password'],
            'role' => $row['role'],
            'account_status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        if ($user->account_status !== 'active' ||
            !\Illuminate\Support\Facades\Hash::check($row['password'], $user->password) ||
            empty($user->public_id)) {
            throw new RuntimeException('Synthetic QA identity validation failed.');
        }
    }
});
echo "SYNTHETIC_USERS_CREATED=3\n";
PHP
chown root:www "$tmp/seed.php"
chmod 640 "$tmp/seed.php"
"$PHP" -l "$tmp/seed.php" >/dev/null || die 'Temporary QA seed PHP failed syntax check.'
id www >/dev/null || die 'Expected www runtime account missing.'
runuser -u www -- "$PHP" "$tmp/seed.php" < "$CRED" ||
  die 'QA fixture insertion failed. Preserve credentials file and investigate; do not rerun.'

count="$(mysql_qa "$DB" -e 'SELECT COUNT(*) FROM users')"
[[ "$count" == 3 ]] || die 'Unexpected QA user count after insertion; inspect without rerunning.'
echo 'BROWSER_QA_FIXTURE_ACCEPTANCE=PASS'
echo "PRIVATE_CREDENTIAL_FILE=$CRED"
echo 'CREDENTIALS=NOT_DISPLAYED'
echo 'BROWSER_QA_VHOST=UNCHANGED'
echo 'ACCEPTED_CLI_QA=UNCHANGED'
echo 'PRODUCTION=UNCHANGED'
