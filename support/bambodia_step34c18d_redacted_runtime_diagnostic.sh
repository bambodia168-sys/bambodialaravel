#!/usr/bin/env bash
# Step34C.18D: one-time, REDACTED diagnostic only. Does not grant acceptance.
# --check is read-only. --diagnose requires a NEW explicit operator approval
# before repeating the disposable private QA transaction with sanitized telemetry.
set -Eeuo pipefail
set +x
umask 077
MODE="$1"
case "$MODE" in --check|--diagnose) ;; *)
 echo "Usage: script --check|--diagnose" >&2; exit 64 ;;
esac

ORIG="/root/bambodia_step34c18_access_revocation_bearer_rollback.sh"
SHA="57180494562127a626e747a0470dae085e24b1fd4d426b0090efb203d5909595"
fail(){ printf 'STEP34C18D_BLOCKED=%s\n' "$1" >&2; exit 34; }

echo '========== VERIFY IMMUTABLE ORIGINAL FAILED TEST =========='
test "$(id -u)" -eq 0 || fail ROOT_REQUIRED
for t in python3 sha256sum stat bash; do
 command -v "$t" >/dev/null || fail TOOL_MISSING
done
test -f "$ORIG" && test ! -L "$ORIG" &&
 test "$(stat -c '%u:%a' "$ORIG")" = "0:700" &&
 test "$(sha256sum "$ORIG" | awk '{print $1}')" = "$SHA" ||
 fail ORIGINAL_TEST_SOURCE_HASH_OR_MODE_MISMATCH
bash -n "$ORIG"
python3 - "$ORIG" <<'PY'
import pathlib,sys
s=pathlib.Path(sys.argv[1]).read_text('utf-8')
need={
 'if len(p.stdout)>32768 or any(len(s)>130 or not s.startswith(prefixes)':1,
 'for line in lines:print(line)':1,
 'if p.returncode!=0 or not all(x in lines for x in needed):':1,
 'REVOKE-PRIVATE-OAUTH-ACCESS-BEARER-ROLLBACK':2,
 'STEP34C18_PRIVATE_ACCESS_REVOCATION_AND_ROLLBACK=PASS':1,
}
if any(s.count(k)!=n for k,n in need.items()):
 raise SystemExit('STEP34C18D_BLOCKED=ORIGINAL_CAPTURE_CONTRACT_CHANGED')
print('ORIGINAL_RUNTIME_CAPTURE_CONTRACT=VERIFIED')
PY

echo '========== RUN PINNED READ-ONLY SAFETY GATES =========='
bash "$ORIG" --check
echo 'STEP34C18D_READ_ONLY_PREFLIGHT=PASS'
if [ "$MODE" = --check ]; then
 echo 'QA_TRANSACTION_OR_HTTP_KERNEL=NOT_STARTED'
 echo 'PRODUCTION_NGINX_AND_DATABASE_WRITES=NONE'
 exit 0
fi

echo '========== REQUIRE NEW PRIVATE DIAGNOSTIC APPROVAL =========='
test -c /dev/tty || fail TERMINAL_REQUIRED
echo 'Runs a fresh disposable QA transaction, not an OAuth acceptance test.'
echo 'Prints only fixed stage flags and numeric stdout statistics, never raw PHP output.'
echo 'No production, Nginx or public OAuth hostname changes are authorized.'
printf 'Type DIAGNOSE-PRIVATE-MCP-REVOCATION-OUTPUT: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'DIAGNOSE-PRIVATE-MCP-REVOCATION-OUTPUT' ||
 fail OPERATOR_DECLINED
echo 'STEP34C18D_OPERATOR_DIAGNOSTIC_APPROVAL=PASS'

TEMP="$(mktemp /root/bambodia-34c18d-script.XXXXXXXX)"
LOG="$(mktemp /root/bambodia-34c18d-log.XXXXXXXX)"
trap 'rm -f -- "$TEMP" "$LOG"' EXIT

echo '========== CREATE TRANSIENT REDACTED INSTRUMENTATION =========='
python3 - "$ORIG" "$TEMP" <<'PY'
import pathlib,sys
s=pathlib.Path(sys.argv[1]).read_text('utf-8')
old='''if len(p.stdout)>32768 or any(len(s)>130 or not s.startswith(prefixes)
                                for s in lines):
 raise SystemExit("STEP34C18_BLOCKED=UNEXPECTED_PHP_OUTPUT_REDACTED")
for line in lines:print(line)
needed=['''
new='''# Diagnostic-only counters. Never echo PHP stdout, JWTs or raw responses.
bad=[l for l in lines if not l.startswith(prefixes)]
oversized=[l for l in lines if len(l)>130]
size=len(p.stdout)
band=("ZERO" if size==0 else "UNDER_4K" if size<4096 else
      "4K_TO_32K" if size<=32768 else "OVER_32K")
print("STEP34C18D_PHP_EXIT_CLASS="+("ZERO" if p.returncode==0 else "NONZERO"))
print("STEP34C18D_STDOUT_SIZE_BAND="+band)
print("STEP34C18D_STDOUT_LINE_COUNT="+str(len(lines)))
print("STEP34C18D_UNKNOWN_LINE_COUNT="+str(len(bad)))
print("STEP34C18D_LONG_LINE_COUNT="+str(len(oversized)))
print("STEP34C18D_JSON_LIKE_LINE_COUNT="+str(sum(
 l.lstrip().startswith(("{","[")) for l in bad)))
print("STEP34C18D_SSE_LIKE_LINE_COUNT="+str(sum(
 l.startswith(("data:","event:")) for l in bad)))
for label,marker in (
 ("ACTIVE_BEARER","ACTIVE_ACCESS_MCP_BEARER=NOT_REJECTED_BY_AUTH_MIDDLEWARE"),
 ("REVOCATION","EXPLICIT_ACCESS_TOKEN_REPOSITORY_REVOCATION=PASS"),
 ("REVOKED_BEARER","REVOKED_ACCESS_MCP_BEARER=REJECTED_WITH_BEARER_CHALLENGE"),
 ("ROLLBACK","DISPOSABLE_QA_ADMIN_CLIENT_CODE_ACCESS_REFRESH=ROLLED_BACK")
):
 print("STEP34C18D_"+label+"_MARKER="+("SEEN" if marker in lines else "NOT_SEEN"))
for label in ("ACTIVE_ACCESS_MCP_BEARER_STATUS",
              "REVOKED_ACCESS_MCP_BEARER_STATUS"):
 values=[l.partition("=")[2] for l in lines if l.startswith(label+"=")]
 good=[v for v in values if len(v)==3 and v.isascii() and v.isdecimal()]
 print("STEP34C18D_"+label+"="+(good[-1] if good else "NOT_CAPTURED"))
needed=['''
if s.count(old)!=1:
 raise SystemExit('STEP34C18D_BLOCKED=OUTPUT_REDATION_PATCH_MISMATCH')
s=s.replace(old,new)
end='''if p.returncode!=0 or not all(x in lines for x in needed):
 raise SystemExit("STEP34C18_BLOCKED=ACCESS_REVOCATION_OR_ROLLBACK_FAILED")'''
if s.count(end)!=1:
 raise SystemExit('STEP34C18D_BLOCKED=RESULT_EXIT_PATCH_MISMATCH')
s=s.replace(end,
 'raise SystemExit("STEP34C18D_DIAGNOSTIC_ONLY_NOT_ACCEPTANCE")')
approval_old="REVOKE-PRIVATE-OAUTH-ACCESS-BEARER-ROLLBACK"
if s.count(approval_old)!=2:
 raise SystemExit('STEP34C18D_BLOCKED=APPROVAL_PATCH_MISMATCH')
s=s.replace(approval_old,"DIAGNOSE-PRIVATE-MCP-REVOCATION-OUTPUT")
pass_marker="STEP34C18_PRIVATE_ACCESS_REVOCATION_AND_ROLLBACK=PASS"
if s.count(pass_marker)!=1:
 raise SystemExit('STEP34C18D_BLOCKED=PASS_MARKER_PATCH_MISMATCH')
s=s.replace(pass_marker,"STEP34C18D_NO_SECURITY_ACCEPTANCE")
pathlib.Path(sys.argv[2]).write_text(s,encoding='utf-8')
print('REDACTED_RUNTIME_TELEMETRY_INSTRUMENTED=YES')
PY

chmod 700 "$TEMP"
bash -n "$TEMP"
echo '========== RUN PRIVATE DIAGNOSTIC AND GUARDED ROLLBACK =========='
if bash "$TEMP" --exercise > "$LOG" 2>&1; then
 fail UNEXPECTED_DIAGNOSTIC_SUCCESS
fi

echo '========== VERIFY ALL DATABASE AND KEY PRESERVATION BEFORE REPORT =========='
python3 - "$LOG" <<'PY'
import pathlib,re,sys
lines=pathlib.Path(sys.argv[1]).read_text('utf-8',errors='replace').splitlines()
required=[
 'STEP34C18_EXPLICIT_ACCESS_REVOCATION_APPROVAL=PASS',
 'STEP34C18D_DIAGNOSTIC_ONLY_NOT_ACCEPTANCE',
 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED',
 'PASSPORT_RSA_KEY_BYTES=UNCHANGED',
 'ISOLATED_QA_USERS_CLIENTS_CODES_TOKENS=ZERO',
 'STEP34C11_READ_ONLY_OAUTH_FIXTURE_READINESS=PASS',
 'STEP34C18_BLOCKED=CONSENT_CODE_REHEARSAL_FAILED_BUT_ISOLATION_VERIFIED',
]
if any(x not in lines for x in required):
 raise SystemExit('STEP34C18D_BLOCKED=DIAGNOSTIC_POSTSTATE_INCOMPLETE')
stems=[
 'STEP34C18D_PHP_EXIT_CLASS',
 'STEP34C18D_STDOUT_SIZE_BAND',
 'STEP34C18D_STDOUT_LINE_COUNT',
 'STEP34C18D_UNKNOWN_LINE_COUNT',
 'STEP34C18D_LONG_LINE_COUNT',
 'STEP34C18D_JSON_LIKE_LINE_COUNT',
 'STEP34C18D_SSE_LIKE_LINE_COUNT',
 'STEP34C18D_ACTIVE_BEARER_MARKER',
 'STEP34C18D_REVOCATION_MARKER',
 'STEP34C18D_REVOKED_BEARER_MARKER',
 'STEP34C18D_ROLLBACK_MARKER',
 'STEP34C18D_ACTIVE_ACCESS_MCP_BEARER_STATUS',
 'STEP34C18D_REVOKED_ACCESS_MCP_BEARER_STATUS',
]
for stem in stems:
 values=[x.partition('=')[2] for x in lines if x.startswith(stem+'=')]
 if len(values)!=1 or not re.fullmatch('[A-Z0-9_]{1,32}|[0-9]{1,8}',values[0]):
  raise SystemExit('STEP34C18D_BLOCKED=SANITIZED_TELEMETRY_FIELD_MISSING')
 print(stem+'='+values[0])
print('STEP34C18D_POST_RUN_ISOLATED_DB_COUNTS=UNCHANGED')
print('STEP34C18D_POST_RUN_PASSPORT_RSA_KEYS=UNCHANGED')
print('STEP34C18D_DISPOSABLE_OAUTH_FIXTURES=ZERO')
print('STEP34C18D_REDACTED_TELEMETRY_AND_ROLLBACK=PASS')
print('STEP34C18_SECURITY_REVOCATION_ACCEPTANCE=NOT_PASSED')
print('PUBLIC_OAUTH_ISSUER_AND_PRODUCTION=UNCHANGED')
PY
