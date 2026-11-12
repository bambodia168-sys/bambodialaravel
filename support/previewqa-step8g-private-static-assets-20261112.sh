#!/usr/bin/env bash
# Step 8G - private, static-only staging of verified Vite assets.
# --check: no persistent changes; --apply: QA-only private filesystem creation.
# Never copies Laravel PHP, .env, or private media into a public document root.
set -Eeuo pipefail
set +x
umask 077
fail() { printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }
[[ "$#" -eq 1 && ( "$1" = --check || "$1" = --apply ) ]] ||
  fail usage_check_or_apply
MODE="$1"
QA='/var/lib/bambodia-preview-qa-20261112'
REV='584e1b29697fd87227dc42c987342fa405ea5c09'
RECOVER='/var/lib/bambodia-preview-restore-20261112'
SRC="$RECOVER/public/build"
DST='/var/lib/bambodia-previewqa-static-20261112'
USER='bambodia_pvrsqa'
SVC='bambodia-previewqa-fpm.service'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'
TMP_MANIFEST=''
cleanup(){ if [[ -n "$TMP_MANIFEST" ]]; then rm -f -- "$TMP_MANIFEST"; fi; }
trap cleanup EXIT

echo '========== STEP 8G — STATIC ASSET SAFETY PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for bin in git stat find sha256sum sort xargs cp chmod chown install diff mktemp systemctl basename; do
  command -v "$bin" >/dev/null || fail "missing_binary:$bin"
done
[[ -d "$QA/.git" && ! -L "$QA" ]] || fail original_qa_missing
[[ "$(git -C "$QA" rev-parse HEAD)" = "$REV" ]] || fail original_qa_commit_changed
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] ||
  fail original_qa_source_changed
[[ -d "$RECOVER" && ! -L "$RECOVER" &&
  "$(realpath -e "$RECOVER")" = "$RECOVER" ]] || fail recovered_qa_missing
[[ "$(stat -c '%U:%G:%a' "$RECOVER")" = "$USER:$USER:700" ]] ||
  fail private_recovery_permissions_changed
[[ -f "$RECOVER/.env" && ! -L "$RECOVER/.env" &&
  "$(stat -c '%U:%G:%a' "$RECOVER/.env")" = "$USER:$USER:600" ]] ||
  fail private_env_permissions_changed
[[ -s "$RECOVER/storage/app/installed.lock" ]] || fail recovered_lock_missing
[[ -d "$SRC" && ! -L "$SRC" && -s "$SRC/manifest.json" ]] ||
  fail vite_build_missing
[[ ! -e "$DST" && ! -L "$DST" ]] ||
  fail asset_staging_already_exists_do_not_rerun
[[ ! -e /www/wwwroot/preview-qa.bambodia.com &&
   ! -L /www/wwwroot/preview-qa.bambodia.com ]] ||
  fail public_qa_docroot_already_exists
systemctl is-active --quiet "$SVC" || fail dedicated_php_fpm_inactive
[[ -S "$SOCKET" ]] || fail dedicated_php_fpm_socket_missing

if [[ -n "$(find "$SRC" -type l -print -quit)" ]] ||
   [[ -n "$(find "$SRC" ! -type f ! -type d -print -quit)" ]]; then
  fail unsupported_asset_symlinks_or_special_files
fi
COUNT="$(find "$SRC" -type f | wc -l | tr -d ' ')"
[[ "$COUNT" -eq 11 ]] || fail unexpected_build_asset_count
while IFS= read -r -d '' file; do
  base="$(basename -- "$file")"
  case "$base" in
    *.js|*.css|*.map|*.png|*.jpg|*.jpeg|*.webp|*.gif|*.svg|*.woff|*.woff2|*.ttf|*.otf|*.ico|*.avif|manifest.json)
      ;;
    *) printf 'UNRECOGNIZED_ASSET=%s\n' "$base" >&2
       fail asset_extension_not_allowlisted ;;
  esac
done < <(find "$SRC" -type f -print0)

TMP_MANIFEST="$(mktemp /root/bambodia-previewqa-assets.XXXXXXXX.sha256)"
(cd "$SRC" && find . -type f -print0 | sort -z | xargs -0 sha256sum) > "$TMP_MANIFEST"
[[ "$(wc -l < "$TMP_MANIFEST")" -eq "$COUNT" ]] || fail manifest_count
(cd "$SRC" && sha256sum --check --status "$TMP_MANIFEST") || fail source_asset_hashes

echo "ORIGINAL_STATIC_FILE_COUNT=$COUNT"
echo 'ASSET_EXTENSION_ALLOWLIST=PASS'
echo 'ASSET_SOURCE_SHA256=PASS'
echo 'PRIVATE_ENV_OR_MEDIA_INCLUDED=NO'
echo 'PHP_EXECUTABLE_ASSET_FILES=ZERO'

if [[ "$MODE" = --check ]]; then
  echo 'PERSISTENT_WRITES=NONE'
  echo 'STEP8G_CHECK=PASS'
  exit 0
fi
[[ "$(printenv BAMBODIA_QA_ASSET_STAGE || true)" = 'APPROVE_PRIVATE_ASSETS_ONLY' ]] ||
  fail explicit_operator_authorization_missing

echo '========== COPY TO PRIVATE ROOT-OWNED STAGING =========='
install -d -o root -g root -m 700 "$DST"
install -d -o root -g root -m 700 "$DST/build"
cp -a -- "$SRC"/. "$DST/build"/
chown -R root:root "$DST"
find "$DST" -type d -exec chmod 700 {} +
find "$DST" -type f -exec chmod 600 {} +
install -o root -g root -m 600 "$TMP_MANIFEST" "$DST/build.sha256"
[[ -z "$(find "$DST" -type l -print -quit)" ]] ||
  fail staged_symlink_detected_do_not_rerun
[[ -z "$(find "$DST" ! -type f ! -type d -print -quit)" ]] ||
  fail staged_special_file_detected_do_not_rerun
[[ "$(stat -c '%U:%G:%a' "$DST")" = 'root:root:700' ]] ||
  fail private_stage_permissions
[[ "$(stat -c '%U:%G:%a' "$DST/build.sha256")" = 'root:root:600' ]] ||
  fail private_manifest_permissions
[[ "$(find "$DST/build" -type f | wc -l | tr -d ' ')" -eq 11 ]] ||
  fail staged_asset_count
(cd "$DST/build" && sha256sum --check --status "$DST/build.sha256") ||
  fail staged_asset_checksum_failed_do_not_rerun
diff -qr -- "$SRC" "$DST/build" >/dev/null ||
  fail staged_assets_differ_do_not_rerun

echo '========== STEP 8G PRIVATE STATIC ASSET ACCEPTANCE =========='
echo 'PRIVATE_STATIC_STAGING=CREATED'
echo 'STAGING_DIRECTORY=/var/lib/bambodia-previewqa-static-20261112'
echo 'STAGING_OWNER=root:root:700'
echo 'STAGED_ASSETS_SHA256=PASS'
echo 'STAGED_PHP_FILES=ZERO'
echo 'STAGED_PRIVATE_MEDIA=ZERO'
echo 'ORIGINAL_QA_AND_RECOVERY=UNMODIFIED'
echo 'FPM_NGINX_DNS_TLS_CHANGES=NONE'
echo 'PUBLIC_QA_HOST=NOT_CREATED'
echo 'SHARED_WWW_FASTCGI_ACCESS=REQUIRES_SECURITY_REVIEW'
echo 'STEP8G=PASS'
