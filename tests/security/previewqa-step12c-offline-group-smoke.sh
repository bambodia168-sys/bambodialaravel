#!/usr/bin/env bash
# Regression proof: Step 12C offline group substitution cannot depend on an
# OS group not yet created, or accidentally expand a Bash "$#" sed expression.
set -Eeuo pipefail
set +x
umask 077
cd "$(dirname "$0")/../.."
SOURCE='support/previewqa-step12c-private-http-install-20261112.sh'
test -f "$SOURCE"
test "$(grep -Fc 'STEP12C_CHECK=PASS' "$SOURCE")" -eq 1
test "$(grep -Fc "echo 'STEP12C=PASS'" "$SOURCE")" -eq 1
grep -Fq 'awk -v before="listen.group = $INGRESS" -v after="listen.group = $WORKER"' "$SOURCE"
if grep -Fq 'sed "s#^listen.group = $INGRESS$#listen.group = $WORKER#"' "$SOURCE"; then
  echo 'BLOCKED=unsafe_sed_end_of_line_anchor' >&2; exit 2
fi
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
WORKER='bambodia_pvrsqa'
INGRESS='bambodia_pvhttp'
printf '[previewqa]\nlisten.group = %s\nlisten.mode = 0660\npm.max_children = 2\n' "$INGRESS" > "$TMP/from.conf"
awk -v before="listen.group = $INGRESS" -v after="listen.group = $WORKER" '
  $0 == before { print after; changed++; next }
  { print }
  END { if (changed != 1) exit 2 }
' "$TMP/from.conf" > "$TMP/to.conf"
grep -Fxq "listen.group = $WORKER" "$TMP/to.conf"
grep -Fxq 'listen.mode = 0660' "$TMP/to.conf"
test "$(wc -l < "$TMP/to.conf")" -eq 4
# Must fail closed if the original group line is absent.
printf '[previewqa]\nlisten.group = unexpected\n' > "$TMP/bad.conf"
if awk -v before="listen.group = $INGRESS" -v after="listen.group = $WORKER" '
  $0 == before { print after; changed++; next }
  { print }
  END { if (changed != 1) exit 2 }
' "$TMP/bad.conf" >/dev/null; then
  echo 'BLOCKED=unknown_fpm_group_not_rejected' >&2; exit 2
fi
echo 'STEP12C_OFFLINE_GROUP_REGRESSION=PASS'
