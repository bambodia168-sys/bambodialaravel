#!/usr/bin/env bash
# Step 34C.18F: installed Passport revocation API audit, strictly read-only.
set -Eeuo pipefail
set +x
umask 077
test "$#" -eq 1 || { echo 'Usage: script --check|--audit' >&2; exit 64; }
MODE="$1"
case "$MODE" in --check|--audit) ;; *) exit 64 ;; esac
APP="/var/lib/bambodia-oauth-public-qa"
PHP="/www/server/php/85/bin/php"
READINESS="/root/bambodia_step34c11_oauth_fixture_readiness_readonly.sh"
READINESS_SHA="913fe0cf292c4dc4e05a8bef1740dd3c743e1e0b6b278b0bd7de458e892dcc4e"
CONTRACT="/root/bambodia_step34c13_passport_vendor_flow_contract_readonly.sh"
CONTRACT_SHA="2f139bd8142220d24b58c59ab6bb1b3f46481527b512ff4c710cc4b5714e09b1"
FAILED="/root/bambodia_step34c18_access_revocation_bearer_rollback.sh"
FAILED_SHA="57180494562127a626e747a0470dae085e24b1fd4d426b0090efb203d5909595"
fail(){ echo "STEP34C18F_BLOCKED=$1" >&2; exit 34; }
verify(){
 test -f "$1" && test ! -L "$1" &&
 test "$(stat -c '%u:%a' "$1")" = "0:700" &&
 test "$(sha256sum "$1" | awk '{print $1}')" = "$2" ||
 fail PINNED_QA_SCRIPT_CHANGED
}
echo '========== VERIFY PINNED READ-ONLY QA PRECONDITIONS =========='
test "$(id -u)" -eq 0 || fail ROOT_REQUIRED
for cmd in stat sha256sum bash python3; do
 command -v "$cmd" >/dev/null || fail TOOL_MISSING
done
test -x "$PHP" || fail PHP85_MISSING
"$PHP" -r 'if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==5)exit(13);' ||
 fail PHP85_VERSION_CHANGED
test -d "$APP" && test ! -L "$APP" &&
 test "$(stat -c '%u:%a' "$APP")" = "0:700" &&
 test -f "$APP/vendor/autoload.php" ||
 fail PRIVATE_QA_ROOT_CHANGED
verify "$READINESS" "$READINESS_SHA"
verify "$CONTRACT" "$CONTRACT_SHA"
verify "$FAILED" "$FAILED_SHA"
bash -n "$FAILED"
bash "$READINESS" --audit
bash "$CONTRACT" --audit
echo 'STEP34C18F_READ_ONLY_QA_BOUNDARIES=PASS'
if [ "$MODE" = --check ]; then
 echo 'STEP34C18F_READ_ONLY_PREFLIGHT=PASS'
 echo 'PASSPORT_METHOD_REFLECTION=NOT_STARTED'
 echo 'DB_HTTP_LISTENER_AND_PRODUCTION_WRITES=NONE'
 exit 0
fi
echo '========== REFLECT INSTALLED PASSPORT API WITHOUT LARAVEL BOOT =========='
python3 - "$PHP" "$APP" <<'PY'
import subprocess,sys
php,app=sys.argv[1:]
source=r'''
$root=getcwd();
$manifest=$root."/vendor/composer/installed.json";
if(!is_file($manifest)){exit(10);}
$installed=json_decode(file_get_contents($manifest),true);
$packages=$installed["packages"]??$installed;
$major=null;
if(!is_array($packages)){exit(11);}
foreach($packages as $p){
 if(is_array($p)&&($p["name"]??null)==="laravel/passport"){
  $v=(string)($p["version"]??"");
  if(preg_match('/^v?([0-9]+)\./',$v,$m))$major=$m[1];
 }
}
if($major===null){exit(12);}
require $root."/vendor/autoload.php";
$vendor=realpath($root."/vendor");
if(!is_string($vendor)){exit(13);}
$methods=[
 ["TOKEN_MODEL_REVOKE_METHOD","Laravel\\Passport\\Token","revoke"],
 ["PASSPORT_FACADE_TOKEN_FACTORY","Laravel\\Passport\\Passport","token"],
 ["LEGACY_REPOSITORY_REVOKE_ACCESS_METHOD",
   "Laravel\\Passport\\TokenRepository","revokeAccessToken"]
];
echo "INSTALLED_PASSPORT_MAJOR_VERSION=".$major."\n";
foreach($methods as $item){
 [$label,$class,$name]=$item;
 $present=class_exists($class)&&method_exists($class,$name);
 if($present){
  $ref=new ReflectionMethod($class,$name);
  $path=$ref->getFileName();
  $real=is_string($path)?realpath($path):false;
  if(!is_string($real)||
    !str_starts_with($real,$vendor.DIRECTORY_SEPARATOR)){exit(14);}
  $present=$ref->isPublic();
 }
 echo $label."=".($present?"YES":"NO")."\n";
}
echo "DATABASE_AND_LARAVEL_KERNEL_NOT_BOOTED=YES\n";
echo "PRIVATE_KEYS_TOKENS_OR_PHP_SOURCE_PRINTED=NO\n";
'''
try:
 p=subprocess.run([php,'-d','display_errors=0','-d','log_errors=0',
 '-r',source],cwd=app,
 env={'HOME':'/root','PATH':'/usr/bin:/bin','APP_ENV':'testing'},
 stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
 timeout=25,check=False)
except subprocess.TimeoutExpired:
 raise SystemExit('STEP34C18F_BLOCKED=VENDOR_REFLECTION_TIMEOUT')
try:lines=p.stdout.decode('ascii').splitlines()
except UnicodeDecodeError:
 raise SystemExit('STEP34C18F_BLOCKED=VENDOR_OUTPUT_NON_ASCII')
prefixes=[
 'INSTALLED_PASSPORT_MAJOR_VERSION',
 'TOKEN_MODEL_REVOKE_METHOD',
 'PASSPORT_FACADE_TOKEN_FACTORY',
 'LEGACY_REPOSITORY_REVOKE_ACCESS_METHOD',
 'DATABASE_AND_LARAVEL_KERNEL_NOT_BOOTED',
 'PRIVATE_KEYS_TOKENS_OR_PHP_SOURCE_PRINTED']
if p.returncode!=0 or len(p.stdout)>1000 or len(lines)!=len(prefixes):
 raise SystemExit('STEP34C18F_BLOCKED=VENDOR_REFLECTION_FAILED_REDACTED')
items=[line.split('=',1) for line in lines]
if any(len(x)!=2 or x[0]!=key for x,key in zip(items,prefixes)) or (
 not items[0][1].isdigit() or len(items[0][1])>2 or
 any(x[1] not in ('YES','NO') for x in items[1:4]) or
 items[4][1]!='YES' or items[5][1]!='NO'):
 raise SystemExit('STEP34C18F_BLOCKED=VENDOR_REFLECTION_SHAPE_CHANGED')
for line in lines:print(line)
if items[1][1]!='YES' or items[2][1]!='YES':
 raise SystemExit('STEP34C18F_BLOCKED=MODERN_TOKEN_MODEL_REVOCATION_MISSING')
print('STEP34C18F_MODEL_BASED_REVOCATION=AVAILABLE')
PY
echo '========== INDEPENDENT POSTAUDIT PRESERVATION =========='
bash "$READINESS" --audit
echo 'STEP34C18F_READ_ONLY_PASSPORT_REVOCATION_API_AUDIT=PASS'
echo 'REVOCATION_AND_REVOKED_BEARER_ACCEPTANCE=NOT_YET_TESTED'
echo 'PUBLIC_OAUTH_ISSUER_AND_HTTP_LISTENER=NOT_ACTIVATED'
echo 'NGINX_DNS_TLS_PRODUCTION_AND_GITHUB_MAIN_WRITES=NONE'
