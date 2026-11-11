#!/usr/bin/env bash
# Guarded aaPanel rehearsal for Bambodia MCP OAuth. No production modifications.
# Documentation: docs/integrations/bambodia-mcp-aapanel-isolated-acceptance.md
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in
  --check|--rehearse|--restore-check) ;;
  *) echo "Usage: $0 --check|--rehearse|--restore-check" >&2; exit 64 ;;
esac

die() { printf 'BLOCKED: %s\n' "$*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }
: "${BAMBODIA_QA_APP:?Set BAMBODIA_QA_APP}"
: "${BAMBODIA_QA_DB:?Set BAMBODIA_QA_DB}"
: "${BAMBODIA_QA_MYSQL_CNF:?Set BAMBODIA_QA_MYSQL_CNF}"
: "${BAMBODIA_QA_EXPECTED_SHA:?Set BAMBODIA_QA_EXPECTED_SHA}"

[[ "$BAMBODIA_QA_APP" =~ ^/var/lib/bambodia-oauth-qa-[a-zA-Z0-9_-]+$ ]] ||
  die "QA app must live in a dedicated /var/lib/bambodia-oauth-qa-* directory."
[[ -d "$BAMBODIA_QA_APP" && ! -L "$BAMBODIA_QA_APP" ]] ||
  die "QA app directory missing or symlinked."
QA_APP="$(realpath -e -- "$BAMBODIA_QA_APP")"
[[ "$QA_APP" == "$BAMBODIA_QA_APP" ]] || die "QA directory resolves outside approved path."
[[ "$BAMBODIA_QA_DB" =~ ^bambodia_oauthqa_[a-z0-9_]+$ ]] ||
  die "Use a separate database named bambodia_oauthqa_*."
[[ "$BAMBODIA_QA_EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] ||
  die "Supply the exact 40-character PR commit."
[[ "$(id -u)" == 0 ]] || die "This isolated-host helper requires root."
[[ -f "$QA_APP/artisan" && -f "$QA_APP/vendor/autoload.php" && -f "$QA_APP/.env" ]] ||
  die "Laravel, dependencies or dedicated QA .env are missing."
[[ ! -L "$QA_APP/.env" && ! -e "$QA_APP/bootstrap/cache/config.php" ]] ||
  die "Refusing symlinked .env or cached config."
for folder in storage bootstrap database vendor support; do
  [[ -d "$QA_APP/$folder" && ! -L "$QA_APP/$folder" ]] ||
    die "Missing or symlinked isolated application folder: $folder"
done
[[ -z "$(find "$QA_APP/storage" -type l -print -quit)" ]] ||
  die "QA storage contains symlinks; private keys/media could escape isolation."
[[ "$(git -C "$QA_APP" rev-parse HEAD)" == "$BAMBODIA_QA_EXPECTED_SHA" ]] ||
  die "QA checkout does not match the pinned PR revision."
[[ -z "$(git -C "$QA_APP" status --porcelain --untracked-files=no)" ]] ||
  die "QA tracked source has local modifications; use a clean reviewed checkout."

QA_CNF="$BAMBODIA_QA_MYSQL_CNF"
[[ "$QA_CNF" == /root/* && -f "$QA_CNF" && ! -L "$QA_CNF" ]] ||
  die "QA-only MySQL credential file must be under /root without symlinks."
[[ "$(stat -c %u "$QA_CNF")" == 0 && "$(stat -c %a "$QA_CNF")" == 600 ]] ||
  die "MySQL credential file must be root-owned, mode 600."

# Parse literal fields only. Never source/eval .env or print its secrets.
env_value() {
  local line value
  line="$(grep -E "^$1=" "$QA_APP/.env" | tail -n 1)" || die "Missing .env field: $1"
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ "$value" == \"*\" ]]; then value="${value:1:${#value}-2}"; fi
  if [[ "$value" == \'*\' ]]; then value="${value:1:${#value}-2}"; fi
  [[ "$value" != *'${'* && "$value" != *'$('* ]] ||
    die "Interpolated .env field is not allowed: $1"
  printf '%s' "$value"
}
[[ "$(env_value APP_ENV)" == staging ]] || die "APP_ENV must be staging."
[[ "$(env_value APP_DEBUG)" == false ]] || die "APP_DEBUG must be false."
[[ "$(env_value DB_CONNECTION)" == mysql ]] || die "DB_CONNECTION must be mysql."
[[ "$(env_value DB_HOST)" == 127.0.0.1 ]] || die "DB_HOST must be 127.0.0.1."
[[ "$(env_value DB_DATABASE)" == "$BAMBODIA_QA_DB" ]] ||
  die "QA database must match the .env target."
[[ "$(env_value DB_USERNAME)" =~ ^bambodiaqa_[a-z0-9_]+$ ]] ||
  die "DB_USERNAME must be a restricted bambodiaqa_* user."
[[ "$(env_value MAIL_MAILER)" == log ]] || die "Use MAIL_MAILER=log."
[[ "$(env_value QUEUE_CONNECTION)" == database ]] || die "Use a stopped database queue."
[[ "$(env_value SESSION_DRIVER)" == file ]] || die "Use private file-based QA sessions."
[[ "$(env_value CACHE_STORE)" == file ]] || die "Use private file-backed QA cache."
[[ "$(env_value SESSION_COOKIE)" =~ ^bambodia_oauthqa_[a-z0-9_]+$ ]] ||
  die "Use a dedicated bambodia_oauthqa_* session cookie."
SESSION_DOMAIN_LINE="$(grep -E '^SESSION_DOMAIN=' "$QA_APP/.env" | tail -n 1 || true)"
[[ -z "$SESSION_DOMAIN_LINE" || "$SESSION_DOMAIN_LINE" == SESSION_DOMAIN=null || "$SESSION_DOMAIN_LINE" == SESSION_DOMAIN= ]] ||
  die "QA must use host-only session cookies (no shared production domain)."
[[ -z "$(grep -E '^(PASSPORT_PRIVATE_KEY|PASSPORT_PUBLIC_KEY)=' "$QA_APP/.env" || true)" ]] ||
  die "QA must generate file-based Passport keys rather than inherit env keys."
[[ -z "$(grep -E '^(DB_URL|DATABASE_URL|DB_SOCKET)=' "$QA_APP/.env" || true)" ]] ||
  die "Remove DB_URL/DATABASE_URL/DB_SOCKET overrides."
QA_URL="$(env_value APP_URL)"
[[ "$QA_URL" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?$ ]] ||
  die "APP_URL must be a separate HTTPS QA origin."
QA_HOST="$(printf '%s' "$QA_URL" | sed -E 's@^https://([^:/]+).*@\1@' | tr '[:upper:]' '[:lower:]')"
QA_HOST="${QA_HOST%.}"
[[ "$QA_HOST" != bambodia.com && "$QA_HOST" != www.bambodia.com ]] ||
  die "APP_URL cannot identify the production domain."

QA_USER="${BAMBODIA_QA_USER:-www}"
[[ "$QA_USER" == www ]] || die "Only the dedicated www PHP runtime is supported."
id "$QA_USER" >/dev/null || die "aaPanel www user missing."
QA_PHP="${BAMBODIA_QA_PHP:-/www/server/php/85/bin/php}"
[[ "$QA_PHP" == /* && -x "$QA_PHP" ]] || die "QA PHP executable not found."
for tool in mysql mysqldump git sha256sum realpath runuser cmp; do
  command -v "$tool" >/dev/null || die "Missing $tool"
done

mysql_qa() {
  mysql --defaults-extra-file="$QA_CNF" --protocol=TCP --host=127.0.0.1 \
    --batch --skip-column-names "$@"
}
artisan_qa() {
  (cd "$QA_APP" && runuser -u "$QA_USER" -- env -i \
    HOME=/tmp PATH=/usr/local/bin:/usr/bin:/bin "$QA_PHP" artisan "$@")
}
table_count() {
  mysql_qa information_schema -e "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$1'"
}
core_counts() {
  local table present
  for table in users api_tokens marketplace_listings content_entries; do
    present="$(mysql_qa information_schema -e \
      "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$1' AND TABLE_NAME='$table'")"
    if [[ "$present" == 1 ]]; then
      printf '%s=%s\n' "$table" "$(mysql_qa "$1" -e "SELECT COUNT(*) FROM \`$table\`")"
    else
      printf '%s=absent\n' "$table"
    fi
  done
}

WHO="$(mysql_qa "$BAMBODIA_QA_DB" -e 'SELECT CURRENT_USER()')"
[[ "$WHO" == bambodiaqa_* && "$WHO" != *root* ]] ||
  die "MySQL credentials do not resolve to a QA-only user."
GRANTS="$(mysql_qa "$BAMBODIA_QA_DB" -e 'SHOW GRANTS FOR CURRENT_USER()')"
# Ordinary restricted accounts may have the harmless "GRANT USAGE ON *.*".
# Any other global grant and any WITH GRANT OPTION are disallowed.
DANGEROUS_GRANTS="$(printf '%s\n' "$GRANTS" |
  grep -F 'ON *.*' |
  grep -v -F 'GRANT USAGE ON *.* ' || true)"
[[ -z "$DANGEROUS_GRANTS" && "$GRANTS" != *'WITH GRANT OPTION'* ]] ||
  die "QA database account has excessive global privileges."
info "QA_APP=$QA_APP"
info "QA_DB=$BAMBODIA_QA_DB"
info "QA_APP_URL=$QA_URL"
info "QA_SHA=$BAMBODIA_QA_EXPECTED_SHA"
info "QA_TABLES=$(table_count "$BAMBODIA_QA_DB")"
info "PREFLIGHT=PASS"

if [[ "$MODE" == --check ]]; then
  info "READ_ONLY=YES"
  exit 0
fi

SNAPSHOT_ROOT=/root/bambodia-oauth-qa-snapshots
[[ ! -L "$SNAPSHOT_ROOT" ]] || die "Snapshot root cannot be symlinked."
mkdir -p -m 700 "$SNAPSHOT_ROOT"
chmod 700 "$SNAPSHOT_ROOT"

if [[ "$MODE" == --restore-check ]]; then
  : "${BAMBODIA_QA_BACKUP:?Set BAMBODIA_QA_BACKUP from the rehearsal output}"
  : "${BAMBODIA_QA_RESTORE_DB:?Set BAMBODIA_QA_RESTORE_DB, an EMPTY QA restore DB}"
  [[ "$BAMBODIA_QA_RESTORE_DB" =~ ^bambodia_oauthqa_restore_[a-z0-9_]+$ &&
     "$BAMBODIA_QA_RESTORE_DB" != "$BAMBODIA_QA_DB" ]] ||
    die "Restore-check DB must be a different bambodia_oauthqa_restore_* database."
  [[ -f "$BAMBODIA_QA_BACKUP" && ! -L "$BAMBODIA_QA_BACKUP" ]] ||
    die "Snapshot SQL file missing/symlinked."
  BACKUP="$(realpath -e -- "$BAMBODIA_QA_BACKUP")"
  [[ "$BACKUP" == "$SNAPSHOT_ROOT"/run.*/before.sql ]] ||
    die "Backup must be in a protected rehearsal snapshot folder."
  [[ -f "$BACKUP.sha256" ]] || die "Snapshot checksum missing."
  sha256sum --status --check "$BACKUP.sha256" || die "Snapshot checksum invalid."
  [[ "$(table_count "$BAMBODIA_QA_RESTORE_DB")" == 0 ]] ||
    die "Restore DB must exist and have zero tables."
  mysql_qa "$BAMBODIA_QA_RESTORE_DB" < "$BACKUP"
  [[ -f "$(dirname "$BACKUP")/core-counts.txt" ]] ||
    die "Backup row-count manifest missing."
  core_counts "$BAMBODIA_QA_RESTORE_DB" > "$(dirname "$BACKUP")/restored-counts.txt"
  cmp -s "$(dirname "$BACKUP")/core-counts.txt" "$(dirname "$BACKUP")/restored-counts.txt" ||
    die "Restored core row counts differ from snapshot."
  [[ "$(table_count "$BAMBODIA_QA_RESTORE_DB")" == "$(cat "$(dirname "$BACKUP")/table-count.txt")" ]] ||
    die "Restored table count differs from the protected snapshot."
  info "RESTORE_CHECK=PASS"
  info "RESTORE_DB=$BAMBODIA_QA_RESTORE_DB"
  info "REHEARSAL_DB_UNCHANGED_BY_RESTORE_CHECK=YES"
  exit 0
fi

# Refuse a repeat run. No broad migrate, no key replacement, no main/staging sites.
[[ "$(mysql_qa information_schema -e \
  "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$BAMBODIA_QA_DB' AND TABLE_NAME LIKE 'oauth\_%'")" == 0 ]] ||
  die "Found OAuth tables; use a fresh isolated database clone."
[[ ! -e "$QA_APP/storage/oauth-private.key" && ! -e "$QA_APP/storage/oauth-public.key" ]] ||
  die "Found Passport keys; use a fresh isolated QA runtime."
RUN="$(mktemp -d "$SNAPSHOT_ROOT/run.XXXXXXXX")"
chmod 700 "$RUN"
BACKUP="$RUN/before.sql"
core_counts "$BAMBODIA_QA_DB" > "$RUN/core-counts.txt"
table_count "$BAMBODIA_QA_DB" > "$RUN/table-count.txt"
mysqldump --defaults-extra-file="$QA_CNF" --protocol=TCP --host=127.0.0.1 \
  --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
  --routines --triggers "$BAMBODIA_QA_DB" > "$BACKUP"
[[ -s "$BACKUP" ]] || die "Backup is empty; migration stopped."
sha256sum "$BACKUP" > "$BACKUP.sha256"
info "BACKUP=$BACKUP"
info "BACKUP_SHA256=$(cut -d ' ' -f 1 "$BACKUP.sha256")"

artisan_qa passport:keys --no-interaction
[[ -s "$QA_APP/storage/oauth-private.key" && -s "$QA_APP/storage/oauth-public.key" ]] ||
  die "Fresh QA Passport keys were not created."
chmod 600 "$QA_APP/storage/oauth-private.key" "$QA_APP/storage/oauth-public.key"

# Only the six OAuth-specific migrations; unrelated pending migrations remain untouched.
for migration in \
  2026_11_11_00001_create_oauth_auth_codes_table.php \
  2026_11_11_00002_create_oauth_access_tokens_table.php \
  2026_11_11_00003_create_oauth_refresh_tokens_table.php \
  2026_11_11_00004_create_oauth_clients_table.php \
  2026_11_11_00005_create_oauth_device_codes_table.php \
  2026_11_11_999999_add_bambodia_mcp_scopes_to_oauth_clients.php
do
  [[ -f "$QA_APP/database/migrations/$migration" ]] ||
    die "OAuth migration not present in QA checkout: $migration"
  artisan_qa migrate --path="database/migrations/$migration" --force --no-interaction
done

for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens oauth_clients oauth_device_codes; do
  [[ "$(mysql_qa information_schema -e \
    "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='$BAMBODIA_QA_DB' AND TABLE_NAME='$table'")" == 1 ]] ||
    die "OAuth table missing after migrations: $table"
done
[[ "$(mysql_qa information_schema -e \
  "SELECT COUNT(*) FROM COLUMNS WHERE TABLE_SCHEMA='$BAMBODIA_QA_DB' AND TABLE_NAME='oauth_clients' AND COLUMN_NAME='scopes'")" == 1 ]] ||
  die "OAuth client scopes column missing."
core_counts "$BAMBODIA_QA_DB" > "$RUN/after-counts.txt"
cmp -s "$RUN/core-counts.txt" "$RUN/after-counts.txt" ||
  die "Existing core record counts changed unexpectedly."
artisan_qa route:list --path=oauth --no-ansi > "$RUN/oauth-routes.txt"
grep -q 'oauth/authorize' "$RUN/oauth-routes.txt" || die "Authorization route missing."
grep -q 'oauth/token' "$RUN/oauth-routes.txt" || die "Token route missing."

info "PASSPORT_MIGRATIONS=PASS"
info "QA_PASSPORT_KEYS=CREATED"
info "EXISTING_CORE_COUNTS=UNCHANGED"
info "OAUTH_ROUTES=PASS"
info "REHEARSAL=PASS"
info "SNAPSHOT=$BACKUP"
info "PRODUCTION_UNCHANGED=YES"
info "NEXT=Run --restore-check with a separately provisioned empty QA restore database."
