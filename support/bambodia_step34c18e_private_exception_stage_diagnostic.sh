#!/usr/bin/env bash
# Step34C.18E: private QA stage & exception CATEGORY diagnostic, NOT acceptance.
# --check does not write; --diagnose requires explicit fresh human approval.
# Original immutable Step34C.18 is never modified or run unchanged again.
set -Eeuo pipefail
set +x
umask 077
MODE="$1"
case "$MODE" in --check|--diagnose) ;; *)
 echo "Usage: script --check|--diagnose" >&2; exit 64 ;;
esac
ORIG="/root/bambodia_step34c18_access_revocation_bearer_rollback.sh"
ORIG_SHA="57180494562127a626e747a0470dae085e24b1fd4d426b0090efb203d5909595"
fail(){ echo "STEP34C18E_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY IMMUTABLE SOURCE AND OWNER =========='
test "$(id -u)" = 0 || fail ROOT_REQUIRED
for t in python3 bash sha256sum stat; do
 command -v "$t" >/dev/null || fail TOOL_MISSING
done
test -f "$ORIG" && test ! -L "$ORIG" &&
 test "$(stat -c '%u:%a' "$ORIG")" = "0:700" &&
 test "$(sha256sum "$ORIG" | awk '{print $1}')" = "$ORIG_SHA" ||
 fail ORIGINAL_SCRIPT_CHANGED
bash -n "$ORIG"

echo '========== READ-ONLY STATIC PHP STAGE INSTRUMENTATION CONTRACT =========='
python3 - "$ORIG" <<'PY'
import pathlib,sys
s=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8')
marker="code=r"+"'"*3
if s.count(marker)!=1:
 raise SystemExit('STEP34C18E_BLOCKED=PHP_SOURCE_NOT_UNIQUE')
php=s.split(marker,1)[1].split("\n"+"'"*3,1)[0]
markers=[
 '$db->transaction(function() use($app,$db,&$verified){',
 '$getResponse=$kernel->handle($get);',
 '$postResponse=$kernel->handle($post);',
 '$tokenResponse=$kernel->handle($tokenRequest);',
 '$refreshResponse=$kernel->handle($refreshRequest);',
 '[$activeStatus,$activeChallenge]=$protectedProbe($rotated["access_token"]);',
 '$repository->revokeAccessToken($newClaims["jti"]);',
 '[$revokedStatus,$revokedChallenge]=$protectedProbe($rotated["access_token"]);',
 'echo "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED',
]
if any(php.count(x)!=1 for x in markers):
 raise SystemExit('STEP34C18E_BLOCKED=INSTRUMENTATION_POINT_CHANGED')
capture='if len(p.stdout)>32768 or any(len(s)>130 or not s.startswith(prefixes)'
if s.count(capture)!=1 or s.count('REVOKE-PRIVATE-OAUTH-ACCESS-BEARER-ROLLBACK')!=2:
 raise SystemExit('STEP34C18E_BLOCKED=CAPTURE_OR_APPROVAL_CONTRACT_CHANGED')
print('STEP34C18E_STAGE_INSTRUMENTATION_POINTS=VERIFIED')
PY

echo '========== COMPILE DIAGNOSTIC PYTHON BLOCKS WITHOUT EXECUTING =========='
python3 - "$0" <<'PY'
import pathlib,re,sys
source=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8')
needle="<<"+"'PY'"
parts=re.findall(re.escape(needle)+r"\n(.*?)\nPY(?=\n|$)",source,flags=re.S)
if len(parts)!=4:
 raise SystemExit('STEP34C18E_BLOCKED=EXPECTED_FOUR_PYTHON_HEREDOCS')
for i,block in enumerate(parts):
 compile(block,'step34c18e_heredoc_'+str(i),'exec')
print('STEP34C18E_EMBEDDED_PYTHON_SYNTAX=PASS')
PY

echo '========== RUN ORIGINAL ACCEPTED READ-ONLY SAFETY GATES =========='
bash "$ORIG" --check
echo 'STEP34C18E_READ_ONLY_PREFLIGHT=PASS'
if [ "$MODE" = --check ]; then
 echo 'QA_FIXTURES_OR_HTTP_KERNEL=NOT_CREATED'
 echo 'DATABASE_AND_PUBLIC_HOST_WRITES=NONE'
 exit 0
fi

echo '========== REQUIRE EXPLICIT NEW OPERATOR DIAGNOSTIC APPROVAL =========='
test -c /dev/tty || fail TERMINAL_REQUIRED
echo 'A NEW disposable private QA-only transaction will run with forced rollback.'
echo 'The probe reports only a fixed execution stage, exception category, and numeric HTTP codes.'
echo 'PHP stack traces, response bodies, bearer tokens, client IDs and secrets are discarded.'
echo 'This is NOT a revocation acceptance and cannot activate the public OAuth issuer.'
printf 'Type CLASSIFY-PRIVATE-REVOCATION-PHP-FAILURE: ' > /dev/tty
IFS= read -r approval < /dev/tty
test "$approval" = 'CLASSIFY-PRIVATE-REVOCATION-PHP-FAILURE' ||
 fail OPERATOR_DECLINED
echo 'STEP34C18E_EXPLICIT_STAGE_DIAGNOSTIC_APPROVAL=PASS'

TMP="$(mktemp /root/bambodia-34c18e-script.XXXXXXXX)"
LOG="$(mktemp /root/bambodia-34c18e-log.XXXXXXXX)"
trap 'rm -f -- "$TMP" "$LOG"' EXIT

echo '========== BUILD EPHEMERAL STAGE-INSTRUMENTED SCRIPT =========='
python3 - "$ORIG" "$TMP" <<'PY'
import pathlib,sys
s=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8')
marker="code=r"+"'"*3
start=s.index(marker)+len(marker)
end=s.index("\n"+"'"*3,start)
php=s[start:end]
stage_map=[
 ('$db->transaction(function() use($app,$db,&$verified){',
  '$db->transaction(function() use($app,$db,&$verified,&$__qaStage,&$__qaActiveHttp,&$__qaRevokedHttp){'
  '\n  $__qaStage="DISPOSABLE_FIXTURE_CREATE";'),
 ('$getResponse=$kernel->handle($get);',
  '$__qaStage="CONSENT_GET_DISPATCH";\n  $getResponse=$kernel->handle($get);\n  $__qaStage="CONSENT_GET_RETURNED";'),
 ('$postResponse=$kernel->handle($post);',
  '$__qaStage="CONSENT_POST_DISPATCH";\n  $postResponse=$kernel->handle($post);\n  $__qaStage="CONSENT_POST_RETURNED";'),
 ('$tokenResponse=$kernel->handle($tokenRequest);',
  '$__qaStage="TOKEN_EXCHANGE_DISPATCH";\n  $tokenResponse=$kernel->handle($tokenRequest);\n  $__qaStage="TOKEN_EXCHANGE_RETURNED";'),
 ('$refreshResponse=$kernel->handle($refreshRequest);',
  '$__qaStage="REFRESH_DISPATCH";\n  $refreshResponse=$kernel->handle($refreshRequest);\n  $__qaStage="REFRESH_RETURNED";'),
 ('[$activeStatus,$activeChallenge]=$protectedProbe($rotated["access_token"]);',
  '$__qaStage="ACTIVE_BEARER_DISPATCH";'
  '\n  [$activeStatus,$activeChallenge]=$protectedProbe($rotated["access_token"]);'
  '\n  $__qaActiveHttp=(string)$activeStatus;'
  '\n  $__qaStage="ACTIVE_BEARER_RETURNED";'),
 ('$repository->revokeAccessToken($newClaims["jti"]);',
  '$__qaStage="REVOKE_REPOSITORY_CALL";'
  '\n  $repository->revokeAccessToken($newClaims["jti"]);'
  '\n  $__qaStage="REVOKE_REPOSITORY_RETURNED";'),
 ('[$revokedStatus,$revokedChallenge]=$protectedProbe($rotated["access_token"]);',
  '$__qaStage="REVOKED_BEARER_DISPATCH";'
  '\n  [$revokedStatus,$revokedChallenge]=$protectedProbe($rotated["access_token"]);'
  '\n  $__qaRevokedHttp=(string)$revokedStatus;'
  '\n  $__qaStage="REVOKED_BEARER_RETURNED";'),
 ('echo "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED',
  '$__qaStage="TRANSACTION_ROLLBACK_VERIFIED";\n'
  'echo "NETWORK_LISTENER_OR_PUBLIC_ISSUER=NOT_STARTED')
]
for old,new in stage_map:
 if php.count(old)!=1:
  raise SystemExit('STEP34C18E_BLOCKED=STAGE_PATCH_ANCHOR_NOT_UNIQUE')
 php=php.replace(old,new,1)
# Capture and dispose of any stdout from the original PHP code/exception
# handler. Only our controlled stage and exception CATEGORY are printed.
header=r'''
$__qaStage="BOOT";
$__qaActiveHttp="NOT_CAPTURED";
$__qaRevokedHttp="NOT_CAPTURED";
$__qaErrorCategory="NONE";
ob_start();
try {
'''
footer=r'''
} catch (\Throwable $__qaError) {
 if($__qaError instanceof \TypeError){
  $__qaErrorCategory="TYPE_ERROR";
 }elseif($__qaError instanceof \ErrorException){
  $__qaErrorCategory="ERROR_EXCEPTION";
 }elseif($__qaError instanceof \Illuminate\Database\QueryException){
  $__qaErrorCategory="DB_QUERY_EXCEPTION";
 }elseif($__qaError instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface){
  $__qaErrorCategory="HTTP_EXCEPTION";
 }elseif($__qaError instanceof \RuntimeException){
  $__qaErrorCategory="RUNTIME_EXCEPTION";
 }elseif($__qaError instanceof \Error){
  $__qaErrorCategory="PHP_ERROR";
 }else{
  $__qaErrorCategory="OTHER_THROWABLE";
 }
}
$__qaRaw=ob_get_clean();
$__qaSize=strlen((string)$__qaRaw);
$__qaBand=$__qaSize===0?"ZERO":($__qaSize<4096?"UNDER_4K":
 ($__qaSize<=32768?"4K_TO_32K":"OVER_32K"));
echo "STEP34C18E_LAST_EXECUTION_STAGE=".$__qaStage."\n";
echo "STEP34C18E_EXCEPTION_CATEGORY=".$__qaErrorCategory."\n";
echo "STEP34C18E_INTERNAL_OUTPUT_BAND=".$__qaBand."\n";
echo "STEP34C18E_ACTIVE_BEARER_HTTP_STATUS=".$__qaActiveHttp."\n";
echo "STEP34C18E_REVOKED_BEARER_HTTP_STATUS=".$__qaRevokedHttp."\n";
echo "STEP34C18E_RAW_PHP_STACK_TRACE_OR_TOKEN_PRINTED=NO\n";
'''
new_php=header+php+footer
s=s[:start]+new_php+s[end:]
# Completely replace the old parent stdout allowlist and expected PASS
# branch with an exact, fixed-shape diagnostic decoder.
output_start=s.index('prefixes=(')
output_end=s.index('\nPY\nthen\n',output_start)
output_decoder=r'''expected=[
 "STEP34C18E_LAST_EXECUTION_STAGE=",
 "STEP34C18E_EXCEPTION_CATEGORY=",
 "STEP34C18E_INTERNAL_OUTPUT_BAND=",
 "STEP34C18E_ACTIVE_BEARER_HTTP_STATUS=",
 "STEP34C18E_REVOKED_BEARER_HTTP_STATUS=",
 "STEP34C18E_RAW_PHP_STACK_TRACE_OR_TOKEN_PRINTED=NO"
]
if p.returncode!=0 or len(p.stdout)>2048:
 raise SystemExit("STEP34C18E_BLOCKED=PHP_DIAGNOSTIC_SUBPROCESS_FAILED")
try:
 lines=p.stdout.decode("ascii").splitlines()
except UnicodeDecodeError:
 raise SystemExit("STEP34C18E_BLOCKED=DIAGNOSTIC_NON_ASCII_OUTPUT")
if len(lines)!=len(expected) or any(
 not line.startswith(expected[i]) or len(line)>110
 for i,line in enumerate(lines)):
 raise SystemExit("STEP34C18E_BLOCKED=UNEXPECTED_DIAGNOSTIC_OUTPUT")
for line in lines:
 print(line)
raise SystemExit("STEP34C18E_DIAGNOSTIC_ONLY_NOT_ACCEPTANCE")'''
s=s[:output_start]+output_decoder+s[output_end:]
orig_confirm="REVOKE-PRIVATE-OAUTH-ACCESS-BEARER-ROLLBACK"
if s.count(orig_confirm)!=2:
 raise SystemExit('STEP34C18E_BLOCKED=OLD_CONFIRM_COUNT_CHANGED')
s=s.replace(orig_confirm,"CLASSIFY-PRIVATE-REVOCATION-PHP-FAILURE")
orig_success="STEP34C18_PRIVATE_ACCESS_REVOCATION_AND_ROLLBACK=PASS"
if s.count(orig_success)!=1:
 raise SystemExit('STEP34C18E_BLOCKED=OLD_ACCEPTANCE_MARKER_CHANGED')
s=s.replace(orig_success,"STEP34C18E_NOT_REVOCATION_ACCEPTANCE")
pathlib.Path(sys.argv[2]).write_text(s,encoding='utf-8')
print('STAGE_INSTRUMENTATION_AND_OUTPUT_REDACTION=PREPARED')
PY

chmod 700 "$TMP"
bash -n "$TMP"

echo '========== EXECUTE ONE DISPOSABLE PRIVATE DIAGNOSTIC =========='
if bash "$TMP" --exercise > "$LOG" 2>&1; then
 fail UNEXPECTED_DIAGNOSTIC_SUCCESS
fi

echo '========== VERIFY POST-RUN DATABASE AND KEYS BEFORE DIAGNOSTIC REPORT =========='
python3 - "$LOG" <<'PY'
import pathlib,re,sys
lines=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8',errors='replace').splitlines()
required=[
 'STEP34C18_EXPLICIT_ACCESS_REVOCATION_APPROVAL=PASS',
 'STEP34C18E_DIAGNOSTIC_ONLY_NOT_ACCEPTANCE',
 'ISOLATED_QA_ALL_TABLE_ROW_COUNTS=UNCHANGED',
 'PASSPORT_RSA_KEY_BYTES=UNCHANGED',
 'ISOLATED_QA_USERS_CLIENTS_CODES_TOKENS=ZERO',
 'STEP34C11_READ_ONLY_OAUTH_FIXTURE_READINESS=PASS',
 'STEP34C18_BLOCKED=CONSENT_CODE_REHEARSAL_FAILED_BUT_ISOLATION_VERIFIED'
]
if any(x not in lines for x in required):
 raise SystemExit('STEP34C18E_BLOCKED=DATABASE_KEY_OR_DIAGNOSTIC_POSTCHECK_FAILED')
stems=[
 'STEP34C18E_LAST_EXECUTION_STAGE',
 'STEP34C18E_EXCEPTION_CATEGORY',
 'STEP34C18E_INTERNAL_OUTPUT_BAND',
 'STEP34C18E_ACTIVE_BEARER_HTTP_STATUS',
 'STEP34C18E_REVOKED_BEARER_HTTP_STATUS',
 'STEP34C18E_RAW_PHP_STACK_TRACE_OR_TOKEN_PRINTED'
]
for stem in stems:
 vals=[x.partition('=')[2] for x in lines if x.startswith(stem+'=')]
 if len(vals)!=1 or not re.fullmatch('[A-Z0-9_]{1,60}|[0-9]{3}',vals[0]):
  raise SystemExit('STEP34C18E_BLOCKED=DIAGNOSTIC_OUTPUT_NOT_FIXED_SHAPE')
 print(stem+'='+vals[0])
print('STEP34C18E_QA_DATABASE_TABLE_COUNTS=UNCHANGED')
print('STEP34C18E_PASSPORT_SIGNING_KEYS=UNCHANGED')
print('STEP34C18E_PERSISTENT_OAUTH_QA_FIXTURES=ZERO')
print('STEP34C18E_REDACTED_STAGE_DIAGNOSIS=COMPLETE')
print('STEP34C18_REVOCATION_SECURITY_ACCEPTANCE=NOT_PASSED')
print('NGINX_PUBLIC_HOST_AND_PRODUCTION=UNCHANGED')
PY
