#!/usr/bin/env bash
# Step 34C.13: READ-ONLY installed Passport + MCP OAuth positive-flow contract.
# No database writes, no Artisan app boot, no fixture creation, no listener.
set -Eeuo pipefail
set +x
umask 077

MODE="$1"
case "$MODE" in --check|--audit) ;; *)
  echo 'Usage: script --check|--audit' >&2; exit 64 ;;
esac
APP="/var/lib/bambodia-oauth-public-qa"
PHP="/www/server/php/85/bin/php"
AUDIT="/root/bambodia_step34c11_oauth_fixture_readiness_readonly.sh"
AUDIT_SHA="913fe0cf292c4dc4e05a8bef1740dd3c743e1e0b6b278b0bd7de458e892dcc4e"
fail(){ echo "STEP34C13_BLOCKED=$1" >&2; exit 34; }

echo '========== VERIFY PINNED READ-ONLY ISOLATION AUDIT =========='
test "$(id -u)" = 0 || fail ROOT_REQUIRED
for t in git sha256sum stat python3; do
  command -v "$t" >/dev/null || fail REQUIRED_TOOL_MISSING
done
test -x "$PHP" || fail PHP85_MISSING
"$PHP" -r 'if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==5)exit(1);' ||
  fail PHP85_RUNTIME_CHANGED
test -f "$AUDIT" && test ! -L "$AUDIT" &&
  test "$(stat -c '%u:%a' "$AUDIT")" = '0:700' &&
  test "$(sha256sum "$AUDIT" | awk '{print $1}')" = "$AUDIT_SHA" ||
  fail PINNED_READINESS_AUDIT_MISSING_OR_CHANGED
test -d "$APP" && test ! -L "$APP" &&
  test "$(stat -c '%u:%a' "$APP")" = '0:700' &&
  test -f "$APP/vendor/autoload.php" ||
  fail PRIVATE_APPLICATION_BOUNDARY_CHANGED
bash "$AUDIT" --audit
echo 'STEP34C13_PRIVATE_READINESS_AND_SCHEMA=PASS'
if [ "$MODE" = --check ]; then
  echo 'STEP34C13_READ_ONLY_PREFLIGHT=PASS'
  echo 'VENDOR_PHP_REFLECTION=NOT_EXECUTED'
  echo 'PUBLIC_HOST_AND_DATABASE_WRITES=NONE'
  exit 0
fi

echo '========== READ ONLY INSTALLED VENDOR AND SECURITY CONTRACT =========='
# PHP autoload + reflection only. Never bootstrap Laravel, dispatch an HTTP
# request, touch the database, create fixtures, or print vendor code or secrets.
python3 - "$PHP" "$APP" <<'PY'
import json,subprocess,sys
php,app=sys.argv[1:]
src=r'''
$base=getcwd();
$manifest=$base."/vendor/composer/installed.json";
if(!is_file($manifest)){exit(3);}
$installed=json_decode(file_get_contents($manifest),true);
if(!is_array($installed)){exit(4);}
$packages=$installed["packages"]??$installed;
$names=[];
foreach($packages as $pkg){
 if(!is_array($pkg) || !isset($pkg["name"]))continue;
 $names[(string)$pkg["name"]]=(string)($pkg["version"]??"unknown");
}
$expected=["laravel/passport","laravel/mcp","league/oauth2-server"];
foreach($expected as $p){
 $label=strtoupper(str_replace(["/","-"],"_",$p));
 echo "INSTALLED_PACKAGE_".$label."=".(isset($names[$p])?"PRESENT":"MISSING")."\n";
}
require $base."/vendor/autoload.php";
$classes=[
 "PASSPORT_AUTHORIZATION_CONTROLLER"=>["Laravel\\Passport\\Http\\Controllers\\AuthorizationController","authorize"],
 "PASSPORT_APPROVAL_CONTROLLER"=>["Laravel\\Passport\\Http\\Controllers\\ApproveAuthorizationController","approve"],
 "PASSPORT_TOKEN_CONTROLLER"=>["Laravel\\Passport\\Http\\Controllers\\AccessTokenController","issueToken"],
 "PASSPORT_CLIENT_MODEL"=>["Laravel\\Passport\\Client",null],
 "PASSPORT_AUTHORIZATION_CODE_GRANT"=>["League\\OAuth2\\Server\\Grant\\AuthCodeGrant",null],
 "PASSPORT_REFRESH_TOKEN_GRANT"=>["League\\OAuth2\\Server\\Grant\\RefreshTokenGrant",null],
 "LARAVEL_MCP_REGISTER_CONTROLLER"=>["Laravel\\Mcp\\Server\\Http\\Controllers\\OAuthRegisterController",null]
];
$missing=[];
$reflections=[];
foreach($classes as $label=>$definition){
 [$name,$method]=$definition;
 $ok=class_exists($name) && (!$method||method_exists($name,$method));
 if($ok){
  $reflection=new ReflectionClass($name);
  $file=$reflection->getFileName();
  $resolved=$file===false?false:realpath($file);
  $vendor=realpath($base."/vendor");
  $ok=is_string($resolved) && is_string($vendor) &&
   str_starts_with($resolved,$vendor.DIRECTORY_SEPARATOR);
  if($ok)$reflections[$label]=$resolved;
 }
 echo $label."=".($ok?"PRESENT":"MISSING_OR_OUTSIDE_VENDOR")."\n";
 if(!$ok)$missing[]=$label;
}
$pkceSource=$reflections["PASSPORT_AUTHORIZATION_CODE_GRANT"]??null;
$pkce=is_string($pkceSource)?file_get_contents($pkceSource):false;
$hasPkce=is_string($pkce) && str_contains($pkce,"code_challenge") &&
 str_contains($pkce,"code_verifier") &&
 str_contains(strtolower($pkce),"s256");
echo "PKCE_CODE_CHALLENGE_VERIFIER_S256_SOURCE_MARKERS=".
 ($hasPkce?"PRESENT":"REVIEW_REQUIRED")."\n";
$mcp=require $base."/config/mcp.php";
$redirects=$mcp["redirect_domains"]??null;
$allowlist=is_array($redirects) && count($redirects)===1 &&
 $redirects[0]==="https://chatgpt.com" &&
 ($mcp["custom_schemes"]??null)===[];
echo "CHATGPT_REDIRECT_DOMAIN_ALLOWLIST=".
 ($allowlist?"RESTRICTED":"REVIEW_REQUIRED")."\n";
$auth=require $base."/config/auth.php";
$guard=(($auth["guards"]??[])["api"]??[])["driver"]??null;
echo "API_AUTH_GUARD_PASSPORT=".($guard==="passport"?"YES":"NO")."\n";
echo "RAW_VENDOR_SOURCE_APP_KEY_PRIVATE_KEYS_AND_CREDENTIALS_PRINTED=NO\n";
echo "PUBLIC_HTTP_LISTENER_STARTED=NO\n";
if(count($missing)>0||!$hasPkce||!$allowlist||$guard!=="passport"||
 array_filter($expected,fn($k)=>!isset($names[$k]))){
 exit(13);
}
echo "INSTALLED_OAUTH_FLOW_CONTRACT_STATIC_COMPATIBILITY=PASS\n";
'''
try:
    proc=subprocess.run(
       [php,'-d','display_errors=0','-d','log_errors=0','-r',src],
       cwd=app,env={'HOME':'/root','PATH':'/usr/bin:/bin','APP_ENV':'testing'},
       stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
       stderr=subprocess.DEVNULL,timeout=45,check=False)
except subprocess.TimeoutExpired:
    raise SystemExit('STEP34C13_BLOCKED=VENDOR_REFLECTION_TIMEOUT')
if len(proc.stdout)>32768:
    raise SystemExit('STEP34C13_BLOCKED=VENDOR_REFLECTION_OUTPUT_TOO_LARGE')
try:
    lines=proc.stdout.decode('utf-8').splitlines()
except UnicodeDecodeError:
    raise SystemExit('STEP34C13_BLOCKED=VENDOR_REFLECTION_NON_UTF8')
allowed_prefixes=(
 'INSTALLED_PACKAGE_', 'PASSPORT_', 'LARAVEL_MCP_',
 'PKCE_', 'CHATGPT_', 'API_AUTH_GUARD_',
 'RAW_VENDOR_', 'PUBLIC_HTTP_LISTENER_', 'INSTALLED_OAUTH_')
if any(not line.startswith(allowed_prefixes) or len(line)>180
       for line in lines):
    raise SystemExit('STEP34C13_BLOCKED=UNEXPECTED_PHP_OUTPUT_REDACTED')
for line in lines:
    print(line)
if proc.returncode!=0 or not lines or (
  'INSTALLED_OAUTH_FLOW_CONTRACT_STATIC_COMPATIBILITY=PASS' not in lines):
    raise SystemExit('STEP34C13_BLOCKED=VENDOR_CONTRACT_REVIEW_REQUIRED')
PY

echo '========== VERIFY ISOLATION AND DATABASE AGAIN =========='
bash "$AUDIT" --audit || fail ISOLATION_CHANGED_AFTER_VENDOR_REFLECTION
echo 'STEP34C13_READ_ONLY_OAUTH_VENDOR_CONTRACT_ACCEPTANCE=PASS'
echo 'ADMIN_OR_OAUTH_CLIENT_FIXTURE=NOT_CREATED'
echo 'PKCE_AUTHORIZATION_CONSENT_TOKEN_REFRESH_REVOCATION=NOT_TESTED'
echo 'PUBLIC_OAUTH_ISSUER_OR_HTTP_SERVER=NOT_STARTED'
echo 'DATABASE_NGINX_DNS_TLS_AND_PRODUCTION_WRITES=NONE'
echo 'GITHUB_MAIN_AND_PR_MERGE=UNCHANGED'
