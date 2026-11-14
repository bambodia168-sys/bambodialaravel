#!/usr/bin/env bash
# PHP 8.5 and localhost OAuth + homepage/blog/jobs HTTP acceptance against
# the TWO-BLADE-updated PRIVATE candidate, in a disposable clone only.
# Never deploy, migrate production, write live source, or use production keys.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" == 1 && "$1" == "--isolated-qa" ]] || {
 echo "Usage: bash $0 --isolated-qa" >&2; exit 64;
}
fail(){ printf 'MCP_REFRESHED_HTTP_QA_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for c in gh git python3 bash sha256sum stat curl; do
 command -v "$c" >/dev/null || fail REQUIRED_TOOL_MISSING
done
REPO=bambodia168-sys/bambodialaravel
RELEASE=5c5aa16e164d16d718b7f3fe7c6057304593bd75
NEW=/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp
PRIOR=/root/Bambodia-MCP-Overlay-Refresh.hZDR51NJ
OLD=/root/Bambodia-MCP-Main-Overlay.ApguAhIY
LIVE=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
for workspace in "$NEW" "$PRIOR" "$OLD"; do
 [[ -d "$workspace" && ! -L "$workspace" &&
    "$(stat -c '%u:%a' "$workspace")" == "0:700" &&
    -d "$workspace/source" && ! -L "$workspace/source" &&
    -d "$workspace/review" && ! -L "$workspace/review" ]] ||
  fail PRIVATE_WORKSPACE_UNSAFE
done
[[ -d "$LIVE" && ! -L "$LIVE" && -f "$LIVE/storage/app/installed.lock" ]] ||
 fail LIVE_INSTALL_DRIFT
[[ -f "$VHOST" && ! -L "$VHOST" &&
  "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
 fail LIVE_VHOST_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
 fail LIVE_COMPOSER_DRIFT
[[ "$(sha256sum "$LIVE/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
 fail LIVE_COMPOSER_LOCK_DRIFT
[[ -f "$NEW/source/vendor/autoload.php" &&
  "$(sha256sum "$NEW/source/composer.lock" | awk '{print $1}')" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] ||
 fail PRIVATE_DEPENDENCIES_CHANGED
for f in "$NEW/source/.env" "$NEW/source/.env.testing" \
 "$NEW/source/storage/oauth-private.key" "$NEW/source/storage/oauth-public.key" \
 "$NEW/source/bootstrap/cache/config.php"; do
 [[ ! -e "$f" && ! -L "$f" ]] || fail PRIVATE_CREDENTIALS_OR_CACHE_PRESENT
done

echo "========== VERIFY TWO-BLADE PRIVATE SOURCE AND CHAIN OF CUSTODY =========="
python3 - "$NEW" "$OLD" "$LIVE" "$PRIOR" <<'PY'
from pathlib import Path
import hashlib, json, stat, sys
new,old,live,prior=map(Path,sys.argv[1:])
def fail(reason):
 print("MCP_REFRESHED_HTTP_QA_BLOCKED="+reason)
 sys.exit(2)
def safe(p):
 try: mode=p.lstat().st_mode
 except OSError: fail("REQUIRED_FILE_MISSING")
 if not stat.S_ISREG(mode):fail("SYMLINK_OR_SPECIAL_FILE")
 return p.read_bytes()
def digest(data):return hashlib.sha256(data).hexdigest()
def blob(data):return hashlib.sha1(b"blob "+str(len(data)).encode()+bytes([0])+data).hexdigest()
sync=json.loads(safe(new/"review/mcp-oauth-security-sync-20261113.json"))
refresh=json.loads(safe(new/"review/20261113-refresh-evidence.json"))
if (sync.get("github_oauth_security_sync")!="PASSED"
 or sync.get("source_git_commit")!="396614486e3b7e50a28cd42932af6b4aa6536cfd"
 or sync.get("production_changed") is not False
 or sync.get("private_candidate_deployable") is not False
 or refresh.get("release_authorized") is not False):
 fail("REFRESH_OR_SYNC_REPORT_INVALID")
# Ensure the new candidate came ONLY from the two-template private catch-up,
# and the old private source/review evidence was retained byte-for-byte.
catchup=json.loads(safe(new/"review/20261113-two-blade-catchup.json"))
initial=json.loads(safe(new/"review/20261113-initial-refresh-evidence.json"))
prior_refresh=json.loads(safe(prior/"review/20261113-refresh-evidence.json"))
two={
 "resources/views/home.blade.php",
 "resources/views/layouts/main.blade.php",
}
if (catchup.get("operation")!="private-only-two-template-rebase"
 or catchup.get("previous_candidate")!=str(prior)
 or catchup.get("new_candidate")!=str(new)
 or set(catchup.get("updated_paths",[]))!=two
 or catchup.get("production_modified") is not False
 or catchup.get("prior_candidate_modified") is not False
 or catchup.get("release_authorized") is not False
 or catchup.get("oauth_security_files_unchanged")!=6
 or catchup.get("protected_live_snapshots_unchanged")!=9
 or catchup.get("vite_files_unchanged")!=23
 or refresh.get("two_blade_catchup_review")!="CURRENT_LIVE_ONLY_PRIVATE_COPY"
 or set(refresh.get("two_blade_catchup_paths",[]))!=two
 or refresh.get("prior_refresh_sha256")!=digest(safe(new/"review/20261113-initial-refresh-evidence.json"))
 or sync.get("private_workspace")!=str(prior)):
 fail("TWO_BLADE_CHAIN_OF_CUSTODY_INVALID")
if initial!=prior_refresh:
 fail("PRIOR_REFRESH_EVIDENCE_NOT_PRESERVED")
before=catchup.get("prior_sha256",{})
after=catchup.get("updated_sha256",{})
if (not isinstance(before,dict) or set(before)!=two or
    not isinstance(after,dict) or set(after)!=two):
 fail("TWO_BLADE_HASH_INVENTORY_INVALID")
if (refresh.get("source_sha256",{})!={**initial.get("source_sha256",{}),**after}):
 fail("TWO_BLADE_HASH_MANIFEST_UNEXPECTED")
for rel in two:
 if (before[rel]!=initial["source_sha256"].get(rel)
     or digest(safe(prior/"source"/rel))!=before[rel]
     or digest(safe(new/"review/pre-two-blade-refresh"/rel))!=before[rel]
     or after[rel]==before[rel]
     or digest(safe(new/"source"/rel))!=after[rel]
     or digest(safe(live/rel))!=after[rel]):
  fail("TWO_BLADE_RECONCILIATION_MISMATCH")
print("TWO_BLADE_CANDIDATE_PROVENANCE=PASS")
print("TWO_BLADE_NEWER_TEMPLATES=2_OF_2_VERIFIED")
print("TWO_BLADE_PRIOR_TEMPLATES=2_SNAPSHOT_PRESERVED")
expected={
 "bootstrap/app.php":"48b931f09aa0c21cc05705396a06272f85b2be8d",
 "app/Http/Middleware/RequireMcpOAuthResource.php":"ae8922d4a1a82a5dabf11df4877d5aab8fb8093d",
 "app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php":"641e625f4a12765a3e8da54916235ccb7b337835",
 "config/mcp.php":"215d9b15714e0a6b687191bdab44af54ace10450",
 "app/Http/Controllers/Api/BambodiaOAuthRegisterController.php":"290f3d66998315235c9c6350e3b878d458344331",
 "tests/Feature/BambodiaMcpOAuthTest.php":"725a067ce74802cec15169eab87beab051c7e3ca"
}
records=sync.get("source_files")
if not isinstance(records,dict) or set(records)!=set(expected):
 fail("SIX_OAUTH_SOURCE_REPORT_INVALID")
for rel,pin in expected.items():
 if (blob(safe(new/"source"/rel))!=pin or
    records[rel].get("approved_git_blob")!=pin):
  fail("OAUTH_FILE_HASH_MISMATCH")
print("REFRESHED_CANDIDATE_OAUTH_SOURCE=6_OF_6_VERIFIED")
paths=refresh.get("updated_live_paths",[])+refresh.get("added_live_paths",[])
if len(paths)!=11 or len(set(paths))!=11 or sync.get("preserved_live_frontend_paths")!=11:
 fail("LIVE_FRONTEND_REPORT_MISMATCH")
for rel in paths:
 h=refresh.get("source_sha256",{}).get(rel)
 if not isinstance(h,str) or digest(safe(new/"source"/rel))!=h or digest(safe(live/rel))!=h:
  fail("CURRENT_FRONTEND_SOURCE_DRIFT")
print("REFRESHED_CANDIDATE_FRONTEND=11_OF_11_MATCH_LIVE")
protected=[
 "app/Http/Controllers/Api/BambodiaMcpController.php",
 "app/Http/Middleware/SetLocale.php",
 "app/Models/User.php",
 "app/Providers/AppServiceProvider.php",
 "app/Support/Mcp/BambodiaTools.php",
 "config/auth.php",
 "resources/views/developer/index.blade.php",
 "resources/views/layouts/workspace.blade.php",
 "routes/web.php"
]
for rel in protected:
 if safe(new/"review/live-critical"/rel)!=safe(live/rel):
  fail("PROTECTED_MCP_LIVE_SNAPSHOT_DRIFT")
print("REFRESHED_CANDIDATE_CRITICAL_SNAPSHOTS=9_UNCHANGED")
assets=refresh.get("live_build_sha256")
if not isinstance(assets,dict) or len(assets)!=23:
 fail("VITE_MANIFEST_HASH_COUNT_INVALID")
def inventory(root):
 if not root.is_dir() or root.is_symlink():fail("VITE_ROOT_UNSAFE")
 result={}
 for p in root.rglob("*"):
  if p.is_dir() and not p.is_symlink():continue
  if p.is_symlink() or not p.is_file():fail("VITE_SPECIAL_FILE")
  rel=p.relative_to(root).as_posix()
  if p.name.startswith(".env") or p.suffix.lower()==".php":
   fail("VITE_SENSITIVE_FILE")
  result[rel]=digest(safe(p))
 return result
if inventory(new/"source/public/build")!=assets or inventory(live/"public/build")!=assets:
 fail("VITE_ASSET_HASH_DRIFT")
print("REFRESHED_CANDIDATE_VITE=23_OF_23_VERIFIED")
print("REFRESHED_CANDIDATE_INTEGRITY=PASS")
PY

# The prior passing QA program is an immutable source, not a mutable shell
# installer. Its original shell syntax + independent HTTP client were tested.
# Adapt exactly TWO known sections in a root-private, short-lived local copy:
# (1) select the refreshed overlay, (2) REMOVE the six hot-patches because
# those six fixes must now be present in the physical candidate itself.
TMP="$(mktemp -d /root/Bambodia-MCP-RefreshedHTTPQA.XXXXXXXX)"
cleanup(){
 if [[ "$TMP" == /root/Bambodia-MCP-RefreshedHTTPQA.* &&
   -d "$TMP" && ! -L "$TMP" ]]; then
  rm -rf --one-file-system -- "$TMP"
 fi
}
trap cleanup EXIT
download(){
 gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/$REPO/contents/$1?ref=$RELEASE" > "$3" ||
  fail QA_SOURCE_DOWNLOAD_FAILED
 [[ -s "$3" && ! -L "$3" &&
   "$(git hash-object "$3")" == "$2" ]] || fail QA_SOURCE_BLOB_MISMATCH
}
download "support/bambodia-mcp-chatgpt-loopback-qa.sh" \
 "19e4bae5a5b3ef33cb1baabbd011270d4c49a693" \
 "$TMP/upstream.sh"
download "support/bambodia-mcp-chatgpt-loopback-client.py" \
 "5ed0d6255b064929f0848d7776b4a80090dbffd3" \
 "$TMP/client.py"
# Never execute the old runner against a stale private source by accident.
python3 - "$TMP/upstream.sh" "$TMP/qa.sh" <<'PY'
from pathlib import Path
import sys
upstream,output=map(Path,sys.argv[1:])
src=upstream.read_text(encoding="utf-8")
old="OVERLAY=/root/Bambodia-MCP-Main-Overlay.ApguAhIY"
new="OVERLAY=/root/Bambodia-MCP-Overlay-TwoBlade.9ZqCTvhp"
if src.count(old)!=1:raise SystemExit("MCP_REFRESHED_HTTP_QA_BLOCKED=UPSTREAM_OVERLAY_ANCHOR_DRIFT")
src=src.replace(old,new,1)
a="# The on-disk private overlay is an immutable, previously reviewed baseline."
b="# Public index.php is fail-closed until installed.lock exists."
if src.count(a)!=1 or src.count(b)!=1 or src.index(a)>src.index(b):
 raise SystemExit("MCP_REFRESHED_HTTP_QA_BLOCKED=UPSTREAM_PATCH_SECTION_DRIFT")
replacement='''# Six current OAuth source files were validated against GitHub hashes in
# the REFRESHED PRIVATE SOURCE and must be copied unchanged into QA.
for rel in bootstrap/app.php app/Http/Middleware/RequireMcpOAuthResource.php \\
  app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php config/mcp.php \\
  app/Http/Controllers/Api/BambodiaOAuthRegisterController.php \\
  tests/Feature/BambodiaMcpOAuthTest.php
do
  [[ -f "$QA/$rel" && ! -L "$QA/$rel" ]] || fail QA_SECURITY_FILE_MISSING
  [[ "$(git hash-object "$QA/$rel")" == "$(git hash-object "$SOURCE/$rel")" ]] ||
    fail QA_SECURITY_FILE_COPY_CHANGED
done
echo "REFRESHED_CANDIDATE_QA_SOURCE=UNPATCHED_PRIVATE_CLONE"
'''
src=src[:src.index(a)]+replacement+src[src.index(b):]
# Verify the current public frontend can render using the disposable DB.
# Only localhost is contacted; redirects are not followed.
smoke_anchor='echo "CHATGPT_LOOPBACK_HTTP=BOUND_TO_127_0_0_1"\n'
smoke='''echo "========== REFRESHED PUBLIC FRONTEND HTTP SMOKE =========="
for item in "HOME|/" "BLOG|/blog" "JOBS|/jobs"; do
  label="${item%%|*}"
  endpoint="${item#*|}"
  code="$(curl --silent --max-time 10 -H 'Host: bambodia.com' \\
    --output /dev/null --write-out '%{http_code}' \\
    "http://127.0.0.1:$PORT$endpoint" 2>/dev/null || true)"
  [[ "$code" == "200" ]] || fail "REFRESHED_PUBLIC_HTTP_${label}_NOT_200"
  echo "REFRESHED_PUBLIC_HTTP_${label}=200"
done
echo "REFRESHED_CANDIDATE_PUBLIC_HTTP_SMOKE=PASS"
'''
if src.count(smoke_anchor)!=1:
 raise SystemExit("MCP_REFRESHED_HTTP_QA_BLOCKED=SMOKE_INJECTION_ANCHOR_DRIFT")
src=src.replace(smoke_anchor,smoke_anchor+smoke,1)
if "qa_patch_php" in src or "QA_SOURCE_RELEASE" in src:
 raise SystemExit("MCP_REFRESHED_HTTP_QA_BLOCKED=HOT_PATCH_STILL_PRESENT")
output.write_text(src,encoding="utf-8")
print("REFRESHED_CANDIDATE_QA_RUNNER=PINNED_SOURCE_ADAPTED")
PY
bash -n "$TMP/qa.sh" || fail ADAPTED_QA_BASH_SYNTAX_FAILED
python3 -m py_compile "$TMP/client.py" || fail QA_CLIENT_SYNTAX_FAILED
echo "REFRESHED_CANDIDATE_QA_SCRIPT=VERIFIED"

# All the original private HTTP checks and ephemeral credentials remain.
# The actual application code is now sourced from the refreshed candidate.
set +e
bash "$TMP/qa.sh" --isolated-loopback-qa "$TMP/client.py" \
 > "$TMP/result.log" 2>&1
STATUS=$?
set -e
echo "========== ALLOWLISTED PRIVATE HTTP ACCEPTANCE =========="
grep -E '^(REFRESHED_CANDIDATE_|REFRESHED_PUBLIC_HTTP_|QA_HTTP_|PHYSICAL_PHP85_MCP_OAUTH_TESTS|CHATGPT_LOOPBACK_|MCP_OAUTH_QA_BLOCKED|PRODUCTION_SOURCE_DATABASE_KEYS_NGINX|EXTERNAL_CHATGPT_ACCOUNT_LINKING)' \
 "$TMP/result.log" | tail -n 120 || true
[[ "$STATUS" -eq 0 ]] || fail PRIVATE_HTTP_QA_FAILED
grep -qx 'CHATGPT_LOOPBACK_INDEPENDENT_ACCEPTANCE=PASS' "$TMP/result.log" ||
 fail INDEPENDENT_HTTP_OAUTH_NOT_PROVEN
grep -qx 'PHYSICAL_PHP85_MCP_OAUTH_TESTS=PASS' "$TMP/result.log" ||
 fail PHP85_OAUTH_TESTS_NOT_PROVEN
grep -qx 'REFRESHED_CANDIDATE_PUBLIC_HTTP_SMOKE=PASS' "$TMP/result.log" ||
 fail PUBLIC_FRONTEND_HTTP_NOT_VERIFIED
echo "TWO_BLADE_CANDIDATE_INDEPENDENT_HTTP_OAUTH=PASS"
echo "TWO_BLADE_CANDIDATE_PUBLIC_HTTP_SMOKE=PASS"
echo "TWO_BLADE_CANDIDATE_RELEASE_AUTHORIZATION=NO"
echo "TWO_BLADE_CANDIDATE_BROWSER_DEVICE_QA=PENDING"
echo "PRODUCTION_SOURCE_DATABASE_NGINX_KEYS=UNCHANGED"
echo "MCP_PRODUCTION_ACTIVATION=NOT_STARTED"
echo "PRODUCTION_DEPLOYMENT=NOT_STARTED"
