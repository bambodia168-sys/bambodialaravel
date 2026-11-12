#!/usr/bin/env bash
# Read-only compatibility check for ChatGPT custom MCP plugin OAuth discovery.
# Sends ONLY anonymous GET requests and one unauthorized MCP initialize POST;
# does NOT register a client, log in, migrate, modify tokens, or write the app.
set -Eeuo pipefail
set +x
umask 077

[[ "$#" -eq 1 && "$1" == "--check" ]] || {
  echo 'Usage: bash this-script --check' >&2; exit 64;
}
HOST=https://oauth-qa.bambodia.com
MCP="$HOST/mcp"
fail(){ printf 'CHATGPT_OAUTH_DIAG_BLOCKED=%s\n' "$*" >&2; exit 1; }
[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
for bin in curl python3 mktemp; do
  command -v "$bin" >/dev/null || fail "MISSING_$bin"
done
[[ -f /var/lib/bambodia-oauth-https-qa/storage/app/installed.lock ]] ||
  fail QA_INSTALLATION_NOT_SEALED

WORK="$(mktemp -d /root/bambodia-chatgpt-oauth.XXXXXXXX)"
chmod 700 "$WORK"
trap 'rm -rf -- "$WORK"' EXIT
# GET documents; don't follow redirects or disable TLS verification.
get(){
  local name="$1" path="$2" status
  status="$(curl --silent --show-error --proto '=https' --proto-redir '=https' \
    --connect-timeout 8 --max-time 18 --max-redirs 0 \
    -H 'Accept: application/json' \
    -D "$WORK/$name.headers" -o "$WORK/$name.body" \
    -w '%{http_code}' "$HOST$path")" || fail "HTTPS_UNREACHABLE_$name"
  printf '%s' "$status" > "$WORK/$name.status"
  echo "HTTPS_"$name"_STATUS=$status"
}
echo '========== REAL PUBLIC HTTPS OAUTH DISCOVERY =========='
get protected_mcp /.well-known/oauth-protected-resource/mcp
get protected_root /.well-known/oauth-protected-resource
get authorization /.well-known/oauth-authorization-server

echo '========== MCP BEARER AUTH CHALLENGE =========='
status="$(curl --silent --show-error --proto '=https' \
  --connect-timeout 8 --max-time 18 --max-redirs 0 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  -D "$WORK/mcp_auth.headers" -o "$WORK/mcp_auth.body" \
  -w '%{http_code}' "$MCP")" || fail MCP_UNAUTHORIZED_PROBE_UNREACHABLE
printf '%s' "$status" > "$WORK/mcp_auth.status"
echo "HTTPS_MCP_WITHOUT_BEARER_STATUS=$status"

python3 - "$WORK" <<'PY'
import json,pathlib,sys
root=pathlib.Path(sys.argv[1])
origin="https://oauth-qa.bambodia.com"
mcp=origin+"/mcp"
errors=[]
def status(name):
    return (root/(name+".status")).read_text().strip()
def jsondoc(name):
    if status(name)!="200":
        errors.append(name.upper()+"_NOT_HTTP_200")
        return {}
    try:
        data=json.loads((root/(name+".body")).read_text())
        if not isinstance(data,dict): raise ValueError()
        return data
    except (UnicodeDecodeError,ValueError):
        errors.append(name.upper()+"_NOT_JSON_OBJECT")
        return {}
def check(condition,code):
    if not condition: errors.append(code)

protected=jsondoc("protected_mcp")
check(protected.get("resource")==mcp,"PROTECTED_RESOURCE_MUST_EQUAL_MCP_URL")
check(isinstance(protected.get("authorization_servers"),list)
      and origin in protected["authorization_servers"],"PROTECTED_RESOURCE_ISSUER_MISMATCH")
check(isinstance(protected.get("scopes_supported"),list)
      and "mcp:use" in protected["scopes_supported"],"PROTECTED_RESOURCE_MCP_SCOPE_NOT_ADVERTISED")

rootmd=jsondoc("protected_root")
check(rootmd.get("resource")==origin,"ROOT_RESOURCE_METADATA_MISMATCH")

auth=jsondoc("authorization")
check(auth.get("issuer")==origin,"AUTHORIZATION_ISSUER_MISMATCH")
for key,url in (
    ("authorization_endpoint",origin+"/oauth/authorize"),
    ("token_endpoint",origin+"/oauth/token"),
    ("registration_endpoint",origin+"/oauth/register"),
):
    check(auth.get(key)==url,key.upper()+"_NOT_ADVERTISED_OR_WRONG")
check(isinstance(auth.get("code_challenge_methods_supported"),list)
      and "S256" in auth["code_challenge_methods_supported"],"PKCE_S256_NOT_ADVERTISED")
check(isinstance(auth.get("token_endpoint_auth_methods_supported"),list)
      and "none" in auth["token_endpoint_auth_methods_supported"],"PUBLIC_CLIENT_AUTH_NONE_NOT_ADVERTISED")
check(isinstance(auth.get("response_types_supported"),list)
      and "code" in auth["response_types_supported"],"AUTHORIZATION_CODE_FLOW_NOT_ADVERTISED")
check(isinstance(auth.get("scopes_supported"),list)
      and "mcp:use" in auth["scopes_supported"],"MCP_SCOPE_NOT_ADVERTISED")

check(status("mcp_auth")=="401","ANONYMOUS_MCP_EXPECTED_401")
headers=(root/"mcp_auth.headers").read_text(errors="replace")
challenge="\n".join(line.strip() for line in headers.splitlines()
                    if line.lower().startswith("www-authenticate:"))
check('resource_metadata="'+origin+'/.well-known/oauth-protected-resource/mcp"' in challenge,
      "MCP_401_MISSING_RESOURCE_METADATA_CHALLENGE")
check(challenge.lower().startswith("www-authenticate: bearer"),"MCP_401_MISSING_BEARER_CHALLENGE")
print("QA_MCP_RESOURCE_ID="+mcp)
print("QA_OAUTH_EXPECTED_REGISTRATION_METHOD=DCR")
print("QA_CHATGPT_CALLBACK_HOST=chatgpt.com")
print("QA_OAUTH_CLIENT_SECRET_REQUIRED=NO")
for error in errors:
    print("CHATGPT_DISCOVERY_FINDING="+error)
print("CHATGPT_OAUTH_METADATA="+("PASS" if not errors else "BLOCKED"))
print("DYNAMIC_CLIENT_REGISTRATION=NOT_PERFORMED")
print("QA_DATABASE_AND_TOKENS=NOT_MODIFIED")
print("NGINX_AND_PRODUCTION=UNCHANGED")
if errors: sys.exit(2)
PY
