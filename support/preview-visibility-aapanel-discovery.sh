#!/usr/bin/env bash
# Read-only aaPanel host discovery. Never install, stop services, open ports,
# create databases, inspect secret values or modify Bambodia production.
set -Eeuo pipefail
umask 077
[[ $# -eq 1 && "$1" == '--check' ]] || { echo 'Usage: bash SCRIPT --check' >&2; exit 2; }
[[ "$(id -u)" -eq 0 ]] || { echo 'BLOCKED: Run from the existing aaPanel root terminal.' >&2; exit 2; }
echo '========== BAMBODIA PREVIEW QA HOST DISCOVERY (READ ONLY) =========='
echo "DATE_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "KERNEL=$(uname -s)"
echo "ARCH=$(uname -m)"
for cmd in git gh composer node npm mysql mysqldump nginx curl tar gzip sha256sum; do
  if command -v "$cmd" >/dev/null 2>&1; then
    echo "BIN_$cmd=AVAILABLE"
  else
    echo "BIN_$cmd=MISSING"
  fi
done
for v in 85 84; do
  p="/www/server/php/$v/bin/php"
  if [[ -x "$p" ]]; then
    echo "PHP_$v=$("$p" -r 'echo PHP_VERSION;')"
    for ext in pdo_mysql mbstring openssl fileinfo; do
      if "$p" -r 'exit(extension_loaded($argv[1])?0:1);' "$ext"; then
        echo "PHP_${v}_${ext}=PASS"
      else
        echo "PHP_${v}_${ext}=MISSING"
      fi
    done
  else
    echo "PHP_$v=NOT_INSTALLED"
  fi
done
for service in nginx mysqld mysql cron; do
  if systemctl is-active --quiet "$service" 2>/dev/null; then
    echo "SERVICE_$service=ACTIVE"
  else
    echo "SERVICE_$service=INACTIVE_OR_UNAVAILABLE"
  fi
done
for path in /www/wwwroot/bambodia-staging-qa /www/wwwroot/bambodia.com; do
  label="${path##*/}"
  if [[ -d "$path" ]]; then
    echo "PROTECTED_${label}=PRESENT_NO_CHANGES"
  else
    echo "PROTECTED_${label}=NOT_FOUND"
  fi
done
QA_APP="${BAMBODIA_PREVIEW_QA_APP:-/var/lib/bambodia-preview-qa-20261112}"
if [[ -L "$QA_APP" ]]; then
  echo 'QA_APP=BLOCKED_SYMLINK'
elif [[ -d "$QA_APP" ]]; then
  if [[ -f "$QA_APP/artisan" && -f "$QA_APP/.env" ]]; then
    echo 'QA_APP=FOUND_REQUIRES_INDEPENDENT_ISOLATION_PROOF'
  else
    echo 'QA_APP=FOUND_NOT_INSTALLED'
  fi
else
  echo 'QA_APP=NOT_PROVISIONED'
fi
for path in /var/lib /root /www/wwwroot; do
  if [[ -d "$path" ]]; then
    df -Pk "$path" | awk -v p="$path" 'NR==2{printf "FREE_KB_%s=%s\n",p,$4}'
  fi
done
echo 'PRODUCTION_WRITES=NONE'
echo 'NEW_QA_INSTALLATION=NOT_PERFORMED'
echo 'QA_BACKUP_RESTORE=NOT_PERFORMED'
echo 'PHYSICAL_DEVICE_ACCEPTANCE=NOT_PERFORMED'
echo 'DISCOVERY=COMPLETE'
