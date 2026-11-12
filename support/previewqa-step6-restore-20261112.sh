#!/usr/bin/env bash
# Real aaPanel QA: create only synthetic fixtures, provision a separate empty
# restore schema, make a QA-only backup, and verify its restoration.
# This is NOT production recovery or real-browser/device acceptance.
set -Eeuo pipefail
set +x
umask 077

QA='/var/lib/bambodia-preview-qa-20261112'
SHA='584e1b29697fd87227dc42c987342fa405ea5c09'
DB='bambodia_previewqa_20261112'
DB_USER='pvqa_20261112'
DEST='bambodia_previewrestore_20261112'
SRC_ACCOUNT='pvqa_snap_20261112'
RESTORE_ACCOUNT='pvrs_20261112'
ORIGIN='http://127.0.0.1:8096'
QA_CNF='/root/bambodia-previewqa-20261112-mysql.cnf'
SRC_CNF='/root/bambodia-previewqa-20261112-snapshot.cnf'
RESTORE_CNF='/root/bambodia-previewqa-20261112-restore.cnf'
BACKUP_ROOT='/root/Bambodia-PreviewQA-Restore-Proof'
CONFIG_DIR='/root/Bambodia-PreviewQA-Private-Config-20261112'
PHP='/www/server/php/85/bin/php'
MYSQL="$(command -v mysql || true)"

fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

printf '========== STEP 6 — SCOPE AND SOURCE QA PREFLIGHT ==========\n'
[[ "$(id -u)" -eq 0 ]] || fail 'root_operator_required'
[[ -d "$QA/.git" && ! -L "$QA" && "$(realpath -e -- "$QA")" = "$QA" ]] || fail 'qa_app_path'
[[ "$(git -C "$QA" rev-parse HEAD)" = "$SHA" ]] || fail 'qa_source_commit'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail 'source_modified'
[[ -f "$QA/.env" && ! -L "$QA/.env" && "$(stat -c %a "$QA/.env")" = '600' ]] || fail 'private_qa_env'
[[ -f "$QA_CNF" && ! -L "$QA_CNF" && "$(stat -c %a "$QA_CNF")" = '600' ]] || fail 'qa_mysql_client'
[[ -f "$QA/vendor/autoload.php" && -f "$QA/public/build/manifest.json" && -x "$PHP" ]] || fail 'qa_runtime_missing'
[[ ! -e "$QA/bootstrap/cache/config.php" && ! -e "$QA/storage/app/installed.lock" ]] || fail 'unexpected_bootstrap_or_install_lock'
[[ ! -e "$SRC_CNF" && ! -e "$RESTORE_CNF" ]] || fail 'credential_file_already_exists_do_not_rerun'
[[ ! -e "$CONFIG_DIR" ]] || fail 'private_config_backup_already_exists_do_not_rerun'
[[ ! -e "$QA/storage/app/private/qa-synthetic/restore-proof-20261112.txt" ]] || fail 'synthetic_media_already_exists_do_not_rerun'
[[ ! -L "$QA/storage" && ! -L "$QA/storage/app/private" && ! -L "$QA/storage/app/public" ]] || fail 'storage_symlink'
[[ -z "$(find "$QA/storage" -type l -print -quit)" ]] || fail 'nested_storage_symlink'
if [[ ! -x "$MYSQL" ]]; then MYSQL='/www/server/mysql/bin/mysql'; fi
[[ -x "$MYSQL" ]] || fail 'mysql_cli_missing'

export BAMBODIA_PREVIEW_QA_ISOLATED=1
export BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN="$ORIGIN"
export BAMBODIA_PREVIEW_QA_PHP="$PHP"
export BAMBODIA_PREVIEW_QA_RESTORE_CONFIRM="$DEST"
export BAMBODIA_PREVIEW_QA_BACKUP_ROOT="$BACKUP_ROOT"

"$PHP" "$QA/support/preview-visibility-qa-preflight.php" --after-install || fail 'post_install_isolation_preflight'

[[ "$DB" =~ ^bambodia_previewqa_[a-z0-9_]{4,30}$ ]] || fail 'source_database_name'
[[ "$DEST" =~ ^bambodia_previewrestore_[a-z0-9_]{4,30}$ ]] || fail 'restore_database_name'

printf '========== CHECK EMPTY QA FIXTURE INVENTORY ==========\n'
for table in users marketplace_listings marketplace_jobs preview_visibility_actions; do
    count="$("$MYSQL" --defaults-extra-file="$QA_CNF" --batch --skip-column-names "$DB" \
      -e "SELECT COUNT(*) FROM \`$table\`")" || fail "inventory_query_failed:$table"
    [[ "$count" = 0 ]] || fail "qa_fixtures_already_present:$table"
done
migration_count="$("$MYSQL" --defaults-extra-file="$QA_CNF" --batch --skip-column-names "$DB" \
  -e 'SELECT COUNT(*) FROM migrations')" || fail 'migration_count_query_failed'
[[ "$migration_count" = '62' ]] || fail 'unexpected_migration_inventory'
printf 'QA_INITIAL_FIXTURES=EMPTY\n'
printf 'QA_MIGRATIONS_VERIFIED=%s\n' "$migration_count"

printf '========== CREATE SEPARATE RESTORE DB AND RESTRICTED ACCOUNTS ==========\n'
printf 'Enter the MySQL root password in the terminal when prompted.\n'
read -r db_count user_count < <(
  "$MYSQL" --protocol=socket --user=root --password --batch --skip-column-names \
    -e "SELECT (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$DEST'),
               (SELECT COUNT(*) FROM mysql.user WHERE User IN ('$SRC_ACCOUNT','$RESTORE_ACCOUNT'));" \
    | tr '\t' ' '
) || fail 'mysql_root_precheck_failed'
[[ "$db_count" = 0 && "$user_count" = 0 ]] || fail 'restore_db_or_snapshot_users_already_exist'

SNAP_PASSWORD="$("$PHP" -r 'echo bin2hex(random_bytes(32));')"
RESTORE_PASSWORD="$("$PHP" -r 'echo bin2hex(random_bytes(32));')"
[[ "${#SNAP_PASSWORD}" -eq 64 && "${#RESTORE_PASSWORD}" -eq 64 ]] || fail 'credential_generation_failed'

# Persist root-private credentials before CREATE USER so a partial SQL failure
# can be diagnosed without repeating the creation statements.
{
  printf '[client]\n'
  printf 'host=127.0.0.1\nport=3306\nprotocol=tcp\n'
  printf 'user=%s\npassword="%s"\n' "$SRC_ACCOUNT" "$SNAP_PASSWORD"
} > "$SRC_CNF"
{
  printf '[client]\n'
  printf 'host=127.0.0.1\nport=3306\nprotocol=tcp\n'
  printf 'user=%s\npassword="%s"\n' "$RESTORE_ACCOUNT" "$RESTORE_PASSWORD"
} > "$RESTORE_CNF"
chmod 600 "$SRC_CNF" "$RESTORE_CNF"
unset SNAP_PASSWORD RESTORE_PASSWORD

SQL="$(mktemp /root/bambodia-previewqa-restore-create.XXXXXXXX.sql)"
trap 'rm -f -- "$SQL"' EXIT
chmod 600 "$SQL"
# Read the fresh credentials privately into Python? Avoid parsing a shell client
# file by keeping the private values generated in variables until SQL creation.
# The cnf files hold only random hex strings; read through a private parse.
SNAP_PASS="$(sed -n 's/^password="\([0-9a-f]*\)"$/\1/p' "$SRC_CNF")"
DST_PASS="$(sed -n 's/^password="\([0-9a-f]*\)"$/\1/p' "$RESTORE_CNF")"
[[ "${#SNAP_PASS}" -eq 64 && "${#DST_PASS}" -eq 64 ]] || fail 'client_credential_format'
cat > "$SQL" <<SQL
CREATE DATABASE \`$DEST\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$SRC_ACCOUNT'@'127.0.0.1' IDENTIFIED BY '$SNAP_PASS';
GRANT SELECT, SHOW VIEW, TRIGGER ON \`$DB\`.* TO '$SRC_ACCOUNT'@'127.0.0.1';
CREATE USER '$RESTORE_ACCOUNT'@'127.0.0.1' IDENTIFIED BY '$DST_PASS';
GRANT ALL PRIVILEGES ON \`$DEST\`.* TO '$RESTORE_ACCOUNT'@'127.0.0.1';
SQL
unset SNAP_PASS DST_PASS
if ! "$MYSQL" --protocol=socket --user=root --password < "$SQL"; then
  fail 'partial_restore_schema_or_accounts_possible_do_not_rerun'
fi
rm -f -- "$SQL"
trap - EXIT

[[ "$("$MYSQL" --defaults-extra-file="$SRC_CNF" --batch --skip-column-names "$DB" -e 'SELECT DATABASE()')" = "$DB" ]] || fail 'snapshot_account_denied'
[[ "$("$MYSQL" --defaults-extra-file="$RESTORE_CNF" --batch --skip-column-names "$DEST" -e 'SELECT DATABASE()')" = "$DEST" ]] || fail 'restore_account_denied'
[[ "$("$MYSQL" --defaults-extra-file="$RESTORE_CNF" --batch --skip-column-names "$DEST" \
  -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type="BASE TABLE"')" = 0 ]] || fail 'restore_db_not_empty'
printf 'RESTORE_SCHEMA=NEW_AND_EMPTY\n'
printf 'SOURCE_SNAPSHOT_ACCOUNT=DB_READ_ONLY\n'
printf 'DESTINATION_ACCOUNT=RESTORE_SCHEMA_SCOPED\n'

printf '========== CREATE ONLY SYNTHETIC QA FIXTURES ==========\n'
"$PHP" -r '
$root=$argv[1];
require $root."/vendor/autoload.php";
$app=require $root."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db="bambodia_previewqa_20261112";
if (config("app.env")!=="testing" || config("app.debug")!==false ||
    config("app.url")!=="http://127.0.0.1:8096" ||
    config("database.connections.mysql.database")!==$db ||
    config("preview_visibility.enabled")!==false) exit(2);
foreach (["users", "marketplace_listings", "marketplace_jobs", "preview_visibility_actions"] as $table) {
    if (\Illuminate\Support\Facades\DB::table($table)->count() !== 0) exit(2);
}
\Illuminate\Support\Facades\DB::transaction(static function (): void {
    $owner=\App\Models\User::query()->create([
        "name"=>"Synthetic QA Restore Creator",
        "email"=>"restore.creator.20261112@example.invalid",
        "password"=>\Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(24))),
        "role"=>"freelancer",
    ]);
    $customer=\App\Models\User::query()->create([
        "name"=>"Synthetic QA Restore Customer",
        "email"=>"restore.customer.20261112@example.invalid",
        "password"=>\Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(24))),
        "role"=>"customer",
    ]);
    \App\Models\MarketplaceListing::query()->create([
        "user_id"=>$owner->id,
        "type"=>"service",
        "title"=>"[QA PREVIEW] Synthetic restoration listing",
        "description"=>"QA-only fake listing; never sourced from real customers.",
        "status"=>"published",
    ]);
    \App\Models\MarketplaceJob::query()->create([
        "client_id"=>$customer->id,
        "title"=>"[QA PREVIEW] Synthetic restoration job",
        "description"=>"QA-only fake job; no real applications or customer records.",
        "category"=>"development",
        "status"=>"published",
    ]);
});
foreach (["users"=>2,"marketplace_listings"=>1,"marketplace_jobs"=>1] as $table=>$expected) {
    if (\Illuminate\Support\Facades\DB::table($table)->count()!==$expected) exit(2);
}
echo "SYNTHETIC_QA_RECORDS=PASS\n";
' "$QA" || fail 'synthetic_qa_fixture_creation_failed_do_not_rerun'

install -d -m 700 "$QA/storage/app/private/qa-synthetic"
printf 'Synthetic Bambodia QA private file for backup/restore SHA-256 verification. No real customer data.\n' \
  > "$QA/storage/app/private/qa-synthetic/restore-proof-20261112.txt"
chmod 600 "$QA/storage/app/private/qa-synthetic/restore-proof-20261112.txt"

"$PHP" "$QA/support/preview-visibility-qa-preflight.php" --audit || fail 'post_fixture_qa_audit'

printf '========== PRESERVE ORIGINAL QA APP KEY AND CONFIG PRIVATELY ==========\n'
install -d -m 700 "$CONFIG_DIR"
install -m 600 "$QA/.env" "$CONFIG_DIR/qa-app.env"
cmp -s "$QA/.env" "$CONFIG_DIR/qa-app.env" || fail 'encrypted_config_backup_mismatch'
[[ -z "$(find "$CONFIG_DIR" -type l -print -quit)" ]] || fail 'config_symlink'
printf 'QA_APP_CONFIG_BACKUP=PASS_PRIVATE_0600\n'

printf '========== ACTUAL AAPANEL RESTORE READ-ONLY PREFLIGHT ==========\n'
bash "$QA/support/preview-visibility-qa-restore-proof.sh" \
    --check "$QA" "$DB" "$DEST" "$SRC_CNF" "$RESTORE_CNF" || fail 'restore_preflight'

printf '========== ACTUAL AAPANEL BACKUP AND SEPARATE DB RESTORE ==========\n'
RESULT="$(mktemp /root/bambodia-previewqa-restore-result.XXXXXXXX.log)"
chmod 600 "$RESULT"
if ! bash "$QA/support/preview-visibility-qa-restore-proof.sh" \
      --apply "$QA" "$DB" "$DEST" "$SRC_CNF" "$RESTORE_CNF" > "$RESULT" 2>&1; then
  echo "QA_RESTORE_PRIVATE_LOG=$RESULT"
  fail 'restore_proof_failed_do_not_rerun_or_drop_target'
fi
for expected in 'QA_DB_RESTORE=PASS' 'QA_MEDIA_RESTORE=PASS' 'SOURCE_DATA=UNCHANGED' 'SOURCE_SWITCH=DISABLED'; do
  grep -Fxq "$expected" "$RESULT" || fail "restore_evidence_missing:$expected"
done
cat "$RESULT"

"$PHP" "$QA/support/preview-visibility-qa-preflight.php" --audit || fail 'source_changed_after_restore'
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail 'qa_source_modified'

printf '========== STEP 6 RESULT ==========\n'
printf 'AAPANEL_MYSQL_RESTORE=PASS\n'
printf 'AAPANEL_PRIVATE_MEDIA_RESTORE=PASS\n'
printf 'ORIGINAL_QA_ENCRYPTION_CONFIG=SAVED_PRIVATELY\n'
printf 'SOURCE_DATABASE=UNCHANGED_BY_RESTORE_SCRIPT\n'
printf 'WITHDRAWAL_WRITES=DISABLED\n'
printf 'RECOVERED_LARAVEL_APP_BOOT=NOT_TESTED\n'
printf 'HTTPS_BROWSER_ACCEPTANCE=NOT_TESTED\n'
printf 'PHYSICAL_DEVICE_ACCEPTANCE=NOT_TESTED\n'
printf 'PRODUCTION_DATABASE=NOT_TOUCHED_BY_SCRIPT\n'
printf 'STEP6=PASS\n'