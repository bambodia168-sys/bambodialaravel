#!/usr/bin/env bash
# Bambodia Step 34B.5 - preserve an existing occupied site before ANY reuse.
# Creates an offline root-only backup of site files and Nginx vhost.
# Does not copy TLS private keys or modify the existing site or QA database.
set -Eeuo pipefail
set +x
umask 077

HOST="oauth-qa.bambodia.com"
SITE="/www/wwwroot/$HOST"
VHOST="/www/server/panel/vhost/nginx/$HOST.conf"
CERTDIR="/www/server/panel/vhost/cert/$HOST"
QA="/var/lib/bambodia-oauth-browser-qa"
QA_SHA="34e86fbbdfcbe56e5e28e0286a47ba2309e48455"
SITE_SHA256="0bae6089419ea8f95d7ddf86ba40a6b6c8ae6fca852dd9b70351f869e19df27b"
VHOST_SHA256="c5e89eb3a29e3e923fb1a82d47b0add50198af45d673f1235956ca7dbeb61c94"
MYSQL="/www/server/mysql/bin/mysql"
CNF="/root/bambodia-oauth-browser-qa.cnf"
DB="bambodia_oauthqa_browser"
STORE="/var/backups"
BACKUP=""

echo '========== CONFIRM ISOLATED QA AND EXISTING SITE =========='
test "$(id -u)" -eq 0
test -d "$QA/.git" && test ! -L "$QA"
test "$(realpath -e "$QA")" = "$QA"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"
test -f "$QA/.env" && test ! -L "$QA/.env"
grep -Fxq 'APP_URL=https://oauth-browser-qa.invalid' "$QA/.env"
test -s "$QA/public/build/manifest.json"
test -d "$SITE" && test ! -L "$SITE"
test "$(realpath -e "$SITE")" = "$SITE"
test -f "$SITE/index.html" && test ! -L "$SITE/index.html"
test -f "$VHOST" && test ! -L "$VHOST"
test -d "$CERTDIR" && test ! -L "$CERTDIR"
test -f "$CERTDIR/fullchain.pem" && test ! -L "$CERTDIR/fullchain.pem"
test "$(stat -c '%u:%g:%a' "$SITE")" = "1000:1000:755"
test "$(sha256sum "$SITE/index.html" | cut -d ' ' -f1)" = "$SITE_SHA256"
test "$(sha256sum "$VHOST" | cut -d ' ' -f1)" = "$VHOST_SHA256"
test -d "$STORE" && test ! -L "$STORE"
test -f "$CNF" && test ! -L "$CNF"
test "$(stat -c '%u:%a' "$CNF")" = "0:600"
test -x "$MYSQL"
for tool in tar sha256sum mktemp install stat python3 openssl; do
  command -v "$tool" >/dev/null
done
# No symlinks can be silently redirected out of the existing site tree.
if find "$SITE" -type l -print -quit | grep -q .; then
  echo 'EXISTING_SITE_NESTED_SYMLINK=BLOCKED'
  exit 35
fi

QA_MYSQL() {
  "$MYSQL" --defaults-extra-file="$CNF" --protocol=TCP \
    --host=127.0.0.1 --batch --skip-column-names "$DB" "$@"
}
test "$(QA_MYSQL -e 'SELECT DATABASE()')" = "$DB"
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM users')" = 1
test "$(QA_MYSQL -e 'SELECT COUNT(*) FROM oauth_clients')" = 1
test "$(QA_MYSQL -e "SELECT COUNT(*) FROM users WHERE email='oauth-admin@oauth-browser-qa.invalid' AND role='super_admin' AND account_status='active'")" = 1
for table in oauth_auth_codes oauth_access_tokens oauth_refresh_tokens; do
  test "$(QA_MYSQL -e "SELECT COUNT(*) FROM $table WHERE revoked=0")" = 0
done
echo 'SOURCE_AND_EXISTING_SITE_FINGERPRINTS=PASS'
echo 'QA_DATABASE_BASELINE=PASS'
echo 'ACTIVE_OAUTH_GRANTS=0'

echo '========== REQUIRE EXPLICIT BACKUP-ONLY APPROVAL =========='
test -c /dev/tty
echo 'This operation creates a root-only backup under /var/backups.'
echo 'It will not replace the existing site, Nginx configuration or TLS files.'
printf 'Type BACKUP-OAUTH-QA-HOST-ONLY to approve: ' > /dev/tty
IFS= read -r approval < /dev/tty
if [ "$approval" != "BACKUP-OAUTH-QA-HOST-ONLY" ]; then
  echo 'STEP34B5_APPROVAL=DECLINED'
  exit 1
fi
echo 'STEP34B5_APPROVAL=PASS'

echo '========== CREATE RESTRICTED PRESERVATION BACKUP =========='
BACKUP="$(mktemp -d "$STORE/bambodia-oauth-qa-preserve.XXXXXXXX")"
test -d "$BACKUP" && test ! -L "$BACKUP"
chmod 700 "$BACKUP"
echo "RESTRICTED_BACKUP_DIRECTORY=$BACKUP"

# Site archive retains existing dotfiles, ACLs and ownership. It stays off webroot.
tar --acls --xattrs --numeric-owner -cf "$BACKUP/site.tar" \
  -C "/www/wwwroot" "$HOST"
install -m 600 "$VHOST" "$BACKUP/nginx-vhost.conf"
test "$(sha256sum "$BACKUP/nginx-vhost.conf" | cut -d ' ' -f1)" = "$VHOST_SHA256"
test -s "$BACKUP/site.tar"
test -s "$BACKUP/nginx-vhost.conf"

# Record a public-only certificate fingerprint; never copy/read TLS private key.
openssl x509 -in "$CERTDIR/fullchain.pem" -noout -fingerprint -sha256 \
  > "$BACKUP/public-cert-sha256.txt"
test -s "$BACKUP/public-cert-sha256.txt"

cat > "$BACKUP/RESTORE_NOTES.txt" <<'NOTES'
Bambodia OAuth QA hostname - PRESERVATION ONLY.
Contains an archived snapshot of the existing site files (including dotfiles),
the exact previous Nginx vhost, and the public certificate fingerprint.
This is NOT an authorized deployment or a tested rollback.
The existing public TLS certificate and private key remain in their original
server paths; no private key was copied.
Do not extract the archive into live webroot or restore the vhost without
separate, explicit operator approval and a restore rehearsal.
Do not upload this restricted backup to GitHub, ChatGPT or the public site.
NOTES

(
  cd "$BACKUP"
  sha256sum site.tar nginx-vhost.conf public-cert-sha256.txt \
    RESTORE_NOTES.txt > SHA256SUMS
  sha256sum -c SHA256SUMS >/dev/null
)
tar -tf "$BACKUP/site.tar" >/dev/null
chmod 600 "$BACKUP"/*
test "$(stat -c '%u:%a' "$BACKUP")" = "0:700"
python3 - "$BACKUP" <<'PY'
import pathlib
import tarfile
import sys

root = pathlib.Path(sys.argv[1])
archive = root / "site.tar"
with tarfile.open(archive, mode="r") as tf:
    members = tf.getmembers()
    assert members, "Archive is empty"
    for m in members:
        p = pathlib.PurePosixPath(m.name)
        assert p.parts and p.parts[0] == "oauth-qa.bambodia.com"
        assert not p.is_absolute() and ".." not in p.parts
        assert m.isfile() or m.isdir(), "Unexpected tar member type"
    names = [m.name for m in members]
    assert "oauth-qa.bambodia.com/index.html" in names
    print("BACKUP_ARCHIVE_MEMBER_COUNT=" + str(len(members)))
for name in ["site.tar", "nginx-vhost.conf", "public-cert-sha256.txt",
             "RESTORE_NOTES.txt", "SHA256SUMS"]:
    p = root / name
    assert p.is_file() and not p.is_symlink()
    assert p.stat().st_mode & 0o077 == 0
print("BACKUP_DIRECTORY_AND_FILE_PERMISSIONS=PASS")
PY

# Recheck original files and frozen QA after backup, while still read-only.
test "$(sha256sum "$SITE/index.html" | cut -d ' ' -f1)" = "$SITE_SHA256"
test "$(sha256sum "$VHOST" | cut -d ' ' -f1)" = "$VHOST_SHA256"
test "$(git -C "$QA" rev-parse HEAD)" = "$QA_SHA"
test -z "$(git -C "$QA" status --porcelain --untracked-files=no)"

echo 'STEP34B5_OFFLINE_PRESERVATION_BACKUP=PASS'
echo 'BACKUP_CHECKSUM_AND_ARCHIVE_STRUCTURE=PASS'
echo 'EXISTING_SITE_AND_VHOST_UNCHANGED=PASS'
echo 'QA_SOURCE_AND_DATABASE_UNCHANGED=PASS'
echo 'CERTIFICATE_PRIVATE_KEY_COPIED=NO'
echo 'RESTORE_REHEARSAL=NOT_PERFORMED'
echo 'HOSTNAME_REUSE_APPROVAL=NOT_GRANTED'
echo 'DNS_CHANGES=NONE'
echo 'NGINX_OR_TLS_CHANGES=NONE'
echo 'PRODUCTION_DEPLOYMENT=NONE'
